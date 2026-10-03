<?php

namespace App\Controller\Admin;

use App\Entity\Abonnement;
use App\Pdf\FactureAbonnementPdf;
use App\Repository\AbonnementRepository;
use App\Repository\OffreRepository;
use App\Repository\PharmacieRepository;
use App\Service\AbonnementService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Échéances, paiements, factures et offres (SA-02, SA-05, SA-06 ; paramétrage des offres en V1).
 */
#[Route('/admin')]
final class AbonnementController extends AbstractController
{
    #[Route('/abonnements', name: 'admin_abonnement_index', methods: ['GET'])]
    public function index(PharmacieRepository $pharmacies, AbonnementRepository $paiements, AbonnementService $abonnements): Response
    {
        $aujourdhui = $abonnements->aujourdhui();
        $echeances = array_map(
            static fn ($p) => ['pharmacie' => $p, 'etat' => $abonnements->etat($p)],
            $pharmacies->echeantEntre($aujourdhui->modify('-'.AbonnementService::JOURS_DE_GRACE.' days'), $aujourdhui->modify('+60 days')),
        );

        return $this->render('admin/abonnement/index.html.twig', [
            'echeances' => $echeances,
            'paiements' => $paiements->derniersPaiements(50),
        ]);
    }

    #[Route('/abonnements/{id}/facture', name: 'admin_abonnement_facture', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function facture(Abonnement $abonnement, FactureAbonnementPdf $pdf): Response
    {
        return $pdf->reponse($abonnement);
    }

    #[Route('/offres', name: 'admin_offre_index', methods: ['GET'])]
    public function offres(OffreRepository $offres): Response
    {
        return $this->render('admin/offre/index.html.twig', ['offres' => $offres->toutes()]);
    }
}
