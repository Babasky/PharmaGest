<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use App\Enum\TypeNotification;
use App\Repository\AffectationRepository;
use App\Repository\BordereauAmoRepository;
use App\Repository\NotificationRepository;
use App\Stock\AlertesStock;
use App\Tenant\TenantContext;
use App\Util\Fcfa;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Alimente le centre de notifications (NO-01) : ruptures, péremptions, échéances d'abonnement, bordereaux impayés.
 *
 * Lancé chaque matin pour chaque pharmacie ({@see \App\Command\NotificationsCommand}). Une alerte déjà signalée
 * n'est pas répétée : la clé de la notification change seulement quand la situation change.
 */
class GenerateurNotifications
{
    /** Un bordereau transmis et toujours impayé est signalé à 30, 60 puis 90 jours. */
    public const PALIERS_BORDEREAU = [90, 60, 30];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly AlertesStock $alertes,
        private readonly AbonnementService $abonnements,
        private readonly AffectationRepository $affectations,
        private readonly BordereauAmoRepository $bordereaux,
        private readonly NotificationRepository $notifications,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $horloge,
    ) {
    }

    /**
     * @return int nombre de notifications créées
     */
    public function generer(Pharmacie $pharmacie): int
    {
        $etat = $this->abonnements->etat($pharmacie);
        if (!$etat->statut->permetAcces()) {
            return 0;
        }

        $precedente = $this->tenantContext->getPharmacie();
        $this->tenantContext->forcer($pharmacie);
        try {
            $proprietaires = $this->affectations->proprietaires($pharmacie);
            $responsables = $this->responsables($pharmacie);
            $creees = 0;

            foreach ($this->alertesStock() as [$type, $message, $lien, $cle]) {
                $creees += $this->notifier($pharmacie, $responsables, $type, $message, $lien, $cle);
            }
            if (null !== $alerte = $this->alerteAbonnement($etat)) {
                $creees += $this->notifier($pharmacie, $proprietaires, TypeNotification::Abonnement, $alerte[0], $this->urls->generate('app_mon_abonnement'), $alerte[1]);
            }
            foreach ($this->bordereauxImpayes() as [$message, $lien, $cle]) {
                $creees += $this->notifier($pharmacie, $responsables, TypeNotification::Bordereau, $message, $lien, $cle);
            }
            $this->em->flush();

            return $creees;
        } finally {
            $this->tenantContext->forcer($precedente);
        }
    }

    /**
     * @return list<array{TypeNotification, string, string, string}>
     */
    private function alertesStock(): array
    {
        $alertes = [];
        foreach ([
            [TypeNotification::Rupture, AlertesStock::SEUIL, '%d produit(s) en rupture ou sous le seuil d\'alerte.'],
            [TypeNotification::Peremption, AlertesStock::PEREMPTION, \sprintf('%%d lot(s) périment dans les %d prochains jours.', $this->alertes->delaiPeremption())],
            [TypeNotification::Perime, AlertesStock::PERIMES, '%d lot(s) périmé(s) encore en stock : à retirer de la vente et à détruire.'],
        ] as [$type, $alerte, $message]) {
            $ids = $this->alertes->identifiants($alerte);
            if ([] === $ids) {
                continue;
            }
            sort($ids);
            $alertes[] = [
                $type,
                \sprintf($message, \count($ids)),
                $this->urls->generate('app_stock_alertes', ['type' => $alerte]),
                $type->value.':'.substr(sha1(implode(',', $ids)), 0, 20),
            ];
        }

        return $alertes;
    }

    /**
     * Échéance à J-30, J-15 et J-7, puis pendant la période de grâce et au passage en lecture seule.
     *
     * @return array{string, string}|null message et clé
     */
    private function alerteAbonnement(EtatAbonnement $etat): ?array
    {
        if (null === $etat->echeance || null === $etat->joursRestants || $etat->joursRestants > AbonnementService::JOURS_ALERTE) {
            return null;
        }
        $jours = $etat->joursRestants;
        $date = $etat->echeance->format('d/m/Y');
        $essai = \App\Enum\StatutAbonnement::Essai === $etat->statut;

        [$palier, $message] = match (true) {
            $jours >= 0 => [
                (string) min(array_filter(AbonnementService::JOURS_RAPPEL, static fn (int $p) => $p >= $jours)),
                \sprintf('%s prend fin le %s (%s). Pensez à %s.', $essai ? 'Votre période d\'essai' : 'Votre abonnement', $date,
                    0 === $jours ? 'aujourd\'hui' : \sprintf('dans %d jour%s', $jours, $jours > 1 ? 's' : ''), $essai ? 'souscrire un abonnement' : 'le renouveler'),
            ],
            $jours >= -AbonnementService::JOURS_DE_GRACE => [
                'grace',
                \sprintf('Votre abonnement a pris fin le %s : la pharmacie passera en lecture seule dans %d jour(s) sans renouvellement.', $date, (int) $etat->joursDeGraceRestants()),
            ],
            default => [
                'expire',
                \sprintf('Votre abonnement a pris fin le %s : la pharmacie est en lecture seule (consultation et exports).', $date),
            ],
        };

        return [$message, 'abonnement:'.$etat->echeance->format('Y-m-d').':'.$palier];
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function bordereauxImpayes(): array
    {
        $aujourdhui = $this->horloge->now()->setTime(0, 0);
        $alertes = [];
        foreach ($this->bordereaux->impayesTransmisAvant($aujourdhui->modify(\sprintf('-%d days', min(self::PALIERS_BORDEREAU)))) as $bordereau) {
            $transmis = $bordereau->getTransmisLe();
            if (null === $transmis) {
                continue;
            }
            $jours = (int) $transmis->setTime(0, 0)->diff($aujourdhui)->days;
            $palier = current(array_filter(self::PALIERS_BORDEREAU, static fn (int $p) => $jours >= $p));
            if (false === $palier) {
                continue;
            }
            $alertes[] = [
                \sprintf('Bordereau %s (%s) transmis le %s : %s impayés depuis plus de %d jours.',
                    $bordereau->getNumero(), $bordereau->getOrganisme()->getCode(), $transmis->format('d/m/Y'), Fcfa::format($bordereau->getReste()), $palier),
                $this->urls->generate('app_amo_bordereau_voir', ['id' => $bordereau->getId()]),
                'bordereau:'.$bordereau->getId().':'.$palier,
            ];
        }

        return $alertes;
    }

    /**
     * Propriétaires et adjoints actifs : ceux qui gèrent le stock, les commandes et l'AMO.
     *
     * @return list<Utilisateur>
     */
    private function responsables(Pharmacie $pharmacie): array
    {
        $responsables = [];
        foreach ($this->affectations->equipe($pharmacie) as $affectation) {
            $utilisateur = $affectation->getUtilisateur();
            if ($affectation->isActif() && $utilisateur->isActif()
                && \in_array($utilisateur->getRole(), [Utilisateur::ROLE_PROPRIETAIRE, Utilisateur::ROLE_ADJOINT], true)) {
                $responsables[] = $utilisateur;
            }
        }

        return $responsables;
    }

    /**
     * @param list<Utilisateur> $destinataires
     */
    private function notifier(Pharmacie $pharmacie, array $destinataires, TypeNotification $type, string $message, string $lien, string $cle): int
    {
        $creees = 0;
        $maintenant = $this->horloge->now();
        foreach ($destinataires as $utilisateur) {
            if ([] !== $this->notifications->clesExistantes($utilisateur, [$cle])) {
                continue;
            }
            $this->em->persist(new Notification($pharmacie, $utilisateur, $type, $message, $lien, $cle, $maintenant));
            ++$creees;
        }

        return $creees;
    }
}
