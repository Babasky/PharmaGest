<?php

namespace App\Controller;

use App\Entity\Abonnement;
use App\Entity\Utilisateur;
use App\Pdf\FactureAbonnementPdf;
use App\Repository\AbonnementRepository;
use App\Security\Voter\AbonnementVoter;
use App\Service\AbonnementService;
use App\Service\AuditLogger;
use App\Service\ExportDonnees;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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

    /**
     * Export complet des données (réversibilité, § 6), possible à tout moment, y compris en lecture seule.
     */
    #[Route('/export', name: 'app_mon_abonnement_export', methods: ['GET'])]
    public function export(ExportDonnees $export, AuditLogger $audit): Response
    {
        $pharmacie = $this->pharmacie();
        $archive = $export->creerArchive($pharmacie);
        $audit->journaliser(AuditLogger::DONNEES_EXPORTEES, $pharmacie, $pharmacie, null, ['taille' => filesize($archive)]);
        $this->entityManager->flush();

        return (new BinaryFileResponse($archive, headers: ['Content-Type' => 'application/zip']))
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $export->nomArchive($pharmacie))
            ->deleteFileAfterSend();
    }
}
