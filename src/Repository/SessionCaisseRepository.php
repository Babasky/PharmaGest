<?php

namespace App\Repository;

use App\Entity\Caisse;
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

    /** Session ouverte de la caisse (une au plus). */
    public function ouverteDeLaCaisse(Caisse $caisse): ?SessionCaisse
    {
        /** @var SessionCaisse|null */
        return $this->createQueryBuilder('s')
            ->andWhere('s.caisse = :c')->setParameter('c', $caisse)
            ->andWhere('s.clotureeLe IS NULL')
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Sessions ouvertes de la pharmacie courante, la plus ancienne d'abord.
     *
     * @return list<SessionCaisse>
     */
    public function ouvertes(): array
    {
        return $this->findBy(['clotureeLe' => null], ['id' => 'ASC']);
    }

    /** Session encore ouverte que l'utilisateur a ouverte dans la pharmacie courante. */
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
     * Sessions de la pharmacie, les ouvertes d'abord ; limitées, si demandé, aux sessions ouvertes (que toute
     * l'équipe partage) et à celles que l'utilisateur a ouvertes.
     *
     * @return Page<SessionCaisse>
     */
    public function liste(?Utilisateur $utilisateur, int $page): Page
    {
        $qb = $this->createQueryBuilder('s')
            ->join('s.utilisateur', 'u')->addSelect('u')
            ->join('s.caisse', 'c')->addSelect('c')
            ->addSelect('CASE WHEN s.clotureeLe IS NULL THEN 0 ELSE 1 END AS HIDDEN ordre')
            ->orderBy('ordre', 'ASC')->addOrderBy('s.ouverteLe', 'DESC')->addOrderBy('s.id', 'DESC');
        if (null !== $utilisateur) {
            $qb->andWhere('s.utilisateur = :u OR s.clotureeLe IS NULL')->setParameter('u', $utilisateur);
        }

        /** @var Page<SessionCaisse> */
        return Page::depuis($qb, $page);
    }
}
