<?php

namespace App\Entity;

use App\Referentiel\ArchivableTrait;
use App\Repository\EntrepotRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrepôt ou magasin de stockage de la pharmacie (Modibo, 2026-10-22). Une pharmacie peut n'en avoir aucun, un ou
 * plusieurs. Son stock est séparé de celui de l'officine : on n'y vend pas, on en fait venir les produits par un
 * {@see TransfertInterne}. Les lots qui s'y trouvent portent l'entrepôt ({@see Lot::getEntrepot()}).
 */
#[UniqueEntity(fields: ['nom'], message: 'Un entrepôt porte déjà ce nom.')]
#[ORM\Entity(repositoryClass: EntrepotRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_entrepot_nom', columns: ['pharmacie_id', 'nom'])]
class Entrepot implements TenantAwareInterface
{
    use ArchivableTrait;
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le nom de l\'entrepôt est obligatoire.')]
    #[Assert\Length(max: 100)]
    private string $nom = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $adresse = null;

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

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = null === $adresse || '' === trim($adresse) ? null : trim($adresse);

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom;
    }
}
