<?php

namespace App\Enum;

/**
 * Les trois types de vente (VE-02).
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
            self::Amo => 'Ordonnance AMO',
        };
    }

    public function avecOrdonnance(): bool
    {
        return self::SansOrdonnance !== $this;
    }
}
