<?php

namespace App\Enum;

enum ZoneEtagere: string
{
    case Comptoir = 'comptoir';
    case Reserve = 'reserve';
    case Refrigerateur = 'refrigerateur';

    public function libelle(): string
    {
        return match ($this) {
            self::Comptoir => 'Comptoir',
            self::Reserve => 'Réserve',
            self::Refrigerateur => 'Réfrigérateur',
        };
    }

    public static function depuisLibelle(string $valeur): ?self
    {
        $cle = mb_strtolower(trim($valeur));
        foreach (self::cases() as $zone) {
            if ($cle === $zone->value || $cle === mb_strtolower($zone->libelle())) {
                return $zone;
            }
        }

        return null;
    }
}
