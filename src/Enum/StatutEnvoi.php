<?php

namespace App\Enum;

/**
 * Résultat de l'envoi d'une commande par email (CO-05).
 */
enum StatutEnvoi: string
{
    case Envoye = 'envoye';
    case Echec = 'echec';

    public function libelle(): string
    {
        return match ($this) {
            self::Envoye => 'Envoyé',
            self::Echec => 'Échec',
        };
    }

    public function couleur(): string
    {
        return self::Envoye === $this ? 'success' : 'danger';
    }
}
