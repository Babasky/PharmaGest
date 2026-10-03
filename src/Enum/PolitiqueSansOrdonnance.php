<?php

namespace App\Enum;

/**
 * Vente sans ordonnance d'un produit « ordonnance obligatoire » (VE-03).
 */
enum PolitiqueSansOrdonnance: string
{
    /** La vente est refusée. */
    case Blocage = 'blocage';
    /** La vente passe après confirmation du propriétaire (code PIN, Lot 4). */
    case Confirmation = 'confirmation';

    public function libelle(): string
    {
        return match ($this) {
            self::Blocage => 'Bloquer la vente',
            self::Confirmation => 'Autoriser après confirmation du propriétaire',
        };
    }
}
