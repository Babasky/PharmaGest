<?php

namespace App\Controller;

use App\Entity\LigneTransfert;
use App\Entity\Pharmacie;
use App\Entity\Produit;
use App\Entity\TransfertStock;
use App\Entity\Utilisateur;
use App\Enum\StatutTransfert;
use App\Repository\LotRepository;
use App\Repository\ProduitRepository;
use App\Repository\TransfertStockRepository;
use App\Stock\StockException;
use App\Stock\StockService;
use App\Stock\TransfertService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Transferts de stock entre officines du même propriétaire (ST-11), pour le propriétaire et l'adjoint.
 *
 * L'officine d'origine prépare, expédie ou annule ; l'officine destinataire confirme la réception.
 * Le transfert est chargé hors filtre tenant par {@see TransfertService::trouver()}, qui ne le rend
 * qu'à l'une des deux officines.
 */
#[Route('/transferts')]
#[IsGranted(Utilisateur::ROLE_ADJOINT)]
final class TransfertController extends AbstractAppController
{
    private const CSRF_NOUVEAU = 'transfert-nouveau';

    public function __construct(private readonly TransfertService $transferts)
    {
    }

    #[Route('', name: 'app_transfert_index', methods: ['GET'])]
    public function index(TransfertStockRepository $repository, #[MapQueryParameter] ?string $statut = null, #[MapQueryParameter] int $page = 1): Response
    {
        $filtreStatut = StatutTransfert::tryFrom((string) $statut);

        return $this->render('transfert/index.html.twig', [
            'destinations' => $this->transferts->destinations($this->pharmacie()),
            'entrants' => $this->transferts->entrants(),
            'sortants' => $repository->sortants($filtreStatut, $page),
            'statuts' => StatutTransfert::cases(),
            'statut' => $filtreStatut?->value,
            'csrf_nouveau' => self::CSRF_NOUVEAU,
        ]);
    }

    #[Route('/nouveau', name: 'app_transfert_nouveau', methods: ['POST'])]
    #[IsCsrfTokenValid(self::CSRF_NOUVEAU)]
    public function nouveau(Request $requete): Response
    {
        $donnees = $requete->getPayload();
        $destination = $this->tenantContext->sansFiltre(fn () => $this->entityManager->find(Pharmacie::class, (int) $donnees->get('destination')));
        if (!$destination instanceof Pharmacie) {
            $this->addFlash('error', 'Choisissez l\'officine qui reçoit le stock.');

            return $this->redirectToRoute('app_transfert_index');
        }
        try {
            $transfert = $this->transferts->creer($destination, (string) $donnees->get('note'));
            $this->addFlash('success', \sprintf('Transfert %s vers %s créé : ajoutez les produits.', $transfert->getNumero(), $destination->getNom()));

            return $this->redirectToRoute('app_transfert_voir', ['id' => $transfert->getId()]);
        } catch (StockException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_transfert_index');
        }
    }

    #[Route('/{id}', name: 'app_transfert_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(int $id, ProduitRepository $produits, LotRepository $lots, StockService $stock, #[MapQueryParameter] ?string $q = null): Response
    {
        $transfert = $this->trouver($id);
        $sortant = $this->estSortant($transfert);
        $resultats = [];
        $synthese = [];
        if ($sortant) {
            if ($transfert->estEnPreparation() && null !== $q && '' !== trim($q)) {
                $resultats = \array_slice($produits->rechercher($q, null, false, 1)->elements, 0, 10);
            }
            $listes = [...$transfert->getLignes()->map(static fn (LigneTransfert $l) => $l->getProduit())->toArray(), ...$resultats];
            $synthese = $lots->syntheseParProduit($listes, $stock->aujourdhui());
        }

        return $this->render('transfert/voir.html.twig', [
            'transfert' => $transfert,
            'sortant' => $sortant,
            'q' => $q,
            'resultats' => $resultats,
            'synthese' => $synthese,
        ]);
    }

    #[Route('/{id}/ajouter', name: 'app_transfert_ajouter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ajouter(int $id, Request $requete, ProduitRepository $produits): Response
    {
        $transfert = $this->trouverSortant($id, $requete);
        $donnees = $requete->getPayload();

        return $this->agir($transfert, function () use ($transfert, $donnees, $produits): string {
            $produit = $produits->find((int) $donnees->get('produit'));
            $quantite = $donnees->get('quantite');
            if (!$produit instanceof Produit || !\is_scalar($quantite) || !ctype_digit(trim((string) $quantite))) {
                throw new StockException('Choisissez un produit et une quantité.');
            }
            $ligne = $this->transferts->ajouter($transfert, $produit, (int) $quantite);

            return \sprintf('%s : %d à transférer.', $produit->getNomCommercial(), $ligne->getQuantite());
        });
    }

    #[Route('/{id}/lignes', name: 'app_transfert_lignes', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function lignes(int $id, Request $requete): Response
    {
        $transfert = $this->trouverSortant($id, $requete);

        return $this->agir($transfert, function () use ($transfert, $requete): string {
            $this->transferts->modifierQuantites($transfert, $requete->getPayload()->all('quantite'));

            return 'Transfert mis à jour.';
        });
    }

    #[Route('/{id}/retirer/{ligne}', name: 'app_transfert_retirer', requirements: ['id' => '\d+', 'ligne' => '\d+'], methods: ['POST'])]
    public function retirer(int $id, int $ligne, Request $requete): Response
    {
        $transfert = $this->trouverSortant($id, $requete);

        return $this->agir($transfert, function () use ($transfert, $ligne): string {
            foreach ($transfert->getLignes() as $l) {
                if ($l->getId() === $ligne) {
                    $this->transferts->retirer($transfert, $l);

                    return \sprintf('%s retiré du transfert.', $l->getProduit()->getNomCommercial());
                }
            }
            throw new StockException('Ce produit ne fait pas partie du transfert.');
        });
    }

    #[Route('/{id}/expedier', name: 'app_transfert_expedier', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function expedier(int $id, Request $requete): Response
    {
        $transfert = $this->trouverSortant($id, $requete);

        return $this->agir($transfert, function () use ($transfert): string {
            $this->transferts->expedier($transfert);

            return \sprintf('Transfert %s expédié : le stock est sorti. %s doit maintenant confirmer la réception.', $transfert->getNumero(), $transfert->getPharmacieDestination()->getNom());
        });
    }

    #[Route('/{id}/annuler', name: 'app_transfert_annuler', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function annuler(int $id, Request $requete): Response
    {
        $transfert = $this->trouverSortant($id, $requete);

        return $this->agir($transfert, function () use ($transfert, $requete): string {
            $this->transferts->annuler($transfert, (string) $requete->getPayload()->get('motif'));

            return \sprintf('Transfert %s annulé.', $transfert->getNumero());
        });
    }

    #[Route('/{id}/receptionner', name: 'app_transfert_receptionner', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function receptionner(int $id, Request $requete): Response
    {
        $transfert = $this->trouver($id);
        $this->exigerJeton($transfert, $requete);

        return $this->agir($transfert, function () use ($transfert): string {
            $crees = $this->transferts->receptionner($transfert);
            $message = \sprintf('Transfert %s reçu : les lots sont entrés dans votre stock.', $transfert->getNumero());
            if ([] !== $crees) {
                $message .= \sprintf(' Produit(s) ajouté(s) à votre catalogue : %s (complétez catégorie et étagère).', implode(', ', $crees));
            }

            return $message;
        });
    }

    private function trouver(int $id): TransfertStock
    {
        return $this->transferts->trouver($id) ?? throw $this->createNotFoundException();
    }

    private function estSortant(TransfertStock $transfert): bool
    {
        return $transfert->getPharmacieOrigine()->getId() === $this->pharmacie()->getId();
    }

    /** Transfert de l'officine courante (les actions de préparation et d'expédition lui sont réservées). */
    private function trouverSortant(int $id, Request $requete): TransfertStock
    {
        $transfert = $this->trouver($id);
        if (!$this->estSortant($transfert)) {
            throw $this->createNotFoundException();
        }
        $this->exigerJeton($transfert, $requete);

        return $transfert;
    }

    private function exigerJeton(TransfertStock $transfert, Request $requete): void
    {
        if (!$this->isCsrfTokenValid('transfert-'.$transfert->getId(), (string) $requete->getPayload()->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    /**
     * @param callable(): string $action renvoie le message de succès
     */
    private function agir(TransfertStock $transfert, callable $action): Response
    {
        try {
            $this->addFlash('success', $action());
        } catch (StockException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_transfert_voir', ['id' => $transfert->getId()]);
    }
}
