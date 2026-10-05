<?php

namespace App\Entity;

use App\Repository\LigneVenteLotRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quantité d'une ligne de vente prise dans un lot (sortie FEFO). Sert à remettre les produits dans leurs
 * lots d'origine en cas d'annulation (RG-12) et au calcul de la marge (RG-17).
 */
#[ORM\Entity(repositoryClass: LigneVenteLotRepository::class)]
class LigneVenteLot implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'lots')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private LigneVente $ligne,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Lot $lot,
        #[ORM\Column]
        private int $quantite,
        /** Prix d'achat du lot, figé pour la marge (RG-17). */
        #[ORM\Column]
        private int $prixAchat,
    ) {
        if (null !== $ligne->getPharmacie()) {
            $this->setPharmacie($ligne->getPharmacie());
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLigne(): LigneVente
    {
        return $this->ligne;
    }

    public function getLot(): Lot
    {
        return $this->lot;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function getPrixAchat(): int
    {
        return $this->prixAchat;
    }
}
