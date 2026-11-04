<?php

namespace App\Repository;

use App\Entity\ZoneRangement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ZoneRangement>
 */
class ZoneRangementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ZoneRangement::class);
    }

    /**
     * @return list<ZoneRangement>
     */
    public function toutes(): array
    {
        return $this->findBy([], ['actif' => 'DESC', 'nom' => 'ASC']);
    }

    /**
     * Zones proposées pour ranger une étagère : celles en service, plus la zone actuelle de l'étagère même archivée
     * (sinon la modifier la sortirait de sa zone sans qu'on le demande).
     */
    public function choix(?ZoneRangement $actuelle = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('z')->orderBy('z.nom', 'ASC');
        if (null === $actuelle?->getId()) {
            return $qb->andWhere('z.actif = true');
        }

        return $qb->andWhere('z.actif = true OR z = :actuelle')->setParameter('actuelle', $actuelle);
    }

    /**
     * Nombre d'étagères par zone (identifiant de la zone).
     *
     * @return array<int, int>
     */
    public function nombreEtageres(): array
    {
        $lignes = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(e.zone) AS zone, COUNT(e.id) AS nombre')
            ->from(\App\Entity\Etagere::class, 'e')
            ->andWhere('e.zone IS NOT NULL')
            ->groupBy('e.zone')
            ->getQuery()->getArrayResult();

        return array_column(array_map(static fn (array $l) => [(int) $l['zone'], (int) $l['nombre']], $lignes), 1, 0);
    }
}
