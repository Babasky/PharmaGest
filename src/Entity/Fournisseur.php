<?php

namespace App\Entity;

use App\Referentiel\ArchivableTrait;
use App\Repository\FournisseurRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use App\Util\Telephone;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Fournisseur (RF-05) : PPM, grossistes répartiteurs….
 */
#[UniqueEntity(fields: ['nom'], message: 'Ce fournisseur existe déjà.')]
#[ORM\Entity(repositoryClass: FournisseurRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_fournisseur_nom', columns: ['pharmacie_id', 'nom'])]
class Fournisseur implements TenantAwareInterface
{
    use ArchivableTrait;
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $nom = '';

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $contact = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $adresse = null;

    /** Délai de livraison habituel, en jours. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 365)]
    private ?int $delaiLivraison = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $conditionsPaiement = null;

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

    public function getContact(): ?string
    {
        return $this->contact;
    }

    public function setContact(?string $contact): static
    {
        $this->contact = self::nettoyer($contact);

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $telephone = self::nettoyer($telephone);
        $this->telephone = null === $telephone ? null : (Telephone::normaliser($telephone) ?? $telephone);

        return $this;
    }

    #[Assert\Callback]
    public function validerTelephone(ExecutionContextInterface $contexte): void
    {
        if (null !== $this->telephone && null === Telephone::normaliser($this->telephone)) {
            $contexte->buildViolation('Numéro de téléphone malien attendu : +223 XX XX XX XX.')->atPath('telephone')->addViolation();
        }
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $email = self::nettoyer($email);
        $this->email = null === $email ? null : mb_strtolower($email);

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = self::nettoyer($adresse);

        return $this;
    }

    public function getDelaiLivraison(): ?int
    {
        return $this->delaiLivraison;
    }

    public function setDelaiLivraison(?int $delaiLivraison): static
    {
        $this->delaiLivraison = $delaiLivraison;

        return $this;
    }

    public function getConditionsPaiement(): ?string
    {
        return $this->conditionsPaiement;
    }

    public function setConditionsPaiement(?string $conditionsPaiement): static
    {
        $this->conditionsPaiement = self::nettoyer($conditionsPaiement);

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom;
    }

    private static function nettoyer(?string $valeur): ?string
    {
        return null === $valeur || '' === trim($valeur) ? null : trim($valeur);
    }
}
