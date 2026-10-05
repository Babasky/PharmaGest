<?php

namespace App\Reporting;

/**
 * Période d'un rapport (RA-01) : jour, semaine (du lundi au dimanche), mois, année ou plage libre.
 * Les bornes sont des dates incluses ; la période précédente a la même nature (ou la même durée pour une plage).
 */
final class Periode
{
    public const JOUR = 'jour';
    public const SEMAINE = 'semaine';
    public const MOIS = 'mois';
    public const ANNEE = 'annee';
    public const LIBRE = 'libre';

    public const TYPES = [self::JOUR => 'Jour', self::SEMAINE => 'Semaine', self::MOIS => 'Mois', self::ANNEE => 'Année', self::LIBRE => 'Plage libre'];

    /** Plage libre la plus longue acceptée, pour garder des rapports rapides. */
    public const JOURS_MAXIMUM = 366 * 3;

    public const MOIS_COURTS = [1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    public const MOIS_NOMS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    private function __construct(
        public readonly string $type,
        public readonly \DateTimeImmutable $debut,
        public readonly \DateTimeImmutable $fin,
    ) {
    }

    public static function pour(string $type, \DateTimeImmutable $jour): self
    {
        $jour = $jour->setTime(0, 0);

        return match ($type) {
            self::JOUR => new self(self::JOUR, $jour, $jour),
            self::SEMAINE => new self(self::SEMAINE, $lundi = $jour->modify('-'.((int) $jour->format('N') - 1).' days'), $lundi->modify('+6 days')),
            self::ANNEE => new self(self::ANNEE, $jour->setDate((int) $jour->format('Y'), 1, 1), $jour->setDate((int) $jour->format('Y'), 12, 31)),
            default => new self(self::MOIS, $premier = $jour->modify('first day of this month'), $premier->modify('last day of this month')),
        };
    }

    public static function libre(\DateTimeImmutable $du, \DateTimeImmutable $au): self
    {
        $du = $du->setTime(0, 0);
        $au = $au->setTime(0, 0);
        if ($au < $du) {
            [$du, $au] = [$au, $du];
        }
        if ($du->diff($au)->days >= self::JOURS_MAXIMUM) {
            $du = $au->modify('-'.(self::JOURS_MAXIMUM - 1).' days');
        }

        return new self(self::LIBRE, $du, $au);
    }

    /**
     * Période lue dans les paramètres d'une page (type, date de référence, ou du/au pour une plage libre).
     * Une valeur absente ou invalide donne le mois en cours.
     */
    public static function depuisRequete(?string $type, ?string $date, ?string $du, ?string $au, \DateTimeImmutable $aujourdhui): self
    {
        if (self::LIBRE === $type) {
            $debut = self::date($du);
            $fin = self::date($au);
            if (null !== $debut && null !== $fin) {
                return self::libre($debut, $fin);
            }
            $type = self::MOIS;
        }

        return self::pour(\array_key_exists((string) $type, self::TYPES) ? (string) $type : self::MOIS, self::date($date) ?? $aujourdhui);
    }

    public function precedente(): self
    {
        return match ($this->type) {
            self::JOUR => self::pour(self::JOUR, $this->debut->modify('-1 day')),
            self::SEMAINE => self::pour(self::SEMAINE, $this->debut->modify('-7 days')),
            self::MOIS => self::pour(self::MOIS, $this->debut->modify('-1 month')),
            self::ANNEE => self::pour(self::ANNEE, $this->debut->modify('-1 year')),
            default => new self(self::LIBRE, $this->debut->modify('-'.$this->nombreJours().' days'), $this->debut->modify('-1 day')),
        };
    }

    public function suivante(): self
    {
        return match ($this->type) {
            self::JOUR => self::pour(self::JOUR, $this->fin->modify('+1 day')),
            self::SEMAINE => self::pour(self::SEMAINE, $this->fin->modify('+1 day')),
            self::MOIS => self::pour(self::MOIS, $this->fin->modify('+1 day')),
            self::ANNEE => self::pour(self::ANNEE, $this->fin->modify('+1 day')),
            default => new self(self::LIBRE, $this->fin->modify('+1 day'), $this->fin->modify('+'.$this->nombreJours().' days')),
        };
    }

    public function nombreJours(): int
    {
        return (int) $this->debut->diff($this->fin)->days + 1;
    }

    /** Début de la période, à minuit. */
    public function debutInstant(): \DateTimeImmutable
    {
        return $this->debut;
    }

    /** Lendemain de la fin, à minuit : borne exclue pour les dates avec heure. */
    public function finInstant(): \DateTimeImmutable
    {
        return $this->fin->modify('+1 day');
    }

    public function contient(\DateTimeImmutable $jour): bool
    {
        $jour = $jour->setTime(0, 0);

        return $jour >= $this->debut && $jour <= $this->fin;
    }

    public function libelle(): string
    {
        return match ($this->type) {
            self::JOUR => self::jourLong($this->debut),
            self::SEMAINE => \sprintf('Semaine du %s au %s', $this->debut->format('d/m'), $this->fin->format('d/m/Y')),
            self::MOIS => ucfirst(self::MOIS_NOMS[(int) $this->debut->format('n')]).' '.$this->debut->format('Y'),
            self::ANNEE => 'Année '.$this->debut->format('Y'),
            default => \sprintf('Du %s au %s', $this->debut->format('d/m/Y'), $this->fin->format('d/m/Y')),
        };
    }

    /**
     * Paramètres d'URL qui redonnent cette période.
     *
     * @return array<string, string>
     */
    public function parametres(): array
    {
        return self::LIBRE === $this->type
            ? ['periode' => self::LIBRE, 'du' => $this->debut->format('Y-m-d'), 'au' => $this->fin->format('Y-m-d')]
            : ['periode' => $this->type, 'date' => $this->debut->format('Y-m-d')];
    }

    /** Fragment de nom de fichier : 2026-10, 2026-10-05_2026-10-11… */
    public function suffixe(): string
    {
        return match ($this->type) {
            self::JOUR => $this->debut->format('Y-m-d'),
            self::MOIS => $this->debut->format('Y-m'),
            self::ANNEE => $this->debut->format('Y'),
            default => $this->debut->format('Y-m-d').'_'.$this->fin->format('Y-m-d'),
        };
    }

    private static function jourLong(\DateTimeImmutable $jour): string
    {
        $jours = [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

        return \sprintf('%s %d %s %s', $jours[(int) $jour->format('N')], (int) $jour->format('j'), self::MOIS_NOMS[(int) $jour->format('n')], $jour->format('Y'));
    }

    private static function date(?string $valeur): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $valeur);

        return false === $date || $date->format('Y-m-d') !== $valeur ? null : $date;
    }
}
