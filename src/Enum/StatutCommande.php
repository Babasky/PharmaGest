<?php

namespace App\Enum;

/**
 * Statuts d'une commande fournisseur (CO-03).
 */
enum StatutCommande: string
{
    case Brouillon = 'brouillon';
    case Envoyee = 'envoyee';
    case RecuePartiellement = 'recue_partiellement';
    case Recue = 'recue';
    case Annulee = 'annulee';

    public function libelle(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Envoyee => 'Envoyée',
            self::RecuePartiellement => 'Reçue partiellement',
            self::Recue => 'Reçue',
            self::Annulee => 'Annulée',
        };
    }

    public function couleur(): string
    {
        return match ($this) {
            self::Brouillon => 'secondary',
            self::Envoyee => 'primary',
            self::RecuePartiellement => 'warning',
            self::Recue => 'success',
            self::Annulee => 'dark',
        };
    }

    /** Une livraison est encore attendue. */
    public function estEnAttenteDeLivraison(): bool
    {
        return self::Envoyee === $this || self::RecuePartiellement === $this;
    }
}
