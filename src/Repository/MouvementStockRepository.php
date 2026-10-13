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

    /**
     * Quantités vendues par produit entre deux instants (fin exclue) : ventes moins annulations et retours clients.
     * Base des suggestions de commande (ventes moyennes, rotation, ventes du jour).
     *
     * @return array<int, int> indexé par id de produit, seulement les produits vendus
     */
    public function ventesNettesParProduit(\DateTimeImmutable $depuis, \DateTimeImmutable $jusqua): array
    {
        $lignes = $this->createQueryBuilder('m')
            ->select('IDENTITY(l.produit) AS produit', 'SUM(m.quantite) AS quantite')
            ->join('m.lot', 'l')
            ->andWhere('m.type IN (:types)')->setParameter('types', [TypeMouvement::Vente, TypeMouvement::Annulation, TypeMouvement::RetourClient])
            ->andWhere('m.date >= :depuis')->setParameter('depuis', $depuis, Types::DATETIME_IMMUTABLE)
            ->andWhere('m.date < :jusqua')->setParameter('jusqua', $jusqua, Types::DATETIME_IMMUTABLE)
            ->groupBy('l.produit')
            ->getQuery()->getArrayResult();

        $ventes = [];
        foreach ($lignes as $ligne) {
            // Les sorties sont négatives, les annulations et retours positifs.
            if (($vendu = -(int) $ligne['quantite']) > 0) {
                $ventes[(int) $ligne['produit']] = $vendu;
            }
        }

        return $ventes;
    }
}
