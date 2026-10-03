<?php

namespace App\Repository;

use App\Entity\OrganismeAmo;
use App\Entity\TauxAmo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Requêtes filtrées sur la pharmacie courante (entité tenant).
 *
 * @extends ServiceEntityRepository<TauxAmo>
 */
class TauxAmoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TauxAmo::class);
    }

    /** Dernier taux entré en vigueur à la date donnée. */
    public function enVigueur(OrganismeAmo $organisme, \DateTimeImmutable $date): ?TauxAmo
    {
        /** @var TauxAmo|null */
        return $this->createQueryBuilder('t')
            ->andWhere('t.organisme = :o')
            ->andWhere('t.dateEffet <= :d')
            ->setParameter('o', $organisme)
            ->setParameter('d', $date->format('Y-m-d'))
            ->orderBy('t.dateEffet', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<TauxAmo>
     */
    public function historique(): array
    {
        /** @var list<TauxAmo> */
        return $this->createQueryBuilder('t')
            ->addSelect('o')
            ->join('t.organisme', 'o')
            ->orderBy('o.nom', 'ASC')
            ->addOrderBy('t.dateEffet', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
