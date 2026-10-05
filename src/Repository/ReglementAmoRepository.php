<?php

namespace App\Repository;

use App\Entity\ReglementAmo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReglementAmo>
 */
class ReglementAmoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReglementAmo::class);
    }
}
