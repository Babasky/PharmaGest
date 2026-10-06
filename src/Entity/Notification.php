<?php

namespace App\Entity;

use App\Enum\TypeNotification;
use App\Repository\NotificationRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Message du centre de notifications (NO-01), adressé à un utilisateur dans une pharmacie.
 *
 * La clé évite de répéter une alerte déjà signalée : une nouvelle notification n'est créée que si la situation
 * a changé (autres produits concernés, nouveau palier d'échéance…).
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Index(name: 'idx_notification_utilisateur', columns: ['utilisateur_id', 'pharmacie_id', 'lue_le'])]
#[ORM\UniqueConstraint(name: 'uniq_notification_cle', columns: ['utilisateur_id', 'pharmacie_id', 'cle'])]
class Notification implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lueLe = null;

    public function __construct(
        Pharmacie $pharmacie,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Utilisateur $utilisateur,
        #[ORM\Column(length: 20, enumType: TypeNotification::class)]
        private TypeNotification $type,
        #[ORM\Column(length: 255)]
        private string $message,
        /** Chemin de la page à ouvrir (relatif à l'application). */
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $lien,
        #[ORM\Column(length: 100)]
        private string $cle,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $creeLe,
    ) {
        $this->setPharmacie($pharmacie);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): Utilisateur
    {
        return $this->utilisateur;
    }

    public function getType(): TypeNotification
    {
        return $this->type;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getLien(): ?string
    {
        return $this->lien;
    }

    public function getCle(): string
    {
        return $this->cle;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getLueLe(): ?\DateTimeImmutable
    {
        return $this->lueLe;
    }

    public function isLue(): bool
    {
        return null !== $this->lueLe;
    }

    public function marquerLue(\DateTimeImmutable $le): void
    {
        $this->lueLe ??= $le;
    }
}
