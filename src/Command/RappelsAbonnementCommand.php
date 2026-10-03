<?php

namespace App\Command;

use App\Mailer\PlateformeMailer;
use App\Repository\AffectationRepository;
use App\Repository\PharmacieRepository;
use App\Service\AbonnementService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rappels d'échéance au propriétaire à J-30, J-15 et J-7 (§ 3.2 étape 4).
 * Lancée chaque matin par le Scheduler ({@see \App\Schedule}) ; sans filtre tenant (commande de plateforme).
 */
#[AsCommand(name: 'app:abonnements:rappels', description: 'Envoie les rappels d\'échéance d\'abonnement du jour')]
final class RappelsAbonnementCommand
{
    public function __construct(
        private readonly PharmacieRepository $pharmacies,
        private readonly AffectationRepository $affectations,
        private readonly AbonnementService $abonnements,
        private readonly PlateformeMailer $mailer,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $envois = 0;
        foreach ($this->pharmacies->nonArchivees() as $pharmacie) {
            $etat = $this->abonnements->etat($pharmacie);
            if (!$etat->statut->permetAcces() || !\in_array($etat->joursRestants, AbonnementService::JOURS_RAPPEL, true)) {
                continue;
            }

            foreach ($this->affectations->proprietaires($pharmacie) as $proprietaire) {
                $this->mailer->rappelEcheance($proprietaire, $pharmacie, $etat);
                ++$envois;
            }
        }

        $io->success(\sprintf('%d rappel(s) envoyé(s).', $envois));

        return Command::SUCCESS;
    }
}
