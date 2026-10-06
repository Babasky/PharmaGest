<?php

namespace App\Controller\Admin;

use App\Entity\Abonnement;
use App\Pdf\FactureAbonnementPdf;
use App\Repository\AbonnementRepository;
use App\Repository\PharmacieRepository;
use App\Service\AbonnementService;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Échéances, paiements et factures d'abonnement (SA-02, SA-05, SA-06), dans l'espace plateforme (EasyAdmin).
 */
#[AdminRoute('/abonnements', name: 'abonnement')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class AbonnementController extends AbstractController
{
    #[AdminRoute('', name: 'index', options: ['methods' => ['GET']])]
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

    #[AdminRoute('/{id}/facture', name: 'facture', options: ['methods' => ['GET'], 'requirements' => ['id' => '\d+']])]
    public function facture(Abonnement $abonnement, FactureAbonnementPdf $pdf): Response
    {
        return $pdf->reponse($abonnement);
    }
}
