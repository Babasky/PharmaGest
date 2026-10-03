<?php

namespace App\Controller;

use App\Entity\Etagere;
use App\Entity\Utilisateur;
use App\Form\EtagereType;
use App\Repository\EtagereRepository;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Étagères et zones de rangement (RF-02) : propriétaire et adjoint.
 */
#[Route('/etageres')]
#[IsGranted(Utilisateur::ROLE_ADJOINT)]
final class EtagereController extends AbstractAppController
{
    #[Route('', name: 'app_etagere_index', methods: ['GET', 'POST'])]
    public function index(Request $requete, EtagereRepository $etageres): Response
    {
        $etagere = new Etagere();
        $formulaire = $this->createForm(EtagereType::class, $etagere);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->entityManager->persist($etagere);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Étagère %s créée.', $etagere->getCode()));

            return $this->redirectToRoute('app_etagere_index');
        }

        return $this->render('etagere/index.html.twig', [
            'etageres' => $etageres->toutes(),
            'formulaire' => $formulaire,
        ], new Response(status: $formulaire->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/modifier', name: 'app_etagere_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Etagere $etagere, Request $requete): Response
    {
        $this->exigerMemePharmacie($etagere);
        $formulaire = $this->createForm(EtagereType::class, $etagere);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Étagère modifiée.');

            return $this->redirectToRoute('app_etagere_index');
        }

        return $this->render('referentiel/formulaire.html.twig', [
            'formulaire' => $formulaire,
            'titre' => 'Modifier l\'étagère '.$etagere->getCode(),
            'retour' => $this->generateUrl('app_etagere_index'),
        ], new Response(status: $formulaire->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/archiver', name: 'app_etagere_archiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"archiver-" ~ args["etagere"].getId()'))]
    public function archiver(Etagere $etagere): Response
    {
        $this->basculerArchivage($etagere, \sprintf('L\'étagère %s', $etagere->getCode()));

        return $this->redirectToRoute('app_etagere_index');
    }
}
