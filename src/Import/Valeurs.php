<?php

namespace App\Import;

use App\Util\Fcfa;

/**
 * Conversion des cellules saisies dans Excel (montants avec espaces, « oui »/« non »…).
 */
final class Valeurs
{
    /**
     * @param array<string, string> $valeurs
     */
    public static function texte(array $valeurs, string $cle): ?string
    {
        $valeur = trim((string) ($valeurs[$cle] ?? ''));

        return '' === $valeur ? null : $valeur;
    }

    /**
     * @param array<string, string> $valeurs
     * @param list<string>          $erreurs
     */
    public static function entier(array $valeurs, string $cle, string $libelle, array &$erreurs): ?int
    {
        $valeur = self::texte($valeurs, $cle);
        if (null === $valeur) {
            return null;
        }
        try {
            $nombre = Fcfa::arrondir(str_ireplace(['fcfa', 'f cfa', 'cfa'], '', $valeur));
        } catch (\InvalidArgumentException) {
            $erreurs[] = \sprintf('%s : « %s » n\'est pas un nombre.', $libelle, $valeur);

            return null;
        }
        if ($nombre < 0) {
            $erreurs[] = \sprintf('%s : la valeur ne peut pas être négative.', $libelle);

            return null;
        }

        return $nombre;
    }

    /**
     * @param array<string, string> $valeurs
     * @param list<string>          $erreurs
     */
    public static function booleen(array $valeurs, string $cle, string $libelle, array &$erreurs): bool
    {
        $valeur = mb_strtolower((string) self::texte($valeurs, $cle));

        return match ($valeur) {
            '', 'non', 'n', '0', 'faux', 'false', 'no' => false,
            'oui', 'o', '1', 'x', 'vrai', 'true', 'yes' => true,
            default => (static function () use ($libelle, $valeur, &$erreurs): bool {
                $erreurs[] = \sprintf('%s : répondez « oui » ou « non » (valeur lue : « %s »).', $libelle, $valeur);

                return false;
            })(),
        };
    }
}
