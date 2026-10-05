<?php

namespace App\Reporting;

use App\Enum\ModeReglement;
use App\Enum\OrigineRecette;
use App\Repository\DepenseRepository;
use App\Repository\RecetteRepository;

/**
 * Rapports par période avec comparaison à la période précédente (RA-01) : ventes (RA-05, RA-06),
 * remises (RE-05) et finances (RA-02 à RA-04). Chacun s'affiche et s'exporte en Excel et en PDF (RA-11).
 */
class Rapports
{
    public const VENTES = 'ventes';
    public const REMISES = 'remises';
    public const FINANCES = 'finances';

    public const TITRES = [self::VENTES => 'Ventes', self::REMISES => 'Remises', self::FINANCES => 'Finances'];

    /** Rapports réservés au propriétaire (matrice des droits : rapports financiers). */
    public const FINANCIERS = [self::FINANCES];

    private const COULEURS = ['#198754', '#0d6efd', '#fd7e14', '#6f42c1', '#20c997', '#dc3545', '#ffc107', '#0dcaf0', '#6c757d', '#d63384'];

    public function __construct(
        private readonly Indicateurs $indicateurs,
        private readonly RecetteRepository $recettes,
        private readonly DepenseRepository $depenses,
    ) {
    }

    public function construire(string $code, Periode $periode): Rapport
    {
        return match ($code) {
            self::REMISES => $this->remises($periode),
            self::FINANCES => $this->finances($periode),
            default => $this->ventes($periode),
        };
    }

    public function ventes(Periode $periode): Rapport
    {
        $actuel = $this->indicateurs->ventes($periode);
        $precedent = $this->indicateurs->ventes($periode->precedente());
        $synthese = self::comparaison($periode, [
            ["Chiffre d'affaires", Tableau::MONTANT, $actuel['ca'], $precedent['ca']],
            ['Nombre de ventes', Tableau::NOMBRE, $actuel['nombre'], $precedent['nombre']],
            ['Panier moyen', Tableau::MONTANT, $actuel['panier'], $precedent['panier']],
            ['Remises accordées', Tableau::MONTANT, $actuel['remises'], $precedent['remises']],
            ['Part AMO (créances)', Tableau::MONTANT, $actuel['part_amo'], $precedent['part_amo']],
            ['Ventes annulées', Tableau::NOMBRE, $actuel['annulees'], $precedent['annulees']],
        ]);

        $types = $this->indicateurs->parType($periode);
        $parType = new Tableau('par-type', 'Ventes par type', ['Type' => Tableau::TEXTE, 'Ventes' => Tableau::NOMBRE, "Chiffre d'affaires" => Tableau::MONTANT, 'Part du CA' => Tableau::POURCENTAGE],
            array_map(static fn (array $t) => [$t['type']->libelle(), $t['nombre'], $t['montant'], self::part($t['montant'], $actuel['ca'])], $types),
            ['Total', $actuel['nombre'], $actuel['ca'], [] === $types ? null : 100.0]);

        $modes = $this->indicateurs->parMode($periode);
        $lignesModes = array_map(static fn (array $m) => [$m['mode']->libelle(), $m['nombre'], $m['montant'], self::part($m['montant'], $actuel['ca'])], $modes);
        $lignesModes[] = ['Part AMO (créance sur les organismes)', null, $actuel['part_amo'], self::part($actuel['part_amo'], $actuel['ca'])];
        $parMode = new Tableau('par-mode', 'Ventes par mode de paiement', ['Mode' => Tableau::TEXTE, 'Ventes' => Tableau::NOMBRE, 'Montant' => Tableau::MONTANT, 'Part du CA' => Tableau::POURCENTAGE],
            $lignesModes, ['Total', null, $actuel['ca'], 100.0],
            'Une vente payée en plusieurs modes compte dans chacun. La part AMO est encaissée au règlement du bordereau.');

        $colonnesTop = ['Rang' => Tableau::NOMBRE, 'Produit' => Tableau::TEXTE, 'Quantité' => Tableau::NOMBRE, "Chiffre d'affaires" => Tableau::MONTANT];
        $topQuantite = $this->indicateurs->topProduits($periode, 'quantite');
        $topMontant = $this->indicateurs->topProduits($periode, 'montant');
        $categories = $this->indicateurs->parCategorie($periode);
        $caLignes = array_sum(array_column($categories, 'montant'));

        $parJour = $this->indicateurs->caParJour($periode);
        $graphiques = [
            'types' => self::camembert('Ventes par type', array_map(static fn (array $t) => $t['type']->libelle(), $types), array_column($types, 'montant')),
            'modes' => self::camembert('Ventes par mode', array_column($lignesModes, 0), array_column($lignesModes, 2)),
            'categories' => self::barres(array_column($categories, 'categorie'), [["Chiffre d'affaires", array_column($categories, 'montant'), self::COULEURS[0]]]),
        ];
        if (Periode::JOUR !== $periode->type && $periode->nombreJours() <= 92) {
            $libelles = [];
            $valeurs = [];
            for ($jour = $periode->debut; $jour <= $periode->fin; $jour = $jour->modify('+1 day')) {
                $libelles[] = $jour->format('d/m');
                $valeurs[] = $parJour[$jour->format('Y-m-d')] ?? 0;
            }
            $graphiques['jours'] = self::barres($libelles, [["Chiffre d'affaires", $valeurs, self::COULEURS[0]]]);
        }

        return new Rapport(self::VENTES, 'Rapport des ventes', $periode, [
            $synthese,
            $parType,
            $parMode,
            new Tableau('top-quantite', 'Top 10 des produits en quantité', $colonnesTop, self::classement($topQuantite)),
            new Tableau('top-ca', "Top 10 des produits en chiffre d'affaires", $colonnesTop, self::classement($topMontant)),
            new Tableau('par-categorie', 'Ventes par catégorie', ['Catégorie' => Tableau::TEXTE, 'Quantité' => Tableau::NOMBRE, 'Montant' => Tableau::MONTANT, 'Part' => Tableau::POURCENTAGE],
                array_map(static fn (array $c) => [$c['categorie'], $c['quantite'], $c['montant'], self::part($c['montant'], $caLignes)], $categories),
                [] === $categories ? null : ['Total', array_sum(array_column($categories, 'quantite')), $caLignes, 100.0],
                'Montant des lignes après leur remise ; la remise sur le total d\'une vente n\'est pas répartie entre les produits.'),
        ], $graphiques);
    }

    public function remises(Periode $periode): Rapport
    {
        $ventes = $this->indicateurs->ventesRemisees($periode);
        $detail = [];
        $parVendeur = [];
        $parClient = [];
        $total = ['nombre' => 0, 'base' => 0, 'remise' => 0];
        foreach ($ventes as $vente) {
            // Base de la remise : le total, ou la part assuré d'une vente AMO (RG-09).
            $base = $vente->getTotalBrut() - $vente->getPartAmo();
            $vendeur = $vente->getVendeur()->getNom();
            $client = $vente->getClient()?->getNom() ?? '—';
            $detail[] = [$vente->getValideeLe(), $vente->getNumero(), $vendeur, $client, $base, $vente->getRemise(), self::part($vente->getRemise(), $base), $vente->getAutorisePar()?->getNom()];
            self::cumuler($parVendeur, $vendeur, $base, $vente->getRemise());
            self::cumuler($parClient, $client, $base, $vente->getRemise());
            ++$total['nombre'];
            $total['base'] += $base;
            $total['remise'] += $vente->getRemise();
        }
        $colonnes = ['Ventes remisées' => Tableau::NOMBRE, 'Montant avant remise' => Tableau::MONTANT, 'Remises' => Tableau::MONTANT, 'Taux moyen' => Tableau::POURCENTAGE];
        $groupes = static function (array $groupe): array {
            uasort($groupe, static fn (array $a, array $b) => $b['remise'] <=> $a['remise']);
            $lignes = [];
            foreach ($groupe as $nom => $g) {
                $lignes[] = [(string) $nom, $g['nombre'], $g['base'], $g['remise'], self::part($g['remise'], $g['base'])];
            }

            return $lignes;
        };
        $ligneTotal = [] === $ventes ? null : ['Total', $total['nombre'], $total['base'], $total['remise'], self::part($total['remise'], $total['base'])];
        $precedent = $this->indicateurs->ventes($periode->precedente());
        $actuel = $this->indicateurs->ventes($periode);

        return new Rapport(self::REMISES, 'Rapport des remises', $periode, [
            self::comparaison($periode, [
                ['Remises accordées', Tableau::MONTANT, $actuel['remises'], $precedent['remises']],
                ['Part du chiffre d\'affaires', Tableau::POURCENTAGE, self::part($actuel['remises'], $actuel['ca'] + $actuel['remises']), self::part($precedent['remises'], $precedent['ca'] + $precedent['remises'])],
            ]),
            new Tableau('par-vendeur', 'Remises par vendeur', ['Vendeur' => Tableau::TEXTE, ...$colonnes], $groupes($parVendeur), $ligneTotal),
            new Tableau('par-client', 'Remises par client', ['Client' => Tableau::TEXTE, ...$colonnes], $groupes($parClient), $ligneTotal),
            new Tableau('detail', 'Détail des ventes remisées', [
                'Date' => Tableau::DATE, 'Vente' => Tableau::TEXTE, 'Vendeur' => Tableau::TEXTE, 'Client' => Tableau::TEXTE,
                'Montant avant remise' => Tableau::MONTANT, 'Remise' => Tableau::MONTANT, 'Taux' => Tableau::POURCENTAGE, 'Autorisée par (PIN)' => Tableau::TEXTE,
            ], $detail, null === $ligneTotal ? null : ['Total', null, null, null, $total['base'], $total['remise'], $ligneTotal[4], null],
                'Sur une vente AMO, la remise porte sur la part assuré (RG-09). « Autorisée par » : remise au-delà du plafond ou vente sans ordonnance validée par le propriétaire.'),
        ]);
    }

    public function finances(Periode $periode): Rapport
    {
        $actuel = $this->indicateurs->finances($periode);
        $precedent = $this->indicateurs->finances($periode->precedente());

        $origines = $this->recettes->parOrigine($periode->debut, $periode->fin);
        $lignesOrigines = [];
        foreach ([OrigineRecette::Vente, OrigineRecette::Amo, OrigineRecette::Manuelle] as $origine) {
            $lignesOrigines[] = [$origine->libelle(), $origines[$origine->value] ?? 0, self::part($origines[$origine->value] ?? 0, $actuel['recettes'])];
        }

        $parMode = [];
        foreach ($this->recettes->retenues($periode->debut, $periode->fin) as $recette) {
            $parMode[$recette->getMode()->value] = ($parMode[$recette->getMode()->value] ?? 0) + $recette->getMontant();
        }
        $sorties = [];
        $depenses = $this->depenses->retenues($periode->debut, $periode->fin);
        foreach ($depenses as $depense) {
            $sorties[$depense->getMode()->value] = ($sorties[$depense->getMode()->value] ?? 0) + $depense->getMontant();
        }
        $lignesModes = [];
        foreach (ModeReglement::cases() as $mode) {
            $entree = $parMode[$mode->value] ?? 0;
            $sortie = $sorties[$mode->value] ?? 0;
            if (0 !== $entree || 0 !== $sortie) {
                $lignesModes[] = [$mode->libelle(), $entree, $sortie, $entree - $sortie];
            }
        }

        $categories = $this->depenses->parCategorie($periode->debut, $periode->fin);
        $mois = $this->indicateurs->douzeMois($periode->fin);

        return new Rapport(self::FINANCES, 'Rapport financier', $periode, [
            self::comparaison($periode, [
                ['Recettes', Tableau::MONTANT, $actuel['recettes'], $precedent['recettes']],
                ['Dépenses', Tableau::MONTANT, $actuel['depenses'], $precedent['depenses']],
                ['Résultat (recettes − dépenses)', Tableau::MONTANT, $actuel['resultat'], $precedent['resultat']],
            ]),
            new Tableau('recettes-origine', 'Recettes par origine', ['Origine' => Tableau::TEXTE, 'Montant' => Tableau::MONTANT, 'Part' => Tableau::POURCENTAGE],
                $lignesOrigines, ['Total', $actuel['recettes'], 0 === $actuel['recettes'] ? null : 100.0],
                'Ventes : montant encaissé, part assuré seulement pour l\'AMO (RG-10), annulations déduites. Règlements AMO : à la date du règlement.'),
            new Tableau('par-mode', 'Entrées et sorties par mode de paiement', ['Mode' => Tableau::TEXTE, 'Recettes' => Tableau::MONTANT, 'Dépenses' => Tableau::MONTANT, 'Solde' => Tableau::MONTANT],
                $lignesModes, [] === $lignesModes ? null : ['Total', $actuel['recettes'], $actuel['depenses'], $actuel['resultat']]),
            new Tableau('depenses-categorie', 'Dépenses par catégorie', ['Catégorie' => Tableau::TEXTE, 'Dépenses' => Tableau::NOMBRE, 'Montant' => Tableau::MONTANT, 'Part' => Tableau::POURCENTAGE],
                array_map(static fn (array $c) => [$c['categorie'], $c['nombre'], $c['montant'], self::part($c['montant'], $actuel['depenses'])], $categories),
                [] === $categories ? null : ['Total', array_sum(array_column($categories, 'nombre')), $actuel['depenses'], 100.0]),
            new Tableau('mois', 'Recettes et dépenses des 12 derniers mois', ['Mois' => Tableau::TEXTE, 'Recettes' => Tableau::MONTANT, 'Dépenses' => Tableau::MONTANT, 'Solde' => Tableau::MONTANT],
                array_map(static fn (array $m) => [ucfirst(Periode::MOIS_NOMS[(int) substr($m['mois'], 5)]).' '.substr($m['mois'], 0, 4), $m['recettes'], $m['depenses'], $m['solde']], $mois),
                ['Total', array_sum(array_column($mois, 'recettes')), array_sum(array_column($mois, 'depenses')), array_sum(array_column($mois, 'solde'))]),
            new Tableau('depenses', 'Détail des dépenses', ['Date' => Tableau::DATE, 'N°' => Tableau::TEXTE, 'Catégorie' => Tableau::TEXTE, 'Libellé' => Tableau::TEXTE, 'Bénéficiaire' => Tableau::TEXTE, 'Mode' => Tableau::TEXTE, 'Montant' => Tableau::MONTANT],
                array_map(static fn ($d) => [$d->getDate(), $d->getNumero(), $d->getCategorie()->getNom(), $d->getLibelle(), $d->getBeneficiaire(), $d->getMode()->libelle(), $d->getMontant()], $depenses),
                [] === $depenses ? null : ['Total', null, null, null, null, null, $actuel['depenses']]),
        ], [
            'mois' => self::graphiqueMois($mois),
            'categories' => self::camembert('Dépenses par catégorie', array_column($categories, 'categorie'), array_column($categories, 'montant')),
        ]);
    }

    /**
     * Graphique RA-03 : recettes et dépenses en barres, solde du mois en courbe.
     *
     * @param list<array{mois: string, libelle: string, recettes: int, depenses: int, solde: int}> $mois
     *
     * @return array<string, mixed>
     */
    public static function graphiqueMois(array $mois): array
    {
        return [
            'type' => 'bar',
            'data' => [
                'labels' => array_column($mois, 'libelle'),
                'datasets' => [
                    ['type' => 'line', 'label' => 'Solde', 'data' => array_column($mois, 'solde'), 'borderColor' => '#0d6efd', 'backgroundColor' => '#0d6efd', 'tension' => 0.3, 'order' => 0],
                    ['label' => 'Recettes', 'data' => array_column($mois, 'recettes'), 'backgroundColor' => '#198754', 'order' => 1],
                    ['label' => 'Dépenses', 'data' => array_column($mois, 'depenses'), 'backgroundColor' => '#dc3545', 'order' => 1],
                ],
            ],
        ];
    }

    /**
     * @param list<string> $libelles
     * @param list<int>    $valeurs
     *
     * @return array<string, mixed>
     */
    public static function camembert(string $titre, array $libelles, array $valeurs): array
    {
        return [
            'type' => 'doughnut',
            'data' => [
                'labels' => $libelles,
                'datasets' => [['label' => $titre, 'data' => $valeurs, 'backgroundColor' => \array_slice(array_merge(self::COULEURS, self::COULEURS, self::COULEURS), 0, max(1, \count($valeurs)))]],
            ],
            'options' => ['plugins' => ['legend' => ['position' => 'right']]],
        ];
    }

    /**
     * @param list<string>                                    $libelles
     * @param list<array{0: string, 1: list<int>, 2: string}> $series   libellé, valeurs, couleur
     *
     * @return array<string, mixed>
     */
    public static function barres(array $libelles, array $series, string $axe = 'x'): array
    {
        return [
            'type' => 'bar',
            'data' => [
                'labels' => $libelles,
                'datasets' => array_map(static fn (array $s) => ['label' => $s[0], 'data' => $s[1], 'backgroundColor' => $s[2]], $series),
            ],
            'options' => ['indexAxis' => $axe],
        ];
    }

    /**
     * Synthèse avec la période précédente (RA-01).
     *
     * @param list<array{0: string, 1: string, 2: int|float|null, 3: int|float|null}> $indicateurs libellé, type, valeur, valeur précédente
     */
    private static function comparaison(Periode $periode, array $indicateurs): Tableau
    {
        $lignes = [];
        foreach ($indicateurs as [$libelle, $type, $valeur, $reference]) {
            $evolution = Tableau::POURCENTAGE === $type || null === $valeur || null === $reference ? null : Indicateurs::evolution((int) $valeur, (int) $reference);
            $lignes[] = [$libelle, $valeur, $reference, $evolution, $type];
        }

        return new Tableau('synthese', 'Synthèse', [
            'Indicateur' => Tableau::TEXTE,
            $periode->libelle() => Tableau::VALEUR,
            'Période précédente ('.$periode->precedente()->libelle().')' => Tableau::VALEUR,
            'Évolution' => Tableau::POURCENTAGE,
        ], array_map(static fn (array $l) => \array_slice($l, 0, 4), $lignes), null, null, array_column($lignes, 4));
    }

    /**
     * @param list<array{produit: string, quantite: int, montant: int}> $produits
     *
     * @return list<list<int|string>>
     */
    private static function classement(array $produits): array
    {
        $lignes = [];
        foreach ($produits as $i => $p) {
            $lignes[] = [$i + 1, $p['produit'], $p['quantite'], $p['montant']];
        }

        return $lignes;
    }

    private static function part(int $montant, int $total): ?float
    {
        return 0 === $total ? null : round($montant * 100 / $total, 1);
    }

    /**
     * @param array<string, array{nombre: int, base: int, remise: int}> $groupe
     */
    private static function cumuler(array &$groupe, string $cle, int $base, int $remise): void
    {
        $groupe[$cle] ??= ['nombre' => 0, 'base' => 0, 'remise' => 0];
        ++$groupe[$cle]['nombre'];
        $groupe[$cle]['base'] += $base;
        $groupe[$cle]['remise'] += $remise;
    }
}
