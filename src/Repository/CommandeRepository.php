<?php

namespace App\Repository;

use App\Entity\Commande;
use App\Entity\Fournisseur;
use App\Entity\LigneCommande;
use App\Enum\StatutCommande;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Commande>
 */
class CommandeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Commande::class);
    }

    /**
     * @return Page<Commande>
     */
    public function liste(?Fournisseur $fournisseur, ?StatutCommande $statut, int $page): Page
    {
        $qb = $this->createQueryBuilder('c')
            ->join('c.fournisseur', 'f')->addSelect('f')
            ->orderBy('c.creeLe', 'DESC')->addOrderBy('c.id', 'DESC');
        if (null !== $fournisseur) {
            $qb->andWhere('c.fournisseur = :fournisseur')->setParameter('fournisseur', $fournisseur);
        }
        if (null !== $statut) {
            $qb->andWhere('c.statut = :statut')->setParameter('statut', $statut);
        }

        /** @var Page<Commande> */
        return Page::depuis($qb, $page);
    }

    /**
     * Quantités commandées et pas encore livrées, par produit (commandes envoyées ou reçues en partie).
     *
     * @return array<int, int> indexé par id de produit
     */
    public function quantitesAttendues(): array
    {
        $lignes = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(l.produit) AS produit')
            ->addSelect('SUM(CASE WHEN l.quantite > l.quantiteRecue THEN l.quantite - l.quantiteRecue ELSE 0 END) AS attendu')
            ->from(LigneCommande::class, 'l')
            ->join('l.commande', 'c')
            ->andWhere('c.statut IN (:statuts)')
            ->setParameter('statuts', [StatutCommande::Envoyee, StatutCommande::RecuePartiellement])
            ->groupBy('l.produit')
            ->getQuery()->getArrayResult();

        $attendus = [];
        foreach ($lignes as $l) {
            if ((int) $l['attendu'] > 0) {
                $attendus[(int) $l['produit']] = (int) $l['attendu'];
            }
        }

        return $attendus;
    }

    /**
     * @return array<string, int> nombre de commandes par statut
     */
    public function nombresParStatut(): array
    {
        $lignes = $this->createQueryBuilder('c')
            ->select('c.statut AS statut, COUNT(c.id) AS nombre')
            ->groupBy('c.statut')
            ->getQuery()->getArrayResult();

        $nombres = [];
        foreach ($lignes as $l) {
            $statut = $l['statut'] instanceof StatutCommande ? $l['statut']->value : (string) $l['statut'];
            $nombres[$statut] = (int) $l['nombre'];
        }

        return $nombres;
    }
}
