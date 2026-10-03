<?php

namespace App\Form\Model;

use App\Entity\Pharmacie;
use App\Service\CreationPharmacie;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données du formulaire de création d'une pharmacie par le super admin.
 */
final class NouvellePharmacie
{
    #[Assert\Valid]
    public Pharmacie $pharmacie;

    #[Assert\NotBlank(message: 'L\'email du propriétaire est obligatoire.')]
    #[Assert\Email]
    public ?string $emailProprietaire = null;

    #[Assert\NotBlank(message: 'Le nom du propriétaire est obligatoire.')]
    #[Assert\Length(max: 120)]
    public ?string $nomProprietaire = null;

    #[Assert\NotNull]
    #[Assert\Range(min: 0, max: 90)]
    public ?int $joursEssai = CreationPharmacie::JOURS_ESSAI_PAR_DEFAUT;

    public function __construct(Pharmacie $pharmacie)
    {
        $this->pharmacie = $pharmacie;
    }
}
