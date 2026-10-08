<?php

namespace App\Entity;

use App\Referentiel\ArchivableTrait;
use App\Repository\CategorieRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Catégorie de produits sur deux niveaux (RF-01) : une catégorie principale et ses sous-catégories.
 */
#[UniqueEntity(fields: ['parent', 'nom'], message: 'Cette catégorie existe déjà à ce niveau.', errorPath: 'nom', ignoreNull: false)]
#[ORM\Entity(repositoryClass: CategorieRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_categorie_nom', columns: ['pharmacie_id', 'parent_id', 'nom'])]
class Categorie implements TenantAwareInterface
{
    use ArchivableTrait;
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: "Ce champ ne doit pas être vide")]
    #[Assert\Length(max: 100)]
    private string $nom = '';

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Categorie $parent = null;

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

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function getNomComplet(): string
    {
        return null === $this->parent ? $this->nom : $this->parent->getNom().' › '.$this->nom;
    }

    #[Assert\Callback]
    public function validerNiveaux(ExecutionContextInterface $contexte): void
    {
        if (null === $this->parent) {
            return;
        }
        if ($this->parent === $this || null !== $this->parent->getParent()) {
            $contexte->buildViolation('Une sous-catégorie ne peut pas avoir elle-même de sous-catégorie (deux niveaux au maximum).')
                ->atPath('parent')
                ->addViolation();
        }
    }

    public function __toString(): string
    {
        return $this->getNomComplet();
    }
}
