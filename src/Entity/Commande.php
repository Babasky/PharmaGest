<?php

namespace App\Entity;

use App\Enum\StatutCommande;
use App\Repository\CommandeRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Commande à un fournisseur (CO-01, CO-03).
 *
 * Le brouillon se compose librement ; quand la commande est passée (par email ou autrement), elle reçoit son
 * numéro CMD-AAAA-NNNNNN (RG-02) et ses lignes ne changent plus. Son statut suit ensuite les réceptions (CO-06).
 */
#[ORM\Entity(repositoryClass: CommandeRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_commande_numero', columns: ['pharmacie_id', 'numero'])]
#[ORM\Index(name: 'idx_commande_statut', columns: ['pharmacie_id', 'statut'])]
class Commande implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Attribué quand la commande est passée : un brouillon supprimé ne laisse pas de trou (RG-02). */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $numero = null;

    #[ORM\Column(length: 25, enumType: StatutCommande::class)]
    private StatutCommande $statut = StatutCommande::Brouillon;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $envoyeeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $envoyeePar = null;

    /** Annulation, ou abandon du reliquat d'une commande reçue en partie. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $clotureeLe = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifCloture = null;

    /** @var Collection<int, LigneCommande> */
    #[ORM\OneToMany(targetEntity: LigneCommande::class, mappedBy: 'commande', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lignes;

    /** @var Collection<int, EnvoiCommande> */
    #[ORM\OneToMany(targetEntity: EnvoiCommande::class, mappedBy: 'commande')]
    #[ORM\OrderBy(['date' => 'DESC', 'id' => 'DESC'])]
    private Collection $envois;

    /** @var Collection<int, Reception> */
    #[ORM\OneToMany(targetEntity: Reception::class, mappedBy: 'commande')]
    #[ORM\OrderBy(['date' => 'ASC', 'id' => 'ASC'])]
    private Collection $receptions;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Fournisseur $fournisseur,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Utilisateur $creePar,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $creeLe,
    ) {
        $this->lignes = new ArrayCollection();
        $this->envois = new ArrayCollection();
        $this->receptions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    /** Numéro, ou « Brouillon n° id » tant que la commande n'est pas passée. */
    public function getLibelle(): string
    {
        return $this->numero ?? 'Brouillon n° '.$this->id;
    }

    public function getStatut(): StatutCommande
    {
        return $this->statut;
    }

    public function estBrouillon(): bool
    {
        return StatutCommande::Brouillon === $this->statut;
    }

    /** Une livraison est attendue : la commande peut être réceptionnée. */
    public function estReceptionnable(): bool
    {
        return $this->statut->estEnAttenteDeLivraison();
    }

    public function getFournisseur(): Fournisseur
    {
        return $this->fournisseur;
    }

    public function getCreePar(): ?Utilisateur
    {
        return $this->creePar;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getEnvoyeeLe(): ?\DateTimeImmutable
    {
        return $this->envoyeeLe;
    }

    public function getEnvoyeePar(): ?Utilisateur
    {
        return $this->envoyeePar;
    }

    public function getClotureeLe(): ?\DateTimeImmutable
    {
        return $this->clotureeLe;
    }

    public function getMotifCloture(): ?string
    {
        return $this->motifCloture;
    }

    /**
     * @return Collection<int, LigneCommande>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function ligneDe(Produit $produit): ?LigneCommande
    {
        foreach ($this->lignes as $ligne) {
            if ($ligne->getProduit() === $produit) {
                return $ligne;
            }
        }

        return null;
    }

    /**
     * @internal réservé à {@see \App\Achat\CommandeService}
     */
    public function ajouterLigne(Produit $produit, int $quantite, int $prixEstime): LigneCommande
    {
        $ligne = new LigneCommande($this, $produit, $quantite, $prixEstime);
        $this->lignes->add($ligne);

        return $ligne;
    }

    /** @internal */
    public function retirerLigne(LigneCommande $ligne): void
    {
        $this->lignes->removeElement($ligne);
    }

    /**
     * @return Collection<int, EnvoiCommande>
     */
    public function getEnvois(): Collection
    {
        return $this->envois;
    }

    /**
     * @return Collection<int, Reception>
     */
    public function getReceptions(): Collection
    {
        return $this->receptions;
    }

    /** @internal */
    public function ajouterReception(Reception $reception): void
    {
        $this->receptions->add($reception);
    }

    /** Montant estimé : quantités commandées au prix d'achat estimé. */
    public function getMontantEstime(): int
    {
        return array_sum($this->lignes->map(static fn (LigneCommande $l) => $l->getMontantEstime())->toArray());
    }

    public function getQuantiteCommandee(): int
    {
        return array_sum($this->lignes->map(static fn (LigneCommande $l) => $l->getQuantite())->toArray());
    }

    public function getQuantiteRecue(): int
    {
        return array_sum($this->lignes->map(static fn (LigneCommande $l) => $l->getQuantiteRecue())->toArray());
    }

    /**
     * La commande est passée : elle reçoit son numéro et ses lignes sont figées.
     *
     * @internal réservé à {@see \App\Achat\CommandeService}
     */
    public function passer(string $numero, \DateTimeImmutable $le, ?Utilisateur $par): void
    {
        if (!$this->estBrouillon()) {
            throw new \LogicException('Cette commande est déjà passée.');
        }
        $this->numero = $numero;
        $this->envoyeeLe = $le;
        $this->envoyeePar = $par;
        $this->statut = StatutCommande::Envoyee;
    }

    /**
     * Statut déduit des quantités reçues après une réception (CO-03).
     *
     * @internal réservé à {@see \App\Achat\ReceptionService}
     */
    public function actualiserStatut(): void
    {
        if (!$this->estReceptionnable()) {
            return;
        }
        $complete = $this->lignes->forAll(static fn (int $i, LigneCommande $l) => 0 === $l->getReste());
        $this->statut = match (true) {
            $complete => StatutCommande::Recue,
            $this->getQuantiteRecue() > 0 => StatutCommande::RecuePartiellement,
            default => StatutCommande::Envoyee,
        };
    }

    /**
     * Annulation (rien n'a été reçu), ou abandon du reliquat d'une commande reçue en partie.
     *
     * @internal réservé à {@see \App\Achat\CommandeService}
     */
    public function cloturer(StatutCommande $statut, string $motif, \DateTimeImmutable $le): void
    {
        $this->statut = $statut;
        $this->motifCloture = $motif;
        $this->clotureeLe = $le;
    }
}
