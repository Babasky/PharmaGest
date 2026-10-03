<?php

namespace App\Repository;

use App\Entity\Etagere;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Etagere>
 */
class EtagereRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Etagere::class);
    }

    /**
     * @return list<Etagere>
     */
    public function toutes(): array
    {
        return $this->findBy([], ['actif' => 'DESC', 'code' => 'ASC']);
    }

    public function choixActifs(): QueryBuilder
    {
        return $this->createQueryBuilder('e')->andWhere('e.actif = true')->orderBy('e.code', 'ASC');
    }
}
