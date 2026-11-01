<?php

namespace App\Repository;

use App\Entity\Categorie;
use App\Entity\Entrepot;
use App\Entity\Etagere;
use App\Entity\Lot;
use App\Entity\MouvementStock;
use App\Entity\ParametrePharmacie;
use App\Entity\Pharmacie;
use App\Entity\Produit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Un lot est « disponible » si sa date de péremption est postérieure au jour donné (RG-05),
 * ou s'il n'a pas de date de péremption (réception sans date : le lot sort en dernier).
 *
 * Sauf mention contraire, les requêtes ne voient que le stock de l'officine (lots sans entrepôt) : c'est lui qu'on
 * vend, qu'on surveille (alertes, rupture) et qu'on inventorie. Le stock des entrepôts se lit à part.
 *
 * @extends ServiceEntityRepository<Lot>
 */
class LotRepository extends ServiceEntityRepository
{
    /** Tri FEFO : les lots sans date de péremption passent après tous les autres. */
    private const SANS_DATE = 'CASE WHEN l.datePeremption IS NULL THEN 1 ELSE 0 END AS HIDDEN sansDate';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Lot::class);
    }

    /**
     * Lots encore en stock d'un produit dans l'officine (ou dans l'entrepôt donné), du premier au dernier périmé.
     *
     * @return list<Lot>
     */
    public function enStock(Produit $produit, ?Entrepot $entrepot = null): array
    {
        /** @var list<Lot> */
        return self::dans($this->createQueryBuilder('l'), $entrepot)
            ->leftJoin('l.fournisseur', 'f')->addSelect('f')
            ->andWhere('l.produit = :produit')->setParameter('produit', $produit)
            ->andWhere('l.quantiteRestante > 0')
            ->addSelect(self::SANS_DATE)->orderBy('sansDate', 'ASC')->addOrderBy('l.datePeremption', 'ASC')->addOrderBy('l.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Lots d'un produit encore présents dans les entrepôts, entrepôt par entrepôt puis du premier au dernier périmé.
     *
     * @return list<Lot>
     */
    public function enEntrepots(Produit $produit): array
    {
        /** @var list<Lot> */
        return $this->createQueryBuilder('l')
            ->join('l.entrepot', 'e')->addSelect('e')
            ->andWhere('l.produit = :produit')->setParameter('produit', $produit)
            ->andWhere('l.quantiteRestante > 0')
            ->addSelect(self::SANS_DATE)->orderBy('e.nom', 'ASC')->addOrderBy('sansDate', 'ASC')->addOrderBy('l.datePeremption', 'ASC')->addOrderBy('l.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Lot d'un emplacement qui a les mêmes numéro, péremption et prix d'achat qu'un lot transféré : il le reçoit
     * au lieu d'une copie (un lot qui fait l'aller-retour entre l'officine et un entrepôt reste une seule ligne).
     */
    public function memeLotDans(Lot $lot, ?Entrepot $entrepot): ?Lot
    {
        $qb = self::dans($this->createQueryBuilder('l'), $entrepot)
            ->andWhere('l.produit = :produit')->setParameter('produit', $lot->getProduit())
            ->andWhere('l.numero = :numero')->setParameter('numero', $lot->getNumero())
            ->andWhere('l.prixAchat = :prix')->setParameter('prix', $lot->getPrixAchat())
            ->orderBy('l.id', 'ASC')->setMaxResults(1);
        if (null === $lot->getDatePeremption()) {
            $qb->andWhere('l.datePeremption IS NULL');
        } else {
            $qb->andWhere('l.datePeremption = :peremption')->setParameter('peremption', $lot->getDatePeremption(), Types::DATE_IMMUTABLE);
        }

        /** @var Lot|null */
        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Lots vendables d'un produit dans l'ordre FEFO (RG-04), verrouillés jusqu'à la fin de la transaction.
     * Sans entrepôt : ceux de l'officine.
     *
     * @return list<Lot>
     */
    public function verrouillerDisponibles(Produit $produit, \DateTimeImmutable $jour, ?Entrepot $entrepot = null): array
    {
        /** @var list<Lot> */
        return self::dans($this->createQueryBuilder('l'), $entrepot)
            ->andWhere('l.produit = :produit')->setParameter('produit', $produit)
            ->andWhere('l.quantiteRestante > 0')
            ->andWhere('(l.datePeremption IS NULL OR l.datePeremption > :jour)')->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->addSelect(self::SANS_DATE)->orderBy('sansDate', 'ASC')->addOrderBy('l.datePeremption', 'ASC')->addOrderBy('l.id', 'ASC')
            ->getQuery()
            // Quantités relues en base sous verrou, même pour un lot déjà chargé : sinon Doctrine garderait la valeur
            // en mémoire, peut-être lue avant qu'une autre requête ne prélève dans ce lot.
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    public function verrouiller(Lot $lot): void
    {
        $this->getEntityManager()->lock($lot, LockMode::PESSIMISTIC_WRITE);
        $this->getEntityManager()->refresh($lot);
    }

    /**
     * RG-03 : stock = somme des quantités restantes des lots non périmés, dans l'officine (ou l'entrepôt donné).
     */
    public function stockDisponible(Produit $produit, \DateTimeImmutable $jour, ?Entrepot $entrepot = null): int
    {
        return (int) self::dans($this->createQueryBuilder('l'), $entrepot)
            ->select('COALESCE(SUM(l.quantiteRestante), 0)')
            ->andWhere('l.produit = :produit')->setParameter('produit', $produit)
            ->andWhere('(l.datePeremption IS NULL OR l.datePeremption > :jour)')->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->getQuery()->getSingleScalarResult();
    }

    /** Lots périmés encore en stock (ST-05). */
    public function perimesEnStock(\DateTimeImmutable $jour): QueryBuilder
    {
        return $this->enStockAvecProduit()
            ->andWhere('l.datePeremption <= :jour')->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->orderBy('l.datePeremption', 'ASC');
    }

    /** Lots qui périment dans le délai d'alerte (ST-05). */
    public function peremptionProche(\DateTimeImmutable $jour, int $delaiJours): QueryBuilder
    {
        return $this->enStockAvecProduit()
            ->andWhere('(l.datePeremption IS NULL OR l.datePeremption > :jour)')->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->andWhere('l.datePeremption <= :limite')->setParameter('limite', $jour->modify(\sprintf('+%d days', $delaiJours)), Types::DATE_IMMUTABLE)
            ->orderBy('l.datePeremption', 'ASC');
    }

    /**
     * Lots à compter pour un inventaire (ST-06) de l'officine ou d'un entrepôt, dans l'ordre du rayon : étagère (officine seulement), produit, péremption.
     * Les lots périmés sont inclus : ils sont physiquement présents.
     *
     * @return list<Lot>
     */
    public function pourInventaire(?Etagere $etagere, ?Categorie $categorie, ?Entrepot $entrepot = null): array
    {
        $qb = $this->enStockAvecProduit($entrepot)->leftJoin('p.etagere', 'e');
        // Les étagères sont celles de l'officine : un entrepôt se compte dans l'ordre des produits.
        if (null === $entrepot) {
            $qb->addOrderBy('e.code', 'ASC');
        }
        $qb->addOrderBy('p.nomCommercial', 'ASC')->addSelect(self::SANS_DATE)->addOrderBy('sansDate', 'ASC')->addOrderBy('l.datePeremption', 'ASC');
        if (null !== $etagere) {
            $qb->andWhere('p.etagere = :etagere')->setParameter('etagere', $etagere);
        }
        if (null !== $categorie) {
            $qb->leftJoin('p.categorie', 'c')
                ->andWhere('c = :categorie OR c.parent = :categorie')->setParameter('categorie', $categorie);
        }

        /** @var list<Lot> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Valeur du stock au prix d'achat des lots (ST-07), par catégorie principale : officine et entrepôts ensemble,
     * car c'est tout le stock que possède la pharmacie.
     *
     * @return list<array{categorie: string|null, disponible: int, perime: int, quantite: int}>
     */
    public function valorisationParCategorie(\DateTimeImmutable $jour): array
    {
        $lignes = $this->createQueryBuilder('l')
            ->select('COALESCE(cp.nom, c.nom) AS categorie')
            ->addSelect('SUM(CASE WHEN (l.datePeremption IS NULL OR l.datePeremption > :jour) THEN l.quantiteRestante * l.prixAchat ELSE 0 END) AS disponible')
            ->addSelect('SUM(CASE WHEN l.datePeremption <= :jour THEN l.quantiteRestante * l.prixAchat ELSE 0 END) AS perime')
            ->addSelect('SUM(CASE WHEN (l.datePeremption IS NULL OR l.datePeremption > :jour) THEN l.quantiteRestante ELSE 0 END) AS quantite')
            ->join('l.produit', 'p')
            ->leftJoin('p.categorie', 'c')
            ->leftJoin('c.parent', 'cp')
            ->andWhere('l.quantiteRestante > 0')
            ->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->groupBy('categorie')
            ->orderBy('disponible', 'DESC')
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $l) => [
            'categorie' => $l['categorie'],
            'disponible' => (int) $l['disponible'],
            'perime' => (int) $l['perime'],
            'quantite' => (int) $l['quantite'],
        ], $lignes);
    }

    /**
     * Stock disponible, quantité périmée et valeur au prix d'achat de chaque produit donné.
     * Pour un produit dont le stock est à zéro, « aZeroDepuis » donne le moment où il y est tombé
     * (null s'il n'a jamais été reçu) et « rupture » s'il y est depuis le délai du produit, ou à défaut
     * de la pharmacie, ou plus (au Mali, « rupture » veut dire que même le fournisseur n'en a plus).
     * Ces chiffres sont ceux de l'officine ; « entrepots » donne le stock disponible dans les entrepôts actifs. Un produit
     * qui attend dans un entrepôt n'est jamais « en rupture » : il suffit de le faire venir.
     *
     * @param iterable<Produit> $produits
     *
     * @return array<int, array{stock: int, perime: int, valeur: int, aZeroDepuis: ?\DateTimeImmutable, rupture: bool, entrepots: int}> indexé par id de produit
     */
    public function syntheseParProduit(iterable $produits, \DateTimeImmutable $jour): array
    {
        $ids = [];
        $synthese = [];
        $delais = [];
        $parPharmacie = [];
        foreach ($produits as $produit) {
            $ids[] = $produit->getId();
            $pharmacie = $produit->getPharmacie();
            $delais[(int) $produit->getId()] = $produit->getJoursAvantRupture()
                ?? ($parPharmacie[(int) $pharmacie?->getId()] ??= $this->joursAvantRupture($pharmacie));
            $synthese[(int) $produit->getId()] = ['stock' => 0, 'perime' => 0, 'valeur' => 0, 'aZeroDepuis' => null, 'rupture' => false, 'entrepots' => 0];
        }
        if ([] === $ids) {
            return [];
        }

        $lignes = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.produit) AS produit')
            ->addSelect('SUM(CASE WHEN (l.datePeremption IS NULL OR l.datePeremption > :jour) THEN l.quantiteRestante ELSE 0 END) AS stock')
            ->addSelect('SUM(CASE WHEN l.datePeremption <= :jour THEN l.quantiteRestante ELSE 0 END) AS perime')
            ->addSelect('SUM(CASE WHEN (l.datePeremption IS NULL OR l.datePeremption > :jour) THEN l.quantiteRestante * l.prixAchat ELSE 0 END) AS valeur')
            ->andWhere('l.produit IN (:ids)')->setParameter('ids', $ids)
            ->andWhere('l.quantiteRestante > 0')
            ->andWhere('l.entrepot IS NULL')
            ->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->groupBy('l.produit')
            ->getQuery()->getArrayResult();

        foreach ($lignes as $l) {
            $synthese[(int) $l['produit']] = ['stock' => (int) $l['stock'], 'perime' => (int) $l['perime'], 'valeur' => (int) $l['valeur']] + $synthese[(int) $l['produit']];
        }
        foreach ($this->disponibleEnEntrepots($ids, $jour) as $id => $quantite) {
            $synthese[$id]['entrepots'] = $quantite;
        }

        $aZero = array_keys(array_filter($synthese, static fn (array $s) => 0 === $s['stock']));
        foreach ($this->aZeroDepuis($aZero, $jour) as $id => $depuis) {
            $synthese[$id]['aZeroDepuis'] = $depuis;
            $synthese[$id]['rupture'] = 0 === $synthese[$id]['entrepots'] && $depuis < $jour->modify(\sprintf('-%d days', $delais[$id] - 1));
        }

        return $synthese;
    }

    /** Délai avant le badge « Rupture » fixé par la pharmacie (paramètres), ou le délai par défaut. */
    private function joursAvantRupture(?Pharmacie $pharmacie): int
    {
        $parametres = null !== $pharmacie ? $this->getEntityManager()->getRepository(ParametrePharmacie::class)->findOneBy(['pharmacie' => $pharmacie]) : null;

        return $parametres?->getJoursAvantRupture() ?? ParametrePharmacie::JOURS_AVANT_RUPTURE_PAR_DEFAUT;
    }

    /**
     * Moment où le stock disponible de chaque produit donné (supposé à zéro) est tombé à zéro :
     * dernier mouvement sur un lot encore valable, ou péremption d'un lot qui avait encore du stock.
     *
     * @param list<int> $produits
     *
     * @return array<int, \DateTimeImmutable> indexé par id de produit ; absent si le produit n'a jamais été reçu
     */
    private function aZeroDepuis(array $produits, \DateTimeImmutable $jour): array
    {
        if ([] === $produits) {
            return [];
        }

        $lots = $this->createQueryBuilder('l')
            ->select('l.id', 'IDENTITY(l.produit) AS produit', 'l.datePeremption', 'l.quantiteRestante', 'l.dateReception')
            ->andWhere('l.produit IN (:produits)')->setParameter('produits', $produits)
            ->andWhere('l.entrepot IS NULL')
            ->getQuery()->getArrayResult();
        if ([] === $lots) {
            return [];
        }
        $ids = array_column($lots, 'id');

        // Dernier mouvement tant que le lot comptait dans le stock (pas après sa péremption).
        $derniers = [];
        foreach ($this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(m.lot) AS lot', 'MAX(m.date) AS date')
            ->from(MouvementStock::class, 'm')->join('m.lot', 'l')
            ->andWhere('m.lot IN (:lots)')->setParameter('lots', $ids)
            ->andWhere('l.datePeremption IS NULL OR m.date < l.datePeremption')
            ->groupBy('m.lot')
            ->getQuery()->getArrayResult() as $m) {
            $derniers[(int) $m['lot']] = new \DateTimeImmutable($m['date']);
        }
        // Lots sortis du stock (destruction, retour) après leur péremption : ils en avaient encore à cette date.
        $sortisApres = array_flip(array_map('intval', $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT IDENTITY(m.lot)')
            ->from(MouvementStock::class, 'm')->join('m.lot', 'l')
            ->andWhere('m.lot IN (:lots)')->setParameter('lots', $ids)
            ->andWhere('m.date >= l.datePeremption')
            ->getQuery()->getSingleColumnResult()));

        $depuis = [];
        foreach ($lots as $lot) {
            $date = $derniers[$lot['id']] ?? $lot['dateReception'];
            $perime = $lot['datePeremption'];
            if (null !== $perime && $perime <= $jour && ($lot['quantiteRestante'] > 0 || isset($sortisApres[$lot['id']]))) {
                $date = max($date, $perime);
            }
            $produit = (int) $lot['produit'];
            $depuis[$produit] = isset($depuis[$produit]) ? max($depuis[$produit], $date) : $date;
        }

        return $depuis;
    }

    /**
     * Stock vendable (lots non périmés) de chaque produit de la pharmacie, pour la caisse hors ligne.
     *
     * @return array<int, int> quantité indexée par id de produit (produits en stock seulement)
     */
    public function stockVendableParProduit(\DateTimeImmutable $jour): array
    {
        $lignes = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.produit) AS produit', 'SUM(l.quantiteRestante) AS stock')
            ->andWhere('l.quantiteRestante > 0')
            ->andWhere('l.entrepot IS NULL')
            ->andWhere('(l.datePeremption IS NULL OR l.datePeremption > :jour)')
            ->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->groupBy('l.produit')
            ->getQuery()->getArrayResult();

        $stocks = [];
        foreach ($lignes as $l) {
            $stocks[(int) $l['produit']] = (int) $l['stock'];
        }

        return $stocks;
    }

    /**
     * Stock disponible (lots non périmés) dans les entrepôts actifs, par produit.
     *
     * @param list<int|null> $produits ids de produits
     *
     * @return array<int, int> quantité indexée par id de produit (produits présents en entrepôt seulement)
     */
    public function disponibleEnEntrepots(array $produits, \DateTimeImmutable $jour): array
    {
        if ([] === $produits) {
            return [];
        }
        $lignes = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.produit) AS produit', 'SUM(l.quantiteRestante) AS stock')
            ->join('l.entrepot', 'e')
            ->andWhere('e.actif = true')
            ->andWhere('l.produit IN (:produits)')->setParameter('produits', $produits)
            ->andWhere('l.quantiteRestante > 0')
            ->andWhere('(l.datePeremption IS NULL OR l.datePeremption > :jour)')->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->groupBy('l.produit')
            ->getQuery()->getArrayResult();

        $stocks = [];
        foreach ($lignes as $l) {
            $stocks[(int) $l['produit']] = (int) $l['stock'];
        }

        return $stocks;
    }

    /**
     * Stock disponible d'un produit dans chaque entrepôt actif qui en a.
     *
     * @return list<array{entrepot: Entrepot, quantite: int}> par nom d'entrepôt
     */
    public function disponibleParEntrepot(Produit $produit, \DateTimeImmutable $jour): array
    {
        $lignes = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.entrepot) AS entrepot', 'SUM(l.quantiteRestante) AS quantite')
            ->join('l.entrepot', 'e')
            ->andWhere('e.actif = true')
            ->andWhere('l.produit = :produit')->setParameter('produit', $produit)
            ->andWhere('l.quantiteRestante > 0')
            ->andWhere('(l.datePeremption IS NULL OR l.datePeremption > :jour)')->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->groupBy('l.entrepot')
            ->getQuery()->getArrayResult();

        $resultat = [];
        foreach ($lignes as $l) {
            $entrepot = $this->getEntityManager()->find(Entrepot::class, (int) $l['entrepot']);
            if (null !== $entrepot) {
                $resultat[] = ['entrepot' => $entrepot, 'quantite' => (int) $l['quantite']];
            }
        }
        usort($resultat, static fn (array $a, array $b) => strcoll($a['entrepot']->getNom(), $b['entrepot']->getNom()));

        return $resultat;
    }

    /**
     * Contenu d'un entrepôt, produit par produit : quantité disponible, quantité périmée, valeur au prix d'achat
     * et date de péremption la plus proche.
     *
     * @return list<array{produit: Produit, disponible: int, perime: int, valeur: int, peremption: ?\DateTimeImmutable}>
     */
    public function contenu(Entrepot $entrepot, \DateTimeImmutable $jour, ?string $recherche = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.produit) AS produit')
            ->addSelect('SUM(CASE WHEN (l.datePeremption IS NULL OR l.datePeremption > :jour) THEN l.quantiteRestante ELSE 0 END) AS disponible')
            ->addSelect('SUM(CASE WHEN l.datePeremption <= :jour THEN l.quantiteRestante ELSE 0 END) AS perime')
            ->addSelect('SUM(l.quantiteRestante * l.prixAchat) AS valeur')
            ->addSelect('MIN(l.datePeremption) AS peremption')
            ->join('l.produit', 'p')
            ->andWhere('l.entrepot = :entrepot')->setParameter('entrepot', $entrepot)
            ->andWhere('l.quantiteRestante > 0')
            ->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->groupBy('l.produit')
            ->addGroupBy('p.nomCommercial')
            ->orderBy('p.nomCommercial', 'ASC');
        if (null !== $recherche && '' !== trim($recherche)) {
            $qb->andWhere('p.nomCommercial LIKE :q OR p.dci LIKE :q')->setParameter('q', '%'.addcslashes(trim($recherche), '%_').'%');
        }
        $lignes = $qb->getQuery()->getArrayResult();
        if ([] === $lignes) {
            return [];
        }

        $produits = [];
        foreach ($this->getEntityManager()->getRepository(Produit::class)->findBy(['id' => array_column($lignes, 'produit')]) as $produit) {
            $produits[(int) $produit->getId()] = $produit;
        }

        return array_values(array_map(static fn (array $l) => [
            'produit' => $produits[(int) $l['produit']],
            'disponible' => (int) $l['disponible'],
            'perime' => (int) $l['perime'],
            'valeur' => (int) $l['valeur'],
            'peremption' => null !== $l['peremption'] ? new \DateTimeImmutable($l['peremption']) : null,
        ], array_filter($lignes, static fn (array $l) => isset($produits[(int) $l['produit']]))));
    }

    /**
     * Valeur au prix d'achat du stock disponible et périmé de chaque emplacement (officine puis entrepôts).
     *
     * @return list<array{entrepot: ?Entrepot, disponible: int, perime: int, quantite: int}>
     */
    public function valorisationParEmplacement(\DateTimeImmutable $jour): array
    {
        $lignes = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.entrepot) AS entrepot')
            ->addSelect('SUM(CASE WHEN (l.datePeremption IS NULL OR l.datePeremption > :jour) THEN l.quantiteRestante * l.prixAchat ELSE 0 END) AS disponible')
            ->addSelect('SUM(CASE WHEN l.datePeremption <= :jour THEN l.quantiteRestante * l.prixAchat ELSE 0 END) AS perime')
            ->addSelect('SUM(CASE WHEN (l.datePeremption IS NULL OR l.datePeremption > :jour) THEN l.quantiteRestante ELSE 0 END) AS quantite')
            ->andWhere('l.quantiteRestante > 0')
            ->setParameter('jour', $jour, Types::DATE_IMMUTABLE)
            ->groupBy('l.entrepot')
            ->getQuery()->getArrayResult();

        $resultat = [];
        foreach ($lignes as $l) {
            $resultat[] = [
                'entrepot' => null !== $l['entrepot'] ? $this->getEntityManager()->find(Entrepot::class, (int) $l['entrepot']) : null,
                'disponible' => (int) $l['disponible'],
                'perime' => (int) $l['perime'],
                'quantite' => (int) $l['quantite'],
            ];
        }
        usort($resultat, static fn (array $a, array $b) => [null !== $a['entrepot'], $a['entrepot']?->getNom()] <=> [null !== $b['entrepot'], $b['entrepot']?->getNom()]);

        return $resultat;
    }

    /** Limite une requête sur les lots (alias « l ») à l'officine, ou à l'entrepôt donné. */
    private static function dans(QueryBuilder $qb, ?Entrepot $entrepot): QueryBuilder
    {
        return null === $entrepot
            ? $qb->andWhere('l.entrepot IS NULL')
            : $qb->andWhere('l.entrepot = :entrepot')->setParameter('entrepot', $entrepot);
    }

    /** Lots encore en stock à un emplacement (null = officine), avec leur produit (alertes, inventaire). */
    private function enStockAvecProduit(?Entrepot $entrepot = null): QueryBuilder
    {
        return self::dans($this->createQueryBuilder('l')
            ->join('l.produit', 'p')->addSelect('p')
            ->andWhere('l.quantiteRestante > 0'), $entrepot);
    }
}
