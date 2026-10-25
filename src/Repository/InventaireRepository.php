<?php

namespace App\Repository;

use App\Entity\Entrepot;
use App\Entity\Inventaire;
use App\Enum\StatutInventaire;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Inventaire>
 */
class InventaireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Inventaire::class);
    }

    /**
     * @return Page<Inventaire>
     */
    public function liste(int $page): Page
    {
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.etagere', 'e')->addSelect('e')
            ->leftJoin('i.categorie', 'c')->addSelect('c')
            ->leftJoin('i.entrepot', 'en')->addSelect('en')
            ->orderBy('i.ouvertLe', 'DESC')->addOrderBy('i.id', 'DESC');

        /** @var Page<Inventaire> */
        return Page::depuis($qb, $page);
    }

    /** Inventaire en cours à un emplacement (null = officine) : un seul à la fois par emplacement. */
    public function enCours(?Entrepot $entrepot = null): ?Inventaire
    {
        return $this->findOneBy(['statut' => StatutInventaire::EnCours, 'entrepot' => $entrepot]);
    }

    /**
     * Inventaires en cours, tous emplacements confondus.
     *
     * @return list<Inventaire>
     */
    public function tousEnCours(): array
    {
        /** @var list<Inventaire> */
        return $this->createQueryBuilder('i')
            ->leftJoin('i.entrepot', 'en')->addSelect('en')
            ->andWhere('i.statut = :statut')->setParameter('statut', StatutInventaire::EnCours)
            ->orderBy('i.ouvertLe', 'ASC')
            ->getQuery()->getResult();
    }
}
