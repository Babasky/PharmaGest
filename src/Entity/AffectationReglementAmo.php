<?php

namespace App\Entity;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Part d'un règlement AMO affectée à une créance (AM-08).
 */
#[ORM\Entity]
class AffectationReglementAmo implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'affectations')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private ReglementAmo $reglement,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private CreanceAmo $creance,
        #[ORM\Column]
        private int $montant,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReglement(): ReglementAmo
    {
        return $this->reglement;
    }

    public function getCreance(): CreanceAmo
    {
        return $this->creance;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }
}
