<?php

namespace App\Entity;

use App\Repository\TauxAmoRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Taux de prise en charge AMO d'un organisme pour une pharmacie, à partir d'une date d'effet (hypothèse H1).
 * Un changement de taux crée une nouvelle ligne : l'historique est conservé, les ventes passées gardent
 * leur taux figé (RG-06).
 */
#[ORM\Entity(repositoryClass: TauxAmoRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_taux_amo', columns: ['pharmacie_id', 'organisme_id', 'date_effet'])]
class TauxAmo implements TenantAwareInterface
{
    use TenantAwareTrait;

    public const TAUX_PAR_DEFAUT = 70;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: "Choisissez l'organisme.")]
    private ?OrganismeAmo $organisme = null;

    /** Part prise en charge par l'organisme, en pourcentage entier. */
    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\Range(min: 0, max: 100)]
    private ?int $taux = self::TAUX_PAR_DEFAUT;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $dateEffet = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganisme(): ?OrganismeAmo
    {
        return $this->organisme;
    }

    public function setOrganisme(?OrganismeAmo $organisme): static
    {
        $this->organisme = $organisme;

        return $this;
    }

    public function getTaux(): ?int
    {
        return $this->taux;
    }

    public function setTaux(?int $taux): static
    {
        $this->taux = $taux;

        return $this;
    }

    public function getDateEffet(): ?\DateTimeImmutable
    {
        return $this->dateEffet;
    }

    public function setDateEffet(?\DateTimeImmutable $dateEffet): static
    {
        $this->dateEffet = $dateEffet?->setTime(0, 0);

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
