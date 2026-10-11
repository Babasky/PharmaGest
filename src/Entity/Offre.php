<?php

namespace App\Entity;

use App\Repository\OffreRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Offre commerciale (Essentiel, Standard, Premium). Les limites se règlent ici, sans toucher au code.
 */
#[ORM\Entity(repositoryClass: OffreRepository::class)]
class Offre
{
    public const ESSENTIEL = 'essentiel';
    public const STANDARD = 'standard';
    public const PREMIUM = 'premium';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, unique: true)]
    private string $code;

    #[ORM\Column(length: 60)]
    private string $nom;

    /** Nombre maximum d'utilisateurs actifs par pharmacie (propriétaire compris). Null = illimité. */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'Au moins un utilisateur (laisser vide pour illimité).')]
    private ?int $maxUtilisateurs;

    /** Nombre maximum de pharmacies pour un même propriétaire. */
    #[ORM\Column]
    #[Assert\Positive(message: 'Au moins une pharmacie.')]
    private int $maxPharmacies;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $fonctions = [];

    /** Tarif annuel en FCFA ; null tant qu'il n'est pas fixé. */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le tarif ne peut pas être négatif.')]
    private ?int $tarifAnnuel = null;

    #[ORM\Column]
    private int $ordre = 0;

    public function __construct(string $code, string $nom, ?int $maxUtilisateurs, int $maxPharmacies)
    {
        $this->code = $code;
        $this->nom = $nom;
        $this->maxUtilisateurs = $maxUtilisateurs;
        $this->maxPharmacies = $maxPharmacies;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getMaxUtilisateurs(): ?int
    {
        return $this->maxUtilisateurs;
    }

    /**
     * Une baisse ne désactive personne : elle bloque seulement les nouveaux comptes au-delà de la limite.
     */
    public function setMaxUtilisateurs(?int $maxUtilisateurs): static
    {
        $this->maxUtilisateurs = $maxUtilisateurs;

        return $this;
    }

    public function getMaxPharmacies(): int
    {
        return $this->maxPharmacies;
    }

    /** Un champ vidé donne 0, refusé par la validation. */
    public function setMaxPharmacies(?int $maxPharmacies): static
    {
        $this->maxPharmacies = $maxPharmacies ?? 0;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getFonctions(): array
    {
        return $this->fonctions;
    }

    /**
     * @param list<string> $fonctions
     */
    public function setFonctions(array $fonctions): static
    {
        $this->fonctions = $fonctions;

        return $this;
    }

    public function getTarifAnnuel(): ?int
    {
        return $this->tarifAnnuel;
    }

    public function setTarifAnnuel(?int $tarifAnnuel): static
    {
        $this->tarifAnnuel = $tarifAnnuel;

        return $this;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom;
    }
}
