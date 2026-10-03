<?php

namespace App\Repository;

use App\Entity\Abonnement;
use App\Entity\Pharmacie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Abonnement>
 */
class AbonnementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Abonnement::class);
    }

    /**
     * @return list<Abonnement>
     */
    public function historique(Pharmacie $pharmacie): array
    {
        return $this->findBy(['pharmacie' => $pharmacie], ['datePaiement' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * @return list<Abonnement>
     */
    public function derniersPaiements(int $nombre): array
    {
        /** @var list<Abonnement> */
        return $this->createQueryBuilder('a')
            ->addSelect('p')
            ->join('a.pharmacie', 'p')
            ->orderBy('a.datePaiement', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($nombre)
            ->getQuery()
            ->getResult();
    }

    /**
     * Revenus d'abonnement par mois (clé « AAAA-MM »), mois sans paiement compris.
     *
     * @return array<string, int>
     */
    public function revenusParMois(\DateTimeImmutable $depuis, \DateTimeImmutable $jusqua): array
    {
        $mois = [];
        for ($m = $depuis->modify('first day of this month'); $m <= $jusqua; $m = $m->modify('+1 month')) {
            $mois[$m->format('Y-m')] = 0;
        }

        $lignes = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT DATE_FORMAT(date_paiement, '%Y-%m') AS mois, SUM(montant) AS total
             FROM abonnement WHERE date_paiement BETWEEN :du AND :au GROUP BY mois",
            ['du' => $depuis->modify('first day of this month')->format('Y-m-d'), 'au' => $jusqua->format('Y-m-d')],
        );
        foreach ($lignes as $ligne) {
            if (isset($mois[$ligne['mois']])) {
                $mois[$ligne['mois']] = (int) $ligne['total'];
            }
        }

        return $mois;
    }
}
