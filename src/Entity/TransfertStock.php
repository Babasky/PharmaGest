<?php

namespace App\Entity;

use App\Enum\StatutTransfert;
use App\Repository\TransfertStockRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Transfert de stock vers une autre officine du même propriétaire (ST-11).
 *
 * Il appartient à l'officine d'origine (son tenant), qui le prépare, l'expédie (le stock sort, lot par lot) ou
 * l'annule avant expédition. L'officine destinataire le voit seulement pour confirmer la réception (le stock entre,
 * avec les mêmes lots, dates de péremption et prix d'achat). Numéro TRF-AAAA-NNNNNN de l'officine d'origine (RG-02).
 */
#[ORM\Entity(repositoryClass: TransfertStockRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_transfert_numero', columns: ['pharmacie_id', 'numero'])]
#[ORM\Index(name: 'idx_transfert_destination', columns: ['pharmacie_destination_id', 'statut'])]
class TransfertStock implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 25, enumType: StatutTransfert::class)]
    private StatutTransfert $statut = StatutTransfert::EnPreparation;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expedieLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $expediePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $recuLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $recuPar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $annuleLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $annulePar = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifAnnulation = null;

    /** @var Collection<int, LigneTransfert> */
    #[ORM\OneToMany(targetEntity: LigneTransfert::class, mappedBy: 'transfert', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lignes;

    public function __construct(
        #[ORM\Column(length: 30)]
        private string $numero,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Pharmacie $pharmacieDestination,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Utilisateur $creePar,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $creeLe,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $note = null,
    ) {
        $this->lignes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    /** L'officine d'origine est le tenant du transfert. */
    public function getPharmacieOrigine(): Pharmacie
    {
        return $this->getPharmacie() ?? throw new \LogicException('Transfert sans officine d\'origine.');
    }

    public function getPharmacieDestination(): Pharmacie
    {
        return $this->pharmacieDestination;
    }

    public function getStatut(): StatutTransfert
    {
        return $this->statut;
    }

    public function estEnPreparation(): bool
    {
        return StatutTransfert::EnPreparation === $this->statut;
    }

    public function estExpedie(): bool
    {
        return StatutTransfert::Expedie === $this->statut;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreePar(): ?Utilisateur
    {
        return $this->creePar;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getExpedieLe(): ?\DateTimeImmutable
    {
        return $this->expedieLe;
    }

    public function getExpediePar(): ?Utilisateur
    {
        return $this->expediePar;
    }

    public function getRecuLe(): ?\DateTimeImmutable
    {
        return $this->recuLe;
    }

    public function getRecuPar(): ?Utilisateur
    {
        return $this->recuPar;
    }

    public function getAnnuleLe(): ?\DateTimeImmutable
    {
        return $this->annuleLe;
    }

    public function getAnnulePar(): ?Utilisateur
    {
        return $this->annulePar;
    }

    public function getMotifAnnulation(): ?string
    {
        return $this->motifAnnulation;
    }

    /**
     * @return Collection<int, LigneTransfert>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function ligneDe(Produit $produit): ?LigneTransfert
    {
        foreach ($this->lignes as $ligne) {
            if ($ligne->getProduit() === $produit) {
                return $ligne;
            }
        }

        return null;
    }

    public function getQuantite(): int
    {
        return array_sum($this->lignes->map(static fn (LigneTransfert $l) => $l->getQuantite())->toArray());
    }

    /** Valeur au prix d'achat des lots expédiés (0 tant que rien n'est expédié). */
    public function getValeur(): int
    {
        return array_sum($this->lignes->map(static fn (LigneTransfert $l) => $l->getValeur())->toArray());
    }

    /**
     * @internal réservé à {@see \App\Stock\TransfertService}
     */
    public function ajouterLigne(Produit $produit, int $quantite): LigneTransfert
    {
        $ligne = new LigneTransfert($this, $produit, $quantite);
        $this->lignes->add($ligne);

        return $ligne;
    }

    /** @internal */
    public function retirerLigne(LigneTransfert $ligne): void
    {
        $this->lignes->removeElement($ligne);
    }

    /** @internal */
    public function marquerExpedie(?Utilisateur $par, \DateTimeImmutable $le): void
    {
        $this->statut = StatutTransfert::Expedie;
        $this->expediePar = $par;
        $this->expedieLe = $le;
    }

    /** @internal */
    public function marquerRecu(?Utilisateur $par, \DateTimeImmutable $le): void
    {
        $this->statut = StatutTransfert::Recu;
        $this->recuPar = $par;
        $this->recuLe = $le;
    }

    /** @internal */
    public function annuler(?Utilisateur $par, \DateTimeImmutable $le, string $motif): void
    {
        $this->statut = StatutTransfert::Annule;
        $this->annulePar = $par;
        $this->annuleLe = $le;
        $this->motifAnnulation = $motif;
    }
}
