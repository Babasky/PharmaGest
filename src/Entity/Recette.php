<?php

namespace App\Entity;

use App\Enum\ModeReglement;
use App\Enum\OrigineRecette;
use App\Repository\RecetteRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Argent entré dans la pharmacie (FI-04, FI-05, RG-10) : le montant encaissé de chaque vente, par mode de
 * paiement, chaque règlement AMO, et les recettes manuelles. Une recette n'est jamais modifiée ni supprimée :
 * son annulation crée une contre-passation de même montant en négatif.
 */
#[ORM\Entity(repositoryClass: RecetteRepository::class)]
#[ORM\Index(name: 'idx_recette_date', columns: ['pharmacie_id', 'date'])]
class Recette implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Vente $vente = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?ReglementAmo $reglementAmo = null;

    /** Recette annulée par cette contre-passation. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Recette $annule = null;

    /** Recette manuelle : date de son annulation. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $annuleeLe = null;

    public function __construct(
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $date,
        #[ORM\Column(length: 20, enumType: OrigineRecette::class)]
        private OrigineRecette $origine,
        #[ORM\Column(length: 150)]
        private string $libelle,
        /** Négatif pour une contre-passation. */
        #[ORM\Column]
        private int $montant,
        #[ORM\Column(length: 20, enumType: ModeReglement::class)]
        private ModeReglement $mode,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Utilisateur $creePar,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $creeLe,
    ) {
    }

    public static function pourVente(Vente $vente, ModeReglement $mode, int $montant, \DateTimeImmutable $le): self
    {
        $recette = new self($le->setTime(0, 0), OrigineRecette::Vente, 'Vente '.$vente->getNumero(), $montant, $mode, $vente->getVendeur(), $le);
        $recette->vente = $vente;
        if (null !== $vente->getPharmacie()) {
            $recette->setPharmacie($vente->getPharmacie());
        }

        return $recette;
    }

    public static function pourReglementAmo(ReglementAmo $reglement, ?Utilisateur $par, \DateTimeImmutable $le): self
    {
        $bordereau = $reglement->getBordereau();
        $libelle = \sprintf('Règlement AMO %s (%s)', $bordereau->getNumero(), $bordereau->getOrganisme()->getCode());
        $recette = new self($reglement->getDate(), OrigineRecette::Amo, $libelle, $reglement->getMontant(), ModeReglement::Virement, $par, $le);
        $recette->reglementAmo = $reglement;
        if (null !== $reglement->getPharmacie()) {
            $recette->setPharmacie($reglement->getPharmacie());
        }

        return $recette;
    }

    /**
     * Contre-passation (montant opposé, même mode, même document d'origine) datée du jour de l'annulation.
     */
    public function contrePasser(string $libelle, ?Utilisateur $par, \DateTimeImmutable $le): self
    {
        $contre = new self($le->setTime(0, 0), OrigineRecette::ContrePassation, mb_substr($libelle, 0, 150), -$this->montant, $this->mode, $par, $le);
        $contre->vente = $this->vente;
        $contre->reglementAmo = $this->reglementAmo;
        $contre->annule = $this;
        if (null !== $this->getPharmacie()) {
            $contre->setPharmacie($this->getPharmacie());
        }
        $this->annuleeLe = $le;

        return $contre;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getOrigine(): OrigineRecette
    {
        return $this->origine;
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

    public function getVente(): ?Vente
    {
        return $this->vente;
    }

    public function getReglementAmo(): ?ReglementAmo
    {
        return $this->reglementAmo;
    }

    public function getAnnule(): ?self
    {
        return $this->annule;
    }

    public function getAnnuleeLe(): ?\DateTimeImmutable
    {
        return $this->annuleeLe;
    }

    public function estAnnulee(): bool
    {
        return null !== $this->annuleeLe;
    }

    public function getCreePar(): ?Utilisateur
    {
        return $this->creePar;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
