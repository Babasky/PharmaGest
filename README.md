# PharmaGest

SaaS multi-tenant de gestion d'officines, adapté au contexte malien (FCFA, `Africa/Bamako`, numéros `+223`).

**Stack** : Symfony 7.4 LTS · PHP 8.3 · MySQL 8.0 · Twig + Bootstrap 5.3 + Bootstrap Icons · AssetMapper
(sans Node) · Stimulus + Turbo · Chart.js · Messenger + Mailer + Scheduler · PhpSpreadsheet · Dompdf.

## Démarrer avec DDEV

```bash
ddev start                      # dépendances, assets (importmap), migrations
ddev composer demo              # (facultatif) données de démonstration (efface la base)
ddev launch                     # https://pharmagest.ddev.site
ddev launch -m                  # Mailpit : emails d'activation, de mot de passe, de rappel
```

Le worker Messenger (emails asynchrones et tâches planifiées) tourne automatiquement dans le conteneur web.

### Comptes de démonstration

Après `composer demo`, tous les comptes ont le mot de passe **`motdepasse`** :

| Compte | Rôle | Situation |
|---|---|---|
| `admin@pharmagest.ml` | Super admin | Espace plateforme |
| `a.traore@fleuve.ml` | Propriétaire | Pharmacie du Fleuve, abonnement actif, équipe complète, catalogue de 10 produits avec stock (une alerte de chaque type) |
| `f.keita@fleuve.ml` | Adjoint | Pharmacie du Fleuve |
| `m.coulibaly@fleuve.ml` / `s.diarra@fleuve.ml` | Vendeur | Pharmacie du Fleuve : arrive sur l'écran de vente (**Caisse**) |
| `a.konate@fleuve.ml` | Caissier | Pharmacie du Fleuve : arrive sur l'**Encaissement**, caisse déjà ouverte, une vente à encaisser |
| `o.guindo@kanaga.ml` | Propriétaire | Échéance dans 12 jours (bandeau d'alerte) |
| `k.sangare@djoliba.ml` | Propriétaire | Période d'essai |
| `i.ouattara@paix.ml` | Propriétaire | Abonnement expiré : lecture seule |
| `m.dembele@groupe-dembele.ml` | Propriétaire Premium | Deux pharmacies, Kati et Koulikoro (sélecteur en haut de page), avec des **Transferts** de stock entre elles |
| `s.cisse@groupe-dembele.ml` | Adjoint | Pharmacie de Koulikoro : un transfert venu de Kati à réceptionner |

Codes PIN de la Pharmacie du Fleuve (remise hors plafond, vente sans ordonnance, changement de vendeur) :
Aminata Traoré **2580** (propriétaire), Fatoumata Keïta **3690**, Moussa Coulibaly **1470**, Seydou Diarra **1590**,
Awa Konaté **4826** (caissière).
Moussa a une session de caisse clôturée avec cinq ventes (dont trois AMO et une annulée).

**Vente et encaissement.** Chacun arrive sur son écran à la connexion : le vendeur sur la **Caisse** (écran de vente),
le caissier sur l'**Encaissement**, le propriétaire et l'adjoint sur le tableau de bord. Le vendeur prépare la vente
sans avoir à ouvrir de caisse, puis choisit :
- **Encaisser** lui-même (il ouvre alors sa caisse avec son fond de caisse) ;
- ou **Envoyer à la caisse** : la vente est validée, numérotée et ses produits sortent du stock ; elle attend dans la
  file du caissier, qui l'encaisse dans sa propre session (espèces, Orange Money, Moov Money, Wave, paiement mixte)
  ou l'annule si le client repart sans payer (les produits reviennent en stock).

Dans la démo, Seydou a envoyé quatre ventes à Awa : deux encaissées (dont une prise en charge à 80 % par la mutuelle
d'une ONG), une annulée et une qui attend. Le caissier n'a accès qu'à l'Encaissement, aux Ventes (réimpression des
tickets) et à ses sessions de caisse. Le propriétaire ajoute un caissier depuis **Équipe**. Le menu **AMO** (propriétaire et adjoint) montre l'encours, un bordereau INPS transmis
et réglé en partie, et une créance CMSS encore en attente. Le menu **Commandes** montre une commande Laborex reçue
en partie (l'Augmentin est encore attendu), un brouillon PPM à envoyer et les suggestions du jour. En local, les emails
aux fournisseurs arrivent dans Mailpit (`ddev launch -m`).

La cloche de la barre supérieure (propriétaire et adjoint) montre les notifications du jour : rupture, péremptions,
lot périmé et bordereau INPS impayé pour la Pharmacie du Fleuve ; échéance proche, fin d'essai ou lecture seule pour
les autres officines. Le **Journal d'audit** (propriétaire) liste les actions sensibles de la démo, et
« Mon abonnement › Exporter toutes mes données » télécharge l'export complet (ZIP). Le super admin consulte le
journal de la plateforme (espace plateforme, construit avec EasyAdmin).

**Transferts de stock entre officines.** Le menu **Stock › Transferts** (propriétaire et adjoint) n'est utile qu'à
un pharmacien qui détient plusieurs officines : le transfert n'est possible qu'entre officines du même propriétaire.
L'officine d'origine prépare le transfert puis l'expédie (le stock sort lot par lot, premier périmé premier sorti) ;
l'officine destinataire confirme la réception (les mêmes lots, dates de péremption et prix d'achat entrent chez elle).
Dans la démo, Kati a envoyé à Koulikoro un transfert déjà reçu, un transfert expédié qui attend la confirmation de
Salif Cissé (adjoint de Koulikoro) et un transfert encore en préparation.

## Sans DDEV

Prérequis : PHP 8.3 (extensions `intl`, `pdo_mysql`, `zip`, `gd`, `mbstring`, `xml`), Composer, MySQL 8.

```bash
composer install
cp .env .env.local                      # puis adapter DATABASE_URL, MAILER_DSN, APP_SECRET
php bin/console importmap:install       # ou bin/importmap-from-npm.sh si jsDelivr est inaccessible
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console app:super-admin:creer admin@exemple.ml "Nom de l'éditeur"
symfony serve                           # ou : php -S 127.0.0.1:8000 -t public bin/serveur-dev.php
php bin/console messenger:consume async scheduler_default   # worker (emails, rappels, notifications)
```

Avec le serveur intégré de PHP, le dernier argument `bin/serveur-dev.php` est indispensable : sans lui, les fichiers
d'assets (CSS, JavaScript) ne sont pas servis. Ce routeur sert aussi les assets d'EasyAdmin (`public/bundles`, installés
par `composer install`), ce que `public/index.php` seul ne fait pas.

Tâches planifiées (lancées par le worker, ou à la main) : `app:notifications:generer` (centre de notifications),
`app:abonnements:rappels` (emails d'échéance), `app:pharmacies:archiver` (archivage 12 mois après l'échéance).

Pour les tests, créer `.env.test.local` avec le `DATABASE_URL` local (le suffixe `_test` est ajouté automatiquement),
puis `php bin/console doctrine:migrations:migrate --env=test`.

## Qualité

```bash
composer qa         # PHP-CS-Fixer (vérification) + PHPStan (niveau 6) + PHPUnit
composer cs-fix     # corrige le style
```

L'intégration continue (GitHub Actions, `.github/workflows/ci.yml`) lance les mêmes contrôles à chaque push.

## Import de données

Produits, fournisseurs et clients s'importent depuis Excel ou CSV (menu de chaque liste › « Importer »).
Un fichier modèle est téléchargeable sur la page d'import. L'analyse ne modifie rien et liste les erreurs ligne par
ligne ; la confirmation importe les lignes valides.

## Architecture multi-tenant

- Toute entité métier implémente `App\Tenant\TenantAwareInterface` (en pratique : `use TenantAwareTrait`).
- Le filtre Doctrine `TenantFilter` est activé à chaque requête sur la pharmacie courante (`TenantRequestListener`),
  fermé par défaut, désactivé seulement pour le super admin sous `/admin`.
- La pharmacie est affectée automatiquement à la création (`TenantAssignationListener`) ; aucun formulaire ne l'expose.
- En seconde ligne : les Voters, et `AbstractAppController::exigerMemePharmacie()` qui renvoie un **404**.
- Hors requête HTTP (commandes, tâches planifiées), utiliser `TenantContext::forcer($pharmacie)` ou
  `TenantContext::sansFiltre(fn () => …)`.
- À l'enregistrement, `TenantCoherenceListener` refuse tout lien entre deux pharmacies.
- Fichiers des pharmacies : `App\Stockage\StockageFichiers` (hors du dossier public, rangés par pharmacie).

## Conventions

- Interface **en français**, fuseau `Africa/Bamako`.
- Montants en **entiers FCFA**, affichés via le filtre Twig `|fcfa` → `12 500 FCFA`.
- Téléphones affichés via `|telephone` → `+223 76 12 34 56`.
- Numéros de documents : `App\Service\Numeroteur` (séquentiels sans trou, par pharmacie et par année, RG-02).
- Actions sensibles : `App\Service\AuditLogger` (journal non modifiable, consultable par le propriétaire). Les
  modifications de prix, de règles de gestion et de taux AMO sont tracées automatiquement (`AuditModificationsListener`).
- Notifications : `App\Service\GenerateurNotifications` (une notification n'est créée que si la situation change).
- Stock : jamais modifié directement ; toute variation passe par `App\Stock\StockService` (mouvement typé, FEFO, RG-03 à RG-05).
- Pages connectées : étendre `layout/app.html.twig` (blocs `title`, `page_actions`, `content`).
  Le menu est décrit dans `App\Menu\Navigation` (rôle requis par entrée ; une route absente apparaît « Bientôt »).
- Graphiques : `{{ stimulus_controller('chart', {config: {...}, devise: true}) }}` sur un `<canvas>`.
- Espace plateforme (super admin, `/admin`) : **EasyAdmin 5**. Tableau de bord et menu dans
  `App\Controller\Admin\DashboardController` ; un `*CrudController` par entité gérée ; les pages propres utilisent
  `#[AdminRoute]` et étendent `@EasyAdmin/page/content.html.twig` (blocs `content_title`, `page_actions`, `main`).
  Aucune action « Supprimer ». Point d'entrée des assets : `assets/admin.js`.

## Documentation

- [Cahier des charges](docs/cahier-des-charges.md)
- [Hypothèses et décisions](docs/hypotheses.md)
- [Guide de mise en production](docs/mise-en-production.md) (Nginx, PHP-FPM, worker supervisé, HTTPS, sauvegardes ; exemples dans `deploy/`)
- [Revue de sécurité OWASP](docs/securite.md)
