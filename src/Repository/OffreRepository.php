<?php

namespace App\Repository;

use App\Entity\Offre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Offre>
 */
class OffreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Offre::class);
    }

    /**
     * @return list<Offre>
     */
    public function toutes(): array
    {
        return $this->findBy([], ['ordre' => 'ASC']);
    }

    /** Rang d'une nouvelle offre : après toutes les autres dans les listes. */
    public function ordreSuivant(): int
    {
        return 1 + (int) $this->createQueryBuilder('o')->select('COALESCE(MAX(o.ordre), 0)')->getQuery()->getSingleScalarResult();
    }

    public function parCode(string $code): Offre
    {
        return $this->findOneBy(['code' => $code])
            ?? throw new \RuntimeException(\sprintf('Offre « %s » introuvable : les migrations ont-elles été jouées ?', $code));
    }
}
