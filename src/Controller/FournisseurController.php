<?php

namespace App\Controller;

use App\Entity\Fournisseur;
use App\Entity\Utilisateur;
use App\Form\FournisseurType;
use App\Repository\FournisseurRepository;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fournisseurs (RF-05) : consultation par toute l'équipe, gestion par le propriétaire et l'adjoint.
 */
#[Route('/fournisseurs')]
#[IsGranted(Utilisateur::ROLE_VENDEUR)]
final class FournisseurController extends AbstractAppController
{
    #[Route('', name: 'app_fournisseur_index', methods: ['GET'])]
    public function index(FournisseurRepository $fournisseurs, #[MapQueryParameter] ?string $q = null, #[MapQueryParameter] bool $archives = false, #[MapQueryParameter] int $page = 1): Response
    {
        return $this->render('fournisseur/index.html.twig', [
            'fournisseurs' => $fournisseurs->rechercher($q, $archives, $page),
            'q' => $q,
            'archives' => $archives,
        ]);
    }

    #[Route('/nouveau', name: 'app_fournisseur_nouveau', methods: ['GET', 'POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    public function nouveau(Request $requete): Response
    {
        return $this->formulaire($requete, new Fournisseur(), 'Nouveau fournisseur');
    }

    #[Route('/{id}/modifier', name: 'app_fournisseur_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    public function modifier(Fournisseur $fournisseur, Request $requete): Response
    {
        $this->exigerMemePharmacie($fournisseur);

        return $this->formulaire($requete, $fournisseur, 'Modifier '.$fournisseur->getNom());
    }

    #[Route('/{id}/archiver', name: 'app_fournisseur_archiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Utilisateur::ROLE_ADJOINT)]
    #[IsCsrfTokenValid(new Expression('"archiver-" ~ args["fournisseur"].getId()'))]
    public function archiver(Fournisseur $fournisseur): Response
    {
        $this->basculerArchivage($fournisseur, \sprintf('Le fournisseur %s', $fournisseur->getNom()));

        return $this->redirectToRoute('app_fournisseur_index');
    }

    private function formulaire(Request $requete, Fournisseur $fournisseur, string $titre): Response
    {
        $formulaire = $this->createForm(FournisseurType::class, $fournisseur);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->entityManager->persist($fournisseur);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Fournisseur %s enregistré.', $fournisseur->getNom()));

            return $this->redirectToRoute('app_fournisseur_index');
        }

        return $this->render('referentiel/formulaire.html.twig', [
            'formulaire' => $formulaire,
            'titre' => $titre,
            'retour' => $this->generateUrl('app_fournisseur_index'),
            'entite' => $fournisseur->getId() ? $fournisseur : null,
            'route_archiver' => 'app_fournisseur_archiver',
        ], new Response(status: $formulaire->isSubmitted() ? 422 : 200));
    }
}
