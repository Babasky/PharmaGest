<?php

namespace App\Controller;

use App\Stock\AlertesStock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TableauDeBordController extends AbstractAppController
{
    /**
     * Tableau de bord de l'officine. Les indicateurs de ventes arrivent avec la caisse (Lot 4) et les rapports (Lot 7).
     */
    #[Route('/', name: 'app_tableau_de_bord', methods: ['GET'])]
    public function index(AlertesStock $alertes): Response
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin_tableau_de_bord');
        }
        $nombres = $alertes->compter();

        return $this->render('tableau_de_bord/index.html.twig', [
            'pharmacie' => $this->pharmacie(),
            'indicateurs' => [
                ['libelle' => "Chiffre d'affaires du jour", 'icone' => 'cash-coin', 'valeur' => 0, 'montant' => true, 'role' => 'ROLE_PROPRIETAIRE'],
                ['libelle' => 'Ventes du jour', 'icone' => 'receipt', 'valeur' => 0, 'montant' => false, 'role' => 'ROLE_VENDEUR'],
                ['libelle' => 'Produits sous le seuil', 'icone' => 'exclamation-triangle', 'valeur' => $nombres[AlertesStock::SEUIL], 'montant' => false, 'role' => 'ROLE_VENDEUR', 'lien' => ['type' => AlertesStock::SEUIL]],
                ['libelle' => 'Lots bientôt périmés', 'icone' => 'hourglass-split', 'valeur' => $nombres[AlertesStock::PEREMPTION], 'montant' => false, 'role' => 'ROLE_VENDEUR', 'lien' => ['type' => AlertesStock::PEREMPTION]],
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
