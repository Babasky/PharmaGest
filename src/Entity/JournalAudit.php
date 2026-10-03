<?php

namespace App\Entity;

use App\Repository\JournalAuditRepository;
use App\Tenant\TenantOptionnelInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace d'une action sensible (AU-01). Écrite une fois, jamais modifiée ni supprimée
 * ({@see \App\Doctrine\JournalAuditImmuableListener}).
 *
 * Pharmacie vide = action de la plateforme (visible du seul super admin).
 */
#[ORM\Entity(repositoryClass: JournalAuditRepository::class, readOnly: true)]
#[ORM\Index(name: 'idx_audit_date', columns: ['date'])]
class JournalAudit implements TenantOptionnelInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Pharmacie $pharmacie = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /**
     * @param array<string, mixed>|null $avant
     * @param array<string, mixed>|null $apres
     */
    public function __construct(
        #[ORM\Column(length: 60)]
        private string $action,
        ?Pharmacie $pharmacie,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Utilisateur $utilisateur,
        #[ORM\Column(length: 60, nullable: true)]
        private ?string $entite = null,
        #[ORM\Column(nullable: true)]
        private ?int $entiteId = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $avant = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $apres = null,
        #[ORM\Column(length: 45, nullable: true)]
        private ?string $adresseIp = null,
    ) {
        $this->pharmacie = $pharmacie;
        $this->date = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPharmacie(): ?Pharmacie
    {
        return $this->pharmacie;
    }

    public function setPharmacie(Pharmacie $pharmacie): static
    {
        if (null !== $this->id) {
            throw new \LogicException("Une entrée du journal d'audit ne se modifie pas.");
        }
        $this->pharmacie = $pharmacie;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function getEntite(): ?string
    {
        return $this->entite;
    }

    public function getEntiteId(): ?int
    {
        return $this->entiteId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAvant(): ?array
    {
        return $this->avant;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getApres(): ?array
    {
        return $this->apres;
    }

    public function getAdresseIp(): ?string
    {
        return $this->adresseIp;
    }
}
