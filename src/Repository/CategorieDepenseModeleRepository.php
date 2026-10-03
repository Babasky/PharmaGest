<?php

namespace App\Repository;

use App\Entity\CategorieDepenseModele;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CategorieDepenseModele>
 */
class CategorieDepenseModeleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CategorieDepenseModele::class);
    }

    /**
     * @return list<CategorieDepenseModele>
     */
    public function actifs(): array
    {
        return $this->findBy(['actif' => true], ['nom' => 'ASC']);
    }
}
