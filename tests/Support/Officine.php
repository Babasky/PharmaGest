<?php

namespace App\Tests\Support;

use App\Entity\Affectation;
use App\Entity\Pharmacie;
use App\Entity\Utilisateur;

/**
 * Une pharmacie de test avec son équipe complète.
 */
final class Officine
{
    public function __construct(
        public readonly Pharmacie $pharmacie,
        public readonly Utilisateur $proprietaire,
        public readonly Utilisateur $adjoint,
        public readonly Utilisateur $vendeur,
        public readonly Affectation $affectationAdjoint,
        public readonly Affectation $affectationVendeur,
    ) {
    }
}
