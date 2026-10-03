<?php

namespace App\Import;

/**
 * Résultat d'une ligne du fichier : à créer, à mettre à jour, ou en erreur.
 */
final class LigneImport
{
    public const CREATION = 'creation';
    public const MISE_A_JOUR = 'mise_a_jour';
    public const ERREUR = 'erreur';

    /**
     * @param list<string> $messages erreurs (statut « erreur ») ou remarques (ex. « catégorie créée »)
     */
    public function __construct(
        /** Numéro de ligne dans le fichier (l'en-tête est la ligne 1). */
        public readonly int $numero,
        public readonly string $statut,
        public readonly string $libelle,
        public readonly array $messages = [],
    ) {
    }

    public function enErreur(): bool
    {
        return self::ERREUR === $this->statut;
    }
}
