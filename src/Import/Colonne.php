<?php

namespace App\Import;

/**
 * Colonne attendue dans un fichier d'import.
 */
final class Colonne
{
    public function __construct(
        public readonly string $cle,
        public readonly string $entete,
        public readonly bool $obligatoire,
        public readonly string $aide,
        public readonly string $exemple,
    ) {
    }
}
