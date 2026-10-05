<?php

namespace App\Repository;

use App\Entity\Lot;
use App\Entity\MouvementStock;
use App\Entity\Produit;
use App\Enum\TypeMouvement;
use App\Util\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Produit>
 */
class ProduitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Produit::class);
    }

    /**
     * Recherche par nom commercial, DCI ou code-barres exact (VE-01 : douchette).
     *
     * @return Page<Produit>
     */
    public function rechercher(?string $recherche, ?int $categorieId, bool $archives, int $page): Page
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c', 'cp', 'e', 'f')
            ->leftJoin('p.categorie', 'c')
            ->leftJoin('c.parent', 'cp')
            ->leftJoin('p.etagere', 'e')
            ->leftJoin('p.forme', 'f')
            ->andWhere('p.actif = :actif')->setParameter('actif', !$archives)
            ->orderBy('p.nomCommercial', 'ASC');

        if (null !== $recherche && '' !== trim($recherche)) {
            $q = trim($recherche);
            $qb->andWhere('p.codeBarres = :exact OR p.nomCommercial LIKE :debut OR p.dci LIKE :debut OR p.nomCommercial LIKE :mot OR p.dci LIKE :mot')
                ->setParameter('exact', $q)
                ->setParameter('debut', addcslashes($q, '%_').'%')
                ->setParameter('mot', '% '.addcslashes($q, '%_').'%');
        }
        if (null !== $categorieId) {
            $qb->andWhere('c.id = :cat OR cp.id = :cat')->setParameter('cat', $categorieId);
        }

        /** @var Page<Produit> */
        return Page::depuis($qb, $page);
    }

    public function parCodeBarres(string $codeBarres): ?Produit
    {
        return $this->findOneBy(['codeBarres' => str_replace(' ', '', trim($codeBarres))]);
    }

    /**
     * Produits actifs dont le stock disponible est au niveau du seuil d'alerte ou en dessous (ST-05).
     * Un stock à zéro est toujours signalé, même sans seuil.
     */
    public function sousLeSeuil(\DateTimeImmutable $jour): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.actif = true')
            ->andWhere(\sprintf('(SELECT COALESCE(SUM(ls.quantiteRestante), 0) FROM %s ls WHERE ls.produit = p AND ls.datePeremption > :jour) <= p.seuilAlerte', Lot::class))
            ->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->orderBy('p.nomCommercial', 'ASC');
    }

    /**
     * Produits actifs en stock depuis au moins 90 jours sans aucune vente sur cette période (ST-05).
     */
    public function dormants(\DateTimeImmutable $jour, int $jours = 90): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.actif = true')
            ->andWhere(\sprintf('EXISTS (SELECT ld.id FROM %s ld WHERE ld.produit = p AND ld.quantiteRestante > 0 AND ld.dateReception <= :depuis)', Lot::class))
            ->andWhere(\sprintf('NOT EXISTS (SELECT md.id FROM %s md JOIN md.lot lm WHERE lm.produit = p AND md.type = :vente AND md.date >= :depuis)', MouvementStock::class))
            ->setParameter('depuis', $jour->modify(\sprintf('-%d days', $jours)), Types::DATE_IMMUTABLE)
            ->setParameter('vente', TypeMouvement::Vente)
            ->orderBy('p.nomCommercial', 'ASC');
    }
}
