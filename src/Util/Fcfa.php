<?php

namespace App\Util;

/**
 * Montants en francs CFA (XOF) : toujours stockés en entiers, sans décimales.
 */
final class Fcfa
{
    /** Espace insécable, pour éviter qu'un montant soit coupé en fin de ligne. */
    public const SEPARATEUR = "\u{00A0}";

    /**
     * Formate un montant : 12500 => « 12 500 FCFA ».
     */
    public static function format(int|float|string|null $montant, bool $avecDevise = true): string
    {
        $entier = self::arrondir($montant ?? 0);
        $texte = number_format($entier, 0, ',', self::SEPARATEUR);

        return $avecDevise ? $texte."\u{00A0}FCFA" : $texte;
    }

    /**
     * Arrondi à l'unité (demi à l'unité supérieure), règle unique de l'application.
     */
    public static function arrondir(int|float|string $montant): int
    {
        if (\is_int($montant)) {
            return $montant;
        }

        if (\is_string($montant)) {
            $montant = str_replace([' ', "\u{00A0}", self::SEPARATEUR, ','], ['', '', '', '.'], $montant);
            if (!is_numeric($montant)) {
                throw new \InvalidArgumentException(\sprintf('Montant invalide : « %s ».', $montant));
            }
            $montant = (float) $montant;
        }

        return (int) round($montant, 0, \PHP_ROUND_HALF_UP);
    }
}
