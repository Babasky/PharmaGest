<?php

namespace App\Entity;

use App\Repository\OrdonnanceRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ordonnance présentée pour une vente « ordonnance classique » ou « ordonnance AMO » (AM-01).
 * Sa copie (photo ou scan) est jointe en annexe des bordereaux AMO (AM-07).
 */
#[ORM\Entity(repositoryClass: OrdonnanceRepository::class)]
class Ordonnance implements TenantAwareInterface
{
    use TenantAwareTrait;

    /** À partir de ce nombre de jours entre prescription et présentation, la caisse signale une ordonnance AMO ancienne. */
    public const JOURS_ANCIENNE = 7;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $numero = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $prescripteur = null;

    /** Structure de santé (CSCOM, CSRéf, hôpital, clinique…). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $structure = null;

    /** Nom du fichier de la copie dans le stockage de la pharmacie ({@see \App\Amo\CopieOrdonnance}). */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $copie = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(?string $numero): static
    {
        $this->numero = self::nettoyer($numero, 50);

        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getPrescripteur(): ?string
    {
        return $this->prescripteur;
    }

    public function setPrescripteur(?string $prescripteur): static
    {
        $this->prescripteur = self::nettoyer($prescripteur, 120);

        return $this;
    }

    public function getStructure(): ?string
    {
        return $this->structure;
    }

    public function setStructure(?string $structure): static
    {
        $this->structure = self::nettoyer($structure, 120);

        return $this;
    }

    public function getCopie(): ?string
    {
        return $this->copie;
    }

    public function setCopie(?string $copie): static
    {
        $this->copie = $copie;

        return $this;
    }

    public function copieEstPdf(): bool
    {
        return null !== $this->copie && str_ends_with($this->copie, '.pdf');
    }

    /** Seule la date est obligatoire ; prescripteur, numéro et structure sont facultatifs. */
    public function estComplete(): bool
    {
        return null !== $this->date;
    }

    /** Nombre de jours entre la date de prescription et le jour donné (date de présentation), null sans date. */
    public function joursDepuisPrescription(\DateTimeInterface $jour): ?int
    {
        if (null === $this->date) {
            return null;
        }
        // Jours calendaires, comparés en UTC pour ne pas dépendre des fuseaux horaires.
        $utc = new \DateTimeZone('UTC');
        $prescription = new \DateTimeImmutable($this->date->format('Y-m-d'), $utc);
        $presentation = new \DateTimeImmutable($jour->format('Y-m-d'), $utc);

        return (int) $prescription->diff($presentation)->format('%r%a');
    }

    private static function nettoyer(?string $valeur, int $longueur): ?string
    {
        return null === $valeur || '' === trim($valeur) ? null : mb_substr(trim($valeur), 0, $longueur);
    }
}
