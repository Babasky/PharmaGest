<?php

namespace App\Entity;

use App\Repository\OrganismeAmoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Organisme gestionnaire de l'AMO (INPS, CMSS…). Le taux de prise en charge est fixé par chaque pharmacie ({@see TauxAmo}).
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = mb_strtoupper(trim($code));

        return $this;
    }
}
