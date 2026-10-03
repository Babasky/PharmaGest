<?php

namespace App\Repository;

use App\Entity\Affectation;
use App\Entity\Pharmacie;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Affectation est une entité tenant : ses requêtes sont filtrées sur la pharmacie courante.
 * Les méthodes qui doivent voir toutes les pharmacies d'un utilisateur passent par
 * {@see \App\Tenant\TenantContext::sansFiltre()}.
 *
 * @extends ServiceEntityRepository<Affectation>
 */
class AffectationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Affectation::class);
    }

    /**
     * Pharmacies auxquelles l'utilisateur a accès, triées par nom (à appeler hors filtre tenant).
     *
     * @return list<Affectation>
     */
    public function activesDe(Utilisateur $utilisateur): array
    {
        /** @var list<Affectation> */
        return $this->createQueryBuilder('a')
            ->addSelect('p')
            ->join('a.pharmacie', 'p')
            ->andWhere('a.utilisateur = :u')
            ->andWhere('a.actif = true')
            ->setParameter('u', $utilisateur)
            ->orderBy('p.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Équipe d'une pharmacie : propriétaires d'abord, puis par nom.
     *
     * @return list<Affectation>
     */
    public function equipe(Pharmacie $pharmacie): array
    {
        /** @var list<Affectation> */
        $affectations = $this->createQueryBuilder('a')
            ->addSelect('u')
            ->join('a.utilisateur', 'u')
            ->andWhere('a.pharmacie = :p')
            ->setParameter('p', $pharmacie)
            ->orderBy('u.nom', 'ASC')
            ->getQuery()
            ->getResult();

        $rang = array_flip(array_keys(Utilisateur::LIBELLES_ROLES));
        usort($affectations, static fn (Affectation $a, Affectation $b) => ($rang[$a->getUtilisateur()->getRole()] ?? 9) <=> ($rang[$b->getUtilisateur()->getRole()] ?? 9));

        return $affectations;
    }

    /** Utilisateurs qui occupent une place dans la limite de l'offre. */
    public function compterActives(Pharmacie $pharmacie): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->join('a.utilisateur', 'u')
            ->andWhere('a.pharmacie = :p')
            ->andWhere('a.actif = true')
            ->andWhere('u.actif = true')
            ->setParameter('p', $pharmacie)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Propriétaires actifs d'une pharmacie (à appeler hors filtre tenant depuis la plateforme).
     *
     * @return list<Utilisateur>
     */
    public function proprietaires(Pharmacie $pharmacie): array
    {
        /** @var list<Affectation> $affectations */
        $affectations = $this->createQueryBuilder('a')
            ->addSelect('u')
            ->join('a.utilisateur', 'u')
            ->andWhere('a.pharmacie = :p')
            ->andWhere('a.actif = true')
            ->andWhere('u.actif = true')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('p', $pharmacie)
            ->setParameter('role', '%"'.Utilisateur::ROLE_PROPRIETAIRE.'"%')
            ->getQuery()
            ->getResult();

        return array_map(static fn (Affectation $a) => $a->getUtilisateur(), $affectations);
    }

    /** Nombre de pharmacies non archivées détenues par un propriétaire (à appeler hors filtre tenant). */
    public function compterPharmaciesDe(Utilisateur $proprietaire): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->join('a.pharmacie', 'p')
            ->andWhere('a.utilisateur = :u')
            ->andWhere('p.archiveeLe IS NULL')
            ->setParameter('u', $proprietaire)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
