<?php

namespace App\Repository;

use App\Entity\CreanceAmo;
use App\Entity\OrganismeAmo;
use App\Entity\Vente;
use App\Enum\StatutCreance;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CreanceAmo>
 */
class CreanceAmoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CreanceAmo::class);
    }

    public function pourVente(Vente $vente): ?CreanceAmo
    {
        return $this->findOneBy(['vente' => $vente]);
    }

    /**
     * Créances en attente d'un organisme, sur aucun bordereau, pour des ventes de la période (AM-06).
     *
     * @return list<CreanceAmo>
     */
    public function disponibles(OrganismeAmo $organisme, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        /** @var list<CreanceAmo> */
        return $this->createQueryBuilder('c')
            ->where('c.organisme = :organisme AND c.statut = :statut AND c.bordereau IS NULL')
            ->andWhere('c.dateVente >= :debut AND c.dateVente < :fin')
            ->setParameter('organisme', $organisme)
            ->setParameter('statut', StatutCreance::EnAttente)
            ->setParameter('debut', $debut->setTime(0, 0))
            ->setParameter('fin', $fin->setTime(0, 0)->modify('+1 day'))
            ->orderBy('c.dateVente', 'ASC')->addOrderBy('c.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * @return Page<CreanceAmo>
     */
    public function liste(?OrganismeAmo $organisme, ?StatutCreance $statut, int $page): Page
    {
        $qb = $this->createQueryBuilder('c')
            ->join('c.vente', 'v')->addSelect('v')
            ->leftJoin('v.client', 'cl')->addSelect('cl')
            ->join('c.organisme', 'o')->addSelect('o')
            ->leftJoin('c.bordereau', 'b')->addSelect('b')
            ->orderBy('c.dateVente', 'DESC')->addOrderBy('c.id', 'DESC');
        if (null !== $organisme) {
            $qb->andWhere('c.organisme = :organisme')->setParameter('organisme', $organisme);
        }
        if (null !== $statut) {
            $qb->andWhere('c.statut = :statut')->setParameter('statut', $statut);
        } else {
            $qb->andWhere('c.statut <> :annulee')->setParameter('annulee', StatutCreance::Annulee);
        }

        /** @var Page<CreanceAmo> */
        return Page::depuis($qb, $page);
    }

    /**
     * Créances qui comptent dans l'encours : en attente, transmises ou payées partiellement (AM-10).
     *
     * @return list<CreanceAmo>
     */
    public function ouvertes(): array
    {
        /** @var list<CreanceAmo> */
        return $this->createQueryBuilder('c')
            ->join('c.organisme', 'o')->addSelect('o')
            ->where('c.statut IN (:ouverts)')
            ->setParameter('ouverts', StatutCreance::ouverts())
            ->getQuery()->getResult();
    }

    /**
     * Par organisme : montant transmis (créances sorties de l'attente) et montant rejeté (AM-10).
     *
     * @return array<int, array{transmis: int, rejete: int}> indexé par id d'organisme
     */
    public function rejetsParOrganisme(): array
    {
        /** @var list<array{organisme: int|string, transmis: int|string|null, rejete: int|string|null}> $lignes */
        $lignes = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.organisme) AS organisme')
            ->addSelect('SUM(c.montant) AS transmis')
            ->addSelect('SUM(CASE WHEN c.statut = :rejetee THEN c.montant - c.montantRegle ELSE 0 END) AS rejete')
            ->where('c.statut NOT IN (:exclus)')
            ->setParameter('rejetee', StatutCreance::Rejetee)
            ->setParameter('exclus', [StatutCreance::EnAttente, StatutCreance::Annulee])
            ->groupBy('c.organisme')
            ->getQuery()->getArrayResult();

        $resultat = [];
        foreach ($lignes as $ligne) {
            $resultat[(int) $ligne['organisme']] = ['transmis' => (int) $ligne['transmis'], 'rejete' => (int) $ligne['rejete']];
        }

        return $resultat;
    }
}
