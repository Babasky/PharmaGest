<?php

namespace App\Command;

use App\Achat\AchatException;
use App\Achat\SuggestionsCommande;
use App\Achat\TransmissionCommandes;
use App\Entity\Commande;
use App\Entity\Pharmacie;
use App\Repository\PharmacieRepository;
use App\Service\AbonnementService;
use App\Service\GenerateurNotifications;
use App\Service\ParametresPharmacie;
use App\Tenant\TenantContext;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Transmission automatique des commandes aux grossistes, pour les pharmacies qui l'ont activée dans leurs paramètres :
 * les commandes suggérées selon les tendances de vente sont envoyées par email, et les responsables en sont prévenus.
 * Lancée chaque soir par le Scheduler ({@see \App\Schedule}).
 */
#[AsCommand(name: 'app:commandes:automatiques', description: 'Envoie aux grossistes les commandes suggérées des pharmacies en envoi automatique')]
final class CommandesAutomatiquesCommand
{
    public function __construct(
        private readonly PharmacieRepository $pharmacies,
        private readonly ParametresPharmacie $parametres,
        private readonly AbonnementService $abonnements,
        private readonly SuggestionsCommande $suggestions,
        private readonly TransmissionCommandes $transmission,
        private readonly GenerateurNotifications $notifications,
        private readonly TenantContext $tenantContext,
        private readonly ClockInterface $horloge,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $envoyees = 0;
        foreach ($this->tenantContext->sansFiltre(fn () => $this->pharmacies->nonArchivees()) as $pharmacie) {
            if (!$this->abonnements->etat($pharmacie)->statut->permetEcriture()) {
                continue;
            }
            $precedente = $this->tenantContext->getPharmacie();
            $this->tenantContext->forcer($pharmacie);
            try {
                if ($this->parametres->pour($pharmacie)->isEnvoiAutomatiqueCommandes()) {
                    $envoyees += $this->traiter($pharmacie, $io);
                }
            } finally {
                $this->tenantContext->forcer($precedente);
            }
        }
        $io->success(\sprintf('%d commande(s) envoyée(s) automatiquement aux grossistes.', $envoyees));

        return Command::SUCCESS;
    }

    private function traiter(Pharmacie $pharmacie, SymfonyStyle $io): int
    {
        try {
            $bilan = $this->transmission->transmettre($this->transmission->preparer($this->suggestions->selonTendances(), false));
        } catch (AchatException $e) {
            $io->warning(\sprintf('%s : %s', $pharmacie->getNom(), $e->getMessage()));
            $bilan = ['envoyees' => [], 'sans_email' => [], 'echecs' => [$e->getMessage()]];
        }

        $phrases = [];
        if ([] !== $bilan['envoyees']) {
            $phrases[] = \sprintf('%d commande(s) envoyée(s) automatiquement : %s.', \count($bilan['envoyees']), implode(', ', array_map(static fn (Commande $c) => \sprintf('%s (%s)', $c->getNumero(), $c->getFournisseur()->getNom()), $bilan['envoyees'])));
        }
        if ([] !== $bilan['sans_email']) {
            $phrases[] = \sprintf('Brouillon à transmettre à la main (fournisseur sans email) : %s.', implode(', ', array_map(static fn (Commande $c) => $c->getFournisseur()->getNom(), $bilan['sans_email'])));
        }
        if ([] !== $bilan['echecs']) {
            $phrases[] = \sprintf('%d envoi(s) en échec : voir les brouillons.', \count($bilan['echecs']));
        }
        if ([] !== $phrases) {
            $message = mb_substr(implode(' ', $phrases), 0, 255);
            $this->notifications->commandesAutomatiques($pharmacie, $message, 'commandes-auto-'.$this->horloge->now()->format('Y-m-d'));
        }

        return \count($bilan['envoyees']);
    }
}
