<?php

namespace App\Enum;

/**
 * Une vente naît « en cours » (panier du vendeur), peut être mise en attente puis reprise (VE-07),
 * est numérotée à sa validation (RG-02), et ne peut ensuite qu'être annulée (VE-09).
 *
 * Quand le vendeur n'encaisse pas lui-même, il valide la vente et l'envoie à la caisse : elle est numérotée,
 * ses produits sortent du stock et elle attend « à encaisser » qu'un caissier la règle.
 */
enum StatutVente: string
{
    case EnCours = 'en_cours';
    case EnAttente = 'en_attente';
    case AEncaisser = 'a_encaisser';
    case Validee = 'validee';
    case Annulee = 'annulee';

    public function libelle(): string
    {
        return match ($this) {
            self::EnCours => 'En cours',
            self::EnAttente => 'En attente',
            self::AEncaisser => 'À encaisser',
            self::Validee => 'Validée',
            self::Annulee => 'Annulée',
        };
    }

    public function couleur(): string
    {
        return match ($this) {
            self::EnCours => 'primary',
            self::EnAttente => 'warning',
            self::AEncaisser => 'info',
            self::Validee => 'success',
            self::Annulee => 'secondary',
        };
    }
}
