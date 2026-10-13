<?php

namespace App\Enum;

/**
 * Rotation d'un produit, d'après ses ventes moyennes par jour sur la période d'analyse des commandes.
 */
enum Rotation: string
{
    case Rapide = 'rapide';
    case Moyenne = 'moyenne';
    case Lente = 'lente';
    case Nulle = 'nulle';

    /** Rapide : au moins une unité par jour ; moyenne : au moins une par semaine ; lente : moins. */
    public static function depuis(float $ventesParJour): self
    {
        return match (true) {
            $ventesParJour >= 1 => self::Rapide,
            $ventesParJour >= 1 / 7 => self::Moyenne,
            $ventesParJour > 0 => self::Lente,
            default => self::Nulle,
        };
    }

    public function libelle(): string
    {
        return match ($this) {
            self::Rapide => 'Rapide',
            self::Moyenne => 'Moyenne',
            self::Lente => 'Lente',
            self::Nulle => 'Aucune vente',
        };
    }

    public function couleur(): string
    {
        return match ($this) {
            self::Rapide => 'success',
            self::Moyenne => 'primary',
            self::Lente => 'warning',
            self::Nulle => 'secondary',
        };
    }
}
