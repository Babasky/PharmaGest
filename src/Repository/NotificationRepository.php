<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Notifications de la pharmacie courante (filtre tenant).
 *
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function compterNonLues(Utilisateur $utilisateur): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.utilisateur = :utilisateur')->setParameter('utilisateur', $utilisateur)
            ->andWhere('n.lueLe IS NULL')
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<Notification>
     */
    public function dernieres(Utilisateur $utilisateur, int $nombre): array
    {
        /** @var list<Notification> */
        return $this->parUtilisateur($utilisateur)->setMaxResults($nombre)->getQuery()->getResult();
    }

    /**
     * @return Page<Notification>
     */
    public function liste(Utilisateur $utilisateur, bool $nonLuesSeulement, int $page): Page
    {
        $qb = $this->parUtilisateur($utilisateur);
        if ($nonLuesSeulement) {
            $qb->andWhere('n.lueLe IS NULL');
        }

        /** @var Page<Notification> */
        return Page::depuis($qb, $page);
    }

    public function marquerToutesLues(Utilisateur $utilisateur, Pharmacie $pharmacie, \DateTimeImmutable $le): int
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.lueLe', ':le')->setParameter('le', $le)
            ->andWhere('n.utilisateur = :utilisateur')->setParameter('utilisateur', $utilisateur)
            ->andWhere('n.pharmacie = :pharmacie')->setParameter('pharmacie', $pharmacie)
            ->andWhere('n.lueLe IS NULL')
            ->getQuery()->execute();
    }

    /**
     * Clés déjà notifiées à un utilisateur dans la pharmacie courante.
     *
     * @param list<string> $cles
     *
     * @return list<string>
     */
    public function clesExistantes(Utilisateur $utilisateur, array $cles): array
    {
        if ([] === $cles) {
            return [];
        }

        /** @var list<string> */
        return $this->createQueryBuilder('n')
            ->select('n.cle')
            ->andWhere('n.utilisateur = :utilisateur')->setParameter('utilisateur', $utilisateur)
            ->andWhere('n.cle IN (:cles)')->setParameter('cles', $cles)
            ->getQuery()->getSingleColumnResult();
    }

    /** Supprime les notifications lues avant une date (ce ne sont pas des traces d'audit). */
    public function purgerLuesAvant(\DateTimeImmutable $date): int
    {
        return $this->createQueryBuilder('n')
            ->delete()
            ->andWhere('n.lueLe IS NOT NULL')
            ->andWhere('n.lueLe < :date')->setParameter('date', $date)
            ->getQuery()->execute();
    }

    private function parUtilisateur(Utilisateur $utilisateur): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.utilisateur = :utilisateur')->setParameter('utilisateur', $utilisateur)
            ->orderBy('n.creeLe', 'DESC')
            ->addOrderBy('n.id', 'DESC');
    }
}
