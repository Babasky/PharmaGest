<?php

namespace App\Entity;

use App\Enum\StatutBordereau;
use App\Enum\StatutCreance;
use App\Repository\BordereauAmoRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Relevé des créances d'un organisme sur une période, envoyé pour remboursement (AM-06).
 *
 * Le brouillon se compose librement ; à la transmission il reçoit son numéro BRD-AAAA-NNNNNN (RG-02) et
 * son montant est figé : il n'est plus modifiable (RG-11). Son statut suit ensuite les règlements (AM-08).
 */
#[ORM\Entity(repositoryClass: BordereauAmoRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_bordereau_amo_numero', columns: ['pharmacie_id', 'numero'])]
class BordereauAmo implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Attribué à la transmission : un brouillon supprimé ne laisse pas de trou (RG-02). */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $numero = null;

    #[ORM\Column(length: 25, enumType: StatutBordereau::class)]
    private StatutBordereau $statut = StatutBordereau::Brouillon;

    /** Montant figé à la transmission. */
    #[ORM\Column(nullable: true)]
    private ?int $montantTransmis = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $transmisLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $transmisPar = null;

    /** @var Collection<int, CreanceAmo> */
    #[ORM\OneToMany(targetEntity: CreanceAmo::class, mappedBy: 'bordereau')]
    #[ORM\OrderBy(['dateVente' => 'ASC', 'id' => 'ASC'])]
    private Collection $creances;

    /** @var Collection<int, ReglementAmo> */
    #[ORM\OneToMany(targetEntity: ReglementAmo::class, mappedBy: 'bordereau')]
    #[ORM\OrderBy(['date' => 'ASC', 'id' => 'ASC'])]
    private Collection $reglements;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private OrganismeAmo $organisme,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $debut,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $fin,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Utilisateur $creePar,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $creeLe,
    ) {
        if ($fin < $debut) {
            throw new \LogicException('La fin de la période précède son début.');
        }
        $this->creances = new ArrayCollection();
        $this->reglements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    /** Numéro, ou « Brouillon n° id » avant la transmission. */
    public function getLibelle(): string
    {
        return $this->numero ?? 'Brouillon n° '.$this->id;
    }

    public function getStatut(): StatutBordereau
    {
        return $this->statut;
    }

    public function estBrouillon(): bool
    {
        return StatutBordereau::Brouillon === $this->statut;
    }

    public function estTransmis(): bool
    {
        return !$this->estBrouillon();
    }

    public function getOrganisme(): OrganismeAmo
    {
        return $this->organisme;
    }

    public function getDebut(): \DateTimeImmutable
    {
        return $this->debut;
    }

    public function getFin(): \DateTimeImmutable
    {
        return $this->fin;
    }

    public function getCreePar(): ?Utilisateur
    {
        return $this->creePar;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getTransmisLe(): ?\DateTimeImmutable
    {
        return $this->transmisLe;
    }

    public function getTransmisPar(): ?Utilisateur
    {
        return $this->transmisPar;
    }

    /**
     * @return Collection<int, CreanceAmo>
     */
    public function getCreances(): Collection
    {
        return $this->creances;
    }

    /** @internal tenu à jour par {@see CreanceAmo::rattacher()} */
    public function ajouterCreance(CreanceAmo $creance): void
    {
        if (!$this->creances->contains($creance)) {
            $this->creances->add($creance);
        }
    }

    /** @internal */
    public function retirerCreance(CreanceAmo $creance): void
    {
        $this->creances->removeElement($creance);
    }

    /**
     * @return Collection<int, ReglementAmo>
     */
    public function getReglements(): Collection
    {
        return $this->reglements;
    }

    /** @internal */
    public function ajouterReglement(ReglementAmo $reglement): void
    {
        $this->reglements->add($reglement);
    }

    /** Montant du bordereau : figé à la transmission, calculé sur les créances tant que c'est un brouillon. */
    public function getMontant(): int
    {
        return $this->montantTransmis ?? array_sum($this->creances->map(static fn (CreanceAmo $c) => $c->getMontant())->toArray());
    }

    public function getMontantRegle(): int
    {
        return array_sum($this->creances->map(static fn (CreanceAmo $c) => $c->getMontantRegle())->toArray());
    }

    public function getMontantRejete(): int
    {
        return array_sum($this->creances->map(static fn (CreanceAmo $c) => $c->getMontantRejete())->toArray());
    }

    public function getReste(): int
    {
        return array_sum($this->creances->map(static fn (CreanceAmo $c) => $c->getReste())->toArray());
    }

    /** Toutes les créances sont payées ou rejetées : plus rien n'est attendu. */
    public function estSolde(): bool
    {
        return $this->estTransmis() && 0 === $this->getReste();
    }

    /**
     * @internal réservé à {@see \App\Amo\GestionBordereaux}
     */
    public function transmettre(string $numero, \DateTimeImmutable $le, ?Utilisateur $par): void
    {
        if (!$this->estBrouillon()) {
            throw new \LogicException('Ce bordereau est déjà transmis.');
        }
        $this->numero = $numero;
        $this->montantTransmis = $this->getMontant();
        $this->transmisLe = $le;
        $this->transmisPar = $par;
        $this->statut = StatutBordereau::Transmis;
        foreach ($this->creances as $creance) {
            $creance->marquerTransmise();
        }
    }

    /**
     * Statut déduit des créances après un règlement ou un rejet (AM-06, R-11).
     *
     * @internal réservé à {@see \App\Amo\GestionBordereaux}
     */
    public function actualiserStatut(): void
    {
        if ($this->estBrouillon()) {
            return;
        }
        $regle = $this->getMontantRegle();
        $toutesRejetees = $this->creances->forAll(static fn (int $i, CreanceAmo $c) => StatutCreance::Rejetee === $c->getStatut());
        $this->statut = match (true) {
            $regle >= $this->getMontant() => StatutBordereau::Paye,
            0 === $regle && $toutesRejetees => StatutBordereau::Rejete,
            $regle > 0 => StatutBordereau::PayePartiellement,
            default => StatutBordereau::Transmis,
        };
    }
}
