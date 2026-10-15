<?php

namespace App\Repository;

use App\Entity\Utilisateur;
use App\Entity\VenteHorsLigne;
use App\Enum\StatutVenteHorsLigne;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VenteHorsLigne>
 */
class VenteHorsLigneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VenteHorsLigne::class);
    }

    public function parUuid(string $uuid): ?VenteHorsLigne
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * Ventes hors ligne en anomalie d'un vendeur, dans l'ordre où elles ont été faites.
     *
     * @return list<VenteHorsLigne>
     */
    public function enAnomalieDe(Utilisateur $vendeur): array
    {
        /** @var list<VenteHorsLigne> */
        return $this->createQueryBuilder('h')
            ->andWhere('h.vendeur = :v')->setParameter('v', $vendeur)
            ->andWhere('h.statut = :statut')->setParameter('statut', StatutVenteHorsLigne::Anomalie)
            ->orderBy('h.venduLe', 'ASC')->addOrderBy('h.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function nombreEnAnomalie(?Utilisateur $vendeur = null): int
    {
        $qb = $this->createQueryBuilder('h')->select('COUNT(h.id)')
            ->andWhere('h.statut = :statut')->setParameter('statut', StatutVenteHorsLigne::Anomalie);
        if (null !== $vendeur) {
            $qb->andWhere('h.vendeur = :v')->setParameter('v', $vendeur);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Ventes hors ligne reçues, les anomalies d'abord ; limitées à un vendeur si demandé.
     *
     * @return Page<VenteHorsLigne>
     */
    public function liste(?Utilisateur $vendeur, int $page): Page
    {
        $qb = $this->createQueryBuilder('h')
            ->join('h.vendeur', 'u')->addSelect('u')
            ->leftJoin('h.vente', 'v')->addSelect('v')
            ->addSelect('CASE WHEN h.statut = :anomalie THEN 0 ELSE 1 END AS HIDDEN ordre')
            ->setParameter('anomalie', StatutVenteHorsLigne::Anomalie)
            ->orderBy('ordre', 'ASC')->addOrderBy('h.venduLe', 'DESC')->addOrderBy('h.id', 'DESC');
        if (null !== $vendeur) {
            $qb->andWhere('h.vendeur = :v')->setParameter('v', $vendeur);
        }

        /** @var Page<VenteHorsLigne> */
        return Page::depuis($qb, $page);
    }
}
