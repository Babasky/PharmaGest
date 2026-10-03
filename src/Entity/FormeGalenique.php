<?php

namespace App\Entity;

use App\Repository\FormeGaleniqueRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: FormeGaleniqueRepository::class)]
#[UniqueEntity('nom', message: 'Cette forme existe déjà.')]
class FormeGalenique extends ReferentielCommun
{
}
