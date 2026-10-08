<?php

namespace App\Entity;

use App\Repository\LotRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lot d'un produit (ST-01) : unités fabriquées ensemble, avec un numéro et une date de péremption.
 *
 * La quantité restante ne change que par un {@see MouvementStock}, via {@see \App\Stock\StockService}.
 */
#[ORM\Entity(repositoryClass: LotRepository::class)]
#[ORM\Index(name: 'idx_lot_peremption', columns: ['pharmacie_id', 'date_peremption'])]
class Lot implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $quantiteRestante = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Produit $produit,
        #[ORM\Column(length: 50)]
        private string $numero,
        /** Facultative à la réception d'une commande : un lot sans date n'est jamais périmé et sort en dernier (FEFO). */
        #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $datePeremption,
        #[ORM\Column]
        private int $quantiteInitiale,
        /** Prix d'achat réellement payé, en FCFA par unité (le prix de la fiche produit n'est qu'une référence). */
        #[ORM\Column]
        private int $prixAchat,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $dateReception,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Fournisseur $fournisseur = null,
    ) {
        $this->numero = trim($numero);
        $this->creeLe = new \DateTimeImmutable();
        if (null !== $produit->getPharmacie()) {
            $this->setPharmacie($produit->getPharmacie());
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduit(): Produit
    {
        return $this->produit;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function getDatePeremption(): ?\DateTimeImmutable
    {
        return $this->datePeremption;
    }

    public function getQuantiteInitiale(): int
    {
        return $this->quantiteInitiale;
    }

    public function getQuantiteRestante(): int
    {
        return $this->quantiteRestante;
    }

    /**
     * Réservé à {@see \App\Stock\StockService}, qui écrit le mouvement correspondant.
     *
     * @internal
     */
    public function modifierQuantite(int $variation): void
    {
        if ($this->quantiteRestante + $variation < 0) {
            throw new \LogicException('Le stock d\'un lot ne peut pas être négatif (RG-03).');
        }
        $this->quantiteRestante += $variation;
    }

    public function getPrixAchat(): int
    {
        return $this->prixAchat;
    }

    public function getDateReception(): \DateTimeImmutable
    {
        return $this->dateReception;
    }

    public function getFournisseur(): ?Fournisseur
    {
        return $this->fournisseur;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    /** RG-05 : un lot est périmé dès que sa date de péremption est atteinte. */
    public function estPerimeLe(\DateTimeInterface $jour): bool
    {
        return null !== $this->datePeremption && $this->datePeremption->format('Y-m-d') <= $jour->format('Y-m-d');
    }

    /** Valeur au prix d'achat du lot (ST-07). */
    public function getValeur(): int
    {
        return $this->quantiteRestante * $this->prixAchat;
    }

    public function __toString(): string
    {
        return null === $this->datePeremption
            ? \sprintf('Lot %s (sans péremption)', $this->numero)
            : \sprintf('Lot %s (pér. %s)', $this->numero, $this->datePeremption->format('d/m/Y'));
    }
}
