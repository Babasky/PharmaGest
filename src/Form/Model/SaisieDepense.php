<?php

namespace App\Form\Model;

use App\Entity\CategorieDepense;
use App\Entity\Depense;
use App\Enum\ModeReglement;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Saisie ou modification d'une dépense (FI-01).
 */
final class SaisieDepense
{
    #[Assert\NotNull(message: 'La date est obligatoire.')]
    #[Assert\LessThanOrEqual('today', message: 'La date ne peut pas être dans le futur.')]
    public ?\DateTimeImmutable $date = null;

    #[Assert\NotNull(message: 'Choisissez la catégorie.')]
    public ?CategorieDepense $categorie = null;

    #[Assert\NotBlank(message: 'Le libellé est obligatoire.')]
    #[Assert\Length(max: 150)]
    public ?string $libelle = null;

    #[Assert\NotNull(message: 'Indiquez le montant.')]
    #[Assert\Positive(message: 'Le montant doit être supérieur à zéro.')]
    #[Assert\LessThanOrEqual(100000000)]
    public ?int $montant = null;

    #[Assert\NotNull(message: 'Choisissez le mode de paiement.')]
    public ?ModeReglement $mode = ModeReglement::Especes;

    #[Assert\Length(max: 120)]
    public ?string $beneficiaire = null;

    public ?UploadedFile $justificatif = null;

    public static function depuis(Depense $depense): self
    {
        $saisie = new self();
        $saisie->date = $depense->getDate();
        $saisie->categorie = $depense->getCategorie();
        $saisie->libelle = $depense->getLibelle();
        $saisie->montant = $depense->getMontant();
        $saisie->mode = $depense->getMode();
        $saisie->beneficiaire = $depense->getBeneficiaire();

        return $saisie;
    }
}
