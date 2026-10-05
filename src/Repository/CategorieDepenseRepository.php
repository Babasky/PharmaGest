<?php

namespace App\Repository;

use App\Entity\CategorieDepense;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CategorieDepense>
 */
class CategorieDepenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CategorieDepense::class);
    }

    /**
     * @return list<CategorieDepense>
     */
    public function toutes(): array
    {
        return $this->findBy([], ['actif' => 'DESC', 'nom' => 'ASC']);
    }

    public function choixActifs(): QueryBuilder
    {
        return $this->createQueryBuilder('c')->andWhere('c.actif = true')->orderBy('c.nom', 'ASC');
    }
}
