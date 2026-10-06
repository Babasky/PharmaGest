<?php

namespace App\Entity;

use App\Referentiel\ArchivableTrait;
use App\Repository\CategorieDepenseRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Catégorie de dépense de la pharmacie (FI-02). La liste part des catégories proposées par la plateforme
 * ({@see CategorieDepenseModele}) et s'adapte ensuite : ajout, archivage (RG-15).
 */
#[UniqueEntity(fields: ['nom'], message: 'Cette catégorie existe déjà.')]
#[ORM\Entity(repositoryClass: CategorieDepenseRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_categorie_depense_nom', columns: ['pharmacie_id', 'nom'])]
class CategorieDepense implements TenantAwareInterface
{
    use ArchivableTrait;
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le nom de la catégorie est obligatoire.')]
    #[Assert\Length(max: 100)]
    private string $nom;

    public function __construct(string $nom)
    {
        $this->nom = trim($nom);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function __toString(): string
    {
        return $this->nom;
    }
}
