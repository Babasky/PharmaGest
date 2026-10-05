<?php

namespace App\Enum;

/**
 * Une vente naît « en cours » (panier du vendeur), peut être mise en attente puis reprise (VE-07),
 * est numérotée à sa validation (RG-02), et ne peut ensuite qu'être annulée (VE-09).
 */
enum StatutVente: string
{
    case EnCours = 'en_cours';
    case EnAttente = 'en_attente';
    case Validee = 'validee';
    case Annulee = 'annulee';

    public function libelle(): string
    {
        return match ($this) {
            self::EnCours => 'En cours',
            self::EnAttente => 'En attente',
            self::Validee => 'Validée',
            self::Annulee => 'Annulée',
        };
    }

    public function couleur(): string
    {
        return match ($this) {
            self::EnCours => 'primary',
            self::EnAttente => 'warning',
            self::Validee => 'success',
            self::Annulee => 'secondary',
        };
    }
}
