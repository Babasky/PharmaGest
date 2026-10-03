<?php

namespace App\Entity;

use App\Enum\MoyenPaiement;
use App\Repository\AbonnementRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paiement d'abonnement enregistré par le super admin. Chaque paiement ouvre une période de 12 mois
 * et porte un numéro de facture unique.
 *
 * Rattaché au tenant pour que le propriétaire ne puisse lire que ses propres factures.
 */
#[ORM\Entity(repositoryClass: AbonnementRepository::class)]
#[ORM\Index(name: 'idx_abonnement_date_paiement', columns: ['date_paiement'])]
class Abonnement implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Offre $offre;

    #[ORM\Column(length: 20, unique: true)]
    private string $numeroFacture;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateDebut;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateFin;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $datePaiement;

    /** Montant payé en FCFA. */
    #[ORM\Column]
    private int $montant;

    #[ORM\Column(length: 20, enumType: MoyenPaiement::class)]
    private MoyenPaiement $moyen;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $reference;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $enregistrePar;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    public function __construct(
        Pharmacie $pharmacie,
        Offre $offre,
        string $numeroFacture,
        \DateTimeImmutable $dateDebut,
        \DateTimeImmutable $dateFin,
        \DateTimeImmutable $datePaiement,
        int $montant,
        MoyenPaiement $moyen,
        ?string $reference,
        ?Utilisateur $enregistrePar,
    ) {
        $this->setPharmacie($pharmacie);
        $this->offre = $offre;
        $this->numeroFacture = $numeroFacture;
        $this->dateDebut = $dateDebut;
        $this->dateFin = $dateFin;
        $this->datePaiement = $datePaiement;
        $this->montant = $montant;
        $this->moyen = $moyen;
        $this->reference = $reference;
        $this->enregistrePar = $enregistrePar;
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOffre(): Offre
    {
        return $this->offre;
    }

    public function getNumeroFacture(): string
    {
        return $this->numeroFacture;
    }

    public function getDateDebut(): \DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function getDateFin(): \DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function getDatePaiement(): \DateTimeImmutable
    {
        return $this->datePaiement;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function getMoyen(): MoyenPaiement
    {
        return $this->moyen;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getEnregistrePar(): ?Utilisateur
    {
        return $this->enregistrePar;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
