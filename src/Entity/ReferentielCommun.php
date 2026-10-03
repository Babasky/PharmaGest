<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Référentiel commun à toutes les pharmacies, géré par le super admin (SA-07).
 * Une valeur n'est jamais supprimée : elle est désactivée et disparaît des listes de choix (RG-15).
 */
#[ORM\MappedSuperclass]
abstract class ReferentielCommun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    protected string $nom = '';

    #[ORM\Column]
    protected bool $actif = true;

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

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom;
    }
}
