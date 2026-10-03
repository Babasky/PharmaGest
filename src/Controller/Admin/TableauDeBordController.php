<?php

namespace App\Controller\Admin;

use App\Enum\StatutAbonnement;
use App\Repository\AbonnementRepository;
use App\Repository\PharmacieRepository;
use App\Service\AbonnementService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tableau de bord de la plateforme (SA-05). Aucune donnée de vente des pharmacies n'y figure.
 */
final class TableauDeBordController extends AbstractController
{
    #[Route('/admin', name: 'admin_tableau_de_bord', methods: ['GET'])]
    public function index(PharmacieRepository $pharmacies, AbonnementRepository $paiements, AbonnementService $abonnements): Response
    {
        $compteurs = array_fill_keys(array_map(static fn (StatutAbonnement $s) => $s->value, StatutAbonnement::cases()), 0);
        $aSurveiller = [];
        foreach ($pharmacies->findAll() as $pharmacie) {
            $etat = $abonnements->etat($pharmacie);
            ++$compteurs[$etat->statut->value];
            if (\in_array($etat->statut, [StatutAbonnement::Alerte, StatutAbonnement::Grace, StatutAbonnement::Expire], true)
                || (StatutAbonnement::Essai === $etat->statut && $etat->joursRestants <= AbonnementService::JOURS_ALERTE)) {
                $aSurveiller[] = ['pharmacie' => $pharmacie, 'etat' => $etat];
            }
        }
        usort($aSurveiller, static fn ($a, $b) => ($a['etat']->joursRestants ?? \PHP_INT_MIN) <=> ($b['etat']->joursRestants ?? \PHP_INT_MIN));

        $aujourdhui = $abonnements->aujourdhui();
        $revenus = $paiements->revenusParMois($aujourdhui->modify('-11 months'), $aujourdhui);
        $libellesMois = array_map(
            static fn (string $mois) => (new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'MMM yy'))->format(new \DateTimeImmutable($mois.'-01')),
            array_keys($revenus),
        );

        return $this->render('admin/tableau_de_bord.html.twig', [
            'compteurs' => $compteurs,
            'a_surveiller' => $aSurveiller,
            'derniers_paiements' => $paiements->derniersPaiements(8),
            'revenus_annee' => array_sum($revenus),
            'graphique' => [
                'type' => 'bar',
                'data' => [
                    'labels' => $libellesMois,
                    'datasets' => [['label' => 'Revenus d\'abonnement', 'data' => array_values($revenus), 'backgroundColor' => '#198754']],
                ],
                'options' => ['plugins' => ['legend' => ['display' => false]]],
            ],
        ]);
    }
}
