<?php

namespace App\Repository;

use App\Entity\Fournisseur;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Fournisseur>
 */
class FournisseurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Fournisseur::class);
    }

    /**
     * @return Page<Fournisseur>
     */
    public function rechercher(?string $recherche, bool $archives, int $page): Page
    {
        $qb = $this->createQueryBuilder('f')->andWhere('f.actif = :actif')->setParameter('actif', !$archives)->orderBy('f.nom', 'ASC');
        if (null !== $recherche && '' !== trim($recherche)) {
            $qb->andWhere('f.nom LIKE :q OR f.contact LIKE :q OR f.telephone LIKE :q')->setParameter('q', '%'.addcslashes(trim($recherche), '%_').'%');
        }

        /** @var Page<Fournisseur> */
        return Page::depuis($qb, $page);
    }

    public function choixActifs(): QueryBuilder
    {
        return $this->createQueryBuilder('f')->andWhere('f.actif = true')->orderBy('f.nom', 'ASC');
    }
}
