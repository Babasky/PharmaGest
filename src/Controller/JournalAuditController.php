<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Repository\JournalAuditRepository;
use App\Service\AuditLogger;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Consultation du journal d'audit de la pharmacie (AU-02), en lecture seule, par le propriétaire.
 */
#[Route('/journal')]
#[IsGranted(Utilisateur::ROLE_PROPRIETAIRE)]
final class JournalAuditController extends AbstractAppController
{
    #[Route('', name: 'app_audit_index', methods: ['GET'])]
    public function index(
        JournalAuditRepository $journal,
        #[MapQueryParameter] ?string $utilisateur = null,
        #[MapQueryParameter] ?string $action = null,
        #[MapQueryParameter] ?string $du = null,
        #[MapQueryParameter] ?string $au = null,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        return $this->render('audit/index.html.twig', self::contexte($journal, null, $utilisateur, $action, $du, $au, $page) + [
            'route' => 'app_audit_index',
            'plateforme' => false,
        ]);
    }

    /**
     * Filtres et résultats, partagés avec le journal de la plateforme (super admin).
     *
     * @param list<string>|null $actions actions consultables (null : toutes)
     *
     * @return array<string, mixed>
     */
    public static function contexte(JournalAuditRepository $journal, ?array $actions, ?string $utilisateur, ?string $action, ?string $du, ?string $au, int $page): array
    {
        $auteurs = $journal->auteurs($actions);
        $auteur = null;
        foreach ($auteurs as $a) {
            if ((string) $a->getId() === $utilisateur) {
                $auteur = $a;
            }
        }
        $libelles = null === $actions ? AuditLogger::LIBELLES : array_intersect_key(AuditLogger::LIBELLES, array_flip($actions));
        asort($libelles);
        $action = isset($libelles[(string) $action]) ? $action : null;
        $dateDu = self::date($du);
        $dateAu = self::date($au);

        return [
            'entrees' => $journal->liste($auteur, $action, $dateDu, $dateAu, $page, $actions),
            'auteurs' => $auteurs,
            'actions' => $libelles,
            'filtres' => [
                'utilisateur' => $auteur?->getId(),
                'action' => $action,
                'du' => $dateDu?->format('Y-m-d'),
                'au' => $dateAu?->format('Y-m-d'),
            ],
        ];
    }

    private static function date(?string $valeur): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $valeur);

        return false === $date ? null : $date;
    }
}
