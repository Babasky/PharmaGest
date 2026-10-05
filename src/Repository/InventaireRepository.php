<?php

namespace App\Repository;

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
            ->orderBy('i.ouvertLe', 'DESC')->addOrderBy('i.id', 'DESC');

        /** @var Page<Inventaire> */
        return Page::depuis($qb, $page);
    }

    public function enCours(): ?Inventaire
    {
        return $this->findOneBy(['statut' => StatutInventaire::EnCours]);
    }
}
