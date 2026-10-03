<?php

namespace App\Repository;

use App\Entity\Pharmacie;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pharmacie>
 */
class PharmacieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pharmacie::class);
    }

    /**
     * @return Page<Pharmacie>
     */
    public function rechercher(?string $recherche, int $page): Page
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('o')
            ->join('p.offre', 'o')
            ->orderBy('p.nom', 'ASC');

        if (null !== $recherche && '' !== trim($recherche)) {
            $qb->andWhere('p.nom LIKE :q OR p.ville LIKE :q OR p.numeroAutorisation LIKE :q')
                ->setParameter('q', '%'.addcslashes(trim($recherche), '%_').'%');
        }

        /** @var Page<Pharmacie> */
        return Page::depuis($qb, $page);
    }

    /**
     * Pharmacies non archivées dont l'échéance (abonnement ou essai) tombe entre deux dates incluses.
     *
     * @return list<Pharmacie>
     */
    public function echeantEntre(\DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        /** @var list<Pharmacie> */
        return $this->createQueryBuilder('p')
            ->addSelect('COALESCE(p.finAbonnement, p.finEssai) AS HIDDEN echeance')
            ->andWhere('p.archiveeLe IS NULL')
            ->andWhere('COALESCE(p.finAbonnement, p.finEssai) BETWEEN :du AND :au')
            ->setParameter('du', $du->format('Y-m-d'))
            ->setParameter('au', $au->format('Y-m-d'))
            ->orderBy('echeance', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Pharmacie>
     */
    public function nonArchivees(): array
    {
        /** @var list<Pharmacie> */
        return $this->createQueryBuilder('p')
            ->andWhere('p.archiveeLe IS NULL')
            ->getQuery()
            ->getResult();
    }
}
