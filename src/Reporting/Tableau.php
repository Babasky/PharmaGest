<?php

namespace App\Reporting;

/**
 * Tableau d'un rapport, affiché tel quel à l'écran et repris dans les exports Excel et PDF (RA-11).
 */
final class Tableau
{
    public const TEXTE = 'texte';
    public const NOMBRE = 'nombre';
    public const MONTANT = 'montant';
    public const POURCENTAGE = 'pourcentage';
    public const DATE = 'date';
    /** Colonne dont le type dépend de la ligne (tableau de synthèse). */
    public const VALEUR = 'valeur';

    /**
     * @param array<string, string>                                $colonnes    libellé => type de valeur
     * @param list<list<string|int|float|\DateTimeImmutable|null>> $lignes
     * @param list<string|int|float|\DateTimeImmutable|null>|null  $total
     * @param list<string>|null                                    $typesLignes type des colonnes « valeur » de chaque ligne
     */
    public function __construct(
        public readonly string $id,
        public readonly string $titre,
        public readonly array $colonnes,
        public readonly array $lignes,
        public readonly ?array $total = null,
        public readonly ?string $note = null,
        public readonly ?array $typesLignes = null,
    ) {
    }

    /** Type d'une cellule : celui de sa colonne, ou celui de sa ligne pour une colonne « valeur ». */
    public function type(int $ligne, int $colonne): string
    {
        $type = $this->types()[$colonne] ?? self::TEXTE;

        return self::VALEUR === $type ? ($this->typesLignes[$ligne] ?? self::NOMBRE) : $type;
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_values($this->colonnes);
    }

    public function estVide(): bool
    {
        return [] === $this->lignes;
    }
}
