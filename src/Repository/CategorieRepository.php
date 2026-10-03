<?php

namespace App\Repository;

use App\Entity\Categorie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Categorie>
 */
class CategorieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Categorie::class);
    }

    /**
     * Arborescence : chaque catégorie principale suivie de ses sous-catégories.
     *
     * @return list<Categorie>
     */
    public function arborescence(bool $actifsSeulement = false): array
    {
        $qb = $this->createQueryBuilder('c')->leftJoin('c.parent', 'p')->addSelect('p');
        if ($actifsSeulement) {
            $qb->andWhere('c.actif = true');
        }
        /** @var list<Categorie> $toutes */
        $toutes = $qb->getQuery()->getResult();

        $cle = static fn (Categorie $c): string => mb_strtolower(null === $c->getParent() ? $c->getNom().'|' : $c->getParent()->getNom().'|'.$c->getNom());
        usort($toutes, static fn (Categorie $a, Categorie $b) => strcmp($cle($a), $cle($b)));

        return $toutes;
    }

    /** Catégories principales actives (choix du parent). */
    public function principalesActives(): QueryBuilder
    {
        return $this->createQueryBuilder('c')->andWhere('c.parent IS NULL')->andWhere('c.actif = true')->orderBy('c.nom', 'ASC');
    }

    public function choixActifs(): QueryBuilder
    {
        // Chaque catégorie principale, puis ses sous-catégories.
        return $this->createQueryBuilder('c')->leftJoin('c.parent', 'p')->addSelect('p')->andWhere('c.actif = true')
            ->addSelect('COALESCE(p.nom, c.nom) AS HIDDEN branche')
            ->addSelect('CASE WHEN c.parent IS NULL THEN 0 ELSE 1 END AS HIDDEN niveau')
            ->orderBy('branche', 'ASC')->addOrderBy('niveau', 'ASC')->addOrderBy('c.nom', 'ASC');
    }

    public function parNomComplet(string $nomComplet): ?Categorie
    {
        $morceaux = array_map('trim', preg_split('/\s*(?:›|>|\/)\s*/u', $nomComplet) ?: []);
        if (2 === \count($morceaux)) {
            return $this->createQueryBuilder('c')->join('c.parent', 'p')
                ->andWhere('p.nom = :parent AND c.nom = :nom')
                ->setParameter('parent', $morceaux[0])->setParameter('nom', $morceaux[1])
                ->getQuery()->setMaxResults(1)->getOneOrNullResult();
        }

        return $this->findOneBy(['nom' => trim($nomComplet), 'parent' => null]);
    }
}
