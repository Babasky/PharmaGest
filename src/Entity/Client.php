<?php

namespace App\Entity;

use App\Referentiel\ArchivableTrait;
use App\Repository\ClientRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use App\Util\Telephone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Client de la pharmacie (RF-06). Seul un client « privilégié » peut bénéficier d'une remise (RE-01).
 */
#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Index(name: 'idx_client_nom', columns: ['pharmacie_id', 'nom'])]
#[ORM\Index(name: 'idx_client_telephone', columns: ['pharmacie_id', 'telephone'])]
class Client implements TenantAwareInterface
{
    use ArchivableTrait;
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Le nom du client est obligatoire.')]
    #[Assert\Length(max: 120)]
    private string $nom = '';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    /** Réservé au propriétaire et à l'adjoint (matrice des droits). */
    #[ORM\Column]
    private bool $privilegie = false;

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $numeroAssure = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?OrganismeAmo $organismeAmo = null;

    /** Entreprise ou mutuelle de rattachement (clients conventionnés, gérés en V2). */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $entreprise = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = trim($nom);

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $telephone = null === $telephone || '' === trim($telephone) ? null : trim($telephone);
        $this->telephone = null === $telephone ? null : (Telephone::normaliser($telephone) ?? $telephone);

        return $this;
    }

    public function isPrivilegie(): bool
    {
        return $this->privilegie;
    }

    public function setPrivilegie(bool $privilegie): static
    {
        $this->privilegie = $privilegie;

        return $this;
    }

    public function getNumeroAssure(): ?string
    {
        return $this->numeroAssure;
    }

    public function setNumeroAssure(?string $numeroAssure): static
    {
        $this->numeroAssure = null === $numeroAssure || '' === trim($numeroAssure) ? null : mb_strtoupper(trim($numeroAssure));

        return $this;
    }

    public function getOrganismeAmo(): ?OrganismeAmo
    {
        return $this->organismeAmo;
    }

    public function setOrganismeAmo(?OrganismeAmo $organismeAmo): static
    {
        $this->organismeAmo = $organismeAmo;

        return $this;
    }

    public function getEntreprise(): ?string
    {
        return $this->entreprise;
    }

    public function setEntreprise(?string $entreprise): static
    {
        $this->entreprise = null === $entreprise || '' === trim($entreprise) ? null : trim($entreprise);

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    /** Assuré AMO : numéro et organisme renseignés (AM-02). */
    public function isAssureAmo(): bool
    {
        return null !== $this->numeroAssure && null !== $this->organismeAmo;
    }

    #[Assert\Callback]
    public function valider(ExecutionContextInterface $contexte): void
    {
        if (null !== $this->telephone && null === Telephone::normaliser($this->telephone)) {
            $contexte->buildViolation('Numéro de téléphone malien attendu : +223 XX XX XX XX.')->atPath('telephone')->addViolation();
        }
        if ((null === $this->numeroAssure) !== (null === $this->organismeAmo)) {
            $contexte->buildViolation('Pour un assuré AMO, renseignez à la fois le numéro d\'assuré et l\'organisme.')
                ->atPath(null === $this->numeroAssure ? 'numeroAssure' : 'organismeAmo')
                ->addViolation();
        }
    }

    public function __toString(): string
    {
        return $this->nom;
    }
}
