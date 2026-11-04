<?php

namespace App\Entity;

use App\Referentiel\ArchivableTrait;
use App\Repository\ZoneRangementRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Zone de rangement de la pharmacie (RF-02) : comptoir, réserve, réfrigérateur, vitrine… Chaque pharmacie a ses
 * propres zones ; les trois premières sont créées d'office ({@see \App\Stock\ZonesRangement}). Une étagère est rangée
 * dans une zone.
 */
#[UniqueEntity(fields: ['nom'], message: 'Une zone porte déjà ce nom.')]
#[ORM\Entity(repositoryClass: ZoneRangementRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_zone_rangement_nom', columns: ['pharmacie_id', 'nom'])]
class ZoneRangement implements TenantAwareInterface
{
    use ArchivableTrait;
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank(message: 'Donnez un nom à la zone (ex. Vitrine).')]
    #[Assert\Length(max: 60)]
    private string $nom = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = trim((string) $nom);

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom;
    }
}
