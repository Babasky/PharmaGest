<?php

namespace App\Stock;

use App\Entity\Lot;

/**
 * Quantité prise dans un lot lors d'une sortie FEFO. Le prix d'achat du lot sert au calcul de la marge (RG-17).
 */
final class Prelevement
{
    public function __construct(
        public readonly Lot $lot,
        public readonly int $quantite,
    ) {
    }

    public function getPrixAchat(): int
    {
        return $this->lot->getPrixAchat();
    }
}
