<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Entity\ZoneRangement;
use App\Form\ZoneRangementType;
use App\Repository\ZoneRangementRepository;
use App\Stock\ZonesRangement;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Zones de rangement propres à la pharmacie (RF-02) : propriétaire et adjoint en créent, les renomment, les archivent.
 */
#[Route('/etageres/zones')]
#[IsGranted(Utilisateur::ROLE_ADJOINT)]
final class ZoneRangementController extends AbstractAppController
{
    #[Route('', name: 'app_zone_index', methods: ['GET', 'POST'])]
    public function index(Request $requete, ZonesRangement $zones, ZoneRangementRepository $repository): Response
    {
        $zones->assurer($this->pharmacie());
        $zone = new ZoneRangement();
        $formulaire = $this->createForm(ZoneRangementType::class, $zone);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->entityManager->persist($zone);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Zone « %s » créée : elle est proposée sur les étagères.', $zone->getNom()));

            return $this->redirectToRoute('app_zone_index');
        }

        return $this->render('zone/index.html.twig', [
            'zones' => $repository->toutes(),
            'etageres' => $repository->nombreEtageres(),
            'formulaire' => $formulaire,
        ], new Response(status: $formulaire->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/modifier', name: 'app_zone_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(ZoneRangement $zone, Request $requete): Response
    {
        $this->exigerMemePharmacie($zone);
        $formulaire = $this->createForm(ZoneRangementType::class, $zone);
        $formulaire->handleRequest($requete);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Zone renommée.');

            return $this->redirectToRoute('app_zone_index');
        }

        return $this->render('referentiel/formulaire.html.twig', [
            'formulaire' => $formulaire,
            'titre' => 'Renommer la zone '.$zone->getNom(),
            'retour' => $this->generateUrl('app_zone_index'),
        ], new Response(status: $formulaire->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/archiver', name: 'app_zone_archiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"archiver-zone-" ~ args["zone"].getId()'))]
    public function archiver(ZoneRangement $zone): Response
    {
        $this->basculerArchivage($zone, \sprintf('La zone « %s »', $zone->getNom()));

        return $this->redirectToRoute('app_zone_index');
    }
}
