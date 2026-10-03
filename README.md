# PharmaGest

SaaS multi-tenant de gestion d'officines, adapté au contexte malien (FCFA, `Africa/Bamako`, numéros `+223`).

**Stack** : Symfony 7.4 LTS · PHP 8.3 · MySQL 8.0 · Twig + Bootstrap 5.3 + Bootstrap Icons · AssetMapper
(sans Node) · Stimulus + Turbo · Chart.js · Messenger + Mailer + Scheduler · PhpSpreadsheet · Dompdf.

## Démarrer avec DDEV

```bash
ddev start                      # dépendances, assets (importmap), migrations
ddev composer demo              # (facultatif) données de démonstration
ddev launch                     # https://pharmagest.ddev.site
ddev launch -m                  # Mailpit : emails d'activation, de mot de passe, de rappel
```

Le worker Messenger (emails asynchrones et tâches planifiées) tourne automatiquement dans le conteneur web.

### Comptes de démonstration

Après `composer demo`, tous les comptes ont le mot de passe **`motdepasse`** :

| Compte | Rôle | Situation |
|---|---|---|
| `admin@pharmagest.ml` | Super admin | Espace plateforme |
| `a.traore@fleuve.ml` | Propriétaire | Pharmacie du Fleuve, abonnement actif, équipe complète |
| `f.keita@fleuve.ml` / `m.coulibaly@fleuve.ml` | Adjoint / vendeur | Pharmacie du Fleuve |
| `o.guindo@kanaga.ml` | Propriétaire | Échéance dans 12 jours (bandeau d'alerte) |
| `k.sangare@djoliba.ml` | Propriétaire | Période d'essai |
| `i.ouattara@paix.ml` | Propriétaire | Abonnement expiré : lecture seule |
| `m.dembele@groupe-dembele.ml` | Propriétaire Premium | Deux pharmacies (sélecteur en haut de page) |

## Sans DDEV

Prérequis : PHP 8.3 (extensions `intl`, `pdo_mysql`, `zip`, `gd`, `mbstring`, `xml`), Composer, MySQL 8.

```bash
composer install
cp .env .env.local                      # puis adapter DATABASE_URL, MAILER_DSN, APP_SECRET
php bin/console importmap:install       # ou bin/importmap-from-npm.sh si jsDelivr est inaccessible
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console app:super-admin:creer admin@exemple.ml "Nom de l'éditeur"
symfony serve                           # ou : php -S 127.0.0.1:8000 -t public
php bin/console messenger:consume async scheduler_default   # worker (emails, rappels)
```

Pour les tests, créer `.env.test.local` avec le `DATABASE_URL` local (le suffixe `_test` est ajouté automatiquement),
puis `php bin/console doctrine:migrations:migrate --env=test`.

## Qualité

```bash
composer qa         # PHP-CS-Fixer (vérification) + PHPStan (niveau 6) + PHPUnit
composer cs-fix     # corrige le style
```

L'intégration continue (GitHub Actions, `.github/workflows/ci.yml`) lance les mêmes contrôles à chaque push.

## Architecture multi-tenant

- Toute entité métier implémente `App\Tenant\TenantAwareInterface` (en pratique : `use TenantAwareTrait`).
- Le filtre Doctrine `TenantFilter` est activé à chaque requête sur la pharmacie courante (`TenantRequestListener`),
  fermé par défaut, désactivé seulement pour le super admin sous `/admin`.
- La pharmacie est affectée automatiquement à la création (`TenantAssignationListener`) ; aucun formulaire ne l'expose.
- En seconde ligne : les Voters, et `AbstractAppController::exigerMemePharmacie()` qui renvoie un **404**.
- Hors requête HTTP (commandes, tâches planifiées), utiliser `TenantContext::forcer($pharmacie)` ou
  `TenantContext::sansFiltre(fn () => …)`.

## Conventions

- Interface **en français**, fuseau `Africa/Bamako`.
- Montants en **entiers FCFA**, affichés via le filtre Twig `|fcfa` → `12 500 FCFA`.
- Téléphones affichés via `|telephone` → `+223 76 12 34 56`.
- Numéros de documents : `App\Service\Numeroteur` (séquentiels sans trou, par pharmacie et par année, RG-02).
- Actions sensibles : `App\Service\AuditLogger` (journal non modifiable).
- Pages connectées : étendre `layout/app.html.twig` (blocs `title`, `page_actions`, `content`).
  Le menu est décrit dans `App\Menu\Navigation` (rôle requis par entrée ; les modules pas encore livrés apparaissent « Bientôt »).
- Graphiques : `{{ stimulus_controller('chart', {config: {...}, devise: true}) }}` sur un `<canvas>`.

## Documentation

- [Cahier des charges](docs/cahier-des-charges.md)
- [Hypothèses et décisions](docs/hypotheses.md)
