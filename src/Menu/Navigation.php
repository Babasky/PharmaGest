<?php

namespace App\Menu;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\RouterInterface;

/**
 * Construit la sidebar selon les rôles de l'utilisateur connecté.
 *
 * Un élément dont la route n'existe pas encore (module d'un lot futur) est affiché grisé « Bientôt ».
 */
final class Navigation
{
    /**
     * @var list<array{titre: string, elements: list<array{libelle: string, icone: string, route: string, role: string}>}>
     */
    private const SECTIONS = [
        [
            'titre' => '',
            'elements' => [
                ['libelle' => 'Tableau de bord', 'icone' => 'speedometer2', 'route' => 'app_tableau_de_bord', 'role' => 'ROLE_VENDEUR'],
            ],
        ],
        [
            'titre' => 'Comptoir',
            'elements' => [
                ['libelle' => 'Caisse', 'icone' => 'cart-plus', 'route' => 'app_caisse', 'role' => 'ROLE_VENDEUR'],
                ['libelle' => 'Ventes', 'icone' => 'receipt', 'route' => 'app_vente_index', 'role' => 'ROLE_VENDEUR'],
                ['libelle' => 'Clients', 'icone' => 'people', 'route' => 'app_client_index', 'role' => 'ROLE_VENDEUR'],
            ],
        ],
        [
            'titre' => 'Stock',
            'elements' => [
                ['libelle' => 'Produits', 'icone' => 'capsule', 'route' => 'app_produit_index', 'role' => 'ROLE_VENDEUR'],
                ['libelle' => 'État du stock', 'icone' => 'box-seam', 'route' => 'app_stock_index', 'role' => 'ROLE_VENDEUR'],
                ['libelle' => 'Commandes', 'icone' => 'truck', 'route' => 'app_commande_index', 'role' => 'ROLE_VENDEUR'],
                ['libelle' => 'Fournisseurs', 'icone' => 'building', 'route' => 'app_fournisseur_index', 'role' => 'ROLE_VENDEUR'],
                ['libelle' => 'Catégories', 'icone' => 'diagram-3', 'route' => 'app_categorie_index', 'role' => 'ROLE_ADJOINT'],
                ['libelle' => 'Étagères', 'icone' => 'bookshelf', 'route' => 'app_etagere_index', 'role' => 'ROLE_ADJOINT'],
                ['libelle' => 'Inventaires', 'icone' => 'clipboard-check', 'route' => 'app_inventaire_index', 'role' => 'ROLE_ADJOINT'],
            ],
        ],
        [
            'titre' => 'Gestion',
            'elements' => [
                ['libelle' => 'AMO', 'icone' => 'shield-plus', 'route' => 'app_amo_index', 'role' => 'ROLE_ADJOINT'],
                ['libelle' => 'Dépenses', 'icone' => 'wallet2', 'route' => 'app_depense_index', 'role' => 'ROLE_PROPRIETAIRE'],
                ['libelle' => 'Rapports', 'icone' => 'bar-chart-line', 'route' => 'app_rapport_index', 'role' => 'ROLE_ADJOINT'],
            ],
        ],
        [
            'titre' => 'Pharmacie',
            'elements' => [
                ['libelle' => 'Équipe', 'icone' => 'person-badge', 'route' => 'app_equipe_index', 'role' => 'ROLE_PROPRIETAIRE'],
                ['libelle' => 'Paramètres', 'icone' => 'gear', 'route' => 'app_parametres', 'role' => 'ROLE_PROPRIETAIRE'],
                ['libelle' => 'Abonnement', 'icone' => 'patch-check', 'route' => 'app_mon_abonnement', 'role' => 'ROLE_PROPRIETAIRE'],
                ['libelle' => "Journal d'audit", 'icone' => 'journal-text', 'route' => 'app_audit_index', 'role' => 'ROLE_PROPRIETAIRE'],
            ],
        ],
        [
            'titre' => 'Administration',
            'elements' => [
                ['libelle' => 'Vue globale', 'icone' => 'globe2', 'route' => 'admin_tableau_de_bord', 'role' => 'ROLE_SUPER_ADMIN'],
                ['libelle' => 'Pharmacies', 'icone' => 'hospital', 'route' => 'admin_pharmacie_index', 'role' => 'ROLE_SUPER_ADMIN'],
                ['libelle' => 'Abonnements', 'icone' => 'calendar-check', 'route' => 'admin_abonnement_index', 'role' => 'ROLE_SUPER_ADMIN'],
                ['libelle' => 'Offres', 'icone' => 'tags', 'route' => 'admin_offre_index', 'role' => 'ROLE_SUPER_ADMIN'],
                ['libelle' => 'Référentiels', 'icone' => 'list-check', 'route' => 'admin_referentiel_index', 'role' => 'ROLE_SUPER_ADMIN'],
            ],
        ],
    ];

    public function __construct(
        private readonly Security $security,
        private readonly RouterInterface $router,
    ) {
    }

    /**
     * @return list<array{titre: string, elements: list<array{libelle: string, icone: string, route: string, disponible: bool, prefixe: string}>}>
     */
    public function sections(): array
    {
        $routes = $this->router->getRouteCollection();
        $sections = [];

        foreach (self::SECTIONS as $section) {
            $elements = [];
            foreach ($section['elements'] as $element) {
                if (!$this->estAutorise($element['role'])) {
                    continue;
                }
                $elements[] = [
                    'libelle' => $element['libelle'],
                    'icone' => $element['icone'],
                    'route' => $element['route'],
                    'disponible' => null !== $routes->get($element['route']),
                    // Les pages d'un module (app_equipe_nouveau…) gardent l'entrée du menu active.
                    'prefixe' => preg_replace('/_index$/', '_', $element['route']) ?? $element['route'],
                ];
            }

            if ([] !== $elements) {
                $sections[] = ['titre' => $section['titre'], 'elements' => $elements];
            }
        }

        return $sections;
    }

    /**
     * Le super admin n'accède pas aux données des pharmacies : il ne voit que sa section.
     */
    private function estAutorise(string $role): bool
    {
        $estSuperAdmin = $this->security->isGranted('ROLE_SUPER_ADMIN');

        if ('ROLE_SUPER_ADMIN' === $role) {
            return $estSuperAdmin;
        }

        return !$estSuperAdmin && $this->security->isGranted($role);
    }
}
