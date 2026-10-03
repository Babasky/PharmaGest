# PharmaGest

SaaS multi-tenant de gestion d'officines, adapté au contexte malien (FCFA, `Africa/Bamako`, numéros `+223`).

**Stack** : Symfony 7.4 LTS · PHP 8.3 · MySQL 8.0 · Twig + Bootstrap 5.3 + Bootstrap Icons · AssetMapper
(sans Node) · Stimulus + Turbo · Chart.js · Messenger + Mailer · PhpSpreadsheet · Dompdf.

## Démarrer avec DDEV

```bash
ddev start          # installe les dépendances, les assets (importmap) et joue les migrations
ddev launch         # ouvre https://pharmagest.ddev.site
ddev launch -m      # ouvre Mailpit (emails envoyés par l'application)
```

Le worker Messenger (envoi asynchrone des emails) tourne automatiquement dans le conteneur web.

## Sans DDEV

Prérequis : PHP 8.3 (extensions `intl`, `pdo_mysql`, `zip`, `gd`, `mbstring`, `xml`), Composer, MySQL 8.

```bash
composer install
cp .env .env.local                      # puis adapter DATABASE_URL, MAILER_DSN, APP_SECRET
php bin/console importmap:install       # ou bin/importmap-from-npm.sh si jsDelivr est inaccessible
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
symfony serve                           # ou : php -S 127.0.0.1:8000 -t public
```

Pour les tests, créer `.env.test.local` avec le `DATABASE_URL` local (le suffixe `_test` est ajouté automatiquement).

## Qualité

```bash
composer qa         # PHP-CS-Fixer (vérification) + PHPStan (niveau 6) + PHPUnit
composer cs-fix     # corrige le style
```

## Conventions

- Interface **en français**, fuseau `Africa/Bamako`.
- Montants en **entiers FCFA**, affichés via le filtre Twig `|fcfa` → `12 500 FCFA`.
- Téléphones affichés via `|telephone` → `+223 76 12 34 56`.
- Pages connectées : étendre `layout/app.html.twig` (blocs `title`, `page_actions`, `content`).
  La sidebar est décrite dans `App\Menu\Navigation` (rôle requis par entrée ; les modules pas encore livrés apparaissent « Bientôt »).
- Graphiques : `{{ stimulus_controller('chart', {config: {...}, devise: true}) }}` sur un `<canvas>`.

## Documentation

- [Hypothèses de conception](docs/hypotheses.md)
