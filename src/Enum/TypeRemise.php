<?php

namespace App\Enum;

/**
 * Une remise se saisit en pourcentage ou en montant (RE-02).
 */
enum TypeRemise: string
{
    case Pourcentage = 'pourcentage';
    case Montant = 'montant';

    /**
     * Montant de la remise en FCFA sur une base, plafonné à la base (RG-01 : arrondi au franc).
     */
    public function montantSur(int $base, int $valeur): int
    {
        $montant = match ($this) {
            self::Pourcentage => \App\Util\Fcfa::arrondir($base * $valeur / 100),
            self::Montant => $valeur,
        };

        return max(0, min($base, $montant));
    }
}
