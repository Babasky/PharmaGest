<?php

namespace App\Enum;

/**
 * Origine d'une recette (FI-04, FI-05) : automatique (vente, règlement AMO), manuelle, ou contre-passation
 * (montant négatif) d'une recette annulée.
 */
enum OrigineRecette: string
{
    case Vente = 'vente';
    case Amo = 'amo';
    case Manuelle = 'manuelle';
    case ContrePassation = 'contre_passation';

    public function libelle(): string
    {
        return match ($this) {
            self::Vente => 'Vente',
            self::Amo => 'Règlement AMO',
            self::Manuelle => 'Autre produit',
            self::ContrePassation => 'Contre-passation',
        };
    }

    public function couleur(): string
    {
        return match ($this) {
            self::Vente => 'success',
            self::Amo => 'info',
            self::Manuelle => 'primary',
            self::ContrePassation => 'danger',
        };
    }
}
