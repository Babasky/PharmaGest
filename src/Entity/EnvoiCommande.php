<?php

namespace App\Entity;

use App\Enum\StatutEnvoi;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Envoi d'une commande par email au fournisseur, réussi ou non (historique CO-05). Écrit une fois, jamais modifié.
 */
#[ORM\Entity(readOnly: true)]
class EnvoiCommande implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'envois')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Commande $commande,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $date,
        #[ORM\Column(length: 180)]
        private string $destinataire,
        #[ORM\Column(length: 10, enumType: StatutEnvoi::class)]
        private StatutEnvoi $statut,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Utilisateur $envoyePar = null,
        /** Cause de l'échec, telle que renvoyée par le serveur d'envoi. */
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $erreur = null,
    ) {
        if (null !== $commande->getPharmacie()) {
            $this->setPharmacie($commande->getPharmacie());
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommande(): Commande
    {
        return $this->commande;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getDestinataire(): string
    {
        return $this->destinataire;
    }

    public function getStatut(): StatutEnvoi
    {
        return $this->statut;
    }

    public function getEnvoyePar(): ?Utilisateur
    {
        return $this->envoyePar;
    }

    public function getErreur(): ?string
    {
        return $this->erreur;
    }
}
