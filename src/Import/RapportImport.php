<?php

namespace App\Import;

/**
 * Rapport d'import ligne par ligne (RF-08).
 */
final class RapportImport
{
    /** @var list<LigneImport> */
    private array $lignes = [];

    /** @var list<string> */
    private array $erreursFichier = [];

    public function ajouter(LigneImport $ligne): void
    {
        $this->lignes[] = $ligne;
    }

    public function erreurFichier(string $message): void
    {
        $this->erreursFichier[] = $message;
    }

    /**
     * @return list<string>
     */
    public function getErreursFichier(): array
    {
        return $this->erreursFichier;
    }

    /**
     * @return list<LigneImport>
     */
    public function getLignes(): array
    {
        return $this->lignes;
    }

    /**
     * @return list<LigneImport>
     */
    public function lignesEnErreur(): array
    {
        return array_values(array_filter($this->lignes, static fn (LigneImport $l) => $l->enErreur()));
    }

    public function compter(string $statut): int
    {
        return \count(array_filter($this->lignes, static fn (LigneImport $l) => $statut === $l->statut));
    }

    public function nombreValides(): int
    {
        return $this->compter(LigneImport::CREATION) + $this->compter(LigneImport::MISE_A_JOUR);
    }

    public function estUtilisable(): bool
    {
        return [] === $this->erreursFichier && $this->nombreValides() > 0;
    }
}
