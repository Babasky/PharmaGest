<?php

namespace App\Entity;

use App\Enum\ModeReglement;
use App\Repository\DepenseRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dépense de la pharmacie (FI-01), numérotée DEP-AAAA-NNNNNN à l'enregistrement (RG-02). Une dépense ne se
 * supprime pas : elle s'annule avec un motif, garde son numéro et sort des totaux.
 */
#[ORM\Entity(repositoryClass: DepenseRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_depense_numero', columns: ['pharmacie_id', 'numero'])]
#[ORM\Index(name: 'idx_depense_date', columns: ['pharmacie_id', 'date'])]
class Depense implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Nom du fichier justificatif (photo ou PDF), rangé hors du dossier public. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $justificatif = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $annuleeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $annuleePar = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifAnnulation = null;

    public function __construct(
        #[ORM\Column(length: 30)]
        private string $numero,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $date,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private CategorieDepense $categorie,
        #[ORM\Column(length: 150)]
        private string $libelle,
        #[ORM\Column]
        private int $montant,
        #[ORM\Column(length: 20, enumType: ModeReglement::class)]
        private ModeReglement $mode,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $beneficiaire,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Utilisateur $creePar,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $creeLe,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getCategorie(): CategorieDepense
    {
        return $this->categorie;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function getMode(): ModeReglement
    {
        return $this->mode;
    }

    public function getBeneficiaire(): ?string
    {
        return $this->beneficiaire;
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
     * @internal réservé à {@see \App\Finance\GestionDepenses}
     */
    public function modifier(\DateTimeImmutable $date, CategorieDepense $categorie, string $libelle, int $montant, ModeReglement $mode, ?string $beneficiaire): void
    {
        $this->date = $date;
        $this->categorie = $categorie;
        $this->libelle = $libelle;
        $this->montant = $montant;
        $this->mode = $mode;
        $this->beneficiaire = $beneficiaire;
    }

    public function getJustificatif(): ?string
    {
        return $this->justificatif;
    }

    public function setJustificatif(?string $justificatif): void
    {
        $this->justificatif = $justificatif;
    }

    public function justificatifEstPdf(): bool
    {
        return null !== $this->justificatif && str_ends_with($this->justificatif, '.pdf');
    }

    public function estAnnulee(): bool
    {
        return null !== $this->annuleeLe;
    }

    /**
     * @internal réservé à {@see \App\Finance\GestionDepenses}
     */
    public function annuler(\DateTimeImmutable $le, ?Utilisateur $par, string $motif): void
    {
        $this->annuleeLe = $le;
        $this->annuleePar = $par;
        $this->motifAnnulation = $motif;
    }

    public function getAnnuleeLe(): ?\DateTimeImmutable
    {
        return $this->annuleeLe;
    }

    public function getAnnuleePar(): ?Utilisateur
    {
        return $this->annuleePar;
    }

    public function getMotifAnnulation(): ?string
    {
        return $this->motifAnnulation;
    }

    /**
     * Valeurs suivies par le journal d'audit.
     *
     * @return array<string, mixed>
     */
    public function etat(): array
    {
        return [
            'date' => $this->date->format('Y-m-d'),
            'categorie' => $this->categorie->getNom(),
            'libelle' => $this->libelle,
            'montant' => $this->montant,
            'mode' => $this->mode->value,
            'beneficiaire' => $this->beneficiaire,
        ];
    }
}
