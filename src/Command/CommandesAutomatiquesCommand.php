<?php

namespace App\Command;

use App\Achat\AchatException;
use App\Achat\SuggestionsCommande;
use App\Achat\TransmissionCommandes;
use App\Entity\Commande;
use App\Entity\Pharmacie;
use App\Kernel;
use App\Repository\PharmacieRepository;
use App\Service\AbonnementService;
use App\Service\GenerateurNotifications;
use App\Service\ParametresPharmacie;
use App\Tenant\TenantContext;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Transmission automatique des commandes aux grossistes (à la manière de winAutopilote), pour les pharmacies qui l'ont
 * activée dans leurs paramètres : à l'heure et aux jours choisis sur la fiche de chaque fournisseur, la commande
 * suggérée selon les tendances de vente lui est envoyée par email.
 *
 * Garde-fou : une commande qui contient une quantité inhabituelle (plus de trois fois le dernier achat du produit)
 * reste en brouillon, à vérifier. Les responsables sont prévenus de chaque passage.
 * Lancée toutes les heures par le Scheduler ({@see \App\Schedule}).
 *
 * @phpstan-import-type Suggestion from SuggestionsCommande
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

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Heure de commande à traiter (0 à 23), pour rattraper un passage manqué ; par défaut l\'heure actuelle')] ?int $heure = null,
    ): int {
        $instant = $this->horloge->now()->setTimezone(new \DateTimeZone(Kernel::TIMEZONE));
        if (null !== $heure) {
            if ($heure < 0 || $heure > 23) {
                $io->error('L\'heure doit être comprise entre 0 et 23.');

                return Command::INVALID;
            }
            $instant = $instant->setTime($heure, 0);
        }
        $envoyees = 0;
        foreach ($this->tenantContext->sansFiltre(fn () => $this->pharmacies->nonArchivees()) as $pharmacie) {
            if (!$this->abonnements->etat($pharmacie)->statut->permetEcriture()) {
                continue;
            }
            $precedente = $this->tenantContext->getPharmacie();
            $this->tenantContext->forcer($pharmacie);
            try {
                if ($this->parametres->pour($pharmacie)->isEnvoiAutomatiqueCommandes()) {
                    $envoyees += $this->traiter($pharmacie, $instant, $io);
                }
            } finally {
                $this->tenantContext->forcer($precedente);
            }
        }
        $io->success(\sprintf('%d commande(s) envoyée(s) automatiquement aux grossistes.', $envoyees));

        return Command::SUCCESS;
    }

    private function traiter(Pharmacie $pharmacie, \DateTimeImmutable $instant, SymfonyStyle $io): int
    {
        /** @var list<Suggestion> $suggestions */
        $suggestions = array_values(array_filter(
            $this->suggestions->selonTendances(),
            static fn (array $s) => null !== $s['fournisseur'] && $s['fournisseur']->commandeAutomatiquePrevue($instant),
        ));
        if ([] === $suggestions) {
            return 0;
        }
        $inhabituels = [];
        foreach ($suggestions as $s) {
            if ($s['inhabituelle'] && null !== $s['fournisseur']) {
                $inhabituels[(int) $s['fournisseur']->getId()][] = $s['produit']->getNomCommercial();
            }
        }

        try {
            $brouillons = $this->transmission->preparer($suggestions, false);
            $aVerifier = array_values(array_filter($brouillons, static fn (Commande $c) => isset($inhabituels[(int) $c->getFournisseur()->getId()])));
            $bilan = $this->transmission->transmettre(array_values(array_filter($brouillons, static fn (Commande $c) => !\in_array($c, $aVerifier, true))));
        } catch (AchatException $e) {
            $io->warning(\sprintf('%s : %s', $pharmacie->getNom(), $e->getMessage()));
            $aVerifier = [];
            $bilan = ['envoyees' => [], 'sans_email' => [], 'echecs' => [$e->getMessage()]];
        }

        $phrases = [];
        if ([] !== $bilan['envoyees']) {
            $phrases[] = \sprintf('%d commande(s) envoyée(s) automatiquement : %s.', \count($bilan['envoyees']), implode(', ', array_map(static fn (Commande $c) => \sprintf('%s (%s)', $c->getNumero(), $c->getFournisseur()->getNom()), $bilan['envoyees'])));
        }
        foreach ($aVerifier as $commande) {
            $phrases[] = \sprintf('À vérifier avant envoi, quantité inhabituelle chez %s : %s.', $commande->getFournisseur()->getNom(), implode(', ', $inhabituels[(int) $commande->getFournisseur()->getId()]));
        }
        if ([] !== $bilan['sans_email']) {
            $phrases[] = \sprintf('Brouillon à transmettre à la main (fournisseur sans email) : %s.', implode(', ', array_map(static fn (Commande $c) => $c->getFournisseur()->getNom(), $bilan['sans_email'])));
        }
        if ([] !== $bilan['echecs']) {
            $phrases[] = \sprintf('%d envoi(s) en échec : voir les brouillons.', \count($bilan['echecs']));
        }
        if ([] !== $phrases) {
            $message = mb_substr(implode(' ', $phrases), 0, 255);
            $this->notifications->commandesAutomatiques($pharmacie, $message, 'commandes-auto-'.$instant->format('Y-m-d-H'));
        }

        return \count($bilan['envoyees']);
    }
}
