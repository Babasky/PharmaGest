<?php

namespace App\Entity;

use App\Repository\ReglementAmoRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paiement reçu d'un organisme pour un bordereau, affecté créance par créance (AM-08).
 * Il deviendra une recette au Lot 7 (RG-10, FI-04).
 */
#[ORM\Entity(repositoryClass: ReglementAmoRepository::class)]
class ReglementAmo implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, AffectationReglementAmo> */
    #[ORM\OneToMany(targetEntity: AffectationReglementAmo::class, mappedBy: 'reglement', cascade: ['persist'])]
    private Collection $affectations;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'reglements')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private BordereauAmo $bordereau,
        /** Date de réception du paiement. */
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $date,
        #[ORM\Column]
        private int $montant,
        /** Référence du virement ou du chèque. */
        #[ORM\Column(length: 80, nullable: true)]
        private ?string $reference,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Utilisateur $enregistrePar,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $enregistreLe,
    ) {
        $this->affectations = new ArrayCollection();
        $bordereau->ajouterReglement($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBordereau(): BordereauAmo
    {
        return $this->bordereau;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getEnregistrePar(): ?Utilisateur
    {
        return $this->enregistrePar;
    }

    public function getEnregistreLe(): \DateTimeImmutable
    {
        return $this->enregistreLe;
    }

    /**
     * @return Collection<int, AffectationReglementAmo>
     */
    public function getAffectations(): Collection
    {
        return $this->affectations;
    }

    public function affecter(CreanceAmo $creance, int $montant): void
    {
        $creance->regler($montant);
        $this->affectations->add(new AffectationReglementAmo($this, $creance, $montant));
    }
}
