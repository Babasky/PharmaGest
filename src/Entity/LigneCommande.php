<?php

namespace App\Entity;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Produit commandé (CO-01) : quantité, prix d'achat unitaire estimé, et quantité déjà reçue (CO-06).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_ligne_commande_produit', columns: ['commande_id', 'produit_id'])]
class LigneCommande implements TenantAwareInterface
{
    use TenantAwareTrait;

    public const QUANTITE_MAXIMALE = 100_000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Cumul des réceptions ; peut dépasser la quantité commandée (RG-16). */
    #[ORM\Column]
    private int $quantiteRecue = 0;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'lignes')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Commande $commande,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private Produit $produit,
        #[ORM\Column]
        private int $quantite,
        /** Prix d'achat unitaire estimé, en FCFA. */
        #[ORM\Column]
        private int $prixEstime,
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

    public function getProduit(): Produit
    {
        return $this->produit;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function getPrixEstime(): int
    {
        return $this->prixEstime;
    }

    /** @internal réservé à {@see \App\Achat\CommandeService} (brouillon seulement) */
    public function modifier(int $quantite, int $prixEstime): void
    {
        $this->quantite = $quantite;
        $this->prixEstime = $prixEstime;
    }

    public function getMontantEstime(): int
    {
        return $this->quantite * $this->prixEstime;
    }

    public function getQuantiteRecue(): int
    {
        return $this->quantiteRecue;
    }

    /** Quantité encore attendue. */
    public function getReste(): int
    {
        return max(0, $this->quantite - $this->quantiteRecue);
    }

    /** @internal réservé à {@see \App\Achat\ReceptionService} */
    public function recevoir(int $quantite): void
    {
        $this->quantiteRecue += $quantite;
    }
}
