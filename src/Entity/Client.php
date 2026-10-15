<?php

namespace App\Entity;

use App\Referentiel\ArchivableTrait;
use App\Repository\ClientRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use App\Util\Telephone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    /**
     * Assurances du client (AMO ou autres), chacune avec son n° d'assuré ; la première est proposée par défaut à la caisse.
     *
     * @var Collection<int, ClientAssurance>
     */
    #[ORM\OneToMany(targetEntity: ClientAssurance::class, mappedBy: 'client', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[Assert\Valid]
    private Collection $assurances;

    /** Entreprise ou mutuelle de rattachement (clients conventionnés, gérés en V2). */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $entreprise = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
        $this->assurances = new ArrayCollection();
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

    /**
     * @return Collection<int, ClientAssurance>
     */
    public function getAssurances(): Collection
    {
        return $this->assurances;
    }

    /** Nommée en anglais pour le formulaire (CollectionType, by_reference: false). */
    public function addAssurance(ClientAssurance $assurance): static
    {
        if (!$this->assurances->contains($assurance)) {
            $assurance->setClient($this);
            $this->assurances->add($assurance);
        }

        return $this;
    }

    public function removeAssurance(ClientAssurance $assurance): static
    {
        $this->assurances->removeElement($assurance);

        return $this;
    }

    /** Ajoute l'assurance, ou met à jour le n° d'assuré si le client l'a déjà. */
    public function definirAssurance(OrganismeAmo $organisme, ?string $numeroAssure): ClientAssurance
    {
        $assurance = $this->assurancePour($organisme);
        if (null === $assurance) {
            $this->addAssurance($assurance = new ClientAssurance($organisme));
        }

        return $assurance->setNumeroAssure($numeroAssure);
    }

    public function assurancePour(?OrganismeAmo $organisme): ?ClientAssurance
    {
        foreach ($this->assurances as $assurance) {
            if (null !== $organisme && $assurance->getOrganisme() === $organisme) {
                return $assurance;
            }
        }

        return null;
    }

    /** Assurance proposée par défaut à la caisse : la première enregistrée encore active. */
    public function assurancePrincipale(): ?ClientAssurance
    {
        foreach ($this->assurances as $assurance) {
            if (true === $assurance->getOrganisme()?->isActif()) {
                return $assurance;
            }
        }

        return null;
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

    /** Assuré : au moins une assurance (AMO ou autre) avec son n° d'assuré (AM-02). */
    public function isAssureAmo(): bool
    {
        return !$this->assurances->isEmpty();
    }

    #[Assert\Callback]
    public function valider(ExecutionContextInterface $contexte): void
    {
        if (null !== $this->telephone && null === Telephone::normaliser($this->telephone)) {
            $contexte->buildViolation('Numéro de téléphone malien attendu : +223 XX XX XX XX.')->atPath('telephone')->addViolation();
        }
        $vus = [];
        foreach ($this->assurances as $cle => $assurance) {
            $organisme = $assurance->getOrganisme();
            if (null === $organisme) {
                continue;
            }
            if (\in_array($organisme, $vus, true)) {
                $contexte->buildViolation(\sprintf('L\'assurance %s est indiquée deux fois.', $organisme->getCode()))
                    ->atPath(\sprintf('assurances[%s].organisme', $cle))
                    ->addViolation();
            }
            $vus[] = $organisme;
        }
    }

    public function __toString(): string
    {
        return $this->nom;
    }
}
