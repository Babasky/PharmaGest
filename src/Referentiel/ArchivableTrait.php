<?php

namespace App\Referentiel;

use Doctrine\ORM\Mapping as ORM;

/**
 * RG-15 : une donnée de référence ne se supprime pas. Archivée, elle disparaît des listes de sélection
 * mais reste attachée à l'historique (ventes, commandes…).
 */
trait ArchivableTrait
{
    #[ORM\Column]
    private bool $actif = true;

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }
}
