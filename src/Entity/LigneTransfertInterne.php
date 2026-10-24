<?php

namespace App\Entity;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Produit d'un {@see TransfertInterne} et quantité déplacée (les lots se lisent sur les mouvements du transfert).
 */
#[ORM\Entity(readOnly: true)]
class LigneTransfertInterne implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'lignes')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private TransfertInterne $transfert,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Produit $produit,
        #[ORM\Column]
        private int $quantite,
    ) {
        if (null !== $transfert->getPharmacie()) {
            $this->setPharmacie($transfert->getPharmacie());
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTransfert(): TransfertInterne
    {
        return $this->transfert;
    }

    public function getProduit(): Produit
    {
        return $this->produit;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }
}
