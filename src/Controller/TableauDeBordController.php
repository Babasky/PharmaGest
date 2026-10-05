<?php

namespace App\Controller;

use App\Repository\VenteRepository;
use App\Stock\AlertesStock;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TableauDeBordController extends AbstractAppController
{
    private const JOURS = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];

    /**
     * Tableau de bord de l'officine : ventes du jour et des 7 derniers jours (ventes annulées exclues),
     * alertes de stock. Les autres indicateurs arrivent avec les rapports (Lot 7).
     */
    #[Route('/', name: 'app_tableau_de_bord', methods: ['GET'])]
    public function index(AlertesStock $alertes, VenteRepository $ventes, ClockInterface $horloge): Response
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin_tableau_de_bord');
        }
        $nombres = $alertes->compter();
        $aujourdhui = $horloge->now()->setTime(0, 0);
        $debut = $aujourdhui->modify('-6 days');
        $parJour = $ventes->parJour($debut, $aujourdhui->modify('+1 day'));
        $duJour = $parJour[$aujourdhui->format('Y-m-d')] ?? ['nombre' => 0, 'montant' => 0];

        $libelles = [];
        $montants = [];
        for ($jour = $debut; $jour <= $aujourdhui; $jour = $jour->modify('+1 day')) {
            $libelles[] = self::JOURS[(int) $jour->format('w')].' '.$jour->format('d/m');
            $montants[] = $parJour[$jour->format('Y-m-d')]['montant'] ?? 0;
        }

        return $this->render('tableau_de_bord/index.html.twig', [
            'pharmacie' => $this->pharmacie(),
            'indicateurs' => [
                ['libelle' => "Chiffre d'affaires du jour", 'icone' => 'cash-coin', 'valeur' => $duJour['montant'], 'montant' => true, 'role' => 'ROLE_PROPRIETAIRE'],
                ['libelle' => 'Ventes du jour', 'icone' => 'receipt', 'valeur' => $duJour['nombre'], 'montant' => false, 'role' => 'ROLE_VENDEUR'],
                ['libelle' => 'Produits sous le seuil', 'icone' => 'exclamation-triangle', 'valeur' => $nombres[AlertesStock::SEUIL], 'montant' => false, 'role' => 'ROLE_VENDEUR', 'lien' => ['type' => AlertesStock::SEUIL]],
                ['libelle' => 'Lots bientôt périmés', 'icone' => 'hourglass-split', 'valeur' => $nombres[AlertesStock::PEREMPTION], 'montant' => false, 'role' => 'ROLE_VENDEUR', 'lien' => ['type' => AlertesStock::PEREMPTION]],
            ],
            'graphique' => [
                'type' => 'bar',
                'data' => [
                    'labels' => $libelles,
                    'datasets' => [[
                        'label' => 'Ventes (FCFA)',
                        'data' => $montants,
                        'backgroundColor' => '#198754',
                    ]],
                ],
            ],
        ]);
    }
}
