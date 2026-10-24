<?php

namespace App\Entity;

use App\Repository\TransfertInterneRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Transfert de stock à l'intérieur d'une même pharmacie : d'un entrepôt vers l'officine, de l'officine vers un
 * entrepôt, ou d'un entrepôt à un autre. Un emplacement null désigne l'officine (stock de vente).
 *
 * Contrairement au transfert entre officines ({@see TransfertStock}), il est fait en une fois : le stock sort de
 * l'origine en FEFO et entre aussitôt à la destination avec les mêmes lots. Les mouvements portent son numéro
 * TRI-AAAA-NNNNNN (RG-02). Il n'est jamais modifié.
 */
#[ORM\Entity(repositoryClass: TransfertInterneRepository::class, readOnly: true)]
#[ORM\UniqueConstraint(name: 'uniq_transfert_interne_numero', columns: ['pharmacie_id', 'numero'])]
#[ORM\Index(name: 'idx_transfert_interne_date', columns: ['pharmacie_id', 'cree_le'])]
class TransfertInterne implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, LigneTransfertInterne> */
    #[ORM\OneToMany(targetEntity: LigneTransfertInterne::class, mappedBy: 'transfert', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lignes;

    public function __construct(
        #[ORM\Column(length: 30)]
        private string $numero,
        /** Null : l'officine. */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
        private ?Entrepot $origine,
        /** Null : l'officine. */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
        private ?Entrepot $destination,
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

    public function getOrigine(): ?Entrepot
    {
        return $this->origine;
    }

    public function getDestination(): ?Entrepot
    {
        return $this->destination;
    }

    public function getCreePar(): ?Utilisateur
    {
        return $this->creePar;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    /**
     * @return Collection<int, LigneTransfertInterne>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function ajouterLigne(Produit $produit, int $quantite): LigneTransfertInterne
    {
        $ligne = new LigneTransfertInterne($this, $produit, $quantite);
        $this->lignes->add($ligne);

        return $ligne;
    }

    public function getLibelleOrigine(): string
    {
        return $this->origine?->getNom() ?? 'Officine';
    }

    public function getLibelleDestination(): string
    {
        return $this->destination?->getNom() ?? 'Officine';
    }

    public function getQuantiteTotale(): int
    {
        return array_sum($this->lignes->map(static fn (LigneTransfertInterne $l) => $l->getQuantite())->toArray());
    }
}
