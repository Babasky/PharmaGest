<?php

namespace App\Controller;

use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Form\ProduitType;
use App\Repository\CategorieRepository;
use App\Repository\LotRepository;
use App\Repository\MouvementStockRepository;
use App\Repository\ProduitRepository;
use App\Stock\StockService;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Catalogue de la pharmacie (RF-03) : consultation par toute l'équipe, gestion par le propriétaire et l'adjoint.
 */
#[Route('/produits')]
#[IsGranted(Utilisateur::ROLE_VENDEUR)]
final class ProduitController extends AbstractAppController
{
    #[Route('', name: 'app_produit_index', methods: ['GET'])]
    public function index(
        ProduitRepository $produits,
        CategorieRepository $categories,
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter] ?int $categorie = null,
        #[MapQueryParameter] bool $archives = false,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        return $this->render('produit/index.html.twig', [
            'produits' => $produits->rechercher($q, $categorie, $archives, $page),
            'categories' => $categories->arborescence(actifsSeulement: true),
            'q' => $q,
            'categorie' => $categorie,
            'archives' => $archives,
        ]);
    }

    #[Route('/{id}', name: 'app_produit_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(
        Produit $produit,
        LotRepository $lots,
        MouvementStockRepository $mouvements,
        StockService $stock,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        $this->exigerMemePharmacie($produit);
        $aujourdhui = $stock->aujourdhui();

        // Ventes des 12 derniers mois, mois en cours compris (ST-08).
        $debut = $aujourdhui->modify('first day of this month')->modify('-11 months');
        $ventes = $mouvements->ventesParMois($produit, $debut);
        $mois = [];
        for ($i = 0; $i < 12; ++$i) {
            $m = $debut->modify(\sprintf('+%d months', $i));
            $mois[$m->format('m/Y')] = $ventes[$m->format('Y-m')] ?? 0;
        }

        return $this->render('produit/voir.html.twig', [
            'produit' => $produit,
            'lots' => $lots->enStock($produit),
            'synthese' => $lots->syntheseParProduit([$produit], $aujourdhui)[(int) $produit->getId()],
            'mouvements' => $mouvements->historique($produit, $page),
            'ventes_mois' => $mois,
            'ventes_quantites' => array_values($mois),
            'aujourdhui' => $aujourdhui,
        ]);
    }

    #[Route('/nouveau', name: 'app_produit_nouveau', methods: ['GET', 'POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    public function nouveau(Request $requete): Response
    {
        return $this->formulaire($requete, new Produit(), 'Nouveau produit');
    }

    #[Route('/{id}/modifier', name: 'app_produit_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    public function modifier(Produit $produit, Request $requete): Response
    {
        $this->exigerMemePharmacie($produit);

        return $this->formulaire($requete, $produit, 'Modifier '.$produit->getNomCommercial());
    }

    #[Route('/{id}/archiver', name: 'app_produit_archiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    #[IsCsrfTokenValid(new Expression('"archiver-" ~ args["produit"].getId()'))]
    public function archiver(Produit $produit): Response
    {
        $this->basculerArchivage($produit, \sprintf('Le produit %s', $produit->getNomCommercial()));

        return $this->redirectToRoute('app_produit_voir', ['id' => $produit->getId()]);
    }

    private function formulaire(Request $requete, Produit $produit, string $titre): Response
    {
        $formulaire = $this->createForm(ProduitType::class, $produit);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->entityManager->persist($produit);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Produit %s enregistré.', $produit->getNomCommercial()));

            return $this->redirectToRoute('app_produit_voir', ['id' => $produit->getId()]);
        }

        return $this->render('produit/formulaire.html.twig', [
            'formulaire' => $formulaire,
            'titre' => $titre,
            'produit' => $produit,
        ], new Response(status: $formulaire->isSubmitted() ? 422 : 200));
    }
}
