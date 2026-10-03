<?php

namespace App\Import;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Ce qu'on peut importer (produits, fournisseurs, clients) et comment chaque ligne devient une entité.
 */
#[AutoconfigureTag]
interface DefinitionImport
{
    /** Identifiant dans l'URL : produits, fournisseurs, clients. */
    public function code(): string;

    public function libelle(): string;

    /**
     * @return list<Colonne>
     */
    public function colonnes(): array;

    /**
     * Prépare l'entité correspondant à une ligne, sans l'enregistrer.
     * Renvoie la ligne de rapport et l'entité (null si erreur).
     *
     * @param array<string, string> $valeurs valeurs de la ligne, par clé de colonne (chaînes nettoyées)
     *
     * @return array{0: LigneImport, 1: object|null}
     */
    public function preparer(int $numero, array $valeurs, bool $simulation): array;

    /** Oublie les éléments mémorisés entre deux lignes (catégories créées, doublons…). */
    public function reinitialiser(): void;
}
