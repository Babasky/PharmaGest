<?php

namespace App\Repository;

use App\Entity\SessionCaisse;
use App\Entity\Utilisateur;
use App\Entity\Vente;
use App\Enum\ModePaiement;
use App\Enum\StatutVente;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vente>
 */
class VenteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vente::class);
    }

    /** Panier en cours d'un vendeur. */
    public function panierDe(Utilisateur $vendeur): ?Vente
    {
        /** @var Vente|null */
        return $this->createQueryBuilder('v')
            ->andWhere('v.vendeur = :u')->setParameter('u', $vendeur)
            ->andWhere('v.statut = :statut')->setParameter('statut', StatutVente::EnCours)
            ->orderBy('v.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Ventes mises en attente dans la pharmacie (VE-07), les plus anciennes d'abord.
     *
     * @return list<Vente>
     */
    public function enAttente(): array
    {
        /** @var list<Vente> */
        return $this->createQueryBuilder('v')
            ->leftJoin('v.client', 'c')->addSelect('c')
            ->join('v.vendeur', 'u')->addSelect('u')
            ->andWhere('v.statut = :statut')->setParameter('statut', StatutVente::EnAttente)
            ->orderBy('v.creeLe', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Ventes envoyées à la caisse par les vendeurs, qui attendent leur encaissement, les plus anciennes d'abord.
     *
     * @return list<Vente>
     */
    public function aEncaisser(): array
    {
        /** @var list<Vente> */
        return $this->createQueryBuilder('v')
            ->leftJoin('v.client', 'c')->addSelect('c')
            ->join('v.vendeur', 'u')->addSelect('u')
            ->andWhere('v.statut = :statut')->setParameter('statut', StatutVente::AEncaisser)
            ->orderBy('v.valideeLe', 'ASC')->addOrderBy('v.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Ventes validées, à encaisser ou annulées, des plus récentes aux plus anciennes.
     *
     * @return Page<Vente>
     */
    public function liste(?string $recherche, ?\DateTimeImmutable $jour, ?StatutVente $statut, int $page): Page
    {
        $qb = $this->createQueryBuilder('v')
            ->leftJoin('v.client', 'c')->addSelect('c')
            ->join('v.vendeur', 'u')->addSelect('u')
            ->andWhere('v.statut IN (:statuts)')
            ->setParameter('statuts', null !== $statut ? [$statut] : [StatutVente::Validee, StatutVente::AEncaisser, StatutVente::Annulee])
            ->orderBy('v.valideeLe', 'DESC')->addOrderBy('v.id', 'DESC');

        if (null !== $recherche && '' !== trim($recherche)) {
            $qb->andWhere('v.numero LIKE :q OR c.nom LIKE :q')->setParameter('q', '%'.addcslashes(trim($recherche), '%_').'%');
        }
        if (null !== $jour) {
            $qb->andWhere('v.valideeLe >= :debut AND v.valideeLe < :fin')
                ->setParameter('debut', $jour->setTime(0, 0), Types::DATETIME_IMMUTABLE)
                ->setParameter('fin', $jour->setTime(0, 0)->modify('+1 day'), Types::DATETIME_IMMUTABLE);
        }

        /** @var Page<Vente> */
        return Page::depuis($qb, $page);
    }

    /**
     * Ventes encaissées dans une session (y compris celles annulées depuis).
     *
     * @return list<Vente>
     */
    public function deLaSession(SessionCaisse $session): array
    {
        /** @var list<Vente> */
        return $this->createQueryBuilder('v')
            ->leftJoin('v.paiements', 'p')->addSelect('p')
            ->andWhere('v.session = :s')->setParameter('s', $session)
            ->andWhere('v.statut IN (:statuts)')->setParameter('statuts', [StatutVente::Validee, StatutVente::Annulee])
            ->orderBy('v.valideeLe', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Nombre de ventes et chiffre d'affaires (total net, part AMO comprise) par jour, ventes annulées exclues.
     *
     * @return array<string, array{nombre: int, montant: int}> indexé par date « Y-m-d »
     */
    public function parJour(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $lignes = $this->createQueryBuilder('v')
            ->select('v.valideeLe AS le', 'v.totalNet AS montant')
            ->andWhere('v.statut = :statut')->setParameter('statut', StatutVente::Validee)
            ->andWhere('v.valideeLe >= :debut AND v.valideeLe < :fin')
            ->setParameter('debut', $debut, Types::DATETIME_IMMUTABLE)
            ->setParameter('fin', $fin, Types::DATETIME_IMMUTABLE)
            ->getQuery()->getArrayResult();

        $jours = [];
        foreach ($lignes as $l) {
            /** @var \DateTimeInterface $le */
            $le = $l['le'];
            $cle = $le->format('Y-m-d');
            $jours[$cle] ??= ['nombre' => 0, 'montant' => 0];
            ++$jours[$cle]['nombre'];
            $jours[$cle]['montant'] += (int) $l['montant'];
        }

        return $jours;
    }

    /** Montant payé dans un mode par les ventes d'une session ayant un statut donné. */
    public function totalPaye(SessionCaisse $session, ModePaiement $mode, StatutVente $statut): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COALESCE(SUM(p.montant), 0)')
            ->join('v.paiements', 'p')
            ->andWhere('v.session = :s')->setParameter('s', $session)
            ->andWhere('v.statut = :statut')->setParameter('statut', $statut)
            ->andWhere('p.mode = :mode')->setParameter('mode', $mode)
            ->getQuery()->getSingleScalarResult();
    }
}
