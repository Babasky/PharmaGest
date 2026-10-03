<?php

namespace App\Util;

/**
 * Numéros maliens : indicatif +223 suivi de 8 chiffres, affichés « +223 XX XX XX XX ».
 */
final class Telephone
{
    public const INDICATIF = '223';

    /**
     * Normalise une saisie libre (« 76 12 34 56 », « 0022376123456 »…) en « +22376123456 ».
     * Retourne null si le numéro n'est pas un numéro malien valide.
     */
    public static function normaliser(?string $saisie): ?string
    {
        if (null === $saisie) {
            return null;
        }

        $chiffres = preg_replace('/\D+/', '', $saisie) ?? '';

        if (str_starts_with($chiffres, '00'.self::INDICATIF)) {
            $chiffres = substr($chiffres, 2 + \strlen(self::INDICATIF));
        } elseif (11 === \strlen($chiffres) && str_starts_with($chiffres, self::INDICATIF)) {
            $chiffres = substr($chiffres, \strlen(self::INDICATIF));
        }

        if (1 !== preg_match('/^\d{8}$/', $chiffres)) {
            return null;
        }

        return '+'.self::INDICATIF.$chiffres;
    }

    /**
     * Affiche un numéro au format « +223 XX XX XX XX ». Une valeur non reconnue est renvoyée telle quelle.
     */
    public static function format(?string $numero): string
    {
        $normalise = self::normaliser($numero);
        if (null === $normalise) {
            return (string) $numero;
        }

        return '+'.self::INDICATIF.' '.implode(' ', str_split(substr($normalise, 4), 2));
    }
}
