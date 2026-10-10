<?php

namespace App\Enum;

/**
 * Les trois types de vente (VE-02). « Amo » couvre toute vente en tiers payant : AMO ou autre assurance.
 */
enum TypeVente: string
{
    case SansOrdonnance = 'sans_ordonnance';
    case Ordonnance = 'ordonnance';
    case Amo = 'amo';

    public function libelle(): string
    {
        return match ($this) {
            self::SansOrdonnance => 'Sans ordonnance',
            self::Ordonnance => 'Ordonnance classique',
            self::Amo => 'AMO / Autres',
        };
    }

    public function avecOrdonnance(): bool
    {
        return self::SansOrdonnance !== $this;
    }
}
