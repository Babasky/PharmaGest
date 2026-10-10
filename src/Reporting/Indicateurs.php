<?php

namespace App\Reporting;

use App\Amo\SuiviAmo;
use App\Entity\LigneVente;
use App\Entity\Paiement;
use App\Entity\Vente;
use App\Enum\ModePaiement;
use App\Enum\StatutVente;
use App\Enum\TypeVente;
use App\Repository\DepenseRepository;
use App\Repository\LotRepository;
use App\Repository\RecetteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Psr\Clock\ClockInterface;

/**
 * Calculs des tableaux de bord et des rapports (RA-02 à RA-06, RE-05). Les ventes annulées sont exclues ;
 * le chiffre d'affaires est le total net des ventes, part AMO comprise.
 */
class Indicateurs
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecetteRepository $recettes,
        private readonly DepenseRepository $depenses,
        private readonly LotRepository $lots,
        private readonly SuiviAmo $suiviAmo,
        private readonly ClockInterface $horloge,
    ) {
    }

    /**
     * Chiffre d'affaires, nombre de ventes, panier moyen et remises d'une période.
     *
     * @return array{ca: int, nombre: int, panier: int, remises: int, part_amo: int, encaisse: int, annulees: int, montant_annule: int}
     */
    public function ventes(Periode $periode): array
    {
        $r = $this->ventesValidees($periode)
            ->select('COUNT(v.id) AS nombre', 'COALESCE(SUM(v.totalNet), 0) AS ca', 'COALESCE(SUM(v.remise), 0) AS remises')
            ->addSelect('COALESCE(SUM(v.partAmo), 0) AS part_amo', 'COALESCE(SUM(v.montantEncaisse), 0) AS encaisse')
            ->getQuery()->getSingleResult();
        $annulees = $this->ventesDeLaPeriode($periode)
            ->select('COUNT(v.id) AS nombre', 'COALESCE(SUM(v.totalNet), 0) AS montant')
            ->andWhere('v.statut = :statut')->setParameter('statut', StatutVente::Annulee)
            ->getQuery()->getSingleResult();
        $nombre = (int) $r['nombre'];
        $ca = (int) $r['ca'];

        return [
            'ca' => $ca,
            'nombre' => $nombre,
            'panier' => $nombre > 0 ? (int) round($ca / $nombre) : 0,
            'remises' => (int) $r['remises'],
            'part_amo' => (int) $r['part_amo'],
            'encaisse' => (int) $r['encaisse'],
            'annulees' => (int) $annulees['nombre'],
            'montant_annule' => (int) $annulees['montant'],
        ];
    }

    /**
     * Recettes, dépenses et résultat (recettes − dépenses) d'une période.
     *
     * @return array{recettes: int, depenses: int, resultat: int}
     */
    public function finances(Periode $periode): array
    {
        $recettes = $this->recettes->total($periode->debut, $periode->fin);
        $depenses = $this->depenses->total($periode->debut, $periode->fin);

        return ['recettes' => $recettes, 'depenses' => $depenses, 'resultat' => $recettes - $depenses];
    }

    /** Créances AMO ni réglées ni rejetées, à ce jour. */
    public function encoursAmo(): int
    {
        return $this->suiviAmo->tableau()['total']['encours'];
    }

    /** Valeur du stock disponible (lots non périmés) au prix d'achat, à ce jour (ST-07). */
    public function valeurStock(): int
    {
        return array_sum(array_column($this->lots->valorisationParCategorie($this->horloge->now()->setTime(0, 0)), 'disponible'));
    }

    /**
     * Ventes par type (RA-05), dans l'ordre des types.
     *
     * @return list<array{type: TypeVente, nombre: int, montant: int}>
     */
    public function parType(Periode $periode): array
    {
        $resultats = [];
        foreach ($this->ventesValidees($periode)->select('v.type AS type', 'COUNT(v.id) AS nombre', 'SUM(v.totalNet) AS montant')->groupBy('v.type')->getQuery()->getArrayResult() as $l) {
            $type = $l['type'] instanceof TypeVente ? $l['type'] : TypeVente::from((string) $l['type']);
            $resultats[$type->value] = ['nombre' => (int) $l['nombre'], 'montant' => (int) $l['montant']];
        }

        return array_map(static fn (TypeVente $t) => ['type' => $t, ...($resultats[$t->value] ?? ['nombre' => 0, 'montant' => 0])], TypeVente::cases());
    }

    /**
     * Montants encaissés par mode de paiement (RA-05), dans l'ordre des modes.
     *
     * @return list<array{mode: ModePaiement, nombre: int, montant: int}>
     */
    public function parMode(Periode $periode): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('p.mode AS mode', 'COUNT(DISTINCT v.id) AS nombre', 'SUM(p.montant) AS montant')
            ->from(Paiement::class, 'p')
            ->join('p.vente', 'v')
            ->andWhere('v.statut = :statut')->setParameter('statut', StatutVente::Validee)
            ->andWhere('v.valideeLe >= :debut AND v.valideeLe < :fin')
            ->setParameter('debut', $periode->debutInstant(), Types::DATETIME_IMMUTABLE)
            ->setParameter('fin', $periode->finInstant(), Types::DATETIME_IMMUTABLE)
            ->groupBy('p.mode')
            ->getQuery()->getArrayResult();
        $resultats = [];
        foreach ($lignes as $l) {
            $mode = $l['mode'] instanceof ModePaiement ? $l['mode'] : ModePaiement::from((string) $l['mode']);
            $resultats[$mode->value] = ['nombre' => (int) $l['nombre'], 'montant' => (int) $l['montant']];
        }

        // Un ancien mode (carte) n'apparaît que s'il a encore servi sur la période.
        $modes = array_filter(ModePaiement::cases(), static fn (ModePaiement $m) => \in_array($m, ModePaiement::proposes(), true) || isset($resultats[$m->value]));

        return array_values(array_map(static fn (ModePaiement $m) => ['mode' => $m, ...($resultats[$m->value] ?? ['nombre' => 0, 'montant' => 0])], $modes));
    }

    /**
     * Produits les plus vendus (RA-06), en quantité ou en chiffre d'affaires. Le CA d'un produit est le montant
     * de ses lignes après remise de ligne (la remise sur le total d'une vente n'est pas répartie).
     *
     * @return list<array{produit: string, quantite: int, montant: int}>
     */
    public function topProduits(Periode $periode, string $critere = 'quantite', int $nombre = 10): array
    {
        $lignes = $this->lignesVendues($periode)
            ->select('p.id AS id', 'p.nomCommercial AS nom', 'p.dosage AS dosage', 'SUM(l.quantite) AS quantite', 'SUM(l.quantite * l.prixUnitaire - l.remise) AS montant')
            ->join('l.produit', 'p')
            ->groupBy('p.id')
            ->orderBy('montant' === $critere ? 'montant' : 'quantite', 'DESC')
            ->addOrderBy('montant' === $critere ? 'quantite' : 'montant', 'DESC')
            ->addOrderBy('p.nomCommercial', 'ASC')
            ->setMaxResults($nombre)
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $l) => [
            'produit' => trim($l['nom'].' '.($l['dosage'] ?? '')),
            'quantite' => (int) $l['quantite'],
            'montant' => (int) $l['montant'],
        ], $lignes);
    }

    /**
     * Ventes par catégorie principale de produit (RA-06).
     *
     * @return list<array{categorie: string, quantite: int, montant: int}>
     */
    public function parCategorie(Periode $periode): array
    {
        $lignes = $this->lignesVendues($periode)
            ->select('COALESCE(cp.nom, c.nom) AS categorie', 'SUM(l.quantite) AS quantite', 'SUM(l.quantite * l.prixUnitaire - l.remise) AS montant')
            ->join('l.produit', 'p')
            ->leftJoin('p.categorie', 'c')
            ->leftJoin('c.parent', 'cp')
            ->groupBy('categorie')
            ->orderBy('montant', 'DESC')
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $l) => [
            'categorie' => $l['categorie'] ?? 'Sans catégorie',
            'quantite' => (int) $l['quantite'],
            'montant' => (int) $l['montant'],
        ], $lignes);
    }

    /**
     * Ventes validées avec une remise (RE-04, RE-05), dans l'ordre chronologique.
     *
     * @return list<Vente>
     */
    public function ventesRemisees(Periode $periode): array
    {
        /** @var list<Vente> */
        return $this->ventesValidees($periode)
            ->join('v.vendeur', 'u')->addSelect('u')
            ->leftJoin('v.client', 'c')->addSelect('c')
            ->leftJoin('v.autorisePar', 'a')->addSelect('a')
            ->andWhere('v.remise > 0')
            ->orderBy('v.valideeLe', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Chiffre d'affaires par jour « Y-m-d ».
     *
     * @return array<string, int>
     */
    public function caParJour(Periode $periode): array
    {
        $jours = [];
        foreach ($this->ventesValidees($periode)->select('v.valideeLe AS le', 'v.totalNet AS montant')->getQuery()->getArrayResult() as $l) {
            /** @var \DateTimeInterface $le */
            $le = $l['le'];
            $jours[$le->format('Y-m-d')] = ($jours[$le->format('Y-m-d')] ?? 0) + (int) $l['montant'];
        }

        return $jours;
    }

    /**
     * Recettes, dépenses et solde (recettes − dépenses) des 12 mois qui finissent avec le mois donné (RA-03).
     *
     * @return list<array{mois: string, libelle: string, recettes: int, depenses: int, solde: int}>
     */
    public function douzeMois(\DateTimeImmutable $dernierMois): array
    {
        $fin = $dernierMois->modify('last day of this month')->setTime(0, 0);
        $debut = $fin->modify('first day of this month')->modify('-11 months');
        $recettes = $this->recettes->parMois($debut, $fin);
        $depenses = $this->depenses->parMois($debut, $fin);

        $mois = [];
        for ($m = $debut; $m <= $fin; $m = $m->modify('+1 month')) {
            $cle = $m->format('Y-m');
            $r = $recettes[$cle] ?? 0;
            $d = $depenses[$cle] ?? 0;
            $mois[] = ['mois' => $cle, 'libelle' => Periode::MOIS_COURTS[(int) $m->format('n')].' '.$m->format('y'), 'recettes' => $r, 'depenses' => $d, 'solde' => $r - $d];
        }

        return $mois;
    }

    /** Évolution en % par rapport à une valeur de référence (null si la référence est nulle). */
    public static function evolution(int $valeur, int $reference): ?float
    {
        return 0 === $reference ? null : round(($valeur - $reference) * 100 / abs($reference), 1);
    }

    private function ventesDeLaPeriode(Periode $periode): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->from(Vente::class, 'v')
            ->andWhere('v.valideeLe >= :debut AND v.valideeLe < :fin')
            ->setParameter('debut', $periode->debutInstant(), Types::DATETIME_IMMUTABLE)
            ->setParameter('fin', $periode->finInstant(), Types::DATETIME_IMMUTABLE);
    }

    private function ventesValidees(Periode $periode): QueryBuilder
    {
        return $this->ventesDeLaPeriode($periode)
            ->select('v')
            ->andWhere('v.statut = :valide')->setParameter('valide', StatutVente::Validee);
    }

    private function lignesVendues(Periode $periode): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->from(LigneVente::class, 'l')
            ->join('l.vente', 'v')
            ->andWhere('v.statut = :statut')->setParameter('statut', StatutVente::Validee)
            ->andWhere('v.valideeLe >= :debut AND v.valideeLe < :fin')
            ->setParameter('debut', $periode->debutInstant(), Types::DATETIME_IMMUTABLE)
            ->setParameter('fin', $periode->finInstant(), Types::DATETIME_IMMUTABLE);
    }
}
