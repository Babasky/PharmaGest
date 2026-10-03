# Hypothèses et décisions

Liste tenue à jour des hypothèses retenues et des décisions prises quand le [cahier des charges](cahier-des-charges.md)
laisse un point ouvert (livrable § 10.2). Chaque entrée peut être remise en cause : il suffit de la modifier ici.

## Hypothèses du cahier des charges (§ 11.1)

| N° | Hypothèse | Où c'est appliqué |
|----|-----------|-------------------|
| H1 | Le taux AMO est paramétrable par organisme (70 % par défaut), et historisé par date d'effet. | Lot 2 (paramètres) et Lot 5 (AMO) |
| H2 | Sur une vente AMO, la remise ne porte que sur la part assuré. | Lot 4 / Lot 5 (RG-09) |
| H3 | La part AMO est une créance ; elle devient une recette au règlement du bordereau. | Lot 5 / Lot 7 (RG-10) |
| H4 | L'abonnement est payé hors plateforme et activé manuellement. | **Lot 1** : le super admin enregistre le paiement |
| H5 | Le nom « PharmaGest » est un nom de travail. | Nom centralisé dans `app.name` et `app.editeur` (`config/services.yaml`) |

## Décisions — Lot 0 (socle)

- **Montants** : entiers en FCFA ; arrondi unique au franc, 0,5 au supérieur (`App\Util\Fcfa::arrondir`, RG-01).
  Affichage `12 500 FCFA` avec des espaces insécables pour qu'un montant ne soit jamais coupé en fin de ligne.
- **Téléphones** : stockés normalisés `+223XXXXXXXX`, affichés `+223 XX XX XX XX` (`App\Util\Telephone`).
- **Fuseau horaire** : `Africa/Bamako` (GMT, sans heure d'été), fixé dans le `Kernel`, Twig, PHP et DDEV.

## Décisions — Lot 1 (plateforme)

### Comptes et rôles

- **Accès par affectation.** Un `Utilisateur` n'a pas de colonne « pharmacie » : il accède aux pharmacies via la table
  `affectation` (utilisateur, pharmacie, actif). C'est ce qui permet à un propriétaire Premium d'avoir plusieurs
  pharmacies et de basculer de l'une à l'autre (§ 2). La pharmacie courante est mémorisée en session.
- **Un rôle par utilisateur**, porté par le compte (pas par l'affectation) : on ne prévoit pas qu'une même personne soit
  propriétaire d'une pharmacie et vendeur dans une autre. Hiérarchie : propriétaire ⊃ adjoint ⊃ vendeur.
  Le super admin n'hérite d'aucun rôle d'officine.
- **Désactiver un membre** = désactiver son affectation : il ne peut plus se connecter à cette pharmacie, ses données
  et son historique restent (RG-15). Le compte du propriétaire ne se gère pas depuis l'écran « Équipe ».
- **Limite d'utilisateurs** : on compte les affectations actives de la pharmacie, **propriétaire compris**
  (Essentiel = propriétaire + 2 personnes).
- **Limite de pharmacies** : appliquée quand le super admin rattache une nouvelle pharmacie à un propriétaire existant,
  selon l'offre choisie pour cette nouvelle pharmacie.
- **Mot de passe initial** : le propriétaire peut saisir un mot de passe pour un vendeur sans email consulté au
  quotidien ; sinon un lien d'activation est envoyé. Le propriétaire, lui, reçoit toujours un lien (§ 3.2).
- **Liens d'activation et de réinitialisation** : URL signées (pas de table dédiée), valables 72 h (activation) ou
  1 h (réinitialisation), et à usage unique (le lien devient invalide dès que le mot de passe change).
- **Le super admin est créé en ligne de commande** (`app:super-admin:creer`), jamais depuis l'interface.

### Isolation multi-tenant

- Le filtre Doctrine `tenant` n'est **pas** activé dans la configuration : il est activé au début de **chaque requête
  HTTP** (après le pare-feu) sur la pharmacie courante, et **fermé par défaut** (aucune ligne) si l'utilisateur n'a pas
  de pharmacie. Il n'est désactivé que pour le super admin sous `/admin`. Les commandes et tâches planifiées
  travaillent sans filtre et doivent choisir explicitement leur pharmacie (`TenantContext::forcer()`).
- Une donnée d'une autre pharmacie renvoie **404**, jamais 403, pour ne pas révéler son existence.
- `Abonnement` porte la clé de sa pharmacie (le cahier indique « non ») : le propriétaire ne peut ainsi lire que ses
  propres factures, sans code spécifique.
- `JournalAudit` accepte une pharmacie vide pour les actions de la plateforme (visibles du seul super admin).

### Abonnements

- **Statut calculé**, jamais stocké : essai, actif, échéance proche (≤ 30 j), grâce (≤ 7 j après l'échéance),
  expiré, suspendu, archivé. Suspension et archivage sont des décisions manuelles enregistrées sur la pharmacie.
- **RG-14** : seule une période **payée** compte comme « date de fin en cours ». Un paiement pendant l'essai fait donc
  partir les 12 mois d'aujourd'hui (l'essai n'est pas prolongé). Un paiement antidaté ne fait pas remonter la période
  dans le passé : elle commence au plus tôt aujourd'hui.
- **12 mois** : ajoutés sans débordement de mois (un abonnement qui finit le 31/01 est prolongé jusqu'au 31/01,
  le 29/02 devient le 28/02).
- **Sans essai ni paiement**, la pharmacie est en lecture seule jusqu'au premier paiement.
- **Fin d'essai** : les rappels J-30/J-15/J-7 et la période de grâce s'appliquent aussi.
- **Lecture seule (expiré)** : toute requête d'écriture (POST…) est refusée avec un message ; la consultation,
  les factures et (plus tard) les exports restent accessibles (R-02).
- **Factures d'abonnement** numérotées `FAC-AAAA-NNNNNN`, séquence unique pour la plateforme, sans trou.
- **Archivage** : manuel au Lot 1 (SA-03). L'archivage automatique 12 mois après expiration, précédé de l'envoi d'un
  export complet, nécessite l'export de données : il est reporté au Lot 8.
- **Tarifs des offres** : non fixés (point ouvert § 11.2) ; le montant est saisi à chaque paiement.

### Report à un lot ultérieur

- **SA-07** (référentiels communs) : traité au Lot 2 (voir ci-dessous).
- **PH-04, code PIN** : le champ et le changement rapide de vendeur arrivent au **Lot 4** (caisse).
- **Consultation du journal d'audit** (AU-02) : **Lot 8**. Les actions sensibles du Lot 1 sont déjà journalisées.

## Décisions — Lot 2 (référentiels)

### Paramètres de la pharmacie

- **Politique par défaut pour un produit « ordonnance obligatoire » vendu sans ordonnance : blocage**
  (point ouvert § 11.2). C'est le choix le plus prudent ; le propriétaire peut passer à « confirmation par le
  propriétaire » dans Paramètres › Règles de gestion.
- **Plafond de remise par défaut : 10 %**, délai d'alerte péremption par défaut : **90 jours**.
- **Taux AMO** : un taux par organisme et par pharmacie, en **pourcentage entier**, avec une **date d'effet**. Changer
  de taux crée une nouvelle ligne (historique conservé) ; un taux à date future s'appliquera à cette date. Sans taux
  saisi, 70 % s'applique (H1).
- **Logo** : PNG ou JPEG, 500 Ko maximum, stocké hors du dossier public (`var/fichiers/pharmacie-{id}/logo/`) sous un
  nom aléatoire et servi par un contrôleur qui ne donne accès qu'au logo de la pharmacie courante.

### Référentiels communs (SA-07)

- **Organismes AMO** initiaux : INPS et CMSS (glossaire § 11.3). **Formes galéniques** : 20 formes courantes.
  **Catégories de dépenses** : la liste du FI-02. Le super admin les complète ; rien n'est supprimé, une valeur
  désactivée n'est plus proposée.
- Les catégories de dépenses par défaut seront **copiées dans chaque pharmacie** au Lot 7 (dépenses), qui pourra
  ensuite adapter sa liste (FI-02).

### Catalogue

- **Rien n'est supprimé** (RG-15) : catégories, étagères, fournisseurs, produits et clients s'**archivent**. Archivés,
  ils disparaissent des listes de choix et de la liste principale (filtre « Archivés » pour les retrouver).
- **Catégories** sur deux niveaux exactement : une sous-catégorie ne peut pas avoir de sous-catégorie.
- **Code-barres** unique **dans une pharmacie** (deux pharmacies peuvent avoir le même produit).
- **TVA** : choix entre 0 % et 18 % (point ouvert § 11.2 : à confirmer avec un fiscaliste).
- **Prix d'achat** sur la fiche produit = **prix de référence** (pour les commandes et la marge indicative) ; le prix
  réellement payé sera porté par chaque **lot** (Lot 3, ST-01). Il n'est pas affiché au vendeur.
- **Stock** : jamais stocké sur le produit ; il sera calculé à partir des lots (RG-03, Lot 3).
- **Clients** : le téléphone est normalisé (`+223…`) ; un assuré AMO doit avoir **à la fois** un numéro d'assuré et
  un organisme. Le vendeur crée et modifie un client mais ne peut ni le marquer « privilégié » ni l'archiver.
- **Entreprise de rattachement** : simple texte pour l'instant ; les clients conventionnés (VE-12) arriveront en V2.
- **Cohérence entre pharmacies** : en plus du filtre, un contrôle à l'enregistrement refuse tout lien vers une donnée
  d'une autre pharmacie (ex. un produit rangé dans la catégorie d'une autre officine).

### Import Excel / CSV (RF-08)

- **Deux temps** : l'analyse vérifie tout le fichier **sans rien enregistrer** et affiche les erreurs avec leur
  numéro de ligne Excel ; la confirmation importe les **lignes valides** et ignore les autres, en une seule
  transaction.
- **Reconnaissance d'un élément existant** (mise à jour plutôt que doublon) : produit par **code-barres** (sinon nom
  commercial + dosage), fournisseur par **nom**, client par **téléphone** (deux homonymes restent distincts).
- **Produits** : les catégories (« Principale > Sous-catégorie »), étagères et fournisseurs inconnus sont **créés** ;
  une forme galénique inconnue est une **erreur** (référentiel commun).
- **Tolérances** : en-têtes sans tenir compte des majuscules, accents ou astérisques ; montants « 1 500 » ou
  « 1500,00 » ; « oui/non », « o/n », « 1/0 », « x » ; CSV à virgule ou point-virgule, en UTF-8 ou Windows-1252 (export
  Excel français). Limites : 2 Mo et 5 000 lignes par fichier.
- Réservé au **propriétaire et à l'adjoint** (y compris l'import de clients, qui est une opération de masse).
