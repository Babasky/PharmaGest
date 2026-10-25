<?php

namespace App\Tests\Unit\Gabarit;

use PHPUnit\Framework\TestCase;

/**
 * Demande du 24/10/2026 : chaque champ de saisie a un texte indicatif (placeholder). Les champs des formulaires
 * Symfony sont vérifiés par les tests fonctionnels ; ici, les champs écrits directement dans les gabarits.
 */
final class PlaceholdersTest extends TestCase
{
    /** Types sans zone de texte libre, où le navigateur n'affiche pas de placeholder. */
    private const TYPES_SANS_SAISIE = ['hidden', 'checkbox', 'radio', 'submit', 'button', 'reset', 'image', 'file', 'date', 'datetime-local', 'month', 'week', 'time', 'color', 'range'];

    public function testChaqueChampDesGabaritsAUnPlaceholder(): void
    {
        $manquants = [];
        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($fichiers as $fichier) {
            if (!str_ends_with($fichier->getFilename(), '.twig')) {
                continue;
            }
            $source = (string) file_get_contents($fichier->getPathname());
            foreach (self::balisesDeSaisie($source) as [$ligne, $balise]) {
                if (preg_match('/\stype="([a-z-]+)"/', $balise, $type) && \in_array($type[1], self::TYPES_SANS_SAISIE, true)) {
                    continue;
                }
                if (!preg_match('/\splaceholder="[^"]+"/', $balise)) {
                    $manquants[] = \sprintf('%s:%d', substr($fichier->getPathname(), \strlen(\dirname(__DIR__, 3)) + 1), $ligne);
                }
            }
        }
        sort($manquants);

        self::assertSame([], $manquants, 'Champs sans placeholder.');
    }

    /**
     * Balises <input> et <textarea>, sur une ou plusieurs lignes ; un « > » dans une expression Twig ne ferme pas la balise.
     *
     * @return list<array{int, string}>
     */
    private static function balisesDeSaisie(string $source): array
    {
        $balises = [];
        preg_match_all('/<(?:input|textarea)\b/', $source, $debuts, \PREG_OFFSET_CAPTURE);
        foreach ($debuts[0] as [, $debut]) {
            $twig = 0;
            for ($i = $debut, $n = \strlen($source); $i < $n; ++$i) {
                $deux = substr($source, $i, 2);
                if ('{{' === $deux || '{%' === $deux) {
                    ++$twig;
                    ++$i;
                } elseif ('}}' === $deux || '%}' === $deux) {
                    --$twig;
                    ++$i;
                } elseif ('>' === $source[$i] && 0 === $twig) {
                    break;
                }
            }
            $balises[] = [substr_count($source, "\n", 0, $debut) + 1, substr($source, $debut, $i - $debut + 1)];
        }

        return $balises;
    }
}
