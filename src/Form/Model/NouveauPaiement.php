<?php

namespace App\Form\Model;

use App\Entity\Offre;
use App\Enum\MoyenPaiement;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Paiement d'abonnement saisi par le super admin (SA-02).
 */
final class NouveauPaiement
{
    #[Assert\NotNull(message: 'Choisissez l\'offre payée.')]
    public ?Offre $offre = null;

    #[Assert\NotNull(message: 'Indiquez le montant reçu.')]
    #[Assert\PositiveOrZero]
    public ?int $montant = null;

    #[Assert\NotNull]
    public ?MoyenPaiement $moyen = MoyenPaiement::Especes;

    #[Assert\Length(max: 80)]
    public ?string $reference = null;

    #[Assert\NotNull]
    #[Assert\LessThanOrEqual('today', message: 'La date de paiement ne peut pas être dans le futur.')]
    public ?\DateTimeImmutable $datePaiement = null;
}
