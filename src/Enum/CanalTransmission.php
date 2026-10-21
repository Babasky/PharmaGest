<?php

namespace App\Enum;

/**
 * Voie par laquelle une commande part chez le fournisseur.
 */
enum CanalTransmission: string
{
    /** Bon de commande Excel envoyé par email. */
    case Email = 'email';
    /** Norme Pharma-ML des répartiteurs (commande XML sur le serveur du répartiteur). */
    case PharmaMl = 'pharmaml';

    public function libelle(): string
    {
        return match ($this) {
            self::Email => 'Email (bon de commande Excel)',
            self::PharmaMl => 'Pharma-ML',
        };
    }
}
