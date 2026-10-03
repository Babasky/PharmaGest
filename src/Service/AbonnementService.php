<?php

namespace App\Service;

use App\Entity\Abonnement;
use App\Entity\Offre;
use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use App\Enum\MoyenPaiement;
use App\Enum\StatutAbonnement;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Cycle de vie d'un compte pharmacie (§ 3.2) et enregistrement des paiements (SA-02, RG-14).
 */
class AbonnementService
{
    public const DUREE_MOIS = 12;
    public const JOURS_ALERTE = 30;
    public const JOURS_DE_GRACE = 7;
    /** Rappels envoyés au propriétaire, en jours avant l'échéance. */
    public const JOURS_RAPPEL = [30, 15, 7];
    public const PREFIXE_FACTURE = 'FAC';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $horloge,
        private readonly Numeroteur $numeroteur,
        private readonly AuditLogger $audit,
    ) {
    }

    public function aujourdhui(): \DateTimeImmutable
    {
        return $this->horloge->now()->setTime(0, 0);
    }

    public function etat(Pharmacie $pharmacie): EtatAbonnement
    {
        $echeance = $pharmacie->getFinAbonnement() ?? $pharmacie->getFinEssai();
        $jours = null === $echeance ? null : $this->ecartEnJours($this->aujourdhui(), $echeance);

        if ($pharmacie->isArchivee()) {
            return new EtatAbonnement(StatutAbonnement::Archive, $echeance, $jours);
        }
        if ($pharmacie->isSuspendue()) {
            return new EtatAbonnement(StatutAbonnement::Suspendu, $echeance, $jours);
        }
        if (null === $jours) {
            // Ni essai ni paiement : le compte attend son premier paiement.
            return new EtatAbonnement(StatutAbonnement::Expire, null, null);
        }

        $statut = match (true) {
            $jours >= 0 && null === $pharmacie->getFinAbonnement() => StatutAbonnement::Essai,
            $jours > self::JOURS_ALERTE => StatutAbonnement::Actif,
            $jours >= 0 => StatutAbonnement::Alerte,
            $jours >= -self::JOURS_DE_GRACE => StatutAbonnement::Grace,
            default => StatutAbonnement::Expire,
        };

        return new EtatAbonnement($statut, $echeance, $jours);
    }

    /**
     * RG-14 : nouvelle date de fin = plus tardive des deux dates (aujourd'hui, fin en cours) + 12 mois.
     * Seul un abonnement payé compte comme « fin en cours » : la période d'essai n'est pas prolongée.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [date de début, date de fin]
     */
    public function prochainePeriode(Pharmacie $pharmacie): array
    {
        $aujourdhui = $this->aujourdhui();
        $finEnCours = $pharmacie->getFinAbonnement();

        if (null !== $finEnCours && $finEnCours >= $aujourdhui) {
            return [$finEnCours->modify('+1 day'), self::ajouterMois($finEnCours, self::DUREE_MOIS)];
        }

        return [$aujourdhui, self::ajouterMois($aujourdhui, self::DUREE_MOIS)];
    }

    public function enregistrerPaiement(
        Pharmacie $pharmacie,
        Offre $offre,
        int $montant,
        MoyenPaiement $moyen,
        ?string $reference,
        \DateTimeImmutable $datePaiement,
        ?Utilisateur $enregistrePar,
    ): Abonnement {
        if ($montant < 0) {
            throw new \InvalidArgumentException('Le montant ne peut pas être négatif.');
        }

        return $this->em->wrapInTransaction(function () use ($pharmacie, $offre, $montant, $moyen, $reference, $datePaiement, $enregistrePar): Abonnement {
            [$debut, $fin] = $this->prochainePeriode($pharmacie);
            $avant = ['offre' => $pharmacie->getOffre()->getCode(), 'fin' => $pharmacie->getFinAbonnement()?->format('Y-m-d')];

            $abonnement = new Abonnement(
                $pharmacie,
                $offre,
                $this->numeroteur->suivant(self::PREFIXE_FACTURE),
                $debut,
                $fin,
                $datePaiement->setTime(0, 0),
                $montant,
                $moyen,
                null !== $reference && '' !== trim($reference) ? trim($reference) : null,
                $enregistrePar,
            );
            $this->em->persist($abonnement);

            $pharmacie->setOffre($offre)->setFinAbonnement($fin);
            $this->em->flush();

            $this->audit->journaliser(AuditLogger::ABONNEMENT_PAIEMENT, $pharmacie, $abonnement, $avant, [
                'offre' => $offre->getCode(),
                'fin' => $fin->format('Y-m-d'),
                'montant' => $montant,
                'moyen' => $moyen->value,
                'facture' => $abonnement->getNumeroFacture(),
            ]);
            $this->em->flush();

            return $abonnement;
        });
    }

    public function suspendre(Pharmacie $pharmacie, string $motif): void
    {
        $pharmacie->suspendre(trim($motif));
        $this->audit->journaliser(AuditLogger::PHARMACIE_SUSPENDUE, $pharmacie, $pharmacie, null, ['motif' => trim($motif)]);
        $this->em->flush();
    }

    public function reactiver(Pharmacie $pharmacie): void
    {
        $pharmacie->reactiver();
        $this->audit->journaliser(AuditLogger::PHARMACIE_REACTIVEE, $pharmacie, $pharmacie);
        $this->em->flush();
    }

    public function archiver(Pharmacie $pharmacie): void
    {
        $pharmacie->archiver($this->horloge->now());
        $this->audit->journaliser(AuditLogger::PHARMACIE_ARCHIVEE, $pharmacie, $pharmacie);
        $this->em->flush();
    }

    /**
     * Ajoute des mois sans déborder sur le mois suivant (31/01 + 1 mois = 28 ou 29/02).
     */
    public static function ajouterMois(\DateTimeImmutable $date, int $mois): \DateTimeImmutable
    {
        $premierDuMois = $date->modify('first day of this month')->modify(\sprintf('+%d months', $mois));
        $jour = min((int) $date->format('j'), (int) $premierDuMois->format('t'));

        return $premierDuMois->setDate((int) $premierDuMois->format('Y'), (int) $premierDuMois->format('n'), $jour);
    }

    private function ecartEnJours(\DateTimeImmutable $de, \DateTimeImmutable $a): int
    {
        $ecart = $de->setTime(0, 0)->diff($a->setTime(0, 0));

        return (int) $ecart->days * ($ecart->invert ? -1 : 1);
    }
}
