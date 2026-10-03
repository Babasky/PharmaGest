<?php

namespace App\Entity;

use App\Repository\CategorieDepenseModeleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * Catégorie de dépense proposée par défaut à chaque pharmacie (FI-02), qui pourra ensuite adapter sa liste (Lot 7).
 */
#[ORM\Entity(repositoryClass: CategorieDepenseModeleRepository::class)]
#[UniqueEntity('nom', message: 'Cette catégorie existe déjà.')]
class CategorieDepenseModele extends ReferentielCommun
{
}
