<?php

namespace App\Form\Model;

use App\Entity\Fournisseur;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrée de stock sans commande (stock initial, livraison hors commande) : crée un lot.
 */
final class EntreeStock
{
    #[Assert\NotBlank(message: 'Le numéro de lot est obligatoire.')]
    #[Assert\Length(max: 50)]
    public ?string $numero = null;

    #[Assert\NotNull(message: 'La date de péremption est obligatoire.')]
    #[Assert\GreaterThan('today', message: 'Ce lot est déjà périmé : il ne peut pas entrer en stock.')]
    public ?\DateTimeImmutable $datePeremption = null;

    #[Assert\NotNull(message: 'Indiquez la quantité.')]
    #[Assert\Positive(message: 'La quantité doit être supérieure à zéro.')]
    #[Assert\LessThanOrEqual(1000000)]
    public ?int $quantite = null;

    #[Assert\NotNull(message: 'Indiquez le prix d\'achat unitaire.')]
    #[Assert\PositiveOrZero]
    public ?int $prixAchat = null;

    public ?Fournisseur $fournisseur = null;

    #[Assert\Length(max: 255)]
    public ?string $motif = null;
}
