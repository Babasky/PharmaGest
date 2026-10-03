<?php

namespace App\Entity;

use App\Enum\ZoneEtagere;
use App\Referentiel\ArchivableTrait;
use App\Repository\EtagereRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Emplacement de rangement (RF-02), affiché à la caisse pour retrouver vite un produit.
 */
#[UniqueEntity(fields: ['code'], message: 'Une étagère porte déjà ce code.')]
#[ORM\Entity(repositoryClass: EtagereRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_etagere_code', columns: ['pharmacie_id', 'code'])]
class Etagere implements TenantAwareInterface
{
    use ArchivableTrait;
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(message: 'Le code est obligatoire (ex. E1-R3).')]
    #[Assert\Length(max: 20)]
    private string $code = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $libelle = '';

    #[ORM\Column(length: 20, enumType: ZoneEtagere::class)]
    private ZoneEtagere $zone = ZoneEtagere::Comptoir;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = mb_strtoupper(trim($code));

        return $this;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = trim($libelle);

        return $this;
    }

    public function getZone(): ZoneEtagere
    {
        return $this->zone;
    }

    public function setZone(ZoneEtagere $zone): static
    {
        $this->zone = $zone;

        return $this;
    }

    public function __toString(): string
    {
        return $this->code.' — '.$this->libelle;
    }
}
