<?php

namespace App\Enum;

enum StatutInventaire: string
{
    case EnCours = 'en_cours';
    case Valide = 'valide';
    case Annule = 'annule';

    public function libelle(): string
    {
        return match ($this) {
            self::EnCours => 'En cours',
            self::Valide => 'Validé',
            self::Annule => 'Annulé',
        };
    }

    public function couleur(): string
    {
        return match ($this) {
            self::EnCours => 'warning',
            self::Valide => 'success',
            self::Annule => 'secondary',
        };
    }
}
