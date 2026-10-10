<?php

namespace App\Entity;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Produit à transférer et sa quantité. À l'expédition, la quantité sort en FEFO et les lots prélevés
 * sont notés dans {@see LotTransfere}.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_ligne_transfert_produit', columns: ['transfert_id', 'produit_id'])]
class LigneTransfert implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, LotTransfere> */
    #[ORM\OneToMany(targetEntity: LotTransfere::class, mappedBy: 'ligne', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lots;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'lignes')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private TransfertStock $transfert,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Produit $produit,
        #[ORM\Column]
        private int $quantite,
    ) {
        $this->lots = new ArrayCollection();
        if (null !== $transfert->getPharmacie()) {
            $this->setPharmacie($transfert->getPharmacie());
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTransfert(): TransfertStock
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

    /** @internal réservé à {@see \App\Stock\TransfertService} */
    public function setQuantite(int $quantite): void
    {
        $this->quantite = $quantite;
    }

    /**
     * @return Collection<int, LotTransfere>
     */
    public function getLots(): Collection
    {
        return $this->lots;
    }

    /** @internal */
    public function ajouterLot(Lot $lot, int $quantite): LotTransfere
    {
        $lotTransfere = new LotTransfere($this, $lot, $quantite);
        $this->lots->add($lotTransfere);

        return $lotTransfere;
    }

    public function getValeur(): int
    {
        return array_sum($this->lots->map(static fn (LotTransfere $l) => $l->getValeur())->toArray());
    }
}
