<?php

namespace App\Controller\Admin;

use App\Controller\JournalAuditController;
use App\Repository\JournalAuditRepository;
use App\Service\AuditLogger;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Journal d'audit de la plateforme (matrice des droits : « L (plateforme) ») : créations, suspensions, archivages
 * et paiements d'abonnement. L'activité interne des officines (ventes, stock…) n'y figure pas.
 */
#[AdminRoute('/journal', name: 'journal')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class JournalController extends AbstractController
{
    #[AdminRoute('', name: 'index', options: ['methods' => ['GET']])]
    public function index(
        JournalAuditRepository $journal,
        #[MapQueryParameter] ?string $utilisateur = null,
        #[MapQueryParameter] ?string $action = null,
        #[MapQueryParameter] ?string $du = null,
        #[MapQueryParameter] ?string $au = null,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        return $this->render('admin/journal.html.twig', JournalAuditController::contexte($journal, AuditLogger::ACTIONS_PLATEFORME, $utilisateur, $action, $du, $au, $page) + [
            'route' => 'admin_journal_index',
            'plateforme' => true,
        ]);
    }
}
