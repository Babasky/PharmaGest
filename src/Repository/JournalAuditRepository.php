<?php

namespace App\Repository;

use App\Entity\JournalAudit;
use App\Entity\Utilisateur;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JournalAudit>
 */
class JournalAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JournalAudit::class);
    }

    /**
     * Consultation filtrable du journal (AU-02), des entrées les plus récentes aux plus anciennes.
     *
     * Côté officine, le filtre tenant limite déjà aux entrées de la pharmacie courante. Côté plateforme
     * (`$actions` renseigné, filtre désactivé), seules les actions listées sont lues.
     *
     * @param list<string>|null $actions actions autorisées (null : toutes)
     *
     * @return Page<JournalAudit>
     */
    public function liste(
        ?Utilisateur $utilisateur,
        ?string $action,
        ?\DateTimeImmutable $du,
        ?\DateTimeImmutable $au,
        int $page,
        ?array $actions = null,
    ): Page {
        $qb = $this->createQueryBuilder('j')
            ->addSelect('u', 'p')
            ->leftJoin('j.utilisateur', 'u')
            ->leftJoin('j.pharmacie', 'p')
            ->orderBy('j.date', 'DESC')
            ->addOrderBy('j.id', 'DESC');

        if (null !== $actions) {
            $qb->andWhere('j.action IN (:actions)')->setParameter('actions', $actions);
        }
        if (null !== $utilisateur) {
            $qb->andWhere('j.utilisateur = :utilisateur')->setParameter('utilisateur', $utilisateur);
        }
        if (null !== $action && '' !== $action) {
            $qb->andWhere('j.action = :action')->setParameter('action', $action);
        }
        if (null !== $du) {
            $qb->andWhere('j.date >= :du')->setParameter('du', $du->setTime(0, 0));
        }
        if (null !== $au) {
            $qb->andWhere('j.date < :au')->setParameter('au', $au->setTime(0, 0)->modify('+1 day'));
        }

        /** @var Page<JournalAudit> */
        return Page::depuis($qb, $page);
    }

    /**
     * Utilisateurs ayant au moins une entrée dans le journal visible (liste du filtre « utilisateur »).
     *
     * @param list<string>|null $actions
     *
     * @return list<Utilisateur>
     */
    public function auteurs(?array $actions = null): array
    {
        $sousRequete = $this->createQueryBuilder('j')->select('IDENTITY(j.utilisateur)')->where('j.utilisateur IS NOT NULL');
        if (null !== $actions) {
            $sousRequete->andWhere('j.action IN (:actions)');
        }
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(Utilisateur::class, 'u')
            ->where('u.id IN ('.$sousRequete->getDQL().')')
            ->orderBy('u.nom');
        if (null !== $actions) {
            $qb->setParameter('actions', $actions);
        }

        /** @var list<Utilisateur> */
        return $qb->getQuery()->getResult();
    }

    /** Une action a-t-elle déjà été tracée pour cette pharmacie depuis une date ? (à appeler hors filtre tenant) */
    public function existe(string $action, \App\Entity\Pharmacie $pharmacie, \DateTimeImmutable $depuis): bool
    {
        return (bool) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->andWhere('j.action = :action')->setParameter('action', $action)
            ->andWhere('j.pharmacie = :pharmacie')->setParameter('pharmacie', $pharmacie)
            ->andWhere('j.date >= :depuis')->setParameter('depuis', $depuis)
            ->getQuery()->getSingleScalarResult();
    }
}
