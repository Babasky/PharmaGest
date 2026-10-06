<?php

namespace App\Controller\Admin;

use App\Entity\Utilisateur;
use App\Enum\StatutAbonnement;
use App\Repository\AbonnementRepository;
use App\Repository\PharmacieRepository;
use App\Service\AbonnementService;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace plateforme du super admin, construit avec EasyAdmin (décision du Lot 8). La page d'accueil est le
 * tableau de bord de la plateforme (SA-05) : aucune donnée de vente des pharmacies n'y figure.
 */
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly PharmacieRepository $pharmacies,
        private readonly AbonnementRepository $paiements,
        private readonly AbonnementService $abonnements,
    ) {
    }

    public function index(): Response
    {
        $compteurs = array_fill_keys(array_map(static fn (StatutAbonnement $s) => $s->value, StatutAbonnement::cases()), 0);
        $aSurveiller = [];
        foreach ($this->pharmacies->findAll() as $pharmacie) {
            $etat = $this->abonnements->etat($pharmacie);
            ++$compteurs[$etat->statut->value];
            if (\in_array($etat->statut, [StatutAbonnement::Alerte, StatutAbonnement::Grace, StatutAbonnement::Expire], true)
                || (StatutAbonnement::Essai === $etat->statut && $etat->joursRestants <= AbonnementService::JOURS_ALERTE)) {
                $aSurveiller[] = ['pharmacie' => $pharmacie, 'etat' => $etat];
            }
        }
        usort($aSurveiller, static fn ($a, $b) => ($a['etat']->joursRestants ?? \PHP_INT_MIN) <=> ($b['etat']->joursRestants ?? \PHP_INT_MIN));

        $aujourdhui = $this->abonnements->aujourdhui();
        $revenus = $this->paiements->revenusParMois($aujourdhui->modify('-11 months'), $aujourdhui);
        $libellesMois = array_map(
            static fn (string $mois) => (new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'MMM yy'))->format(new \DateTimeImmutable($mois.'-01')),
            array_keys($revenus),
        );

        return $this->render('admin/tableau_de_bord.html.twig', [
            'compteurs' => $compteurs,
            'a_surveiller' => $aSurveiller,
            'derniers_paiements' => $this->paiements->derniersPaiements(8),
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

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('PharmaGest <small>Plateforme</small>')
            ->setLocales(['fr']);
    }

    public function configureAssets(): Assets
    {
        // Icônes Bootstrap et graphiques (Stimulus) des pages propres à PharmaGest, sans le thème de l'officine.
        return Assets::new()->addAssetMapperEntry('admin');
    }

    public function configureCrud(): Crud
    {
        return Crud::new()
            ->setDateFormat('dd/MM/yyyy')
            ->setDateTimeFormat('dd/MM/yyyy HH:mm')
            ->setPaginatorPageSize(25);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Vue globale', 'fa fa-globe');
        yield MenuItem::linkTo(PharmacieCrudController::class, 'Pharmacies', 'fa fa-hospital');
        yield MenuItem::linkToRoute('Abonnements', 'fa fa-calendar-check', 'admin_abonnement_index');
        yield MenuItem::linkTo(OffreCrudController::class, 'Offres', 'fa fa-tags');
        yield MenuItem::section('Référentiels communs');
        yield MenuItem::linkTo(OrganismeAmoCrudController::class, 'Organismes AMO', 'fa fa-shield-heart');
        yield MenuItem::linkTo(FormeGaleniqueCrudController::class, 'Formes galéniques', 'fa fa-capsules');
        yield MenuItem::linkTo(CategorieDepenseCrudController::class, 'Catégories de dépenses', 'fa fa-wallet');
        yield MenuItem::section();
        yield MenuItem::linkToRoute('Journal d\'audit', 'fa fa-clipboard-list', 'admin_journal_index');
    }

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        $menu = parent::configureUserMenu($user)->displayUserAvatar(false);

        return $user instanceof Utilisateur ? $menu->setName($user->getNom()) : $menu;
    }
}
