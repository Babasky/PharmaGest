<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Utilisateur;
use App\Form\CategorieType;
use App\Repository\CategorieRepository;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Catégories de produits sur deux niveaux (RF-01) : propriétaire et adjoint.
 */
#[Route('/categories')]
#[IsGranted(Utilisateur::ROLE_ADJOINT)]
final class CategorieController extends AbstractAppController
{
    #[Route('', name: 'app_categorie_index', methods: ['GET', 'POST'])]
    public function index(Request $requete, CategorieRepository $categories): Response
    {
        $categorie = new Categorie();
        $formulaire = $this->createForm(CategorieType::class, $categorie);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->entityManager->persist($categorie);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Catégorie « %s » créée.', $categorie->getNomComplet()));

            return $this->redirectToRoute('app_categorie_index');
        }

        return $this->render('categorie/index.html.twig', [
            'categories' => $categories->arborescence(),
            'formulaire' => $formulaire,
        ], new Response(status: $formulaire->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/modifier', name: 'app_categorie_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Categorie $categorie, Request $requete): Response
    {
        $this->exigerMemePharmacie($categorie);
        $formulaire = $this->createForm(CategorieType::class, $categorie);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Catégorie modifiée.');

            return $this->redirectToRoute('app_categorie_index');
        }

        return $this->render('referentiel/formulaire.html.twig', [
            'formulaire' => $formulaire,
            'titre' => 'Modifier la catégorie',
            'retour' => $this->generateUrl('app_categorie_index'),
        ], new Response(status: $formulaire->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/archiver', name: 'app_categorie_archiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"archiver-" ~ args["categorie"].getId()'))]
    public function archiver(Categorie $categorie): Response
    {
        $this->basculerArchivage($categorie, \sprintf('La catégorie « %s »', $categorie->getNomComplet()));

        return $this->redirectToRoute('app_categorie_index');
    }
}
