# Rapport d'analyse : SoftPharm / PharmaGest

*Dépôt `Babasky/PharmaGest`, commit `372c19e` (« #62 Inventaire des entrepôts », 25/10/2026). Analyse du 31/10/2026.*

> **Méthode et limites.** Analyse statique : j'ai lu le code sans l'exécuter. Les dépendances (`composer install`)
> n'ont pas pu être installées dans l'environnement d'analyse. PHPUnit, PHPStan et PHP-CS-Fixer n'ont donc **pas**
> été lancés ici. Les résultats de la CI GitHub restent la référence pour savoir si la suite passe.

---

## 1. Synthèse

SoftPharm est un SaaS multi-tenant de gestion d'officines pour le Mali (FCFA, `Africa/Bamako`, AMO/INPS/CMSS,
Orange Money, Moov, Wave). Le projet est **de bonne qualité professionnelle**. L'architecture est claire, les règles
métier sont regroupées dans des services, la sécurité multi-tenant est construite en plusieurs couches et les tests
sont nombreux. La documentation est sérieuse : cahier des charges, revue OWASP et guide de mise en production.

Le principal défaut trouvé est une **classe de bugs de concurrence** (*TOCTOU*). Le statut d'un document (vente,
transfert) est vérifié **avant** la transaction, sans verrou. Deux validations simultanées du même document peuvent
alors **sortir le stock deux fois** ou **le faire entrer deux fois**. C'est le point à corriger en priorité
(section 4.1).

| Domaine | Appréciation |
|---|---|
| Architecture / organisation | ★★★★★ |
| Sécurité (accès, multi-tenant, OWASP) | ★★★★☆ |
| Intégrité des données en concurrence | ★★★☆☆ (voir 4.1) |
| Tests et outillage qualité | ★★★★☆ |
| Documentation | ★★★★★ |
| Exploitation (déploiement, sauvegardes) | ★★★★☆ |

---

## 2. Vue d'ensemble technique

**Stack :** Symfony 7.4 LTS, PHP ≥ 8.3, Doctrine ORM 3, MySQL 8, Twig + Bootstrap 5.3, AssetMapper (sans Node),
Stimulus + Turbo, EasyAdmin 5 (espace super-admin), Messenger + Scheduler + Mailer, PhpSpreadsheet, Dompdf.
Environnement local DDEV, CI GitHub Actions.

**Volumétrie :**

| Élément | Quantité |
|---|---|
| Code PHP `src/` | ~30 900 lignes, 310 fichiers |
| Entités Doctrine | 51 |
| Contrôleurs | 44 (dont 12 dans `Admin/`) |
| Gabarits Twig | 115 fichiers, ~6 900 lignes |
| JavaScript (Stimulus, caisse hors ligne, service worker) | ~1 300 lignes |
| Tests | 60 classes, ~350 méthodes, ~11 100 lignes (49 fonctionnelles, 11 unitaires/intégration) |
| Migrations | 26 |

**Organisation par domaine métier** (bonne pratique, lisible) :

| Dossier | Rôle |
|---|---|
| `Tenant/` | Isolation multi-pharmacie : filtre SQL, contexte, listeners de cohérence |
| `Vente/` | Panier, contrôles, encaissement, caisse, accords du propriétaire, ventes hors ligne |
| `Stock/` | Lots FEFO, mouvements, inventaires, transferts inter-officines et internes (entrepôts) |
| `Achat/` | Commandes fournisseurs, réception, suggestions, PharmaML, envoi email/Excel |
| `Amo/` | Créances et bordereaux d'assurance maladie |
| `Finance/` | Recettes, dépenses, justificatifs |
| `Reporting/` | Indicateurs, rapports, exports |
| `Import/` | Import CSV/Excel (produits, clients, fournisseurs) |
| `Security/`, `EventSubscriber/` | Code PIN, voters, en-têtes, inactivité, abonnement |
| `Service/` | Abonnement, équipe, numérotation, audit, export RGPD, archivage |

**Fonctionnalités couvertes :** caisse avec validation par code PIN, flux vendeur → caissier, sessions de caisse et
rapport Z, multi-caisses, remises avec plafond et accord du propriétaire, ventes avec ou sans ordonnance, AMO
(part assuré/organisme, bordereaux, règlements), stock par lots FEFO avec blocage des périmés, alertes, inventaires,
entrepôts, transferts entre officines d'un même propriétaire, commandes et réceptions, suggestions de commande,
dépenses et recettes, rapports PDF/Excel, notifications, journal d'audit immuable, abonnements (essai, grâce,
lecture seule, suspension, archivage), export complet des données (ZIP), **caisse hors ligne** (service worker +
`localStorage`, resynchronisation idempotente par UUID).

---

## 3. Points forts

### 3.1 Isolation multi-tenant en plusieurs couches
- **`TenantFilter`** (filtre SQL Doctrine) **fermé par défaut** : sans pharmacie courante, il renvoie `1 = 0`. Les
  référentiels partagés (`TenantPartageInterface`) gèrent proprement les valeurs communes (`pharmacie_id IS NULL`).
- **`TenantAssignationListener`** rattache automatiquement la pharmacie et refuse d'écrire dans une autre.
- **`TenantCoherenceListener`** (`onFlush`) refuse toute association vers une donnée d'une autre pharmacie, même si
  un formulaire a été contourné.
- **`TenantVoter`** + `exigerMemePharmacie()` en seconde ligne, avec une réponse **404 et non 403** (on ne révèle pas
  l'existence de la donnée).
- Seul `/admin` **et** un super-admin désactivent le filtre (`TenantRequestListener`).
- `sansFiltre()` restaure correctement l'état du filtre dans un `finally`.

### 3.2 Sécurité applicative
- Hiérarchie de rôles cohérente (propriétaire ⊃ adjoint ⊃ vendeur ⊃ caissier), super-admin isolé.
- `#[IsGranted]` sur les contrôleurs, CSRF partout, *login throttling* (5 essais / 15 min).
- Code PIN haché comme un mot de passe, limiteur **consommé avant la vérification** (résiste aux requêtes
  parallèles), codes triviaux refusés.
- Le PIN ne vaut plus accord du propriétaire : l'accord se donne depuis le compte du propriétaire, ce qui est une
  bonne décision de conception.
- Fichiers hors du dossier public, noms aléatoires, motif strict sur le nom (`/^[a-f0-9]{24}\.[a-z0-9]{1,5}$/`), pas
  de traversée de répertoire.
- Redirection des notifications protégée contre l'*open redirect* (`//`, `\`, caractères de contrôle).
- En-têtes de sécurité (nosniff, X-Frame-Options, COOP, CSP partielle, HSTS en HTTPS).
- Protection contre l'injection de formules dans les exports Excel/CSV.
- Lecture seule à l'expiration de l'abonnement **appliquée côté serveur** (toute méthode non sûre est refusée).
- Journal d'audit en écriture seule (`JournalAuditImmuableListener`).

### 3.3 Règles métier
- Logique dans les services, contrôleurs minces (`AbstractAppController`).
- Numérotation **sans trou** par pharmacie et par année (`Numeroteur`, `SELECT … FOR UPDATE` + `INSERT IGNORE` pour
  la création concurrente, `HINT_REFRESH` pour relire la valeur en base).
- Sortie FEFO avec verrou pessimiste sur les lots (`verrouillerDisponibles`), vérification du stock avant toute
  écriture.
- Encaissement par le caissier protégé contre la double prise par un `UPDATE … WHERE statut = 'a_encaisser'`
  conditionnel. **C'est le bon modèle**, à généraliser (voir 4.1).
- Aucune suppression des données financières : contre-passation et annulation motivée.
- Synchronisation hors ligne idempotente : contrainte unique `(pharmacie_id, uuid)`, et le serveur recontrôle et
  renumérote chaque vente.

### 3.4 Qualité et outillage
- CI complète : PHP-CS-Fixer, PHPStan niveau 6 (extensions Symfony et Doctrine), `doctrine:schema:validate`,
  PHPUnit sur MySQL 8. Fuseau `Africa/Bamako` imposé en CI.
- Tests d'isolation tenant dédiés (`IsolationTenantTest`, `IsolationReferentielsTest`), tests de droits par module.
- Fabriques Foundry et `AppStory` pour une démo réaliste (`composer demo`).
- Déploiement atomique par *releases* + lien symbolique, sauvegardes nocturnes chiffrées GPG (clé publique seule
  sur le serveur), copie distante rclone, rétention de 30 jours.

---

## 4. Problèmes identifiés

### 4.1 🔴 Priorité haute : contrôles de statut hors transaction (double validation)

**Principe.** Plusieurs opérations vérifient l'état du document **avant** d'ouvrir la transaction, sur une entité
lue sans verrou. Si deux requêtes arrivent en même temps sur le même document, toutes deux passent le contrôle. La
seconde attend le verrou (compteur ou lots), puis **refait l'opération** sur un document déjà traité.

Causes possibles : deux onglets, deux utilisateurs, un renvoi réseau ou un rejeu du navigateur. Turbo désactive le
bouton pendant l'envoi, ce qui limite le double-clic simple, mais ne protège pas des autres cas.

| Opération | Fichier | Contrôle hors transaction | Conséquence d'un double envoi |
|---|---|---|---|
| `VenteService::encaisser()` / `envoyerEnCaisse()` / `encaisserHorsLigne()` | `src/Vente/VenteService.php:378`, `:468`, `:419` | `exigerModifiable($vente)` | Stock **sorti deux fois**, deuxième numéro attribué (le premier est écrasé : **trou dans la numérotation**, RG-02 violée), paiements et recette en double |
| `VenteService::annuler()` | `src/Vente/VenteService.php:531` | `estValidee() / estAEncaisser()` | Stock **réintégré deux fois**, recette contre-passée deux fois |
| `TransfertService::expedier()` | `src/Stock/TransfertService.php:217` | `exigerEnPreparation()` | Stock de l'origine **sorti deux fois** |
| `TransfertService::receptionner()` | `src/Stock/TransfertService.php:280` | `estExpedie()` | Stock **entré deux fois** chez la destination (deux adjoints qui confirment en même temps) |

Les autres opérations du même type méritent la même vérification : réception de commande, clôture de caisse,
validation d'inventaire, transfert interne, bordereaux AMO.

**Correctif recommandé.** Le projet le fait déjà dans `encaisserEnCaisse()`. Il faut, **dans** la transaction :

```php
// Option A : verrou + relecture, puis contrôle
$this->em->lock($vente, LockMode::PESSIMISTIC_WRITE);
$this->em->refresh($vente);
$this->exigerModifiable($vente);

// Option B : transition de statut conditionnelle (comme encaisserEnCaisse)
UPDATE vente SET statut = :nouveau WHERE id = :id AND statut IN (:attendus)  -- doit toucher 1 ligne
```

Ajouter des tests d'intégration qui appellent deux fois l'opération (simulation séquentielle avec une entité
périmée en mémoire) pour figer le comportement.

### 4.2 🟠 Priorité moyenne

1. **Lots verrouillés mais pas relus** (`LotRepository::verrouillerDisponibles()`, `:97`). La requête
   `FOR UPDATE` n'a pas `HINT_REFRESH`, contrairement à `Numeroteur`. Si un lot est déjà chargé dans
   l'EntityManager (traitement de plusieurs ventes dans la même requête, synchronisation hors ligne,
   `importerEnAttente`), Doctrine garde la quantité en mémoire au lieu de celle relue sous verrou.

   Combiné avec `StockService::transaction()`, qui **laisse l'EntityManager ouvert après un rollback**, un lot peut
   rester décrémenté en mémoire après une vente refusée. Le flush suivant écrit alors une quantité fausse.

   *Correctif :* ajouter `->setHint(Query::HINT_REFRESH, true)`. Après un rollback dans une boucle, faire un
   `refresh()` ou un `clear()` des entités touchées.
2. **`transaction()` dupliquée** à l'identique dans `StockService` et `CommandeService`. À extraire dans un service
   unique (par ex. `App\Service\Transaction`), d'autant que ce comportement « ne pas fermer l'EM » est subtil
   (point 1).
3. **28 appels à `sansFiltre()`.** C'est le point d'entrée de toute fuite entre pharmacies. Chaque appel est à revoir
   et à commenter. Un test d'architecture (ou une règle PHPStan) pourrait limiter les classes autorisées à l'appeler.
4. **CSP sans restriction des scripts.** C'est connu et documenté (`docs/securite.md`, A05) : sans `script-src`, une
   éventuelle XSS n'est pas contenue. À traiter avec un nonce sur l'importmap, comme prévu en V1.
5. **Chiffrement au repos des ordonnances** : marqué « à faire avant l'ouverture commerciale ». C'est une donnée de
   santé : à garder en tête de la feuille de route.
6. **Double authentification du propriétaire** : prévue en V1 (PH-06). Elle est importante, puisque le propriétaire
   donne les accords et voit tout.

### 4.3 🟡 Priorité basse / hygiène

- **`|raw` dans les gabarits** (`_bandeau_abonnement.html.twig`, `referentiel/_filtres.html.twig`). Ils sont sûrs
  aujourd'hui (HTML composé par les gabarits), mais fragiles : préférer passer des blocs Twig ou des fragments
  `include`.
- **Caisse hors ligne et `localStorage`.** Le catalogue (prix, stock) et les ventes en attente restent sur le poste.
  À la déconnexion, l'identité et le catalogue sont effacés, mais pas les ventes en attente (c'est voulu). Il faut le
  documenter pour les postes partagés et prévoir une limite d'âge des ventes non synchronisées.
- **README** : le paragraphe « Codes PIN de la Pharmacie du Fleuve » apparaît **deux fois avec deux versions
  contradictoires** (l'une dit que le PIN ne vaut jamais accord du propriétaire, l'autre qu'il le peut). Supprimer la
  seconde, obsolète depuis la décision du 23/10/2026.
- **Gros fichiers** à surveiller : `VenteService` (791 lignes), `Vente` (720), `CaisseController` (533),
  `CommandeController` (514). `VenteService` pourrait être découpé (panier / validation / annulation).
- **`.env`** : `APP_SECRET` est vide. C'est normal en dev, et le déploiement passe par `.env.local` et
  `composer dump-env prod`. Vérifier qu'un contrôle bloque un démarrage en prod sans secret.
- **`deployer.sh`** : les migrations tournent **avant** la bascule de `current`, donc l'ancien code tourne un instant
  sur le nouveau schéma. C'est acceptable si les migrations restent rétrocompatibles : à formaliser comme règle.
  Le script clone l'URL publique GitHub : si le dépôt devient privé, prévoir une clé de déploiement.
- **PHPStan niveau 6** : viser 8 progressivement (types de retour, `mixed`).
- **Dépendances `require-dev` en `"*"`** (php-cs-fixer, phpstan, foundry…) : figées par le `composer.lock`, mais une
  mise à jour peut faire passer une version majeure sans prévenir. Préférer des contraintes `^x.y`.

---

## 5. Couverture de tests

**Bien couverts :** isolation tenant, droits par rôle, caisse (PIN, encaissement, annulation, clôture, remises,
accords, hors ligne), AMO, commandes, stock (FEFO, ruptures, inventaires, entrepôts, transferts), finances,
abonnement, export, journal d'audit, pages d'erreur, en-têtes de sécurité.

**Manquants :**
- **Concurrence et rejeu** (section 4.1) : aucun test ne vérifie qu'une deuxième validation ou annulation est
  refusée.
- Tests unitaires du `TenantFilter` / `TenantContext::sansFiltre()` (restauration du paramètre après une exception).
- Tests JavaScript de la caisse hors ligne (file d'attente, reprise après erreur partielle de synchronisation).

---

## 6. Plan d'action recommandé

| # | Action | Effort | Impact |
|---|---|---|---|
| 1 | Déplacer les contrôles de statut **dans** la transaction avec verrou ou `UPDATE` conditionnel (vente : encaisser, envoyer en caisse, annuler ; transfert : expédier, réceptionner ; puis les autres documents) + tests | 1–2 j | Intégrité du stock et de la numérotation |
| 2 | `HINT_REFRESH` sur `verrouillerDisponibles()` + `refresh` des lots après rollback dans les boucles | 0,5 j | Quantités justes en traitement par lot |
| 3 | Factoriser `transaction()` dans un service unique | 0,5 j | Maintenabilité |
| 4 | Revue documentée des 28 `sansFiltre()` + garde-fou statique | 1 j | Sécurité multi-tenant |
| 5 | Corriger le doublon contradictoire du README | 5 min | Clarté |
| 6 | Chiffrement au repos des ordonnances, 2FA propriétaire, CSP avec nonce | Feuille de route V1 | Conformité et sécurité |
| 7 | Découper `VenteService`, monter PHPStan au niveau 7 puis 8, contraindre les versions dev | Continu | Dette technique |

---

## 7. Conclusion

Le code est mature, homogène et visiblement pensé pour la production : multi-tenant défendu en profondeur, règles
métier testées, exploitation documentée. Le seul risque sérieux relevé est la gestion des **opérations rejouées ou
simultanées** sur un même document. La correction est localisée et le bon modèle existe déjà dans le code
(`encaisserEnCaisse`). Une fois ce point traité, puis le chiffrement des ordonnances et la 2FA, l'application est
dans un très bon état pour une ouverture commerciale.
