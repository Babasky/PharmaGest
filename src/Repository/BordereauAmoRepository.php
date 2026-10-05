<?php

namespace App\Repository;

use App\Entity\BordereauAmo;
use App\Entity\OrganismeAmo;
use App\Enum\StatutBordereau;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BordereauAmo>
 */
class BordereauAmoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BordereauAmo::class);
    }

    /**
     * @return Page<BordereauAmo>
     */
    public function liste(?OrganismeAmo $organisme, ?StatutBordereau $statut, int $page): Page
    {
        $qb = $this->createQueryBuilder('b')
            ->join('b.organisme', 'o')->addSelect('o')
            ->orderBy('b.creeLe', 'DESC')->addOrderBy('b.id', 'DESC');
        if (null !== $organisme) {
            $qb->andWhere('b.organisme = :organisme')->setParameter('organisme', $organisme);
        }
        if (null !== $statut) {
            $qb->andWhere('b.statut = :statut')->setParameter('statut', $statut);
        }

        /** @var Page<BordereauAmo> */
        return Page::depuis($qb, $page);
    }
}
