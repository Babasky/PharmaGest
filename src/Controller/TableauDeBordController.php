<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Reporting\Indicateurs;
use App\Reporting\Periode;
use App\Reporting\Rapports;
use App\Repository\DepenseRepository;
use App\Repository\VenteRepository;
use App\Stock\AlertesStock;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TableauDeBordController extends AbstractAppController
{
    private const JOURS = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];

    /**
     * Tableau de bord de l'officine (RA-02) : chiffre d'affaires, ventes, panier moyen, dépenses, résultat,
     * encours AMO et valeur du stock, comparés au même nombre de jours du mois précédent ; graphiques des
     * recettes et dépenses sur 12 mois (RA-03) et des dépenses par catégorie (RA-04). Chacun ne voit que ce que
     * la matrice des droits lui ouvre : le vendeur le nombre de ventes du jour et les alertes (pas le chiffre d'affaires), l'adjoint les ventes et
     * le stock, le propriétaire en plus les finances.
     */
    #[Route('/', name: 'app_tableau_de_bord', methods: ['GET'])]
    public function index(AlertesStock $alertes, VenteRepository $ventes, Indicateurs $indicateurs, DepenseRepository $depenses, ClockInterface $horloge): Response
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin');
        }
        $nombres = $alertes->compter();
        $aujourdhui = $horloge->now()->setTime(0, 0);
        $jour = $indicateurs->ventes(Periode::pour(Periode::JOUR, $aujourdhui));

        $indicateursAffiches = [
            ['libelle' => 'Ventes du jour', 'icone' => 'receipt', 'valeur' => $jour['nombre'], 'montant' => false, 'id' => 'ventes-jour'],
        ];
        $graphiques = [];

        if ($this->isGranted(Utilisateur::ROLE_ADJOINT)) {
            // Mois en cours jusqu'à aujourd'hui, comparé aux mêmes jours du mois précédent (RA-01).
            $mois = Periode::libre($aujourdhui->modify('first day of this month'), $aujourdhui);
            $debutPrecedent = $mois->debut->modify('-1 month');
            $finPrecedent = min($debutPrecedent->modify('+'.((int) $aujourdhui->format('j') - 1).' days'), $debutPrecedent->modify('last day of this month'));
            $precedent = Periode::libre($debutPrecedent, $finPrecedent);
            $ventesMois = $indicateurs->ventes($mois);
            $ventesPrecedent = $indicateurs->ventes($precedent);
            array_unshift($indicateursAffiches, ['libelle' => "Chiffre d'affaires du jour", 'icone' => 'cash-coin', 'valeur' => $jour['ca'], 'montant' => true, 'id' => 'ca-jour']);
            $indicateursAffiches = array_merge($indicateursAffiches, [
                ['libelle' => "Chiffre d'affaires du mois", 'icone' => 'graph-up-arrow', 'valeur' => $ventesMois['ca'], 'montant' => true, 'id' => 'ca-mois', 'evolution' => Indicateurs::evolution($ventesMois['ca'], $ventesPrecedent['ca'])],
                ['libelle' => 'Ventes du mois', 'icone' => 'bag-check', 'valeur' => $ventesMois['nombre'], 'montant' => false, 'id' => 'ventes-mois', 'evolution' => Indicateurs::evolution($ventesMois['nombre'], $ventesPrecedent['nombre'])],
                ['libelle' => 'Panier moyen du mois', 'icone' => 'basket', 'valeur' => $ventesMois['panier'], 'montant' => true, 'id' => 'panier', 'evolution' => Indicateurs::evolution($ventesMois['panier'], $ventesPrecedent['panier'])],
            ]);
            if ($this->isGranted(Utilisateur::ROLE_PROPRIETAIRE)) {
                $finances = $indicateurs->finances($mois);
                $financesPrecedent = $indicateurs->finances($precedent);
                $indicateursAffiches = array_merge($indicateursAffiches, [
                    ['libelle' => 'Dépenses du mois', 'icone' => 'wallet2', 'valeur' => $finances['depenses'], 'montant' => true, 'id' => 'depenses-mois', 'evolution' => Indicateurs::evolution($finances['depenses'], $financesPrecedent['depenses']), 'inverse' => true],
                    ['libelle' => 'Résultat du mois', 'icone' => 'calculator', 'valeur' => $finances['resultat'], 'montant' => true, 'id' => 'resultat-mois', 'evolution' => Indicateurs::evolution($finances['resultat'], $financesPrecedent['resultat'])],
                ]);
                $categories = $depenses->parCategorie($mois->debut, $mois->fin);
                $graphiques['mois'] = ['titre' => 'Recettes et dépenses des 12 derniers mois', 'config' => Rapports::graphiqueMois($indicateurs->douzeMois($aujourdhui)), 'devise' => true];
                $graphiques['categories'] = ['titre' => 'Dépenses du mois par catégorie', 'config' => Rapports::camembert('Dépenses', array_column($categories, 'categorie'), array_column($categories, 'montant')), 'devise' => false, 'vide' => [] === $categories];
            }
            $indicateursAffiches = array_merge($indicateursAffiches, [
                ['libelle' => 'Encours AMO', 'icone' => 'shield-plus', 'valeur' => $indicateurs->encoursAmo(), 'montant' => true, 'id' => 'encours-amo', 'route' => 'app_amo_index'],
                ['libelle' => 'Valeur du stock', 'icone' => 'box-seam', 'valeur' => $indicateurs->valeurStock(), 'montant' => true, 'id' => 'valeur-stock', 'route' => 'app_stock_index'],
            ]);
        }
        $indicateursAffiches[] = ['libelle' => 'Produits sous le seuil', 'icone' => 'exclamation-triangle', 'valeur' => $nombres[AlertesStock::SEUIL], 'montant' => false, 'id' => 'alertes-seuil', 'lien' => ['type' => AlertesStock::SEUIL]];
        $indicateursAffiches[] = ['libelle' => 'Lots bientôt périmés', 'icone' => 'hourglass-split', 'valeur' => $nombres[AlertesStock::PEREMPTION], 'montant' => false, 'id' => 'alertes-peremption', 'lien' => ['type' => AlertesStock::PEREMPTION]];

        // Ventes des 7 derniers jours.
        $debut = $aujourdhui->modify('-6 days');
        $parJour = $ventes->parJour($debut, $aujourdhui->modify('+1 day'));
        $libelles = [];
        $montants = [];
        for ($j = $debut; $j <= $aujourdhui; $j = $j->modify('+1 day')) {
            $libelles[] = self::JOURS[(int) $j->format('w')].' '.$j->format('d/m');
            $montants[] = $parJour[$j->format('Y-m-d')]['montant'] ?? 0;
        }
        $graphiques = ['semaine' => ['titre' => 'Ventes des 7 derniers jours', 'config' => [
            'type' => 'bar',
            'data' => ['labels' => $libelles, 'datasets' => [['label' => 'Ventes (FCFA)', 'data' => $montants, 'backgroundColor' => '#198754']]],
        ], 'devise' => true]] + $graphiques;

        return $this->render('tableau_de_bord/index.html.twig', [
            'pharmacie' => $this->pharmacie(),
            'indicateurs' => $indicateursAffiches,
            'graphiques' => $graphiques,
        ]);
    }
}
