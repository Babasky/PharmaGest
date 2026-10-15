<?php

namespace App\Entity;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une assurance du client (AMO ou autre assurance) avec son n° d'assuré : un client peut en avoir plusieurs,
 * et la caisse choisit celle qui s'applique à la vente (AM-02).
 */
#[ORM\Entity]
class ClientAssurance implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'assurances')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Client $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Choisissez l\'assurance.')]
    private ?OrganismeAmo $organisme = null;

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank(message: 'Le n° d\'assuré est obligatoire.')]
    #[Assert\Length(max: 40)]
    private string $numeroAssure = '';

    public function __construct(?OrganismeAmo $organisme = null, ?string $numeroAssure = null)
    {
        $this->organisme = $organisme;
        $this->setNumeroAssure($numeroAssure);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    /**
     * @internal réservé à {@see Client::addAssurance()}
     */
    public function setClient(?Client $client): static
    {
        $this->client = $client;
        if (null !== $client?->getPharmacie()) {
            $this->setPharmacie($client->getPharmacie());
        }

        return $this;
    }

    public function getOrganisme(): ?OrganismeAmo
    {
        return $this->organisme;
    }

    public function setOrganisme(?OrganismeAmo $organisme): static
    {
        $this->organisme = $organisme;

        return $this;
    }

    public function getNumeroAssure(): string
    {
        return $this->numeroAssure;
    }

    public function setNumeroAssure(?string $numeroAssure): static
    {
        $this->numeroAssure = mb_strtoupper(trim((string) $numeroAssure));

        return $this;
    }

    /** « INPS INPS-0045871 » */
    public function libelle(): string
    {
        return trim(($this->organisme?->getCode() ?? '').' '.$this->numeroAssure);
    }
}
