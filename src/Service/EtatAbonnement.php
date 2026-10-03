<?php

namespace App\Service;

use App\Enum\StatutAbonnement;

/**
 * Photographie de l'abonnement d'une pharmacie à une date donnée.
 */
final class EtatAbonnement
{
    public function __construct(
        public readonly StatutAbonnement $statut,
        /** Date de fin de l'abonnement payé, ou de l'essai s'il n'y a jamais eu de paiement. */
        public readonly ?\DateTimeImmutable $echeance,
        /** Jours restants avant l'échéance (négatif une fois dépassée). */
        public readonly ?int $joursRestants,
    ) {
    }

    /** Jours restants avant la fin de la période de grâce. */
    public function joursDeGraceRestants(): ?int
    {
        return null === $this->joursRestants ? null : $this->joursRestants + AbonnementService::JOURS_DE_GRACE;
    }
}
