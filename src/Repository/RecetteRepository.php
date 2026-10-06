<?php

namespace App\Repository;

use App\Entity\Recette;
use App\Entity\Vente;
use App\Enum\OrigineRecette;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Recette>
 */
class RecetteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Recette::class);
    }

    /**
     * Journal des recettes d'une période (bornes incluses), des plus récentes aux plus anciennes.
     *
     * @return Page<Recette>
     */
    public function liste(\DateTimeImmutable $debut, \DateTimeImmutable $fin, ?OrigineRecette $origine, int $page): Page
    {
        $qb = $this->periode($debut, $fin)->orderBy('r.date', 'DESC')->addOrderBy('r.id', 'DESC');
        if (null !== $origine) {
            $qb->andWhere('r.origine = :origine')->setParameter('origine', $origine);
        }

        /** @var Page<Recette> */
        return Page::depuis($qb, $page);
    }

    /** Recettes nettes d'une période (contre-passations déduites). */
    public function total(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        return (int) $this->periode($debut, $fin)->select('COALESCE(SUM(r.montant), 0)')->getQuery()->getSingleScalarResult();
    }

    /**
     * Recettes nettes par origine ; les contre-passations sont rattachées à l'origine qu'elles annulent.
     *
     * @return array<string, int> indexé par valeur d'{@see OrigineRecette}
     */
    public function parOrigine(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $lignes = $this->periode($debut, $fin)
            ->select('COALESCE(a.origine, r.origine) AS origine', 'SUM(r.montant) AS montant')
            ->leftJoin('r.annule', 'a')
            ->groupBy('origine')
            ->getQuery()->getArrayResult();
        $totaux = [];
        foreach ($lignes as $l) {
            $origine = $l['origine'] instanceof OrigineRecette ? $l['origine']->value : (string) $l['origine'];
            $totaux[$origine] = (int) $l['montant'];
        }

        return $totaux;
    }

    /**
     * Recettes nettes par mois « Y-m » (RA-03).
     *
     * @return array<string, int>
     */
    public function parMois(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $mois = [];
        foreach ($this->periode($debut, $fin)->select('r.date AS date', 'r.montant AS montant')->getQuery()->getArrayResult() as $l) {
            /** @var \DateTimeInterface $date */
            $date = $l['date'];
            $mois[$date->format('Y-m')] = ($mois[$date->format('Y-m')] ?? 0) + (int) $l['montant'];
        }

        return $mois;
    }

    /**
     * @return list<Recette>
     */
    public function deLaVente(Vente $vente): array
    {
        return $this->findBy(['vente' => $vente], ['id' => 'ASC']);
    }

    /**
     * @return list<Recette>
     */
    public function retenues(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        /** @var list<Recette> */
        return $this->periode($debut, $fin)->orderBy('r.date', 'ASC')->addOrderBy('r.id', 'ASC')->getQuery()->getResult();
    }

    private function periode(\DateTimeImmutable $debut, \DateTimeImmutable $fin): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.date >= :debut AND r.date <= :fin')
            ->setParameter('debut', $debut, Types::DATE_IMMUTABLE)
            ->setParameter('fin', $fin, Types::DATE_IMMUTABLE);
    }
}
