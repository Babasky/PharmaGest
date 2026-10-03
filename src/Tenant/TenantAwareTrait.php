<?php

namespace App\Tenant;

use App\Entity\Pharmacie;
use Doctrine\ORM\Mapping as ORM;

/**
 * Implémentation standard de {@see TenantAwareInterface}. Aucun formulaire ne doit exposer ce champ.
 */
trait TenantAwareTrait
{
    #[ORM\ManyToOne(targetEntity: Pharmacie::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Pharmacie $pharmacie;

    /** Null uniquement avant le premier enregistrement (la pharmacie est alors affectée automatiquement). */
    public function getPharmacie(): ?Pharmacie
    {
        return $this->pharmacie ?? null;
    }

    public function setPharmacie(Pharmacie $pharmacie): static
    {
        if (isset($this->pharmacie) && $this->pharmacie !== $pharmacie) {
            throw new \LogicException('Une donnée ne peut pas changer de pharmacie.');
        }
        $this->pharmacie = $pharmacie;

        return $this;
    }
}
