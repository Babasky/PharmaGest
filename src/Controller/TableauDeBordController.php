<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TableauDeBordController extends AbstractAppController
{
    /**
     * Tableau de bord de l'officine. Les indicateurs réels arrivent avec la caisse (Lot 4) et les rapports (Lot 7).
     */
    #[Route('/', name: 'app_tableau_de_bord', methods: ['GET'])]
    public function index(): Response
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin_tableau_de_bord');
        }

        return $this->render('tableau_de_bord/index.html.twig', [
            'pharmacie' => $this->pharmacie(),
            'indicateurs' => [
                ['libelle' => "Chiffre d'affaires du jour", 'icone' => 'cash-coin', 'valeur' => 0, 'montant' => true, 'role' => 'ROLE_PROPRIETAIRE'],
                ['libelle' => 'Ventes du jour', 'icone' => 'receipt', 'valeur' => 0, 'montant' => false, 'role' => 'ROLE_VENDEUR'],
                ['libelle' => 'Produits en rupture', 'icone' => 'exclamation-triangle', 'valeur' => 0, 'montant' => false, 'role' => 'ROLE_VENDEUR'],
                ['libelle' => 'Produits bientôt périmés', 'icone' => 'hourglass-split', 'valeur' => 0, 'montant' => false, 'role' => 'ROLE_VENDEUR'],
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
