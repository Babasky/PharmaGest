# Hypothèses et décisions

Liste tenue à jour des hypothèses retenues et des décisions prises quand le [cahier des charges](cahier-des-charges.md)
laisse un point ouvert (livrable § 10.2). Chaque entrée peut être remise en cause : il suffit de la modifier ici.

## Hypothèses du cahier des charges (§ 11.1)

| N° | Hypothèse | Où c'est appliqué |
|----|-----------|-------------------|
| H1 | Le taux AMO est paramétrable par organisme (70 % par défaut), et historisé par date d'effet. | Lot 2 (paramètres) et Lot 5 (AMO) |
| H2 | Sur une vente AMO, la remise ne porte que sur la part assuré. | **Lot 4** : calcul de la vente ; créances au Lot 5 (RG-09) |
| H3 | La part AMO est une créance ; elle devient une recette au règlement du bordereau. | **Lot 5** : créance à la vente, règlements par bordereau ; recette au Lot 7 (RG-10) |
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
- **PH-04, code PIN** : livré au **Lot 4** (voir « Décisions — Lot 4 »).
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

## Décisions — Lot 3 (stock)

### Lots et mouvements

- **Le stock n'est jamais saisi directement** : il est la somme des quantités restantes des lots non périmés (RG-03).
  Toute variation d'un lot passe par `App\Stock\StockService`, qui écrit un `MouvementStock` typé (ST-04) avec la
  quantité restante après le mouvement, l'utilisateur et le document d'origine (n° d'inventaire, de vente…).
  Les mouvements ne se modifient pas.
- **Périmé** = date de péremption **atteinte** : un lot qui périme aujourd'hui n'est déjà plus vendable (RG-05).
  Il reste visible (fiche produit, alertes, valorisation à part) jusqu'à sa destruction.
- **Entrée de stock manuelle** (type « Entrée de stock ») : pour charger le stock initial d'une officine et les
  livraisons sans commande, en attendant la réception des commandes (Lot 6). Elle crée un lot, est réservée au
  propriétaire et à l'adjoint, et est tracée au journal d'audit. Un lot déjà périmé ne peut pas entrer.
- **Deux livraisons d'un même n° de lot** donnent deux lots distincts (chacun avec son prix d'achat et sa date
  d'entrée) ; aucun contrôle d'unicité du numéro.
- **Sortie FEFO** (`StockService::prelever()`, RG-04) : prête pour la caisse (Lot 4). Elle verrouille les lots
  (SELECT … FOR UPDATE), refuse toute sortie supérieure au stock disponible et renvoie, pour chaque lot consommé,
  la quantité et le prix d'achat (marge RG-17). Si seul un lot périmé reste, le message le dit (R-03).
- **Ajustement** : on saisit la quantité réelle du lot, avec un motif obligatoire ; l'écart devient un mouvement
  « Ajustement » et une entrée du journal d'audit (AU-01).
- **Destruction** : possible sur tout lot (périmé ou abîmé), motif obligatoire, tracée. Le procès-verbal PDF (ST-10)
  et le retour fournisseur (ST-09) sont prévus en V1.

### Alertes (ST-05)

- **Rupture ou sous le seuil** : produit actif dont le stock disponible est **inférieur ou égal** au seuil d'alerte.
  Avec un seuil à 0 (valeur par défaut), seul un stock nul est signalé.
- **Péremption proche** : lot en stock qui périme dans le délai réglé dans les paramètres (90 jours par défaut).
- **Produit dormant** : produit actif ayant un lot en stock reçu depuis plus de 90 jours, et aucune vente sur les
  90 derniers jours. Un produit reçu récemment n'est donc jamais « dormant ».
- Les alertes sont calculées à l'affichage (page Stock › Alertes et tableau de bord). Le centre de notifications
  (NO-01) arrive au Lot 8.

### Inventaire (ST-06)

- **Complet, par étagère ou par catégorie** (une catégorie principale inclut ses sous-catégories). Les lots
  périmés encore présents sont comptés.
- Les **quantités théoriques sont figées à l'ouverture**. À la validation, l'écart (compté − théorique) est appliqué
  à la **quantité actuelle** du lot : les ventes faites pendant le comptage restent justes. Si cela rendait un lot
  négatif, la validation est refusée et le lot doit être recompté.
- **Un seul inventaire en cours par pharmacie**, pour qu'un lot ne soit jamais ajusté deux fois.
- Tous les lots doivent être comptés avant la validation (saisir 0 pour un lot introuvable). Un champ vide = pas
  encore compté ; le comptage s'enregistre en plusieurs fois.
- Validation et annulation par le **propriétaire ou l'adjoint** ; numéro `INV-AAAA-NNNNNN` (RG-02). La validation
  écrit un mouvement « Ajustement » par écart (document = n° d'inventaire) et **une** entrée du journal d'audit
  pour l'inventaire (nombre de lots, d'écarts et valeur des écarts).

### Valorisation et fiche produit

- **Valorisation** (ST-07) au prix d'achat réel de chaque lot, par catégorie principale ; les lots périmés sont
  valorisés à part (perte à constater). Réservée au propriétaire et à l'adjoint, comme les prix d'achat.
- **Fiche produit** (ST-08) : stock par lot, historique paginé des mouvements, graphique des ventes des 12 derniers
  mois (alimenté à partir du Lot 4).

## Décisions — Lot 4 (caisse)

### Panier et vente

- Le **panier** est une vente « en cours » sans numéro. Le numéro `V-AAAA-NNNNNN` (RG-02) n'est attribué qu'à
  l'encaissement : une vente abandonnée ne laisse donc pas de trou dans la numérotation.
- **Une ligne par produit** : scanner deux fois le même produit augmente la quantité. Le prix de vente est figé à
  l'ajout de la ligne (RG-06) ; les totaux, la remise, la part AMO et le coût d'achat des lots sont figés à
  l'encaissement.
- Un code-barres scanné à l'identique ajoute directement le produit ; sinon la recherche affiche les résultats.
- Le stock est vérifié à l'ajout et à l'encaissement (R-03) ; la sortie se fait en **FEFO** via
  `StockService::prelever()` (RG-04), lot par lot, dans la même transaction que la numérotation.
- **Ventes en attente** : un panier mis en attente peut être repris par n'importe quel vendeur de la pharmacie (le
  client revient souvent vers un autre comptoir).

### Remises et code PIN

- Remise par ligne ou globale, en pourcentage ou en montant (arrondi RG-01). Sur une **vente AMO**, seule la remise
  globale est possible et elle porte sur la **part assuré** (H2, RG-09).
- Les remises sont réservées aux **clients privilégiés**. Le **plafond** de la pharmacie (RG-08) est comparé au
  **plus haut** des taux : chaque ligne, la remise globale et le total des remises. Au-delà, le **code PIN du
  propriétaire** est demandé ; le propriétaire connecté n'a pas besoin de le saisir. Chaque dépassement est
  journalisé (vendeur, client, taux, plafond, qui a autorisé).
- **Code PIN** (PH-04) : 4 chiffres, stocké haché, codes triviaux refusés (0000, 1234…), choisi par chacun dans
  « Mon code PIN » après saisie de son mot de passe. **5 essais faux en 15 minutes** bloquent la saisie (par
  utilisateur, et par pharmacie pour le PIN du propriétaire).
- **Changement rapide de vendeur** : depuis l'écran de caisse, avec le code PIN du vendeur, sans mot de passe.

### Types de vente et ordonnance

- Trois types : sans ordonnance, ordonnance classique, ordonnance AMO. Une ordonnance est complète avec sa **date et
  son prescripteur** ; le numéro et la structure sont facultatifs. Le scan de l'ordonnance arrive au Lot 5.
- Un produit soumis à ordonnance dans une vente sans ordonnance : selon le paramètre de la pharmacie, la vente est
  **bloquée**, ou autorisée avec le **code PIN du propriétaire** et journalisée.
- Vente AMO : organisme, n° d'assuré et taux (taux de l'organisme, sinon celui de la pharmacie) sont figés ; la part
  AMO est calculée sur les produits remboursables. La **créance AMO** et les bordereaux (AM-05) sont construits au
  **Lot 5** à partir de ces ventes.

### Paiements, tickets

- Paiement **mixte** : espèces, Orange Money, Moov Money, carte (référence facultative). Les paiements
  électroniques ne peuvent pas dépasser le montant dû ; le reste est payé en espèces et la monnaie est calculée sur
  les espèces remises.
- Ticket **80 mm** et facture **A4** en PDF, réimprimables depuis la fiche de la vente.
- **Crédit client** (VE-04 / VE-11) et **avoir** : reportés à la **V1**. Les recettes et la contre-passation
  (FI-04), ainsi que le rapport des remises (RE-05), viennent au **Lot 7**, calculés à partir des paiements.

### Sessions de caisse et annulations

- **Une session par utilisateur** : un vendeur ne vend qu'avec sa propre caisse ouverte (fond de caisse saisi à
  l'ouverture, numéro `SC-AAAA-NNNNNN`). Le vendeur ne voit que ses sessions ; le propriétaire et l'adjoint voient
  et peuvent clôturer toutes les sessions.
- **Clôture** : comptage par billet et pièce du franc CFA. Espèces attendues = fond + encaissements espèces −
  remboursements espèces. Tout écart (RG-13) exige une justification et est journalisé ; le **rapport Z** (PDF) est
  disponible après la clôture.
- **Annulation** (RG-12) par le propriétaire ou l'adjoint, avec motif, **le jour même** et tant que la session de la
  vente est ouverte ; au-delà, il faut établir un avoir (V1). Le stock est réintégré dans les lots d'origine
  (mouvement « Annulation ») et le remboursement est compté comme une sortie de la session de la vente.

## Décisions — Lot 5 (AMO)

### Créances (AM-05)

- Chaque vente AMO encaissée avec une part AMO crée une **créance « en attente »** sur l'organisme, dans la même
  transaction que la vente. Son montant est la part AMO figée sur la vente (RG-06, RG-07) : rien n'est recalculé.
- Les ventes AMO encaissées avant ce lot reçoivent leur créance à la migration (les ventes annulées sont exclues).
- **Annulation** de la vente le jour même : la créance passe « annulée » et sort de l'encours (et d'un bordereau
  brouillon). Si elle figure déjà sur un bordereau transmis, l'annulation est refusée.
- Statuts d'une créance : en attente, transmise, payée partiellement, payée, rejetée, annulée. Le statut se déduit
  du montant réglé et du motif de rejet : une créance réglée en partie puis rejetée garde le montant réglé, seul le
  reste est perdu.

### Bordereaux (AM-06, AM-07, RG-11)

- Un bordereau regroupe les créances en attente **d'un organisme** pour les **ventes d'une période** (dates de
  vente). Le brouillon se compose librement : retirer une créance, ajouter celles arrivées depuis, supprimer le
  brouillon. Une créance n'est jamais sur deux bordereaux.
- Le numéro `BRD-AAAA-NNNNNN` (RG-02) est attribué à la **transmission**, comme pour les ventes : un brouillon
  supprimé ne laisse pas de trou. À la transmission, le montant est figé et plus rien ne change (RG-11), y compris
  la copie des ordonnances.
- **Exports** : Excel (une ligne par créance, numéros d'assuré en texte, totaux) et **PDF unique** : le relevé avec
  cadres de signature, puis une page par copie d'ordonnance. Les deux sont disponibles dès le brouillon (mention
  « BROUILLON » sur le PDF) pour vérification. Le format propre à chaque organisme reste à confirmer (§ 11.2).
- Le brouillon signale les ordonnances sans copie ; la transmission n'est pas bloquée pour autant.

### Copie de l'ordonnance (AM-01)

- Photo ou scan en **JPEG, PNG ou WebP** (8 Mo au plus), prise à la caisse ou ajoutée ensuite depuis la fiche de
  la vente. L'image est **recompressée en JPEG, 1600 px au plus** : lisible, légère en 3G, et intégrable telle quelle
  au PDF. Les scans PDF ne sont pas acceptés pour l'instant (il faudrait fusionner des PDF).
- Fichiers rangés par pharmacie hors du dossier public, servis par un contrôleur qui vérifie la pharmacie
  (une autre pharmacie obtient une 404). Le vendeur peut joindre et voir la copie ; le super admin jamais.

### Règlements et rejets (AM-08)

- Un règlement (date, montant reçu, référence) est **affecté créance par créance** ; le total affecté doit être égal
  au montant reçu. Plusieurs règlements successifs sont possibles sur un même bordereau.
- Un **motif de rejet** sur une créance solde son reste ; un bouton rejette tout le reste du bordereau.
- Statut du bordereau : payé si tout est réglé ; rejeté si rien n'est réglé et tout est rejeté ; payé partiellement
  dès qu'une partie est réglée (R-11 : 9 réglées et 1 rejetée → « payé partiellement »).
- Le règlement est la source de la **recette AMO** : sa génération (FI-04, RG-10) arrive au Lot 7 avec les autres
  recettes. Le traitement d'une créance rejetée (refacturation, nouvelle soumission, perte, AM-09) et le rappel des
  bordereaux impayés (AM-11) restent en V1.
- Bordereaux et règlements : propriétaire et adjoint seulement (matrice des droits). Transmission, règlement et rejet
  sont journalisés.

### Suivi (AM-10)

- **Encours** = part AMO ni réglée ni rejetée, par organisme, réparti par **ancienneté depuis la date de la vente**
  (0-30, 31-60, 61-90, plus de 90 jours).
- **Taux de rejet** = montant rejeté / montant des créances transmises, par organisme et au total.

## Décisions — Lot 6 (commandes)

### Brouillon et numérotation (CO-01, CO-03, RG-02)

- Une commande = **un fournisseur**. Le brouillon se compose librement : recherche par nom, DCI ou code-barres, une
  ligne par produit (ajouter deux fois le même produit cumule la quantité), quantité 0 = ligne retirée. Le prix
  estimé est repris du **prix d'achat de référence** de la fiche produit et reste modifiable sur le brouillon.
- Le **vendeur** prépare des brouillons (matrice des droits) mais ne voit pas les prix d'achat (décision du Lot 2) :
  colonnes de prix masquées, prix envoyé ignoré. Passer, envoyer, annuler, réceptionner et télécharger l'Excel sont
  réservés au **propriétaire et à l'adjoint**.
- Le numéro `CMD-AAAA-NNNNNN` est attribué quand la commande est **passée** (comme les ventes et les bordereaux) :
  un brouillon supprimé ne laisse pas de trou. Passée, la commande n'est plus modifiable.
- Deux façons de passer une commande : **par email** (CO-05) ou **sans email**, pour une commande téléphonée ou
  envoyée par WhatsApp avec le bon Excel. Les deux donnent le statut « envoyée ».
- Statuts (CO-03) : brouillon → envoyée → reçue partiellement → reçue ; « annulée » seulement pour une commande
  envoyée dont rien n'a été reçu (un brouillon se supprime). Une commande reçue en partie dont le reste ne viendra
  pas peut être **soldée** avec un motif : elle passe « reçue » et le reliquat n'est plus attendu. Annulation et
  solde sont journalisés (motif, unités non livrées).

### Suggestion (CO-02)

- Produits actifs au **seuil d'alerte ou en dessous** (même règle que l'alerte du Lot 3), groupés par **fournisseur
  habituel** ; les produits sans fournisseur habituel actif sont listés à part pour qu'on le renseigne.
- Quantité proposée = stock maximum − stock disponible − **quantité déjà commandée et pas encore livrée** (commandes
  envoyées ou reçues en partie), pour ne pas commander deux fois. **Sans stock maximum**, on vise le double du seuil.
  Un produit en rupture et non commandé reçoit au moins 1 unité.
- On coche les produits, on ajuste les quantités, et le brouillon est créé : il reste à le vérifier et l'envoyer.

### Bon de commande Excel et email (CO-04, CO-05)

- Excel A4 portrait, une page en largeur, en-tête du tableau répété à l'impression : pharmacie (adresse, téléphone,
  email, n° d'autorisation), fournisseur, numéro, date, lignes (désignation, code-barres, conditionnement,
  quantité, prix unitaire estimé, montant) et total. Codes-barres en texte. Un brouillon s'exporte avec la mention
  « BROUILLON ».
- L'email part **tout de suite** (sans file d'attente) pour enregistrer le résultat réel : chaque tentative entre
  dans l'**historique des envois** (date, destinataire, envoyé ou échec avec la cause). Si l'envoi d'un brouillon
  échoue, il reste brouillon et son numéro n'est pas consommé. Une commande passée peut être renvoyée.
- Le fournisseur doit avoir un email ; « Répondre » écrit à l'email de la pharmacie (à défaut, celui de l'expéditeur),
  pas à l'adresse technique de la plateforme.

### Réception (CO-06, RG-16)

- Une réception (date, n° du bon de livraison) par livraison ; plusieurs réceptions par commande. Chaque ligne reçue
  exige un **n° de lot, une péremption future et le prix d'achat réel** (proposé : le prix estimé), et crée un lot
  par `StockService::entrer()` (mouvement « Réception », document = n° de commande, fournisseur de la commande).
  Un même produit peut arriver en **plusieurs lots** (« + lot »).
- Tout est vérifié avant la première écriture : une ligne refusée n'enregistre rien.
- Une quantité **supérieure** à la commande est acceptée avec un avertissement (RG-16). Le statut se déduit des
  quantités reçues : tout reçu → « reçue », sinon « reçue partiellement ».
- L'écart entre prix réel et estimé (CO-07), la mise à jour du prix de référence et les factures et dettes
  fournisseurs (CO-08) restent en **V1**.

### Entrée de stock manuelle

- Elle est **conservée** pour ce qui n'est pas une commande : stock initial à l'installation, don, échantillons,
  régularisation. Le **motif devient obligatoire** et l'écran renvoie vers la réception pour une livraison commandée.
  Elle reste réservée au propriétaire et à l'adjoint et tracée au journal d'audit.
