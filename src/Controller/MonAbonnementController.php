<?php

namespace App\Controller;

use App\Entity\Abonnement;
use App\Entity\Utilisateur;
use App\Pdf\FactureAbonnementPdf;
use App\Repository\AbonnementRepository;
use App\Security\Voter\AbonnementVoter;
use App\Service\AbonnementService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Abonnement de la pharmacie courante, en lecture seule pour le propriétaire (§ 2).
 */
#[Route('/abonnement')]
#[IsGranted(Utilisateur::ROLE_PROPRIETAIRE)]
final class MonAbonnementController extends AbstractAppController
{
    #[Route('', name: 'app_mon_abonnement', methods: ['GET'])]
    public function index(AbonnementService $abonnements, AbonnementRepository $paiements): Response
    {
        $pharmacie = $this->pharmacie();

        return $this->render('abonnement/index.html.twig', [
            'pharmacie' => $pharmacie,
            'etat' => $abonnements->etat($pharmacie),
            'paiements' => $paiements->historique($pharmacie),
        ]);
    }

    #[Route('/factures/{id}', name: 'app_mon_abonnement_facture', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function facture(Abonnement $abonnement, FactureAbonnementPdf $pdf): Response
    {
        $this->exigerMemePharmacie($abonnement);
        $this->denyAccessUnlessGranted(AbonnementVoter::FACTURE, $abonnement);

        return $pdf->reponse($abonnement);
    }
}
