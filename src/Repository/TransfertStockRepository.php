<?php

namespace App\Repository;

use App\Entity\Pharmacie;
use App\Entity\TransfertStock;
use App\Enum\StatutTransfert;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Un transfert appartient à l'officine d'origine : les requêtes filtrées ne voient que les transferts sortants.
 * Les transferts entrants et le chargement complet se lisent hors filtre tenant
 * (via {@see \App\Stock\TransfertService}).
 *
 * @extends ServiceEntityRepository<TransfertStock>
 */
class TransfertStockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TransfertStock::class);
    }

    /**
     * Transferts sortants de la pharmacie courante.
     *
     * @return Page<TransfertStock>
     */
    public function sortants(?StatutTransfert $statut, int $page): Page
    {
        $qb = $this->createQueryBuilder('t')
            ->join('t.pharmacieDestination', 'd')->addSelect('d')
            ->orderBy('t.creeLe', 'DESC')->addOrderBy('t.id', 'DESC');
        if (null !== $statut) {
            $qb->andWhere('t.statut = :statut')->setParameter('statut', $statut);
        }

        /** @var Page<TransfertStock> */
        return Page::depuis($qb, $page);
    }

    /**
     * Transferts expédiés ou reçus à destination d'une pharmacie, les plus récents d'abord (hors filtre tenant).
     *
     * @return list<TransfertStock>
     */
    public function entrants(Pharmacie $destination, int $limite = 50): array
    {
        /** @var list<TransfertStock> */
        return $this->createQueryBuilder('t')
            ->join('t.pharmacie', 'o')->addSelect('o')
            ->andWhere('t.pharmacieDestination = :destination')->setParameter('destination', $destination)
            ->andWhere('t.statut IN (:statuts)')->setParameter('statuts', [StatutTransfert::Expedie, StatutTransfert::Recu])
            ->orderBy('t.expedieLe', 'DESC')->addOrderBy('t.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()->getResult();
    }

    /**
     * Transfert avec ses lignes, produits et lots, tout chargé d'un coup (hors filtre tenant) : l'officine
     * destinataire peut ensuite l'afficher sans relire les données de l'origine.
     */
    public function complet(int $id): ?TransfertStock
    {
        /** @var TransfertStock|null */
        return $this->createQueryBuilder('t')
            ->join('t.pharmacie', 'o')->addSelect('o')
            ->join('t.pharmacieDestination', 'd')->addSelect('d')
            ->leftJoin('t.lignes', 'l')->addSelect('l')
            ->leftJoin('l.produit', 'p')->addSelect('p')
            ->leftJoin('p.forme', 'f')->addSelect('f')
            ->leftJoin('l.lots', 'lt')->addSelect('lt')
            ->andWhere('t.id = :id')->setParameter('id', $id)
            ->getQuery()->getOneOrNullResult();
    }
}
