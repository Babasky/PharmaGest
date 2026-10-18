<?php

namespace App\Repository;

use App\Entity\Caisse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Caisse>
 */
class CaisseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Caisse::class);
    }

    /**
     * Caisses de la pharmacie courante, les actives d'abord, dans l'ordre de création.
     *
     * @return list<Caisse>
     */
    public function toutes(): array
    {
        return $this->findBy([], ['actif' => 'DESC', 'id' => 'ASC']);
    }

    /**
     * @return list<Caisse>
     */
    public function actives(): array
    {
        return $this->findBy(['actif' => true], ['id' => 'ASC']);
    }
}
