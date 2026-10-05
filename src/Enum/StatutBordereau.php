<?php

namespace App\Enum;

/**
 * Statuts d'un bordereau AMO (AM-06). Un bordereau transmis n'est plus modifiable (RG-11).
 */
enum StatutBordereau: string
{
    case Brouillon = 'brouillon';
    case Transmis = 'transmis';
    case PayePartiellement = 'paye_partiellement';
    case Paye = 'paye';
    case Rejete = 'rejete';

    public function libelle(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Transmis => 'Transmis',
            self::PayePartiellement => 'Payé partiellement',
            self::Paye => 'Payé',
            self::Rejete => 'Rejeté',
        };
    }

    public function couleur(): string
    {
        return match ($this) {
            self::Brouillon => 'secondary',
            self::Transmis => 'primary',
            self::PayePartiellement => 'warning',
            self::Paye => 'success',
            self::Rejete => 'danger',
        };
    }
}
