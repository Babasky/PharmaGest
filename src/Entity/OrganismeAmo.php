<?php

namespace App\Entity;

use App\Enum\TypeOrganisme;
use App\Repository\OrganismeAmoRepository;
use App\Tenant\TenantPartageInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Organisme de prise en charge (tiers payant) : gestionnaire de l'AMO (INPS, CMSS…) ou autre assurance à laquelle
 * une entreprise ou une ONG inscrit ses employés. Le taux de prise en charge est fixé par chaque pharmacie
 * ({@see TauxAmo}). Le nom « OrganismeAmo » est historique : créances et bordereaux fonctionnent de la même façon
 * pour toutes les assurances.
 *
 * Sans pharmacie, l'organisme est commun à toutes (créé par l'éditeur) ; une pharmacie peut aussi créer les siens,
 * visibles d'elle seule. Nom et code sont uniques parmi les organismes que voit une pharmacie (les communs et les
 * siens) : le contrôle passe par le filtre tenant.
 */
#[ORM\Entity(repositoryClass: OrganismeAmoRepository::class)]
#[ORM\AttributeOverrides([new ORM\AttributeOverride(name: 'nom', column: new ORM\Column(length: 100))])]
#[ORM\UniqueConstraint(name: 'uniq_organisme_amo_nom', columns: ['pharmacie_id', 'nom'])]
#[ORM\UniqueConstraint(name: 'uniq_organisme_amo_code', columns: ['pharmacie_id', 'code'])]
#[UniqueEntity('nom', message: 'Cet organisme existe déjà.')]
#[UniqueEntity('code', message: 'Ce code est déjà utilisé.')]
class OrganismeAmo extends ReferentielCommun implements TenantPartageInterface
{
    /** Pharmacie qui a créé l'organisme ; null : organisme commun créé par l'éditeur. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Pharmacie $pharmacie = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    private string $code = '';

    #[ORM\Column(length: 20, enumType: TypeOrganisme::class, options: ['default' => 'amo'])]
    private TypeOrganisme $type = TypeOrganisme::Amo;

    public function getPharmacie(): ?Pharmacie
    {
        return $this->pharmacie;
    }

    public function setPharmacie(Pharmacie $pharmacie): static
    {
        $this->pharmacie = $pharmacie;

        return $this;
    }

    /** Organisme commun à toutes les pharmacies (créé par l'éditeur). */
    public function isCommun(): bool
    {
        return null === $this->pharmacie;
    }

    /** « Éditeur (commun) » ou le nom de la pharmacie qui l'a créé (liste du back-office). */
    public function getCreateur(): string
    {
        return $this->pharmacie?->getNom() ?? 'Éditeur (commun)';
    }

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
