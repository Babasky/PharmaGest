<?php

namespace App\Entity;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Produit reçu dans une livraison : le lot créé porte le numéro, la péremption, la quantité et le prix réel (CO-06).
 */
#[ORM\Entity]
class LigneReception implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Quantité reçue, figée (le lot, lui, se vide au fil des ventes). */
    #[ORM\Column]
    private int $quantite;

    /** Prix d'achat unitaire réel, en FCFA. */
    #[ORM\Column]
    private int $prixAchat;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'lignes')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Reception $reception,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private LigneCommande $ligneCommande,
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'RESTRICT')]
        private Lot $lot,
    ) {
        $this->quantite = $lot->getQuantiteInitiale();
        $this->prixAchat = $lot->getPrixAchat();
        if (null !== $reception->getPharmacie()) {
            $this->setPharmacie($reception->getPharmacie());
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReception(): Reception
    {
        return $this->reception;
    }

    public function getLigneCommande(): LigneCommande
    {
        return $this->ligneCommande;
    }

    public function getLot(): Lot
    {
        return $this->lot;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function getPrixAchat(): int
    {
        return $this->prixAchat;
    }

    public function getMontant(): int
    {
        return $this->quantite * $this->prixAchat;
    }
}
