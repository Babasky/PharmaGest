<?php

namespace App\Repository;

use App\Entity\MouvementStock;
use App\Entity\Produit;
use App\Enum\TypeMouvement;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MouvementStock>
 */
class MouvementStockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MouvementStock::class);
    }

    /**
     * Historique des mouvements d'un produit, du plus récent au plus ancien (ST-08).
     *
     * @return Page<MouvementStock>
     */
    public function historique(Produit $produit, int $page): Page
    {
        $qb = $this->createQueryBuilder('m')
            ->join('m.lot', 'l')->addSelect('l')
            ->leftJoin('m.utilisateur', 'u')->addSelect('u')
            ->andWhere('l.produit = :produit')->setParameter('produit', $produit)
            ->orderBy('m.date', 'DESC')->addOrderBy('m.id', 'DESC');

        /** @var Page<MouvementStock> */
        return Page::depuis($qb, $page, 20);
    }

    /**
     * Quantités vendues par mois (« 2026-10 » => 42), ventes moins annulations et retours (ST-08).
     *
     * @return array<string, int>
     */
    public function ventesParMois(Produit $produit, \DateTimeImmutable $depuis): array
    {
        $mouvements = $this->createQueryBuilder('m')
            ->select('m.date', 'm.quantite')
            ->join('m.lot', 'l')
            ->andWhere('l.produit = :produit')->setParameter('produit', $produit)
            ->andWhere('m.type IN (:types)')->setParameter('types', [TypeMouvement::Vente, TypeMouvement::Annulation, TypeMouvement::RetourClient])
            ->andWhere('m.date >= :depuis')->setParameter('depuis', $depuis, Types::DATETIME_IMMUTABLE)
            ->getQuery()->getArrayResult();

        $parMois = [];
        foreach ($mouvements as $m) {
            $mois = $m['date']->format('Y-m');
            $parMois[$mois] = ($parMois[$mois] ?? 0) - $m['quantite'];
        }

        return $parMois;
    }
}
