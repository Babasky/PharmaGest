<?php

namespace App\Repository;

use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SessionCaisse>
 */
class SessionCaisseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SessionCaisse::class);
    }

    /** Session ouverte de l'utilisateur dans la pharmacie courante (une au plus). */
    public function ouverteDe(Utilisateur $utilisateur): ?SessionCaisse
    {
        /** @var SessionCaisse|null */
        return $this->createQueryBuilder('s')
            ->andWhere('s.utilisateur = :u')->setParameter('u', $utilisateur)
            ->andWhere('s.clotureeLe IS NULL')
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Sessions de la pharmacie, les ouvertes d'abord ; limitées à un utilisateur si demandé.
     *
     * @return Page<SessionCaisse>
     */
    public function liste(?Utilisateur $utilisateur, int $page): Page
    {
        $qb = $this->createQueryBuilder('s')
            ->join('s.utilisateur', 'u')->addSelect('u')
            ->addSelect('CASE WHEN s.clotureeLe IS NULL THEN 0 ELSE 1 END AS HIDDEN ordre')
            ->orderBy('ordre', 'ASC')->addOrderBy('s.ouverteLe', 'DESC')->addOrderBy('s.id', 'DESC');
        if (null !== $utilisateur) {
            $qb->andWhere('s.utilisateur = :u')->setParameter('u', $utilisateur);
        }

        /** @var Page<SessionCaisse> */
        return Page::depuis($qb, $page);
    }
}
