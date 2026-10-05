<?php

namespace App\Controller;

use App\Entity\Lot;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Form\EntreeStockType;
use App\Form\Model\EntreeStock;
use App\Form\Model\OperationLot;
use App\Form\OperationLotType;
use App\Repository\CategorieRepository;
use App\Repository\LotRepository;
use App\Repository\ProduitRepository;
use App\Stock\AlertesStock;
use App\Stock\StockException;
use App\Stock\StockService;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * État du stock, alertes et valorisation (ST-05, ST-07) ; entrées, ajustements et destructions de lots
 * (ST-01, ST-04), réservés au propriétaire et à l'adjoint.
 */
#[Route('/stock')]
#[IsGranted(Utilisateur::ROLE_VENDEUR)]
final class StockController extends AbstractAppController
{
    public function __construct(
        private readonly StockService $stock,
        private readonly LotRepository $lots,
    ) {
    }

    #[Route('', name: 'app_stock_index', methods: ['GET'])]
    public function index(
        ProduitRepository $produits,
        CategorieRepository $categories,
        AlertesStock $alertes,
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter] ?int $categorie = null,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $resultats = $produits->rechercher($q, $categorie, false, $page);

        return $this->render('stock/index.html.twig', [
            'produits' => $resultats,
            'synthese' => $this->lots->syntheseParProduit($resultats, $this->stock->aujourdhui()),
            'alertes' => $alertes->compter(),
            'categories' => $categories->arborescence(actifsSeulement: true),
            'q' => $q,
            'categorie' => $categorie,
        ]);
    }

    #[Route('/alertes', name: 'app_stock_alertes', methods: ['GET'])]
    public function alertes(
        AlertesStock $alertes,
        #[MapQueryParameter] string $type = AlertesStock::SEUIL,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        if (!\array_key_exists($type, AlertesStock::LIBELLES)) {
            throw $this->createNotFoundException();
        }
        $elements = $alertes->liste($type, $page);
        $produitsListes = array_map(static fn ($e) => $e instanceof Lot ? $e->getProduit() : $e, $elements->elements);

        return $this->render('stock/alertes.html.twig', [
            'type' => $type,
            'elements' => $elements,
            'synthese' => $this->lots->syntheseParProduit(array_filter($produitsListes, static fn ($p) => $p instanceof Produit), $this->stock->aujourdhui()),
            'nombres' => $alertes->compter(),
            'delai' => $alertes->delaiPeremption(),
            'jours_dormant' => AlertesStock::JOURS_DORMANT,
            'aujourdhui' => $this->stock->aujourdhui(),
        ]);
    }

    #[Route('/valorisation', name: 'app_stock_valorisation', methods: ['GET'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    public function valorisation(): Response
    {
        $lignes = $this->lots->valorisationParCategorie($this->stock->aujourdhui());

        return $this->render('stock/valorisation.html.twig', [
            'lignes' => $lignes,
            'total' => array_sum(array_column($lignes, 'disponible')),
            'total_perime' => array_sum(array_column($lignes, 'perime')),
        ]);
    }

    #[Route('/produit/{id}/entree', name: 'app_stock_entree', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    public function entree(Produit $produit, Request $requete): Response
    {
        $this->exigerMemePharmacie($produit);
        $entree = new EntreeStock();
        $entree->prixAchat = $produit->getPrixAchat();
        $entree->fournisseur = $produit->getFournisseurHabituel()?->isActif() ? $produit->getFournisseurHabituel() : null;

        $formulaire = $this->createForm(EntreeStockType::class, $entree);
        $formulaire->handleRequest($requete);
        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            try {
                $lot = $this->stock->entrer($produit, (string) $entree->numero, $entree->datePeremption ?? new \DateTimeImmutable(), (int) $entree->quantite, (int) $entree->prixAchat, $entree->fournisseur, motif: $entree->motif);
                $this->addFlash('success', \sprintf('%d unité(s) de %s entrée(s) en stock (lot %s).', $lot->getQuantiteInitiale(), $produit->getNomCommercial(), $lot->getNumero()));

                return $this->redirectToRoute('app_produit_voir', ['id' => $produit->getId()]);
            } catch (StockException $e) {
                $formulaire->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('stock/entree.html.twig', [
            'produit' => $produit,
            'formulaire' => $formulaire,
        ], self::reponseRefusSiSoumis($formulaire));
    }

    #[Route('/lot/{id}/ajuster', name: 'app_stock_ajuster', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    public function ajuster(Lot $lot, Request $requete): Response
    {
        return $this->operationLot($lot, $requete, 'ajuster');
    }

    #[Route('/lot/{id}/detruire', name: 'app_stock_detruire', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    public function detruire(Lot $lot, Request $requete): Response
    {
        return $this->operationLot($lot, $requete, 'detruire');
    }

    private function operationLot(Lot $lot, Request $requete, string $operation): Response
    {
        $this->exigerMemePharmacie($lot);
        $estAjustement = 'ajuster' === $operation;
        $donnees = new OperationLot();
        $donnees->quantite = $estAjustement ? $lot->getQuantiteRestante() : null;

        $formulaire = $this->createForm(OperationLotType::class, $donnees, [
            'libelle_quantite' => $estAjustement ? 'Quantité réelle dans le lot' : 'Quantité à détruire',
        ]);
        $formulaire->handleRequest($requete);
        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            try {
                if ($estAjustement) {
                    $avant = $lot->getQuantiteRestante();
                    $mouvement = $this->stock->ajuster($lot, (int) $donnees->quantite, (string) $donnees->motif);
                    $this->addFlash(null === $mouvement ? 'info' : 'success', null === $mouvement
                        ? 'Quantité inchangée : aucun ajustement enregistré.'
                        : \sprintf('Lot %s ajusté de %d à %d unité(s).', $lot->getNumero(), $avant, $lot->getQuantiteRestante()));
                } else {
                    $this->stock->detruire($lot, (int) $donnees->quantite, (string) $donnees->motif);
                    $this->addFlash('success', \sprintf('%d unité(s) du lot %s sorties du stock pour destruction.', $donnees->quantite, $lot->getNumero()));
                }

                return $this->redirectToRoute('app_produit_voir', ['id' => $lot->getProduit()->getId()]);
            } catch (StockException $e) {
                $formulaire->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('stock/operation_lot.html.twig', [
            'lot' => $lot,
            'formulaire' => $formulaire,
            'ajustement' => $estAjustement,
            'perime' => $lot->estPerimeLe($this->stock->aujourdhui()),
        ], self::reponseRefusSiSoumis($formulaire));
    }
}
