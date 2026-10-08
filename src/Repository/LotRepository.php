<?php

namespace App\Repository;

use App\Entity\Categorie;
use App\Entity\Etagere;
use App\Entity\Lot;
use App\Entity\Produit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Un lot est « disponible » si sa date de péremption est postérieure au jour donné (RG-05),
 * ou s'il n'a pas de date de péremption (réception sans date : le lot sort en dernier).
 *
 * @extends ServiceEntityRepository<Lot>
 */
class LotRepository extends ServiceEntityRepository
{
    /** Tri FEFO : les lots sans date de péremption passent après tous les autres. */
    private const SANS_DATE = 'CASE WHEN l.datePeremption IS NULL THEN 1 ELSE 0 END AS HIDDEN sansDate';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Lot::class);
    }

    /**
     * Lots encore en stock d'un produit, du premier au dernier périmé.
     *
     * @return list<Lot>
     */
    public function enStock(Produit $produit): array
    {
        /** @var list<Lot> */
        return $this->createQueryBuilder('l')
            ->leftJoin('l.fournisseur', 'f')->addSelect('f')
            ->andWhere('l.produit = :produit')->setParameter('produit', $produit)
            ->andWhere('l.quantiteRestante > 0')
            ->addSelect(self::SANS_DATE)->orderBy('sansDate', 'ASC')->addOrderBy('l.datePeremption', 'ASC')->addOrderBy('l.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Lots vendables d'un produit dans l'ordre FEFO (RG-04), verrouillés jusqu'à la fin de la transaction.
     *
     * @return list<Lot>
     */
    public function verrouillerDisponibles(Produit $produit, \DateTimeImmutable $jour): array
    {
        /** @var list<Lot> */
        return $this->createQueryBuilder('l')
            ->andWhere('l.produit = :produit')->setParameter('produit', $produit)
            ->andWhere('l.quantiteRestante > 0')
            ->andWhere('(l.datePeremption IS NULL OR l.datePeremption > :jour)')->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->addSelect(self::SANS_DATE)->orderBy('sansDate', 'ASC')->addOrderBy('l.datePeremption', 'ASC')->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    public function verrouiller(Lot $lot): void
    {
        $this->getEntityManager()->lock($lot, LockMode::PESSIMISTIC_WRITE);
        $this->getEntityManager()->refresh($lot);
    }

    /**
     * RG-03 : stock = somme des quantités restantes des lots non périmés.
     */
    public function stockDisponible(Produit $produit, \DateTimeImmutable $jour): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COALESCE(SUM(l.quantiteRestante), 0)')
            ->andWhere('l.produit = :produit')->setParameter('produit', $produit)
            ->andWhere('(l.datePeremption IS NULL OR l.datePeremption > :jour)')->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->getQuery()->getSingleScalarResult();
    }

    /** Lots périmés encore en stock (ST-05). */
    public function perimesEnStock(\DateTimeImmutable $jour): QueryBuilder
    {
        return $this->enStockAvecProduit()
            ->andWhere('l.datePeremption <= :jour')->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->orderBy('l.datePeremption', 'ASC');
    }

    /** Lots qui périment dans le délai d'alerte (ST-05). */
    public function peremptionProche(\DateTimeImmutable $jour, int $delaiJours): QueryBuilder
    {
        return $this->enStockAvecProduit()
            ->andWhere('(l.datePeremption IS NULL OR l.datePeremption > :jour)')->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->andWhere('l.datePeremption <= :limite')->setParameter('limite', $jour->modify(\sprintf('+%d days', $delaiJours)), Types::DATE_IMMUTABLE)
            ->orderBy('l.datePeremption', 'ASC');
    }

    /**
     * Lots à compter pour un inventaire (ST-06), dans l'ordre du rayon : étagère, produit, péremption.
     * Les lots périmés sont inclus : ils sont physiquement présents.
     *
     * @return list<Lot>
     */
    public function pourInventaire(?Etagere $etagere, ?Categorie $categorie): array
    {
        $qb = $this->enStockAvecProduit()
            ->leftJoin('p.etagere', 'e')
            ->orderBy('e.code', 'ASC')->addOrderBy('p.nomCommercial', 'ASC')->addSelect(self::SANS_DATE)->addOrderBy('sansDate', 'ASC')->addOrderBy('l.datePeremption', 'ASC');
        if (null !== $etagere) {
            $qb->andWhere('p.etagere = :etagere')->setParameter('etagere', $etagere);
        }
        if (null !== $categorie) {
            $qb->leftJoin('p.categorie', 'c')
                ->andWhere('c = :categorie OR c.parent = :categorie')->setParameter('categorie', $categorie);
        }

        /** @var list<Lot> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Valeur du stock au prix d'achat des lots (ST-07), par catégorie principale.
     *
     * @return list<array{categorie: string|null, disponible: int, perime: int, quantite: int}>
     */
    public function valorisationParCategorie(\DateTimeImmutable $jour): array
    {
        $lignes = $this->createQueryBuilder('l')
            ->select('COALESCE(cp.nom, c.nom) AS categorie')
            ->addSelect('SUM(CASE WHEN (l.datePeremption IS NULL OR l.datePeremption > :jour) THEN l.quantiteRestante * l.prixAchat ELSE 0 END) AS disponible')
            ->addSelect('SUM(CASE WHEN l.datePeremption <= :jour THEN l.quantiteRestante * l.prixAchat ELSE 0 END) AS perime')
            ->addSelect('SUM(CASE WHEN (l.datePeremption IS NULL OR l.datePeremption > :jour) THEN l.quantiteRestante ELSE 0 END) AS quantite')
            ->join('l.produit', 'p')
            ->leftJoin('p.categorie', 'c')
            ->leftJoin('c.parent', 'cp')
            ->andWhere('l.quantiteRestante > 0')
            ->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->groupBy('categorie')
            ->orderBy('disponible', 'DESC')
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $l) => [
            'categorie' => $l['categorie'],
            'disponible' => (int) $l['disponible'],
            'perime' => (int) $l['perime'],
            'quantite' => (int) $l['quantite'],
        ], $lignes);
    }

    /**
     * Stock disponible, quantité périmée et valeur au prix d'achat de chaque produit donné.
     *
     * @param iterable<Produit> $produits
     *
     * @return array<int, array{stock: int, perime: int, valeur: int}> indexé par id de produit
     */
    public function syntheseParProduit(iterable $produits, \DateTimeImmutable $jour): array
    {
        $ids = [];
        $synthese = [];
        foreach ($produits as $produit) {
            $ids[] = $produit->getId();
            $synthese[(int) $produit->getId()] = ['stock' => 0, 'perime' => 0, 'valeur' => 0];
        }
        if ([] === $ids) {
            return [];
        }

        $lignes = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.produit) AS produit')
            ->addSelect('SUM(CASE WHEN (l.datePeremption IS NULL OR l.datePeremption > :jour) THEN l.quantiteRestante ELSE 0 END) AS stock')
            ->addSelect('SUM(CASE WHEN l.datePeremption <= :jour THEN l.quantiteRestante ELSE 0 END) AS perime')
            ->addSelect('SUM(CASE WHEN (l.datePeremption IS NULL OR l.datePeremption > :jour) THEN l.quantiteRestante * l.prixAchat ELSE 0 END) AS valeur')
            ->andWhere('l.produit IN (:ids)')->setParameter('ids', $ids)
            ->andWhere('l.quantiteRestante > 0')
            ->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->groupBy('l.produit')
            ->getQuery()->getArrayResult();

        foreach ($lignes as $l) {
            $synthese[(int) $l['produit']] = ['stock' => (int) $l['stock'], 'perime' => (int) $l['perime'], 'valeur' => (int) $l['valeur']];
        }

        return $synthese;
    }

    private function enStockAvecProduit(): QueryBuilder
    {
        return $this->createQueryBuilder('l')
            ->join('l.produit', 'p')->addSelect('p')
            ->andWhere('l.quantiteRestante > 0');
    }
}
