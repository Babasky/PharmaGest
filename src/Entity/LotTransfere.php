<?php

namespace App\Entity;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lot sorti de l'officine d'origine à l'expédition. Numéro, date de péremption et prix d'achat sont copiés :
 * l'officine destinataire recrée le même lot à la réception, sans lire les données de l'origine.
 */
#[ORM\Entity]
class LotTransfere implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $numero;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $datePeremption;

    #[ORM\Column]
    private int $prixAchat;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'lots')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private LigneTransfert $ligne,
        /** Lot de l'officine d'origine. */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Lot $lot,
        #[ORM\Column]
        private int $quantite,
    ) {
        $this->numero = $lot->getNumero();
        $this->datePeremption = $lot->getDatePeremption();
        $this->prixAchat = $lot->getPrixAchat();
        if (null !== $ligne->getPharmacie()) {
            $this->setPharmacie($ligne->getPharmacie());
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLigne(): LigneTransfert
    {
        return $this->ligne;
    }

    public function getLot(): Lot
    {
        return $this->lot;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function getDatePeremption(): ?\DateTimeImmutable
    {
        return $this->datePeremption;
    }

    public function getPrixAchat(): int
    {
        return $this->prixAchat;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function getValeur(): int
    {
        return $this->quantite * $this->prixAchat;
    }
}
