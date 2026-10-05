<?php

namespace App\Reporting;

/**
 * Rapport prêt à afficher ou exporter : un titre, une période et des tableaux.
 */
final class Rapport
{
    /**
     * @param list<Tableau>        $tableaux
     * @param array<string, mixed> $graphiques configurations Chart.js, pour l'écran seulement
     */
    public function __construct(
        public readonly string $code,
        public readonly string $titre,
        public readonly Periode $periode,
        public readonly array $tableaux,
        public readonly array $graphiques = [],
    ) {
    }

    public function tableau(string $id): ?Tableau
    {
        foreach ($this->tableaux as $tableau) {
            if ($tableau->id === $id) {
                return $tableau;
            }
        }

        return null;
    }

    public function nomFichier(string $extension): string
    {
        return \sprintf('rapport-%s-%s.%s', $this->code, $this->periode->suffixe(), $extension);
    }
}
