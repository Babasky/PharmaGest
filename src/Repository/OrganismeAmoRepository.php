<?php

namespace App\Repository;

use App\Entity\OrganismeAmo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganismeAmo>
 */
class OrganismeAmoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganismeAmo::class);
    }

    /**
     * @return list<OrganismeAmo>
     */
    public function actifs(): array
    {
        return $this->findBy(['actif' => true], ['nom' => 'ASC']);
    }
}
