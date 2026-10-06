<?php

namespace App\Command;

use App\Repository\NotificationRepository;
use App\Repository\PharmacieRepository;
use App\Service\GenerateurNotifications;
use App\Tenant\TenantContext;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Alimente le centre de notifications de chaque pharmacie (NO-01) et purge les notifications lues depuis 90 jours.
 * Lancée chaque matin par le Scheduler ({@see \App\Schedule}).
 */
#[AsCommand(name: 'app:notifications:generer', description: 'Crée les notifications du jour (ruptures, péremptions, échéances, bordereaux impayés)')]
final class NotificationsCommand
{
    public const JOURS_CONSERVATION = 90;

    public function __construct(
        private readonly PharmacieRepository $pharmacies,
        private readonly GenerateurNotifications $generateur,
        private readonly NotificationRepository $notifications,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $horloge,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $creees = 0;
        foreach ($this->tenantContext->sansFiltre(fn () => $this->pharmacies->nonArchivees()) as $pharmacie) {
            $creees += $this->generateur->generer($pharmacie);
        }
        $purgees = $this->tenantContext->sansFiltre(fn () => $this->notifications->purgerLuesAvant($this->horloge->now()->modify(\sprintf('-%d days', self::JOURS_CONSERVATION))));

        $io->success(\sprintf('%d notification(s) créée(s), %d ancienne(s) notification(s) lue(s) supprimée(s).', $creees, $purgees));

        return Command::SUCCESS;
    }
}
