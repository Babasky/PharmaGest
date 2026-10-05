<?php

namespace App\Enum;

/**
 * Inventaire complet ou tournant (ST-06).
 */
enum PerimetreInventaire: string
{
    case Complet = 'complet';
    case Etagere = 'etagere';
    case Categorie = 'categorie';

    public function libelle(): string
    {
        return match ($this) {
            self::Complet => 'Complet',
            self::Etagere => 'Par étagère',
            self::Categorie => 'Par catégorie',
        };
    }
}
