<?php

namespace App\Entity;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Livraison reçue pour une commande (CO-06). Chaque ligne crée un lot ; une commande peut être livrée en plusieurs fois.
 */
#[ORM\Entity]
class Reception implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, LigneReception> */
    #[ORM\OneToMany(targetEntity: LigneReception::class, mappedBy: 'reception', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lignes;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'receptions')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Commande $commande,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $date,
        /** N° du bon de livraison du fournisseur. */
        #[ORM\Column(length: 50, nullable: true)]
        private ?string $numeroBonLivraison,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Utilisateur $creePar,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $creeLe,
    ) {
        $this->lignes = new ArrayCollection();
        if (null !== $commande->getPharmacie()) {
            $this->setPharmacie($commande->getPharmacie());
        }
        $commande->ajouterReception($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommande(): Commande
    {
        return $this->commande;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getNumeroBonLivraison(): ?string
    {
        return $this->numeroBonLivraison;
    }

    public function getCreePar(): ?Utilisateur
    {
        return $this->creePar;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    /**
     * @return Collection<int, LigneReception>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    /** @internal réservé à {@see \App\Achat\ReceptionService} */
    public function ajouterLigne(LigneCommande $ligneCommande, Lot $lot): LigneReception
    {
        $ligne = new LigneReception($this, $ligneCommande, $lot);
        $this->lignes->add($ligne);

        return $ligne;
    }

    /** Valeur de la livraison au prix réel. */
    public function getMontant(): int
    {
        return array_sum($this->lignes->map(static fn (LigneReception $l) => $l->getMontant())->toArray());
    }
}
