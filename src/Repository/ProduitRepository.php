<?php

namespace App\Repository;

use App\Entity\Produit;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Produit>
 */
class ProduitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Produit::class);
    }

    /**
     * Recherche par nom commercial, DCI ou code-barres exact (VE-01 : douchette).
     *
     * @return Page<Produit>
     */
    public function rechercher(?string $recherche, ?int $categorieId, bool $archives, int $page): Page
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c', 'cp', 'e', 'f')
            ->leftJoin('p.categorie', 'c')
            ->leftJoin('c.parent', 'cp')
            ->leftJoin('p.etagere', 'e')
            ->leftJoin('p.forme', 'f')
            ->andWhere('p.actif = :actif')->setParameter('actif', !$archives)
            ->orderBy('p.nomCommercial', 'ASC');

        if (null !== $recherche && '' !== trim($recherche)) {
            $q = trim($recherche);
            $qb->andWhere('p.codeBarres = :exact OR p.nomCommercial LIKE :debut OR p.dci LIKE :debut OR p.nomCommercial LIKE :mot OR p.dci LIKE :mot')
                ->setParameter('exact', $q)
                ->setParameter('debut', addcslashes($q, '%_').'%')
                ->setParameter('mot', '% '.addcslashes($q, '%_').'%');
        }
        if (null !== $categorieId) {
            $qb->andWhere('c.id = :cat OR cp.id = :cat')->setParameter('cat', $categorieId);
        }

        /** @var Page<Produit> */
        return Page::depuis($qb, $page);
    }

    public function parCodeBarres(string $codeBarres): ?Produit
    {
        return $this->findOneBy(['codeBarres' => str_replace(' ', '', trim($codeBarres))]);
    }
}
