<?php

namespace App\Entity;

use App\Referentiel\ArchivableTrait;
use App\Repository\CaisseRepository;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Caisse (tiroir et poste d'encaissement) de la pharmacie. Une officine malienne n'en a souvent qu'une :
 * elle est créée d'office (« Caisse principale ») et toute l'équipe y encaisse. Le propriétaire peut en ajouter.
 * Une caisse n'a qu'une session ouverte à la fois, quel que soit l'utilisateur qui encaisse.
 */
#[UniqueEntity(fields: ['libelle'], message: 'Une caisse porte déjà ce nom.')]
#[ORM\Entity(repositoryClass: CaisseRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_caisse_libelle', columns: ['pharmacie_id', 'libelle'])]
class Caisse implements TenantAwareInterface
{
    use ArchivableTrait;
    use TenantAwareTrait;

    public const PRINCIPALE = 'Caisse principale';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank(message: 'Donnez un nom à la caisse (ex. Caisse 2).')]
    #[Assert\Length(max: 60)]
    private string $libelle = '';

    public function __construct(string $libelle = '')
    {
        $this->setLibelle($libelle);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(?string $libelle): static
    {
        $this->libelle = trim((string) $libelle);

        return $this;
    }

    public function __toString(): string
    {
        return $this->libelle;
    }
}
