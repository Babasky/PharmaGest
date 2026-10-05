<?php

namespace App\Entity;

use App\Enum\ModePaiement;
use App\Repository\PaiementRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Règlement d'une vente dans un mode donné (VE-04). Une vente réglée en plusieurs modes a plusieurs paiements.
 * Pour les espèces, le montant remis par le client et la monnaie rendue sont conservés.
 */
#[ORM\Entity(repositoryClass: PaiementRepository::class)]
class Paiement implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'paiements')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Vente $vente,
        #[ORM\Column(length: 20, enumType: ModePaiement::class)]
        private ModePaiement $mode,
        /** Montant affecté à la vente. */
        #[ORM\Column]
        private int $montant,
        /** Référence de la transaction (Orange Money, Moov Money, carte). */
        #[ORM\Column(length: 60, nullable: true)]
        private ?string $reference = null,
        /** Espèces : somme remise par le client (≥ montant). */
        #[ORM\Column(nullable: true)]
        private ?int $montantRemis = null,
    ) {
        if (null !== $vente->getPharmacie()) {
            $this->setPharmacie($vente->getPharmacie());
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVente(): Vente
    {
        return $this->vente;
    }

    public function getMode(): ModePaiement
    {
        return $this->mode;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getMontantRemis(): ?int
    {
        return $this->montantRemis;
    }

    public function getMonnaieRendue(): int
    {
        return null === $this->montantRemis ? 0 : $this->montantRemis - $this->montant;
    }
}
