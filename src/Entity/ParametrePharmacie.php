<?php

namespace App\Entity;

use App\Enum\PolitiqueSansOrdonnance;
use App\Repository\ParametrePharmacieRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Paramètres de gestion d'une pharmacie (PH-02). Une seule ligne par pharmacie, réglée par le propriétaire.
 */
#[ORM\Entity(repositoryClass: ParametrePharmacieRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_parametre_pharmacie', columns: ['pharmacie_id'])]
class ParametrePharmacie implements TenantAwareInterface
{
    use TenantAwareTrait;

    public const PLAFOND_REMISE_PAR_DEFAUT = 10;
    public const DELAI_PEREMPTION_PAR_DEFAUT = 90;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Remise maximale (en %) qu'un vendeur ou un adjoint peut accorder sans le code PIN du propriétaire (RG-08). */
    #[ORM\Column]
    #[Assert\Range(min: 0, max: 100)]
    private int $plafondRemise = self::PLAFOND_REMISE_PAR_DEFAUT;

    /** Un lot est signalé « péremption proche » ce nombre de jours avant sa date de péremption (ST-05). */
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 730)]
    private int $delaiAlertePeremption = self::DELAI_PEREMPTION_PAR_DEFAUT;

    #[ORM\Column(length: 20, enumType: PolitiqueSansOrdonnance::class)]
    private PolitiqueSansOrdonnance $politiqueSansOrdonnance = PolitiqueSansOrdonnance::Blocage;

    /** Texte libre imprimé en bas du ticket de caisse (VE-06). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $mentionsTicket = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlafondRemise(): int
    {
        return $this->plafondRemise;
    }

    public function setPlafondRemise(int $plafondRemise): static
    {
        $this->plafondRemise = $plafondRemise;

        return $this;
    }

    public function getDelaiAlertePeremption(): int
    {
        return $this->delaiAlertePeremption;
    }

    public function setDelaiAlertePeremption(int $delaiAlertePeremption): static
    {
        $this->delaiAlertePeremption = $delaiAlertePeremption;

        return $this;
    }

    public function getPolitiqueSansOrdonnance(): PolitiqueSansOrdonnance
    {
        return $this->politiqueSansOrdonnance;
    }

    public function setPolitiqueSansOrdonnance(PolitiqueSansOrdonnance $politique): static
    {
        $this->politiqueSansOrdonnance = $politique;

        return $this;
    }

    public function getMentionsTicket(): ?string
    {
        return $this->mentionsTicket;
    }

    public function setMentionsTicket(?string $mentionsTicket): static
    {
        $this->mentionsTicket = null !== $mentionsTicket && '' !== trim($mentionsTicket) ? trim($mentionsTicket) : null;

        return $this;
    }
}
