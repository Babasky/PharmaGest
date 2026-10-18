<?php

namespace App\Entity;

use App\Enum\TypeRemise;
use App\Enum\TypeVente;
use App\Repository\LigneVenteRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use App\Util\Fcfa;
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

    /**
     * Prix de vente de la pharmacie au moment de la vente. Null sur les lignes antérieures au 15/10/2026 (part AMO
     * calculée sur le prix AMO, même plus élevé). Du 15 au 18/10/2026, une ligne AMO pouvait être facturée au prix
     * AMO ({@see self::$prixUnitaire} plus bas que ce prix).
     */
    #[ORM\Column(nullable: true)]
    private ?int $prixPharmacie = null;

    /** L'AMO exigeait un accord préalable de l'organisme pour ce produit au moment de la vente (RG-06). */
    #[ORM\Column(options: ['default' => false])]
    private bool $accordPrealable = false;

    /** N° de l'accord préalable présenté par l'assuré : sans lui, l'AMO ne prend pas la ligne en charge. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $numeroAccord = null;

    /**
     * Assurance autre que l'AMO : la ligne est-elle prise en charge (cochée au panier) ? Null : selon que le produit
     * est remboursable.
     */
    #[ORM\Column(nullable: true)]
    private ?bool $priseEnCharge = null;

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
        /** Prix unitaire facturé, figé à la vente (RG-06) : le prix de la pharmacie, même sur une vente AMO. */
        #[ORM\Column]
        private int $prixUnitaire,
        /** Remboursable AMO au moment de la vente (RG-06). */
        #[ORM\Column]
        private bool $remboursable,
        /** Prix de vente AMO unitaire au moment de la vente (RG-06) ; null : pas de prix AMO propre au médicament. */
        #[ORM\Column(nullable: true)]
        private ?int $prixUnitaireAmo = null,
    ) {
        $this->lots = new ArrayCollection();
        $this->prixPharmacie = $prixUnitaire;
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

    /** Prix de vente de la pharmacie (avant application éventuelle du prix AMO). */
    public function getPrixPharmacie(): int
    {
        return $this->prixPharmacie ?? $this->prixUnitaire;
    }

    /** Ligne vendue entre le 15 et le 18/10/2026 au prix AMO, plus bas que le prix de la pharmacie. */
    public function isAuPrixAmo(): bool
    {
        return $this->prixUnitaire < $this->getPrixPharmacie();
    }

    /** Pour un organisme AMO, la part AMO se calcule sur le prix AMO, plus bas que le prix facturé. */
    public function isBaseAuPrixAmo(bool $tarifAmo): bool
    {
        return $tarifAmo && null !== $this->prixPharmacie && $this->prixPriseEnCharge(true) < $this->prixUnitaire;
    }

    /**
     * Ligne d'un panier commencé quand une ligne AMO était facturée au prix AMO : elle reprend le prix de la pharmacie.
     *
     * @internal réservé à {@see Vente}
     */
    public function retablirPrixPharmacie(): void
    {
        if (null !== $this->prixPharmacie) {
            $this->prixUnitaire = $this->prixPharmacie;
        }
    }

    public function isAccordPrealable(): bool
    {
        return $this->accordPrealable;
    }

    /**
     * @internal réservé à {@see Vente::ajouterLigne()}
     */
    public function exigerAccordPrealable(bool $accordPrealable): void
    {
        $this->accordPrealable = $accordPrealable;
    }

    public function getNumeroAccord(): ?string
    {
        return $this->numeroAccord;
    }

    public function setNumeroAccord(?string $numeroAccord): static
    {
        $numeroAccord = mb_strtoupper(trim((string) $numeroAccord));
        $this->numeroAccord = '' === $numeroAccord ? null : mb_substr($numeroAccord, 0, 40);

        return $this;
    }

    /** Coche du panier pour une assurance autre que l'AMO (null : selon que le produit est remboursable). */
    public function getPriseEnCharge(): ?bool
    {
        return $this->priseEnCharge;
    }

    public function setPriseEnCharge(?bool $priseEnCharge): static
    {
        $this->priseEnCharge = $priseEnCharge;

        return $this;
    }

    /**
     * L'organisme prend-il la ligne en charge ? Organisme AMO : médicament remboursable, avec le n° d'accord quand
     * un accord préalable est exigé. Autre assurance : ligne cochée au panier, par défaut les produits remboursables.
     */
    public function estPriseEnCharge(bool $tarifAmo): bool
    {
        if ($tarifAmo) {
            return $this->remboursable && (!$this->accordPrealable || null !== $this->numeroAccord);
        }

        return $this->priseEnCharge ?? $this->remboursable;
    }

    /** Prise en charge par l'organisme de la vente (ticket, facture, fiche de la vente). */
    public function isCouverte(): bool
    {
        return TypeVente::Amo === $this->vente->getType() && $this->estPriseEnCharge($this->vente->tarifAmo());
    }

    /** Organisme AMO, accord préalable exigé mais pas encore saisi : la ligne reste à la charge de l'assuré. */
    public function attendAccord(bool $tarifAmo): bool
    {
        return $tarifAmo && $this->remboursable && $this->accordPrealable && null === $this->numeroAccord;
    }

    public function isRemboursable(): bool
    {
        return $this->remboursable;
    }

    public function getPrixUnitaireAmo(): ?int
    {
        return $this->prixUnitaireAmo;
    }

    /** Montant avant remise. */
    public function getMontantBrut(): int
    {
        return $this->prixUnitaire * $this->quantite;
    }

    /**
     * Prix unitaire sur lequel s'applique le taux de prise en charge : pour un organisme AMO, le plus bas du prix AMO
     * et du prix facturé (la différence reste à la charge de l'assuré) ; pour une autre assurance, le prix facturé.
     * Les lignes antérieures au 15/10/2026 gardent leur calcul d'origine (prix AMO, même plus élevé).
     */
    public function prixPriseEnCharge(bool $tarifAmo): int
    {
        if (!$tarifAmo || null === $this->prixUnitaireAmo) {
            return $this->prixUnitaire;
        }

        return null === $this->prixPharmacie ? $this->prixUnitaireAmo : min($this->prixUnitaire, $this->prixUnitaireAmo);
    }

    /** Base de prise en charge de la ligne (0 si l'organisme ne la prend pas en charge). */
    public function basePriseEnCharge(bool $tarifAmo): int
    {
        return $this->estPriseEnCharge($tarifAmo) ? $this->prixPriseEnCharge($tarifAmo) * $this->quantite : 0;
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

    /**
     * Part de la ligne prise en charge par l'organisme d'une vente AMO / assurance : taux appliqué à sa base de prise en
     * charge, sans dépasser le montant facturé. 0 hors AMO ou pour une ligne que l'organisme ne prend pas en charge.
     */
    public function calculerPartAmo(): int
    {
        if (TypeVente::Amo !== $this->vente->getType()) {
            return 0;
        }
        $part = Fcfa::arrondir($this->basePriseEnCharge($this->vente->tarifAmo()) * ($this->vente->getTauxAmo() ?? 0) / 100);

        return min($this->getMontantBrut(), $part);
    }

    /** Montant sur lequel porte la remise de la ligne : le montant de la ligne, ou sa part assuré sur une vente AMO (RG-09). */
    public function baseRemise(): int
    {
        return $this->getMontantBrut() - $this->calculerPartAmo();
    }

    /** Remise calculée sur le montant actuel de la ligne (sa part assuré sur une vente AMO, RG-09). */
    public function calculerRemise(): int
    {
        return $this->remiseType?->montantSur($this->baseRemise(), $this->remiseValeur) ?? 0;
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
