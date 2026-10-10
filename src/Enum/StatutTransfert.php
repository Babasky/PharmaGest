<?php

namespace App\Enum;

/**
 * Statuts d'un transfert de stock entre officines du même propriétaire (ST-11).
 */
enum StatutTransfert: string
{
    case EnPreparation = 'en_preparation';
    case Expedie = 'expedie';
    case Recu = 'recu';
    case Annule = 'annule';

    public function libelle(): string
    {
        return match ($this) {
            self::EnPreparation => 'En préparation',
            self::Expedie => 'Expédié',
            self::Recu => 'Reçu',
            self::Annule => 'Annulé',
        };
    }

    public function couleur(): string
    {
        return match ($this) {
            self::EnPreparation => 'secondary',
            self::Expedie => 'primary',
            self::Recu => 'success',
            self::Annule => 'dark',
        };
    }
}
