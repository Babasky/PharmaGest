<?php

namespace App\Entity;

use App\Repository\LigneInventaireRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Comptage d'un lot pendant un inventaire (ST-06).
 */
#[ORM\Entity(repositoryClass: LigneInventaireRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_ligne_inventaire_lot', columns: ['inventaire_id', 'lot_id'])]
class LigneInventaire implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Quantité du lot à l'ouverture de l'inventaire. */
    #[ORM\Column]
    private int $quantiteTheorique;

    #[ORM\Column(nullable: true)]
    private ?int $quantiteComptee = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'lignes')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Inventaire $inventaire,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Lot $lot,
        /** Ordre d'affichage (étagère, puis produit) pour suivre le rayon pendant le comptage. */
        #[ORM\Column]
        private int $ordre,
    ) {
        $this->quantiteTheorique = $lot->getQuantiteRestante();
        if (null !== $inventaire->getPharmacie()) {
            $this->setPharmacie($inventaire->getPharmacie());
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInventaire(): Inventaire
    {
        return $this->inventaire;
    }

    public function getLot(): Lot
    {
        return $this->lot;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function getQuantiteTheorique(): int
    {
        return $this->quantiteTheorique;
    }

    public function getQuantiteComptee(): ?int
    {
        return $this->quantiteComptee;
    }

    public function setQuantiteComptee(?int $quantite): static
    {
        if (null !== $quantite && $quantite < 0) {
            throw new \InvalidArgumentException('Une quantité comptée ne peut pas être négative.');
        }
        $this->quantiteComptee = $quantite;

        return $this;
    }

    /** Compté − théorique ; null tant que le lot n'est pas compté. */
    public function getEcart(): ?int
    {
        return null === $this->quantiteComptee ? null : $this->quantiteComptee - $this->quantiteTheorique;
    }
}
