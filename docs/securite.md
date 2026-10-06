# Revue de sécurité — OWASP Top 10 (2021)

Revue faite au Lot 8 avant la mise en production (§ 6 du [cahier des charges](cahier-des-charges.md) : « Revue selon
l'OWASP Top 10 avant mise en production »). Pour chaque risque : ce qui protège PharmaGest, où c'est dans le code, et
ce qui reste à faire. À relire à chaque lot qui ajoute un écran, un fichier ou un export.

## A01 — Contrôle d'accès

| Mesure | Où |
|---|---|
| Matrice des droits appliquée par rôle sur chaque contrôleur (`#[IsGranted]`), hiérarchie propriétaire ⊃ adjoint ⊃ vendeur ; tout le reste exige une connexion (`access_control`). | `config/packages/security.yaml`, `src/Controller/*` |
| Isolation multi-tenant : filtre Doctrine activé à chaque requête, fermé par défaut ; affectation automatique de la pharmacie ; refus des liens entre deux pharmacies ; Voter de seconde ligne. Une donnée d'une autre pharmacie répond **404**. | `src/Tenant/*`, `AbstractAppController::exigerMemePharmacie()` |
| Le super admin n'a aucun rôle d'officine ; son journal ne montre que les actions de la plateforme. L'espace plateforme (EasyAdmin) est protégé deux fois : `access_control` sur `/admin` et `#[IsGranted('ROLE_SUPER_ADMIN')]` sur chaque contrôleur ; les suppressions y sont désactivées. | `src/Controller/Admin/*` |
| Fichiers (logos, justificatifs, ordonnances, exports) hors du dossier public, noms aléatoires, servis après contrôle de la pharmacie. | `StockageFichiers`, contrôleurs de téléchargement |
| Lecture seule à l'expiration : toute requête d'écriture est refusée côté serveur, pas seulement masquée. | `AccesAbonnementListener` |
| Une notification ne s'ouvre que pour son destinataire ; la redirection n'accepte qu'un chemin de l'application. | `NotificationController::ouvrir()` |

Tests : `IsolationTenantTest`, `IsolationReferentielsTest`, `DroitsReferentielsTest`, tests de droits de chaque module.

## A02 — Défaillances cryptographiques

- Mots de passe et codes PIN hachés (`auto` : bcrypt ou argon2id). Liens d'activation et de réinitialisation signés,
  à durée limitée et à usage unique.
- HTTPS obligatoire en production (redirection Nginx, HSTS envoyé sur toute réponse HTTPS), cookie de session
  `Secure` dès que la requête est en HTTPS.
- **À faire avant l'ouverture commerciale** : chiffrement au repos des copies d'ordonnances (§ 6, « scans chiffrés
  au repos »). En attendant, le dossier des fichiers et les sauvegardes sont sur un volume chiffré (voir
  [mise en production](mise-en-production.md)) et les sauvegardes sont chiffrées avant de quitter le serveur.

## A03 — Injection

- Requêtes Doctrine paramétrées partout ; l'export complet lit les tables par leurs noms de métadonnées, avec
  identifiants protégés et paramètre lié pour la pharmacie.
- Twig échappe toute sortie par défaut ; les seuls `|raw` affichent du HTML composé par les gabarits eux-mêmes, dont les
  données sont déjà échappées (bandeau d'abonnement, filtres des listes).
- EasyAdmin affiche sans échappement les messages flash et les valeurs mises en forme par `formatValue()` : l'espace
  plateforme échappe (`htmlspecialchars`) toute donnée saisie qu'il y place.
- **Injection de formules** dans les fichiers Excel/CSV : les textes saisis sont écrits comme texte
  (`setCellValueExplicit`) dans les exports Excel, et précédés d'une apostrophe dans l'export CSV quand ils commencent
  par `=`, `+`, `-` ou `@`. Corrigé au Lot 8 pour le nom de la pharmacie et du fournisseur (bon de commande,
  bordereau AMO).

## A04 — Conception non sécurisée

- Limitation des tentatives : 5 connexions ratées en 15 minutes (pare-feu), 5 codes PIN faux en 15 minutes.
- Règles métier dans des services testés (FEFO, blocage des périmés, plafond de remise, numérotation sans trou).
- Journal d'audit en écriture seule (aucune route de modification, listener qui refuse mise à jour et suppression).

## A05 — Mauvaise configuration

- En-têtes sur toutes les réponses : `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
  `Referrer-Policy: same-origin`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, CSP
  `frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'` (`EnTetesSecuriteListener`).
  La CSP ne restreint pas encore les scripts : l'importmap d'AssetMapper est un script en ligne ; une CSP avec nonce
  est à étudier en V1.
- Cookie de session `HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS (`framework.yaml`).
- Production : `APP_ENV=prod`, `APP_DEBUG=0`, `APP_SECRET` long et aléatoire, aucun outil de développement installé
  (`composer install --no-dev`), seul `public/` est servi par Nginx.

## A06 — Composants vulnérables

- Versions figées par `composer.lock` et `importmap.php` ; Symfony 7.4 LTS.
- `composer audit` : aucune vulnérabilité connue au 06/10/2026. À relancer à chaque mise à jour et chaque mois
  (étape de la [check-list d'exploitation](mise-en-production.md#exploitation-courante)).

## A07 — Identification et authentification

- Comptes activés par lien email ; mot de passe de 8 caractères au moins ; compte désactivé, sans pharmacie
  accessible : connexion refusée ; pharmacie suspendue ou archivée : plus aucun accès.
- Déconnexion après 30 minutes d'inactivité ; à la caisse, délai réglé par le propriétaire (5 à 720 minutes) tant
  qu'une session de caisse est ouverte (`DelaiInactiviteListener`).
- Protection CSRF sur la connexion, la déconnexion et tous les formulaires.
- Double authentification du propriétaire : **V1** (PH-06).

## A08 — Intégrité des logiciels et des données

- Dépendances installées depuis le fichier de verrouillage ; intégration continue (style, PHPStan niveau 6, schéma
  Doctrine, tests) à chaque push.
- Le stock ne change que par des mouvements typés ; recettes et dépenses ne se suppriment pas (contre-passation,
  annulation motivée).

## A09 — Journalisation et surveillance

- Journal d'audit des actions sensibles (AU-01), consultable et filtrable par le propriétaire (AU-02) ; journal de la
  plateforme pour le super admin. Ajouts du Lot 8 : modification de prix, règles de gestion, taux AMO, export complet
  des données, annonce d'archivage.
- Erreurs applicatives en JSON sur la sortie d'erreur (Monolog, `fingers_crossed`) récupérées par PHP-FPM ; à brancher
  sur la supervision du serveur (voir [mise en production](mise-en-production.md)).

## A10 — Falsification de requêtes côté serveur (SSRF)

- L'application n'appelle aucune adresse fournie par un utilisateur (emails envoyés par le serveur SMTP configuré,
  aucun téléchargement d'URL).

## Points ouverts

| Point | Échéance |
|---|---|
| Chiffrement applicatif des copies d'ordonnances | Avant l'ouverture commerciale (pilote sur volume chiffré) |
| CSP restreignant les scripts (nonce sur l'importmap) | V1 |
| Double authentification du propriétaire (PH-06) | V1 |
| Déclaration APDP et avis d'un juriste (loi n° 2013-015) | Avant le pilote (point ouvert § 11.2) |
| Test d'intrusion externe | Avant l'ouverture commerciale |
