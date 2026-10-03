<?php

namespace App\Entity;

use App\Repository\PharmacieRepository;
use App\Util\Telephone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Pharmacie cliente : c'est le tenant. Elle n'est pas elle-même filtrée.
 */
#[ORM\Entity(repositoryClass: PharmacieRepository::class)]
#[ORM\Index(name: 'idx_pharmacie_fin_abonnement', columns: ['fin_abonnement'])]
class Pharmacie
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $nom = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $ville = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $adresse = '';

    /** Stocké normalisé : +223XXXXXXXX. */
    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    private string $telephone = '';

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank(message: "Le numéro d'autorisation est obligatoire.")]
    #[Assert\Length(max: 60)]
    private string $numeroAutorisation = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Offre $offre;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finEssai = null;

    /** Date de fin du dernier abonnement payé (dénormalisée pour les listes et tableaux de bord). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finAbonnement = null;

    #[ORM\Column]
    private bool $suspendue = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifSuspension = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archiveeLe = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    public function __construct(Offre $offre)
    {
        $this->offre = $offre;
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = trim($nom);

        return $this;
    }

    public function getVille(): string
    {
        return $this->ville;
    }

    public function setVille(string $ville): static
    {
        $this->ville = trim($ville);

        return $this;
    }

    public function getAdresse(): string
    {
        return $this->adresse;
    }

    public function setAdresse(string $adresse): static
    {
        $this->adresse = trim($adresse);

        return $this;
    }

    public function getTelephone(): string
    {
        return $this->telephone;
    }

    public function setTelephone(string $telephone): static
    {
        $this->telephone = Telephone::normaliser($telephone) ?? trim($telephone);

        return $this;
    }

    #[Assert\Callback]
    public function validerTelephone(ExecutionContextInterface $contexte): void
    {
        if ('' !== $this->telephone && null === Telephone::normaliser($this->telephone)) {
            $contexte->buildViolation('Numéro de téléphone malien attendu : +223 XX XX XX XX.')
                ->atPath('telephone')
                ->addViolation();
        }
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email ? mb_strtolower(trim($email)) : null;

        return $this;
    }

    public function getNumeroAutorisation(): string
    {
        return $this->numeroAutorisation;
    }

    public function setNumeroAutorisation(string $numeroAutorisation): static
    {
        $this->numeroAutorisation = trim($numeroAutorisation);

        return $this;
    }

    public function getOffre(): Offre
    {
        return $this->offre;
    }

    public function setOffre(Offre $offre): static
    {
        $this->offre = $offre;

        return $this;
    }

    public function getFinEssai(): ?\DateTimeImmutable
    {
        return $this->finEssai;
    }

    public function setFinEssai(?\DateTimeImmutable $finEssai): static
    {
        $this->finEssai = $finEssai;

        return $this;
    }

    public function getFinAbonnement(): ?\DateTimeImmutable
    {
        return $this->finAbonnement;
    }

    public function setFinAbonnement(?\DateTimeImmutable $finAbonnement): static
    {
        $this->finAbonnement = $finAbonnement;

        return $this;
    }

    public function isSuspendue(): bool
    {
        return $this->suspendue;
    }

    public function getMotifSuspension(): ?string
    {
        return $this->motifSuspension;
    }

    public function suspendre(string $motif): static
    {
        $this->suspendue = true;
        $this->motifSuspension = $motif;

        return $this;
    }

    public function reactiver(): static
    {
        $this->suspendue = false;
        $this->motifSuspension = null;
        $this->archiveeLe = null;

        return $this;
    }

    public function getArchiveeLe(): ?\DateTimeImmutable
    {
        return $this->archiveeLe;
    }

    public function isArchivee(): bool
    {
        return null !== $this->archiveeLe;
    }

    public function archiver(\DateTimeImmutable $le): static
    {
        $this->archiveeLe = $le;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function __toString(): string
    {
        return $this->nom;
    }
}
