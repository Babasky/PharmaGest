<?php

namespace App\Enum;

/**
 * Cycle d'une créance AMO (AM-05, AM-08) : en attente tant qu'elle n'est pas sur un bordereau transmis,
 * puis transmise, payée (partiellement ou en totalité) ou rejetée. Une vente annulée annule sa créance.
 */
enum StatutCreance: string
{
    case EnAttente = 'en_attente';
    case Transmise = 'transmise';
    case PayeePartiellement = 'payee_partiellement';
    case Payee = 'payee';
    case Rejetee = 'rejetee';
    case Annulee = 'annulee';

    public function libelle(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Transmise => 'Transmise',
            self::PayeePartiellement => 'Payée partiellement',
            self::Payee => 'Payée',
            self::Rejetee => 'Rejetée',
            self::Annulee => 'Annulée',
        };
    }

    public function couleur(): string
    {
        return match ($this) {
            self::EnAttente => 'secondary',
            self::Transmise => 'primary',
            self::PayeePartiellement => 'warning',
            self::Payee => 'success',
            self::Rejetee => 'danger',
            self::Annulee => 'light',
        };
    }

    /** Une créance soldée n'attend plus rien de l'organisme. */
    public function estSoldee(): bool
    {
        return \in_array($this, [self::Payee, self::Rejetee, self::Annulee], true);
    }

    /** @return list<self> statuts qui comptent dans l'encours AMO */
    public static function ouverts(): array
    {
        return [self::EnAttente, self::Transmise, self::PayeePartiellement];
    }
}
