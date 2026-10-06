<?php

namespace App\Repository;

use App\Entity\CategorieDepense;
use App\Entity\Depense;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Depense>
 */
class DepenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Depense::class);
    }

    /**
     * Dépenses d'une période (bornes incluses), des plus récentes aux plus anciennes.
     *
     * @return Page<Depense>
     */
    public function liste(\DateTimeImmutable $debut, \DateTimeImmutable $fin, ?CategorieDepense $categorie, ?string $recherche, int $page): Page
    {
        $qb = $this->periode($debut, $fin)
            ->join('d.categorie', 'c')->addSelect('c')
            ->orderBy('d.date', 'DESC')->addOrderBy('d.id', 'DESC');
        if (null !== $categorie) {
            $qb->andWhere('d.categorie = :categorie')->setParameter('categorie', $categorie);
        }
        if (null !== $recherche && '' !== trim($recherche)) {
            $qb->andWhere('d.numero LIKE :q OR d.libelle LIKE :q OR d.beneficiaire LIKE :q')
                ->setParameter('q', '%'.addcslashes(trim($recherche), '%_').'%');
        }

        /** @var Page<Depense> */
        return Page::depuis($qb, $page);
    }

    /**
     * Dépenses non annulées d'une période (bornes incluses), dans l'ordre chronologique.
     *
     * @return list<Depense>
     */
    public function retenues(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        /** @var list<Depense> */
        return $this->periode($debut, $fin)
            ->join('d.categorie', 'c')->addSelect('c')
            ->andWhere('d.annuleeLe IS NULL')
            ->orderBy('d.date', 'ASC')->addOrderBy('d.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** Total des dépenses non annulées d'une période (bornes incluses). */
    public function total(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        return (int) $this->periode($debut, $fin)
            ->select('COALESCE(SUM(d.montant), 0)')
            ->andWhere('d.annuleeLe IS NULL')
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Dépenses non annulées par catégorie (RA-04), de la plus lourde à la plus légère.
     *
     * @return list<array{categorie: string, nombre: int, montant: int}>
     */
    public function parCategorie(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $lignes = $this->periode($debut, $fin)
            ->select('c.nom AS categorie', 'COUNT(d.id) AS nombre', 'SUM(d.montant) AS montant')
            ->join('d.categorie', 'c')
            ->andWhere('d.annuleeLe IS NULL')
            ->groupBy('c.id')
            ->orderBy('montant', 'DESC')
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $l) => ['categorie' => (string) $l['categorie'], 'nombre' => (int) $l['nombre'], 'montant' => (int) $l['montant']], $lignes);
    }

    /**
     * Dépenses non annulées par mois « Y-m » (RA-03).
     *
     * @return array<string, int>
     */
    public function parMois(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $mois = [];
        foreach ($this->periode($debut, $fin)->select('d.date AS date', 'd.montant AS montant')->andWhere('d.annuleeLe IS NULL')->getQuery()->getArrayResult() as $l) {
            /** @var \DateTimeInterface $date */
            $date = $l['date'];
            $mois[$date->format('Y-m')] = ($mois[$date->format('Y-m')] ?? 0) + (int) $l['montant'];
        }

        return $mois;
    }

    private function periode(\DateTimeImmutable $debut, \DateTimeImmutable $fin): QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.date >= :debut AND d.date <= :fin')
            ->setParameter('debut', $debut, Types::DATE_IMMUTABLE)
            ->setParameter('fin', $fin, Types::DATE_IMMUTABLE);
    }
}
