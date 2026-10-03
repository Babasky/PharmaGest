<?php

namespace App\Entity;

use App\Repository\AffectationRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Accès d'un utilisateur à une pharmacie. Désactiver l'affectation retire l'accès à cette pharmacie.
 */
#[ORM\Entity(repositoryClass: AffectationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_affectation', columns: ['utilisateur_id', 'pharmacie_id'])]
class Affectation implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Utilisateur $utilisateur;

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    public function __construct(Utilisateur $utilisateur, ?Pharmacie $pharmacie = null)
    {
        $this->utilisateur = $utilisateur;
        if (null !== $pharmacie) {
            $this->setPharmacie($pharmacie);
        }
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): Utilisateur
    {
        return $this->utilisateur;
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

    /** Un compte désactivé globalement n'a plus accès, même si l'affectation est active. */
    public function isAccesOuvert(): bool
    {
        return $this->actif && $this->utilisateur->isActif();
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
