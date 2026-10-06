<?php

namespace App\Controller\Admin;

use App\Controller\JournalAuditController;
use App\Repository\JournalAuditRepository;
use App\Service\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Journal d'audit de la plateforme (matrice des droits : « L (plateforme) ») : créations, suspensions, archivages
 * et paiements d'abonnement. L'activité interne des officines (ventes, stock…) n'y figure pas.
 */
#[Route('/admin/journal')]
final class JournalController extends AbstractController
{
    #[Route('', name: 'admin_journal_index', methods: ['GET'])]
    public function index(
        JournalAuditRepository $journal,
        #[MapQueryParameter] ?string $utilisateur = null,
        #[MapQueryParameter] ?string $action = null,
        #[MapQueryParameter] ?string $du = null,
        #[MapQueryParameter] ?string $au = null,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        return $this->render('audit/index.html.twig', JournalAuditController::contexte($journal, AuditLogger::ACTIONS_PLATEFORME, $utilisateur, $action, $du, $au, $page) + [
            'route' => 'admin_journal_index',
            'plateforme' => true,
        ]);
    }
}
