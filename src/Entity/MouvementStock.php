<?php

namespace App\Entity;

use App\Enum\TypeMouvement;
use App\Repository\MouvementStockRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Variation de la quantité d'un lot (ST-04). Écrit une fois, jamais modifié : c'est l'historique du stock.
 * La quantité est positive pour une entrée, négative pour une sortie.
 */
#[ORM\Entity(repositoryClass: MouvementStockRepository::class, readOnly: true)]
#[ORM\Index(name: 'idx_mouvement_date', columns: ['pharmacie_id', 'date'])]
#[ORM\Index(name: 'idx_mouvement_type', columns: ['pharmacie_id', 'type', 'date'])]
class MouvementStock implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Quantité restante du lot après le mouvement. */
    #[ORM\Column]
    private int $quantiteApres;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Lot $lot,
        #[ORM\Column(length: 30, enumType: TypeMouvement::class)]
        private TypeMouvement $type,
        #[ORM\Column]
        private int $quantite,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $date,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Utilisateur $utilisateur = null,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $motif = null,
        /** Numéro du document d'origine (inventaire, vente, réception…). */
        #[ORM\Column(length: 30, nullable: true)]
        private ?string $document = null,
    ) {
        $this->quantiteApres = $lot->getQuantiteRestante();
        if (null !== $lot->getPharmacie()) {
            $this->setPharmacie($lot->getPharmacie());
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLot(): Lot
    {
        return $this->lot;
    }

    public function getType(): TypeMouvement
    {
        return $this->type;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function getQuantiteApres(): int
    {
        return $this->quantiteApres;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function getDocument(): ?string
    {
        return $this->document;
    }
}
