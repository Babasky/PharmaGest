<?php

namespace App\Repository;

use App\Entity\Entrepot;
use App\Entity\TransfertInterne;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TransfertInterne>
 */
class TransfertInterneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TransfertInterne::class);
    }

    /**
     * Transferts internes les plus récents d'abord ; avec un entrepôt, ceux qui en partent ou y arrivent.
     *
     * @return Page<TransfertInterne>
     */
    public function liste(?Entrepot $entrepot, int $page): Page
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.origine', 'o')->addSelect('o')
            ->leftJoin('t.destination', 'd')->addSelect('d')
            ->leftJoin('t.creePar', 'u')->addSelect('u')
            ->orderBy('t.creeLe', 'DESC')->addOrderBy('t.id', 'DESC');
        if (null !== $entrepot) {
            $qb->andWhere('t.origine = :entrepot OR t.destination = :entrepot')->setParameter('entrepot', $entrepot);
        }

        /** @var Page<TransfertInterne> */
        return Page::depuis($qb, $page);
    }
}
