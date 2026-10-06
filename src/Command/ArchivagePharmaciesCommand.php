<?php

namespace App\Command;

use App\Repository\PharmacieRepository;
use App\Service\ArchivageAutomatique;
use App\Tenant\TenantContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Annonce puis réalise l'archivage des pharmacies dont l'échéance est dépassée depuis 12 mois.
 * Lancée chaque nuit par le Scheduler ({@see \App\Schedule}) ; sans filtre tenant (commande de plateforme).
 */
#[AsCommand(name: 'app:pharmacies:archiver', description: 'Archive les pharmacies expirées depuis 12 mois (export envoyé au propriétaire)')]
final class ArchivagePharmaciesCommand
{
    public function __construct(
        private readonly PharmacieRepository $pharmacies,
        private readonly ArchivageAutomatique $archivage,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $bilan = ['archivee' => 0, 'annoncee' => 0];
        foreach ($this->tenantContext->sansFiltre(fn () => $this->pharmacies->nonArchivees()) as $pharmacie) {
            $resultat = $this->tenantContext->sansFiltre(fn () => $this->archivage->traiter($pharmacie));
            if (null !== $resultat) {
                ++$bilan[$resultat];
                $io->writeln(\sprintf('%s : %s', $pharmacie->getNom(), 'archivee' === $resultat ? 'archivée, export envoyé' : 'archivage annoncé'));
            }
        }

        $io->success(\sprintf('%d pharmacie(s) archivée(s), %d archivage(s) annoncé(s).', $bilan['archivee'], $bilan['annoncee']));

        return Command::SUCCESS;
    }
}
