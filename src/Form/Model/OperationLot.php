<?php

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ajustement (nouvelle quantité) ou destruction (quantité détruite) d'un lot, toujours motivé (AU-01).
 */
final class OperationLot
{
    #[Assert\NotNull(message: 'Indiquez la quantité.')]
    #[Assert\PositiveOrZero(message: 'La quantité ne peut pas être négative.')]
    public ?int $quantite = null;

    #[Assert\NotBlank(message: 'Le motif est obligatoire.')]
    #[Assert\Length(max: 255)]
    public ?string $motif = null;
}
