<?php

namespace App\Enum;

/**
 * Sort d'une vente saisie hors ligne une fois reçue par le serveur : importée (devenue une vente numérotée),
 * en anomalie (stock insuffisant, caisse fermée… : à reprendre), ou rejetée par un adjoint avec un motif.
 */
enum StatutVenteHorsLigne: string
{
    case Importee = 'importee';
    case Anomalie = 'anomalie';
    case Rejetee = 'rejetee';

    public function libelle(): string
    {
        return match ($this) {
            self::Importee => 'Importée',
            self::Anomalie => 'En anomalie',
            self::Rejetee => 'Rejetée',
        };
    }

    public function couleur(): string
    {
        return match ($this) {
            self::Importee => 'success',
            self::Anomalie => 'danger',
            self::Rejetee => 'secondary',
        };
    }
}
