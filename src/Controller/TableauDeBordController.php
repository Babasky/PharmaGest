<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TableauDeBordController extends AbstractController
{
    /**
     * Lot 0 : page d'accueil de démonstration du socle (layout, |fcfa, Chart.js).
     * L'accès sera restreint aux utilisateurs connectés au Lot 1 (authentification).
     */
    #[Route('/', name: 'app_tableau_de_bord', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('tableau_de_bord/index.html.twig', [
            'indicateurs' => [
                ['libelle' => "Chiffre d'affaires du jour", 'icone' => 'cash-coin', 'valeur' => 0, 'montant' => true],
                ['libelle' => 'Ventes du jour', 'icone' => 'receipt', 'valeur' => 0, 'montant' => false],
                ['libelle' => 'Produits en rupture', 'icone' => 'exclamation-triangle', 'valeur' => 0, 'montant' => false],
                ['libelle' => 'Produits bientôt périmés', 'icone' => 'hourglass-split', 'valeur' => 0, 'montant' => false],
            ],
            'graphique' => [
                'type' => 'bar',
                'data' => [
                    'labels' => ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'],
                    'datasets' => [[
                        'label' => 'Ventes (FCFA)',
                        'data' => [0, 0, 0, 0, 0, 0, 0],
                        'backgroundColor' => '#198754',
                    ]],
                ],
            ],
        ]);
    }
}
