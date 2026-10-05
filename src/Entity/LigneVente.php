<?php

namespace App\Entity;

use App\Enum\TypeRemise;
use App\Repository\LigneVenteRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne d'une vente : un produit, sa quantité et son prix figé (RG-06). Une ligne par produit.
 * À la validation, les lots consommés en FEFO sont enregistrés dans {@see LigneVenteLot} (RG-04, RG-17).
 */
#[ORM\Entity(repositoryClass: LigneVenteRepository::class)]
class LigneVente implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, nullable: true, enumType: TypeRemise::class)]
    private ?TypeRemise $remiseType = null;

    /** Remise saisie : pourcentage entier ou montant en FCFA, selon {@see self::$remiseType}. */
    #[ORM\Column]
    private int $remiseValeur = 0;

    /** Montant de la remise de la ligne, figé à la validation. */
    #[ORM\Column]
    private int $remise = 0;

    /** @var Collection<int, LigneVenteLot> */
    #[ORM\OneToMany(targetEntity: LigneVenteLot::class, mappedBy: 'ligne', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lots;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'lignes')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Vente $vente,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Produit $produit,
        #[ORM\Column]
        private int $quantite,
        /** Prix de vente unitaire au moment de la vente (RG-06). */
        #[ORM\Column]
        private int $prixUnitaire,
        /** Remboursable AMO au moment de la vente (RG-06). */
        #[ORM\Column]
        private bool $remboursable,
    ) {
        $this->lots = new ArrayCollection();
        if (null !== $vente->getPharmacie()) {
            $this->setPharmacie($vente->getPharmacie());
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVente(): Vente
    {
        return $this->vente;
    }

    public function getProduit(): Produit
    {
        return $this->produit;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): static
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function getPrixUnitaire(): int
    {
        return $this->prixUnitaire;
    }

    public function isRemboursable(): bool
    {
        return $this->remboursable;
    }

    /** Montant avant remise. */
    public function getMontantBrut(): int
    {
        return $this->prixUnitaire * $this->quantite;
    }

    public function getRemiseType(): ?TypeRemise
    {
        return $this->remiseType;
    }

    public function getRemiseValeur(): int
    {
        return $this->remiseValeur;
    }

    public function definirRemise(?TypeRemise $type, int $valeur): static
    {
        $this->remiseType = $valeur > 0 ? $type : null;
        $this->remiseValeur = null === $this->remiseType ? 0 : $valeur;

        return $this;
    }

    /** Remise calculée sur le montant actuel de la ligne. */
    public function calculerRemise(): int
    {
        return $this->remiseType?->montantSur($this->getMontantBrut(), $this->remiseValeur) ?? 0;
    }

    /** Remise figée à la validation (0 avant). */
    public function getRemise(): int
    {
        return $this->remise;
    }

    /**
     * @internal réservé à {@see Vente::figer()}
     */
    public function figerRemise(int $remise): void
    {
        $this->remise = $remise;
    }

    /** Montant net de la ligne, après sa propre remise. */
    public function getMontantNet(): int
    {
        return $this->getMontantBrut() - $this->calculerRemise();
    }

    /**
     * @return Collection<int, LigneVenteLot>
     */
    public function getLots(): Collection
    {
        return $this->lots;
    }

    public function ajouterLot(Lot $lot, int $quantite): LigneVenteLot
    {
        $ligneLot = new LigneVenteLot($this, $lot, $quantite, $lot->getPrixAchat());
        $this->lots->add($ligneLot);

        return $ligneLot;
    }

    /** Coût d'achat des unités vendues, au prix des lots consommés (RG-17). */
    public function getCoutAchat(): int
    {
        $cout = 0;
        foreach ($this->lots as $ligneLot) {
            $cout += $ligneLot->getQuantite() * $ligneLot->getPrixAchat();
        }

        return $cout;
    }
}
