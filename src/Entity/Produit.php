<?php

namespace App\Entity;

use App\Referentiel\ArchivableTrait;
use App\Repository\ProduitRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Produit du catalogue de la pharmacie (RF-03). Les prix sont en FCFA entiers (RG-01).
 * Le stock n'est pas stocké ici : il se calcule à partir des lots (Lot 3, RG-03).
 */
#[ORM\Entity(repositoryClass: ProduitRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_produit_code_barres', columns: ['pharmacie_id', 'code_barres'])]
#[ORM\Index(name: 'idx_produit_nom', columns: ['pharmacie_id', 'nom_commercial'])]
#[ORM\Index(name: 'idx_produit_dci', columns: ['pharmacie_id', 'dci'])]
#[UniqueEntity(fields: ['codeBarres'], message: 'Un produit porte déjà ce code-barres.')]
class Produit implements TenantAwareInterface
{
    use ArchivableTrait;
    use TenantAwareTrait;

    /** Taux de TVA proposés (point ouvert § 11.2 : médicaments exonérés, parapharmacie à 18 %). */
    public const TAUX_TVA = ['Exonéré (0 %)' => 0, '18 %' => 18];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Le nom commercial est obligatoire.')]
    #[Assert\Length(max: 150)]
    private string $nomCommercial = '';

    /** Dénomination commune internationale (molécule). */
    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $dci = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?FormeGalenique $forme = null;

    #[ORM\Column(length: 60, nullable: true)]
    #[Assert\Length(max: 60)]
    private ?string $dosage = null;

    /** Ex. « Boîte de 30 comprimés ». */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $conditionnement = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    #[Assert\Regex('/^[0-9A-Za-z\-]+$/', message: 'Le code-barres ne contient que des chiffres, des lettres et des tirets.')]
    private ?string $codeBarres = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Categorie $categorie = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Etagere $etagere = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Fournisseur $fournisseurHabituel = null;

    /** Prix d'achat de référence, en FCFA (le prix réel est porté par chaque lot). */
    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private ?int $prixAchat = 0;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Le prix de vente est obligatoire.')]
    #[Assert\Positive(message: 'Le prix de vente doit être supérieur à zéro.')]
    private ?int $prixVente = null;

    /** En dessous de ce stock, le produit est signalé en rupture (ST-05). */
    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private ?int $seuilAlerte = 0;

    /** Sert à la suggestion de commande : quantité proposée = stock maximum − stock actuel (CO-02). */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    #[Assert\GreaterThanOrEqual(propertyPath: 'seuilAlerte', message: 'Le stock maximum doit être supérieur ou égal au seuil d\'alerte.')]
    private ?int $stockMax = null;

    #[ORM\Column]
    private bool $ordonnanceObligatoire = false;

    #[ORM\Column]
    private bool $remboursableAmo = false;

    /**
     * Prix de vente fixé par l'AMO pour ce médicament, sur lequel s'applique le taux de prise en charge d'un
     * organisme AMO. Vide : le prix de vente de la pharmacie sert de base.
     */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'Le prix de vente AMO doit être positif.')]
    #[Assert\LessThanOrEqual(100_000_000)]
    private ?int $prixVenteAmo = null;

    #[ORM\Column]
    #[Assert\Choice(choices: self::TAUX_TVA)]
    private int $tauxTva = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNomCommercial(): string
    {
        return $this->nomCommercial;
    }

    public function setNomCommercial(string $nomCommercial): static
    {
        $this->nomCommercial = trim($nomCommercial);

        return $this;
    }

    public function getDci(): ?string
    {
        return $this->dci;
    }

    public function setDci(?string $dci): static
    {
        $this->dci = self::nettoyer($dci);

        return $this;
    }

    public function getForme(): ?FormeGalenique
    {
        return $this->forme;
    }

    public function setForme(?FormeGalenique $forme): static
    {
        $this->forme = $forme;

        return $this;
    }

    public function getDosage(): ?string
    {
        return $this->dosage;
    }

    public function setDosage(?string $dosage): static
    {
        $this->dosage = self::nettoyer($dosage);

        return $this;
    }

    public function getConditionnement(): ?string
    {
        return $this->conditionnement;
    }

    public function setConditionnement(?string $conditionnement): static
    {
        $this->conditionnement = self::nettoyer($conditionnement);

        return $this;
    }

    public function getCodeBarres(): ?string
    {
        return $this->codeBarres;
    }

    public function setCodeBarres(?string $codeBarres): static
    {
        $this->codeBarres = null === $codeBarres ? null : self::nettoyer(str_replace(' ', '', $codeBarres));

        return $this;
    }

    public function getCategorie(): ?Categorie
    {
        return $this->categorie;
    }

    public function setCategorie(?Categorie $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getEtagere(): ?Etagere
    {
        return $this->etagere;
    }

    public function setEtagere(?Etagere $etagere): static
    {
        $this->etagere = $etagere;

        return $this;
    }

    public function getFournisseurHabituel(): ?Fournisseur
    {
        return $this->fournisseurHabituel;
    }

    public function setFournisseurHabituel(?Fournisseur $fournisseurHabituel): static
    {
        $this->fournisseurHabituel = $fournisseurHabituel;

        return $this;
    }

    public function getPrixAchat(): ?int
    {
        return $this->prixAchat;
    }

    public function setPrixAchat(?int $prixAchat): static
    {
        $this->prixAchat = $prixAchat;

        return $this;
    }

    public function getPrixVente(): ?int
    {
        return $this->prixVente;
    }

    public function setPrixVente(?int $prixVente): static
    {
        $this->prixVente = $prixVente;

        return $this;
    }

    public function getSeuilAlerte(): ?int
    {
        return $this->seuilAlerte;
    }

    public function setSeuilAlerte(?int $seuilAlerte): static
    {
        $this->seuilAlerte = $seuilAlerte;

        return $this;
    }

    public function getStockMax(): ?int
    {
        return $this->stockMax;
    }

    public function setStockMax(?int $stockMax): static
    {
        $this->stockMax = $stockMax;

        return $this;
    }

    public function isOrdonnanceObligatoire(): bool
    {
        return $this->ordonnanceObligatoire;
    }

    public function setOrdonnanceObligatoire(bool $ordonnanceObligatoire): static
    {
        $this->ordonnanceObligatoire = $ordonnanceObligatoire;

        return $this;
    }

    public function isRemboursableAmo(): bool
    {
        return $this->remboursableAmo;
    }

    public function setRemboursableAmo(bool $remboursableAmo): static
    {
        $this->remboursableAmo = $remboursableAmo;

        return $this;
    }

    public function getPrixVenteAmo(): ?int
    {
        return $this->prixVenteAmo;
    }

    public function setPrixVenteAmo(?int $prixVenteAmo): static
    {
        $this->prixVenteAmo = $prixVenteAmo;

        return $this;
    }

    public function getTauxTva(): int
    {
        return $this->tauxTva;
    }

    public function setTauxTva(int $tauxTva): static
    {
        $this->tauxTva = $tauxTva;

        return $this;
    }

    /** Ex. « Doliprane — Paracétamol 500 mg, comprimé ». */
    public function getDesignation(): string
    {
        $details = trim(implode(' ', array_filter([$this->dci, $this->dosage])));
        if (null !== $this->forme) {
            $details = '' === $details ? $this->forme->getNom() : $details.', '.mb_strtolower($this->forme->getNom());
        }

        return '' === $details ? $this->nomCommercial : $this->nomCommercial.' — '.$details;
    }

    /** Marge brute de référence en FCFA (prix de vente − prix d'achat). */
    public function getMargeReference(): int
    {
        return (int) $this->prixVente - (int) $this->prixAchat;
    }

    public function __toString(): string
    {
        return $this->getDesignation();
    }

    private static function nettoyer(?string $valeur): ?string
    {
        return null === $valeur || '' === trim($valeur) ? null : trim($valeur);
    }
}
