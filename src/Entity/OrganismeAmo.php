<?php

namespace App\Entity;

use App\Enum\TypeOrganisme;
use App\Repository\OrganismeAmoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Organisme de prise en charge (tiers payant) : gestionnaire de l'AMO (INPS, CMSS…) ou autre assurance à laquelle
 * une entreprise ou une ONG inscrit ses employés. Le taux de prise en charge est fixé par chaque pharmacie
 * ({@see TauxAmo}). Le nom « OrganismeAmo » est historique : créances et bordereaux fonctionnent de la même façon
 * pour toutes les assurances.
 */
#[ORM\Entity(repositoryClass: OrganismeAmoRepository::class)]
#[UniqueEntity('nom', message: 'Cet organisme existe déjà.')]
#[UniqueEntity('code', message: 'Ce code est déjà utilisé.')]
class OrganismeAmo extends ReferentielCommun
{
    #[ORM\Column(length: 20, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    private string $code = '';

    #[ORM\Column(length: 20, enumType: TypeOrganisme::class, options: ['default' => 'amo'])]
    private TypeOrganisme $type = TypeOrganisme::Amo;

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = mb_strtoupper(trim($code));

        return $this;
    }

    public function getType(): TypeOrganisme
    {
        return $this->type;
    }

    public function setType(TypeOrganisme $type): static
    {
        $this->type = $type;

        return $this;
    }

    /** Le taux s'applique au prix de vente AMO du médicament, et non au prix de la pharmacie. */
    public function appliqueTarifAmo(): bool
    {
        return $this->type->appliqueTarifAmo();
    }
}
