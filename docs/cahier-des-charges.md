# Cahier des charges — PharmaGest

SaaS multi-tenant de gestion d'officines adapté au Mali — 3 oct. 2026 · @Modibo

> Version de référence transmise par le porteur du projet. Toute évolution passe par une modification de ce fichier
> (avec la date) et, si elle change une décision, par une entrée dans [hypotheses.md](hypotheses.md).

## 1. Présentation du projet

PharmaGest est un logiciel en ligne, vendu par abonnement annuel, qui permet à une officine malienne de gérer son stock, ses ventes (y compris les ordonnances AMO), ses commandes, ses dépenses et ses rapports depuis un simple navigateur.

### 1.1 Contexte

- Beaucoup d'officines gèrent encore leur activité sur cahier, sur Excel ou avec des logiciels installés sur un seul poste, sans sauvegarde ni accès à distance.
- Une officine s'approvisionne auprès de plusieurs fournisseurs : la PPM et des grossistes répartiteurs privés.
- Les produits sont classés par catégorie et rangés par étagère. Retrouver vite un produit au comptoir est un besoin quotidien.
- On vend avec ou sans ordonnance. Il existe deux types d'ordonnances : classiques et AMO. Pour l'AMO, l'assuré paie 30 % et l'organisme rembourse 70 % à la pharmacie, avec un délai.
- L'AMO n'est pas la seule assurance : une ONG ou une entreprise privée peut inscrire ses employés auprès d'une autre assurance, avec un taux de prise en charge différent. L'AMO fixe aussi, pour chaque médicament, son propre prix de vente, qui peut différer de celui de la pharmacie : le taux AMO s'applique sur ce prix AMO.
- La créance AMO pèse sur la trésorerie : sans suivi, les bordereaux impayés ou rejetés passent inaperçus.
- Les officines accordent des remises à leurs clients fidèles, souvent sans trace écrite.

### 1.2 Objectifs

| Objectif | Indicateur cible |
|---|---|
| Vendre vite au comptoir | Vente courante enregistrée en moins de 60 secondes |
| Ne plus vendre de produit périmé | 0 vente sur un lot périmé (blocage système) |
| Maîtriser la créance AMO | Encours AMO et ancienneté visibles en temps réel |
| Éviter les ruptures | Alerte sur 100 % des produits sous le seuil |
| Piloter l'officine | Recettes, dépenses et résultat du mois en 1 clic |
| Tracer les remises | 100 % des remises rattachées à un vendeur et à un client |
| Rentabiliser la plateforme | Abonnements suivis et renouvelés sans ressaisie |

### 1.3 Périmètre

**Inclus** : gestion multi-pharmacies par abonnement, utilisateurs et rôles, catalogue, stock par lots, caisse, ordonnances classiques et AMO, créances et bordereaux AMO, remises, commandes fournisseurs (email et Excel), réceptions, dépenses, recettes, clôture de caisse, rapports graphiques, notifications.

**Exclus de la première version** :
- télétransmission électronique des bordereaux AMO (à étudier si la CANAM ou les organismes gestionnaires ouvrent une interface) ;
- paiement de l'abonnement en ligne (l'activation reste manuelle) ;
- comptabilité générale complète (un export des écritures est prévu, pas un logiciel comptable) ;
- application mobile native (l'interface web est responsive).

### 1.4 Cibles

- Principale : officines indépendantes de Bamako et des capitales régionales, de 1 à 10 employés.
- Secondaire : groupes de 2 à 5 officines appartenant au même titulaire, et dépôts pharmaceutiques.

## 2. Acteurs, rôles et droits

La plateforme compte quatre rôles. Un rôle de pharmacien adjoint a été ajouté entre le propriétaire et le vendeur, pour déléguer le stock, les commandes et l'AMO sans donner accès aux paramètres ni aux finances.

| Rôle | Qui | Créé par |
|---|---|---|
| Super admin | Éditeur de la plateforme | Installation |
| Propriétaire | Pharmacien titulaire | Super admin |
| Pharmacien adjoint | Pharmacien ou gestionnaire salarié | Propriétaire |
| Vendeur | Personnel de comptoir | Propriétaire |

### Matrice des droits

O = autorisé, L = lecture seule, — = interdit.

| Fonction | Super admin | Propriétaire | Adjoint | Vendeur |
|---|---|---|---|---|
| Créer une pharmacie et son propriétaire | O | — | — | — |
| Gérer les abonnements | O | L | — | — |
| Paramètres de la pharmacie (taux AMO, plafond de remise…) | — | O | — | — |
| Créer et désactiver des utilisateurs | — | O | — | — |
| Produits, catégories, étagères, fournisseurs | — | O | O | L |
| Clients (création) | — | O | O | O |
| Marquer un client « privilégié » | — | O | O | — |
| Vendre (caisse) | — | O | O | O |
| Remise dans le plafond | — | O | O | O |
| Remise au-delà du plafond | — | O | — | — |
| Annuler une vente ou faire un avoir | — | O | O | — |
| Ajustement de stock et inventaire | — | O | O | — |
| Commandes fournisseurs (brouillon) | — | O | O | O |
| Envoyer une commande, réceptionner | — | O | O | — |
| Bordereaux et règlements AMO | — | O | O | — |
| Dépenses | — | O | — | — |
| Ouvrir et clôturer sa session de caisse | — | O | O | O |
| Rapports financiers | — | O | — | — |
| Rapports stock et ventes | — | O | O | — |
| Journal d'audit | L (plateforme) | L | — | — |

### Règles d'accès

- Le super admin ne voit jamais les ventes, clients ni ordonnances d'une pharmacie.
- Pour le support, il peut se connecter « en tant que » un utilisateur seulement si le propriétaire a activé l'autorisation, pour une durée limitée (24 h). Chaque session de ce type est tracée et visible par le propriétaire.
- Le propriétaire peut ajuster finement les droits de l'adjoint (cases à cocher par fonction).
- Le modèle de données permet à un même propriétaire de détenir plusieurs pharmacies (offre Premium). Il bascule alors de l'une à l'autre depuis le menu.

## 3. Abonnements et cycle de vie d'un compte

L'abonnement est annuel. Il est payé hors plateforme et activé manuellement par le super admin. Trois offres sont proposées ; les tarifs restent à fixer.

### 3.1 Offres

| Fonction | Essentiel | Standard | Premium |
|---|---|---|---|
| Pharmacies | 1 | 1 | Jusqu'à 5 |
| Utilisateurs | 3 | 8 | Illimité |
| Caisse, stock par lots, AMO, remises | Oui | Oui | Oui |
| Commandes fournisseurs (email, Excel) | Oui | Oui | Oui |
| Dépenses, recettes, clôture de caisse | Oui | Oui | Oui |
| Rapports graphiques | De base | Complets | Complets + consolidés |
| Export comptable SYSCOHADA | — | Oui | Oui |
| SMS (rappels de renouvellement aux patients) | — | Option | Inclus (quota) |
| Transferts de stock entre officines | — | — | Oui |
| Tarif annuel (FCFA) | À fixer | À fixer | À fixer |

Les limites (utilisateurs, pharmacies) sont paramétrées par offre dans l'espace super admin, sans modification du code.

### 3.2 Cycle de vie

1. **Création** : le super admin crée la pharmacie, son propriétaire et choisit l'offre. Le propriétaire reçoit un email d'activation pour définir son mot de passe.
2. **Essai** (optionnel, 30 jours par défaut) : accès complet, bandeau « période d'essai ».
3. **Actif** : le super admin enregistre un paiement (date, montant, moyen, référence). L'abonnement court 12 mois. Une prolongation part de la date de fin en cours si l'abonnement est encore actif.
4. **Alerte** : bandeau et email au propriétaire à J-30, J-15 et J-7. Le super admin voit la liste des échéances proches.
5. **Grâce** (7 jours après l'échéance) : la caisse fonctionne encore, un bandeau rouge invite à renouveler.
6. **Expiré** : accès en lecture seule. Le propriétaire peut consulter et exporter ses données, mais ne peut plus vendre ni modifier.
7. **Suspendu** : décision manuelle du super admin (impayé, fraude). Plus aucun accès, page d'information avec les coordonnées de l'éditeur.
8. **Archivé** : 12 mois après l'expiration sans renouvellement, les données sont archivées. Un export complet est envoyé au propriétaire avant archivage.

Chaque paiement génère une facture d'abonnement PDF numérotée, téléchargeable par le propriétaire.

## 4. Exigences fonctionnelles

Chaque exigence porte un identifiant et une priorité : MVP (indispensable au lancement), V1 (3 mois après), V2 (évolution).

### 4.1 Espace super admin

| ID | Exigence | Priorité |
|---|---|---|
| SA-01 | Créer une pharmacie (nom, ville, adresse, téléphone, n° d'autorisation) et son compte propriétaire | MVP |
| SA-02 | Enregistrer un paiement d'abonnement et prolonger l'accès de 12 mois | MVP |
| SA-03 | Suspendre, réactiver ou archiver une pharmacie | MVP |
| SA-04 | Paramétrer les offres (limites, fonctions incluses) | V1 |
| SA-05 | Tableau de bord : pharmacies actives, en essai, expirant sous 30 jours, expirées ; revenus d'abonnement par mois | MVP |
| SA-06 | Générer la facture d'abonnement PDF | MVP |
| SA-07 | Gérer les référentiels communs : organismes d'assurance (AMO et autres assurances), formes galéniques, catégories de dépenses par défaut | MVP |
| SA-08 | Catalogue national de produits partagé (nom, DCI, forme, dosage) que les pharmacies peuvent importer dans leur catalogue | V1 |
| SA-09 | Diffuser une annonce (maintenance, nouveauté) affichée à toutes les pharmacies | V1 |
| SA-10 | Connexion « en tant que » avec autorisation du propriétaire, tracée | V1 |

### 4.2 Paramétrage de la pharmacie et utilisateurs

| ID | Exigence | Priorité |
|---|---|---|
| PH-01 | Fiche pharmacie : nom, logo, adresse, téléphone, email, n° d'autorisation, mentions du ticket | MVP |
| PH-02 | Paramètres : taux de prise en charge par organisme, AMO ou autre assurance (défaut 70 %), plafond de remise (%), délai d'alerte péremption (jours), politique de vente sans ordonnance | MVP |
| PH-03 | Créer, modifier, désactiver des utilisateurs (adjoints, vendeurs) dans la limite de l'offre | MVP |
| PH-04 | Réinitialisation du mot de passe par email ; code PIN à 4 chiffres pour changer rapidement de vendeur à la caisse | MVP |
| PH-05 | Droits fins de l'adjoint (cases à cocher par fonction) | V1 |
| PH-06 | Double authentification (code par application) pour le propriétaire | V1 |

### 4.3 Référentiels

| ID | Exigence | Priorité |
|---|---|---|
| RF-01 | Catégories de produits sur deux niveaux | MVP |
| RF-02 | Étagères : code (ex. E1-R3), libellé, zone (comptoir, réserve, réfrigérateur) | MVP |
| RF-03 | Produits : nom commercial, DCI, forme, dosage, conditionnement, code-barres, catégorie, étagère, fournisseur habituel, prix d'achat, prix de vente, seuil d'alerte, stock maximum, ordonnance obligatoire, remboursable AMO, taux de TVA, actif/inactif | MVP |
| RF-04 | Produits équivalents : regroupement par DCI + dosage + forme pour proposer un générique à la caisse | V1 |
| RF-05 | Fournisseurs : nom, contact, téléphone, email, adresse, délai de livraison habituel, conditions de paiement | MVP |
| RF-06 | Clients : nom, téléphone, privilégié (oui/non), n° d'assuré, organisme (AMO ou autre assurance), entreprise rattachée (clients conventionnés) | MVP |
| RF-07 | Prescripteurs : nom, spécialité, structure de santé (saisie libre réutilisable) | V1 |
| RF-08 | Import Excel/CSV des produits, fournisseurs et clients, avec rapport d'erreurs ligne par ligne | MVP |
| RF-09 | Modification de prix en masse (par catégorie ou fournisseur, en % ou en montant) avec historique | V1 |
| RF-10 | Impression d'étiquettes prix et code-barres | V2 |

### 4.4 Stock et lots

| ID | Exigence | Priorité |
|---|---|---|
| ST-01 | Lots : n° de lot, date de péremption, quantité initiale et restante, prix d'achat, fournisseur, date de réception | MVP |
| ST-02 | Sortie automatique des lots en FEFO (premier périmé, premier sorti) à la vente | MVP |
| ST-03 | Blocage de la vente de tout lot périmé | MVP |
| ST-04 | Toute variation de stock passe par un mouvement typé (réception, vente, retour client, annulation, ajustement, destruction, retour fournisseur, transfert) | MVP |
| ST-05 | Alertes : rupture (sous le seuil), péremption proche, lots périmés en stock, produits dormants (sans vente depuis 90 jours) | MVP |
| ST-06 | Inventaire : complet ou tournant (par étagère ou catégorie), saisie des quantités par lot, génération des écarts validés par le propriétaire ou l'adjoint | MVP |
| ST-07 | Valorisation du stock au prix d'achat des lots | MVP |
| ST-08 | Fiche produit : stock par lot, historique des mouvements, ventes des 12 derniers mois | MVP |
| ST-09 | Retour fournisseur (produits proches de la péremption ou défectueux) avec bon de retour PDF | V1 |
| ST-10 | Procès-verbal de destruction des périmés (PDF) | V1 |
| ST-11 | Transfert de stock entre officines du même propriétaire | V2 |

### 4.5 Caisse et ventes

| ID | Exigence | Priorité |
|---|---|---|
| VE-01 | Écran de caisse : recherche par nom, DCI ou code-barres (douchette), panier, stock et étagère visibles, utilisable au clavier | MVP |
| VE-02 | Trois types de vente : sans ordonnance, ordonnance classique, ordonnance AMO | MVP |
| VE-03 | Contrôle des produits « ordonnance obligatoire » selon la politique de la pharmacie (blocage ou confirmation du propriétaire) | MVP |
| VE-04 | Modes de paiement : espèces (avec monnaie à rendre), Orange Money, Moov Money, carte, crédit client ; paiement mixte | MVP |
| VE-05 | Numérotation séquentielle par pharmacie et par année | MVP |
| VE-06 | Ticket de caisse 80 mm et facture A4 (PDF) | MVP |
| VE-07 | Mise en attente d'une vente et reprise (plusieurs clients au comptoir) | MVP |
| VE-08 | Proposition d'un générique équivalent quand le produit est en rupture | V1 |
| VE-09 | Annulation d'une vente le jour même par le propriétaire ou l'adjoint, avec motif ; après la clôture de caisse, on passe par un avoir | MVP |
| VE-10 | Retour client et avoir : réintégration en stock seulement si l'emballage est intact et le lot non périmé | V1 |
| VE-11 | Vente à crédit pour clients autorisés, avec plafond et suivi des encaissements | V1 |
| VE-12 | Clients conventionnés (entreprises, ONG, mutuelles) : prise en charge à un taux défini, facture mensuelle à l'entreprise | V2 |
| VE-13 | Historique des achats du patient, consultable à la caisse | V1 |
| VE-14 | Mode caisse dégradé hors ligne (enregistrement local, synchronisation au retour du réseau) | V2 |

### 4.6 Ordonnances et AMO

| ID | Exigence | Priorité |
|---|---|---|
| AM-01 | Saisie de l'ordonnance : numéro, date, prescripteur, structure, photo ou scan | MVP |
| AM-02 | Vente AMO : client assuré obligatoire (n° d'assuré, organisme) | MVP |
| AM-03 | Calcul automatique de la part AMO et de la part assuré, affiché à la caisse et sur le ticket | MVP |
| AM-04 | Produits non remboursables payés à 100 % par l'assuré, distingués sur le ticket | MVP |
| AM-12 | Autres assurances que l'AMO (assurance privée, ONG, mutuelle d'entreprise) : même parcours que l'AMO (vente, créance, bordereau, règlement), avec leur propre taux | MVP |
| AM-13 | Prix de vente AMO par médicament, distinct du prix de la pharmacie : la part d'un organisme AMO se calcule sur ce prix | MVP |
| AM-05 | Chaque vente AMO crée une créance « en attente » sur l'organisme | MVP |
| AM-06 | Bordereau : regroupement des créances par organisme et par période, statuts brouillon, transmis, payé partiellement, payé, rejeté | MVP |
| AM-07 | Export du bordereau en Excel et en PDF, avec copies des ordonnances en annexe (PDF unique) | MVP |
| AM-08 | Règlement : montant reçu, date, référence ; affectation créance par créance ; motif des rejets | MVP |
| AM-09 | Traitement d'une créance rejetée : refacturation à l'assuré, nouvelle soumission ou passage en perte | V1 |
| AM-10 | Tableau de suivi : encours par organisme, ancienneté (0-30, 31-60, 61-90, plus de 90 jours), taux de rejet | MVP |
| AM-11 | Rappel automatique quand un bordereau transmis n'est pas payé après un délai paramétrable | V1 |

### 4.7 Remises et fidélité

| ID | Exigence | Priorité |
|---|---|---|
| RE-01 | Remise disponible uniquement pour un client marqué « privilégié » | MVP |
| RE-02 | Remise saisie librement à la vente, en % ou en montant, par ligne ou sur le total | MVP |
| RE-03 | Plafond fixé par le propriétaire ; au-delà, validation par le code PIN du propriétaire | MVP |
| RE-04 | Traçabilité : vendeur, client, montant, % et vente concernée | MVP |
| RE-05 | Rapport des remises par période, par vendeur et par client | MVP |
| RE-06 | Programme de points (1 point par tranche d'achat, points convertibles en bon d'achat) | V2 |

### 4.8 Commandes et réceptions

| ID | Exigence | Priorité |
|---|---|---|
| CO-01 | Création d'une commande par fournisseur : produits, quantités, prix d'achat estimés | MVP |
| CO-02 | Suggestion automatique : produits sous le seuil, quantité proposée = stock maximum − stock actuel, filtrés par fournisseur habituel | MVP |
| CO-03 | Statuts : brouillon, envoyée, reçue partiellement, reçue, annulée | MVP |
| CO-04 | Export Excel mis en forme (en-tête pharmacie et fournisseur, numéro, date, lignes, total) prêt à imprimer | MVP |
| CO-05 | Envoi par email au fournisseur avec l'Excel en pièce jointe, et historique des envois | MVP |
| CO-06 | Réception : quantités reçues, n° de lot, péremption, prix réel ; création des lots ; réception partielle | MVP |
| CO-07 | Écart de prix signalé quand le prix réel diffère du prix estimé ; mise à jour optionnelle du prix d'achat | V1 |
| CO-08 | Saisie de la facture fournisseur, échéance de paiement et suivi des dettes fournisseurs | V1 |
| CO-09 | Comparaison des prix d'achat d'un produit entre fournisseurs | V2 |

### 4.9 Dépenses, recettes et trésorerie

| ID | Exigence | Priorité |
|---|---|---|
| FI-01 | Dépenses : date, catégorie, libellé, montant, mode de paiement, bénéficiaire, justificatif (photo ou PDF) | MVP |
| FI-02 | Catégories de dépenses paramétrables (loyer, salaires, électricité, eau, transport, achats de marchandises, impôts et taxes, divers) | MVP |
| FI-03 | Dépenses récurrentes (loyer, salaires) générées chaque mois à valider | V1 |
| FI-04 | Recettes automatiques : chaque vente (montant encaissé) et chaque règlement AMO ; contre-passation en cas d'annulation | MVP |
| FI-05 | Recettes manuelles (autres produits), réservées au propriétaire | MVP |
| FI-06 | Session de caisse : ouverture avec fond de caisse, clôture avec comptage des espèces par coupure, calcul de l'écart, justification obligatoire si écart | MVP |
| FI-07 | Rapport Z de clôture (PDF) : ventes par mode de paiement, remises, annulations, écart | MVP |
| FI-08 | Soldes par moyen de trésorerie (caisse, Orange Money, Moov Money, banque) et transferts entre eux (ex. dépôt en banque) | V1 |
| FI-09 | Export des écritures au format journal SYSCOHADA (ventes, achats, trésorerie) en Excel, avec table de correspondance des comptes paramétrable | V1 |

### 4.10 Rapports et tableaux de bord

| ID | Exigence | Priorité |
|---|---|---|
| RA-01 | Filtres communs : jour, semaine, mois, année, plage libre ; comparaison avec la période précédente | MVP |
| RA-02 | Indicateurs : CA du jour et du mois, nombre de ventes, panier moyen, dépenses, résultat (recettes − dépenses), encours AMO, valeur du stock | MVP |
| RA-03 | Graphique recettes et dépenses par mois, avec la courbe du solde | MVP |
| RA-04 | Graphique des dépenses par catégorie | MVP |
| RA-05 | Ventes par type (sans ordonnance, classique, AMO) et par mode de paiement | MVP |
| RA-06 | Top 10 des produits en quantité et en CA ; ventes par catégorie | MVP |
| RA-07 | Marge brute par produit et par catégorie (prix de vente − prix d'achat du lot consommé) | V1 |
| RA-08 | Performance par vendeur : ventes, panier moyen, remises accordées, annulations | V1 |
| RA-09 | Évolution de l'encours AMO et taux de rejet | V1 |
| RA-10 | Rapport de stock : valeur, rotation, dormants, péremptions à venir en valeur | V1 |
| RA-11 | Export Excel et PDF de chaque rapport | MVP |
| RA-12 | Rapport mensuel PDF envoyé automatiquement au propriétaire le 1er du mois | V1 |
| RA-13 | Vue consolidée multi-officines | V2 |

### 4.11 Notifications

| ID | Exigence | Priorité |
|---|---|---|
| NO-01 | Centre de notifications dans l'application (cloche) : ruptures, péremptions, échéances d'abonnement, bordereaux impayés | MVP |
| NO-02 | Emails : activation de compte, mot de passe, échéance d'abonnement, envoi des commandes, rapport mensuel | MVP |
| NO-03 | Résumé quotidien par email au propriétaire (CA du jour, écarts de caisse, alertes) | V1 |
| NO-04 | SMS aux patients pour le renouvellement d'un traitement chronique (avec consentement du patient) | V2 |

### 4.12 Journal d'audit

| ID | Exigence | Priorité |
|---|---|---|
| AU-01 | Trace horodatée des actions sensibles : annulation, avoir, remise hors plafond, ajustement de stock, modification de prix, écart de caisse, désactivation d'utilisateur, paiement d'abonnement, connexion « en tant que » | MVP |
| AU-02 | Consultation filtrable par le propriétaire (utilisateur, action, période) ; les entrées ne sont ni modifiables ni supprimables | MVP |

## 5. Règles de gestion

Ces règles s'appliquent partout dans l'application ; chaque règle de calcul fait l'objet d'un test automatisé.

| ID | Règle |
|---|---|
| RG-01 | Tous les montants sont en FCFA entiers. Les arrondis se font au franc le plus proche (0,5 arrondi au supérieur). |
| RG-02 | Les numéros sont séquentiels, sans trou, par pharmacie et par année : ventes V-2026-000001, avoirs AV-, commandes CMD-, bordereaux BRD-, dépenses DEP-, sessions de caisse SC-. |
| RG-03 | Le stock d'un produit = somme des quantités restantes de ses lots non périmés. Il ne peut jamais être négatif. |
| RG-04 | À la vente, les lots sortent dans l'ordre de leur date de péremption (FEFO). Une ligne peut consommer plusieurs lots. |
| RG-05 | Un lot dont la date de péremption est atteinte ne peut plus être vendu. Il ne peut sortir que par destruction ou retour fournisseur. |
| RG-06 | Prix, taux AMO et taux de remise sont figés sur la vente au moment où elle est validée. Un changement ultérieur de paramètre ne modifie pas les ventes passées. |
| RG-07 | Vente AMO ou assurance : base = total des lignes remboursables, au prix de vente AMO du médicament pour un organisme AMO (prix de la pharmacie si le médicament n'a pas de prix AMO), au prix de la pharmacie pour une autre assurance ; part de l'organisme = arrondi(base × taux de l'organisme), sans dépasser le prix facturé des lignes remboursables ; part assuré = total − part de l'organisme. |
| RG-08 | Remise : possible seulement si un client privilégié est sélectionné. Au-delà du plafond, le code PIN du propriétaire est exigé. |
| RG-09 | Sur une vente AMO, la remise ne s'applique qu'à la part assuré. La part AMO est toujours calculée sans remise (sur le prix AMO ou le prix plein, selon RG-07). |
| RG-10 | Recette automatique = montant réellement encaissé (après remise, part assuré seulement pour l'AMO). La part AMO devient une créance, puis une recette à son règlement. |
| RG-11 | Une créance AMO n'appartient qu'à un seul bordereau à la fois. Un bordereau transmis n'est plus modifiable. |
| RG-12 | Annulation : possible le jour même, avant la clôture de la session de caisse. Ensuite, seul un avoir est possible. Les deux remettent les produits dans leurs lots d'origine et contre-passent la recette. |
| RG-13 | Clôture de caisse : écart = espèces comptées − (fond de caisse + encaissements espèces − décaissements espèces). Un écart non nul exige une justification. |
| RG-14 | Abonnement : nouvelle date de fin = plus tardive des deux dates (aujourd'hui, date de fin en cours) + 12 mois. |
| RG-15 | Une donnée déjà utilisée (produit vendu, client, fournisseur) ne se supprime pas : elle est archivée et disparaît des listes de sélection. |
| RG-16 | Réception : une quantité reçue supérieure à la quantité commandée est acceptée avec un avertissement. Chaque ligne reçue doit avoir un n° de lot et une date de péremption. |
| RG-17 | Marge d'une ligne vendue = prix de vente net − prix d'achat du ou des lots consommés. |

### Exemple de calcul AMO avec remise

Ordonnance AMO de 20 000 FCFA, dont 18 000 remboursables et 2 000 non remboursables ; taux AMO 70 % ; client privilégié avec 10 % de remise.

| Étape | Calcul | Montant (FCFA) |
|---|---|---|
| Part AMO | 18 000 × 70 % | 12 600 |
| Part assuré avant remise | 20 000 − 12 600 | 7 400 |
| Remise | 7 400 × 10 % | 740 |
| Encaissé auprès de l'assuré (recette immédiate) | 7 400 − 740 | 6 660 |
| Créance sur l'organisme (recette au règlement) | | 12 600 |

Seule la part assuré entre en recette le jour de la vente ; la part AMO reste une créance jusqu'au règlement, ou au rejet.

Avec un prix de vente AMO : boîte vendue 2 400 FCFA par la pharmacie, prix AMO 2 100 FCFA, taux 70 %. Part AMO = 2 100 × 70 % = 1 470 ; l'assuré paie 2 400 − 1 470 = 930 (la différence de prix reste à sa charge). Pour une autre assurance à 80 %, sans prix propre : part assurance = 2 400 × 80 % = 1 920, l'assuré paie 480.

## 6. Exigences non fonctionnelles

La plateforme doit rester rapide sur une connexion 3G et ne jamais exposer les données d'une pharmacie à une autre.

| Domaine | Exigence |
|---|---|
| Isolation des données | Chaque donnée métier est rattachée à sa pharmacie. Filtrage automatique sur toutes les requêtes + contrôle des droits sur chaque ressource. Accès à la donnée d'une autre pharmacie = page introuvable (404). |
| Performance | Page affichée en moins de 2 s sur 3G. Recherche produit à la caisse en moins de 300 ms sur un catalogue de 10 000 produits. Listes paginées côté serveur. |
| Volumétrie | Dimensionnement initial : 500 pharmacies, 10 utilisateurs et 300 ventes par jour chacune. |
| Disponibilité | 99,5 % par mois. Maintenances annoncées 48 h à l'avance, hors horaires d'ouverture (après 22 h). |
| Sauvegardes | Base sauvegardée chaque nuit, chiffrée, conservée 30 jours, copie hors du serveur principal. Restauration testée chaque mois. |
| Réversibilité | Le propriétaire peut exporter toutes ses données (Excel + fichiers) à tout moment, y compris en lecture seule. |
| Sécurité | HTTPS obligatoire. Mots de passe hachés. Limitation des tentatives de connexion. Protection CSRF. Expiration de session après 30 min d'inactivité (paramétrable pour la caisse). Fichiers stockés hors du dossier public, servis après contrôle des droits. Revue selon l'OWASP Top 10 avant mise en production. |
| Données personnelles et de santé | Les ordonnances et l'historique patient sont des données de santé : accès limité aux rôles qui en ont besoin, scans chiffrés au repos. Conformité à la loi malienne n° 2013-015 sur la protection des données personnelles et déclaration auprès de l'APDP (à faire valider par un juriste). |
| Traçabilité | Journal d'audit non modifiable. Horodatage en heure de Bamako (GMT). |
| Ergonomie | Interface en français, responsive (ordinateur, tablette), écran de caisse utilisable au clavier, impression ticket 80 mm. Montants affichés au format 12 500 FCFA. |
| Compatibilité | Chrome, Firefox et Edge dans leurs deux dernières versions ; tablettes Android 10 et plus. |
| Maintenabilité | Code analysé statiquement, tests automatisés couvrant au moins 80 % de la logique métier, intégration continue à chaque modification. |

## 7. Architecture et stack technique

L'application est un monolithe Symfony découpé en modules, avec une base MySQL unique partagée entre toutes les pharmacies.

### 7.1 Stack

| Couche | Choix |
|---|---|
| Langage et framework | PHP 8.3+, Symfony 7.4 LTS |
| Environnement de développement | DDEV (PHP-FPM, Nginx, MySQL 8, Mailpit) |
| Base de données | MySQL 8, Doctrine ORM, Doctrine Migrations |
| Interface | Twig, Bootstrap 5.3, Bootstrap Icons, AssetMapper (sans Node), Stimulus et Turbo |
| Graphiques | Chart.js |
| Excel | PhpSpreadsheet |
| PDF | Dompdf (tickets, factures, bordereaux, rapport Z) |
| Emails | Symfony Mailer, envoi asynchrone via Messenger |
| Tâches planifiées | Symfony Scheduler (alertes, échéances, rapport mensuel) |
| Tests et qualité | PHPUnit, Zenstruck Foundry, PHPStan niveau 6, PHP-CS-Fixer |
| Production | Serveur Linux, Nginx + PHP-FPM, MySQL, worker Messenger supervisé, certificat HTTPS, sauvegardes automatisées |

### 7.2 Modules applicatifs

Chaque module regroupe ses entités, services, contrôleurs et templates. Les contrôleurs restent minces ; toute la logique métier est dans des services testés.

| Module | Contenu | Services clés |
|---|---|---|
| Plateforme | Pharmacies, offres, abonnements, utilisateurs, audit | AbonnementService, TenantContext |
| Catalogue | Produits, catégories, étagères, fournisseurs, clients | ImportService |
| Stock | Lots, mouvements, inventaires, alertes | StockService (FEFO) |
| Vente | Caisse, ventes, avoirs, ordonnances, remises, sessions de caisse | VenteService, RemiseService |
| AMO | Créances, bordereaux, règlements | CalculAmoService, BordereauService |
| Achats | Commandes, réceptions, envois | CommandeService, ExportCommandeExcel |
| Finance | Dépenses, recettes, trésorerie, export comptable | RecetteService, ExportSyscohada |
| Reporting | Tableaux de bord, rapports, exports | ReportingService |

### 7.3 Multi-tenant

- Une seule base. Chaque entité métier porte une clé vers sa pharmacie et implémente une interface commune.
- Un filtre Doctrine ajoute automatiquement la condition « pharmacie = pharmacie de l'utilisateur » à chaque requête. Il n'est désactivé que dans l'espace super admin.
- La pharmacie est affectée automatiquement à la création d'un enregistrement ; aucun formulaire ne permet de la choisir.
- Des Voters contrôlent en plus chaque accès à une ressource.
- Les fichiers (justificatifs, ordonnances, logos) sont rangés par pharmacie et servis par un contrôleur qui vérifie les droits.

### 7.4 Environnements

| Environnement | Usage |
|---|---|
| Local (DDEV) | Développement, données de démonstration |
| Recette | Validation des lots par le porteur du projet, données fictives |
| Production | Pharmacies clientes, sauvegardes et supervision |

## 8. Modèle de données

Le modèle compte une trentaine d'entités. Toutes celles marquées « oui » dans la colonne Tenant portent la clé de leur pharmacie et sont filtrées automatiquement.

| Entité | Champs principaux | Liée à | Tenant |
|---|---|---|---|
| Offre | nom, max utilisateurs, max pharmacies, fonctions incluses, tarif | Abonnement | non |
| Pharmacie | nom, adresse, ville, téléphone, email, logo, n° d'autorisation, statut | Propriétaire, Offre | — |
| Abonnement | date début, date fin, montant, moyen, référence, statut, enregistré par | Pharmacie, Offre | non |
| Utilisateur | nom, email, mot de passe, PIN, rôles, actif, dernière connexion | Pharmacie(s) | oui |
| ParametrePharmacie | plafond remise, délai péremption, politique sans ordonnance, mentions ticket | Pharmacie | oui |
| OrganismeAmo | nom, code, nature (AMO ou autre assurance) | TauxAmo | non |
| TauxAmo | taux, date d'effet | Pharmacie, OrganismeAmo | oui |
| Categorie | nom, parent | Produit | oui |
| Etagere | code, libellé, zone | Produit | oui |
| Fournisseur | nom, contact, téléphone, email, délai, conditions | Produit, Commande, Lot | oui |
| Produit | nom, DCI, forme, dosage, conditionnement, code-barres, prix achat, prix vente, seuil, stock max, ordonnance obligatoire, remboursable AMO, TVA, actif | Categorie, Etagere, Fournisseur | oui |
| Lot | n° lot, péremption, quantité initiale, quantité restante, prix achat, date réception | Produit, Fournisseur, LigneReception | oui |
| MouvementStock | type, quantité, date, motif | Lot, Utilisateur, document d'origine | oui |
| Inventaire / LigneInventaire | date, périmètre, statut ; quantité théorique, comptée, écart | Lot | oui |
| Client | nom, téléphone, privilégié, n° assuré, plafond crédit, solde | OrganismeAmo, Entreprise | oui |
| Ordonnance | type, numéro, date, prescripteur, structure, fichier | Vente, Client | oui |
| SessionCaisse | ouverture, fermeture, fond, espèces comptées, écart, justification | Utilisateur | oui |
| Vente | numéro, date, type, total brut, remise, total net, part AMO, part assuré, taux AMO figé, statut | Client, Utilisateur, SessionCaisse, Ordonnance | oui |
| LigneVente | quantité, prix unitaire, remise, remboursable, montant | Vente, Produit | oui |
| LigneVenteLot | quantité prélevée, prix achat du lot | LigneVente, Lot | oui |
| Paiement | mode, montant, référence | Vente | oui |
| Avoir / LigneAvoir | numéro, motif, montant ; quantité retournée, réintégrée ou non | Vente | oui |
| CreanceAmo | montant, statut, motif de rejet | Vente, Bordereau, OrganismeAmo | oui |
| BordereauAmo | numéro, période, organisme, montant, statut, date transmission | CreanceAmo | oui |
| ReglementAmo | date, montant, référence | BordereauAmo | oui |
| Commande / LigneCommande | numéro, statut, date ; quantité, prix estimé | Fournisseur, Produit | oui |
| EnvoiCommande | date, destinataire, statut | Commande | oui |
| Reception / LigneReception | date, n° bon de livraison ; quantité reçue, lot, péremption, prix réel | Commande, Lot | oui |
| CategorieDepense | nom | Depense | oui |
| Depense | numéro, date, libellé, montant, mode, bénéficiaire, justificatif | CategorieDepense, Utilisateur | oui |
| Recette | date, origine (vente, AMO, manuelle, contre-passation), montant, mode | Vente ou ReglementAmo | oui |
| Notification | type, message, lu, lien | Utilisateur | oui |
| JournalAudit | date, action, entité, valeurs avant et après, adresse IP | Utilisateur | oui |

## 9. Planning de réalisation

Le MVP est estimé à environ 14 semaines pour un développeur assisté d'un agent de code. Ces durées sont indicatives et se recalent à la fin de chaque lot. Chaque lot se termine par une démonstration et une validation en environnement de recette.

### 9.1 MVP (environ 14 semaines)

1. **Lot 0 — Socle** (1 semaine) : projet sous DDEV, mise en page Bootstrap, menus selon le rôle, outils de qualité, intégration continue.
2. **Lot 1 — Plateforme** (2 semaines) : pharmacies, utilisateurs, rôles, isolation multi-tenant, offres et abonnements, espace super admin, tests d'isolation.
3. **Lot 2 — Référentiels** (1,5 semaine) : paramètres, catégories, étagères, fournisseurs, produits, clients, imports Excel.
4. **Lot 3 — Stock** (2 semaines) : lots, mouvements, FEFO, alertes, inventaire.
5. **Lot 4 — Caisse** (2,5 semaines) : écran de caisse, types de vente, paiements, remises, tickets, sessions et clôture de caisse, annulations.
6. **Lot 5 — AMO** (1,5 semaine) : calcul, créances, bordereaux, exports, règlements.
7. **Lot 6 — Commandes** (1 semaine) : création, suggestion, export Excel, envoi par email, réception.
8. **Lot 7 — Finances et rapports** (1,5 semaine) : dépenses, recettes automatiques, tableau de bord et graphiques, exports.
9. **Lot 8 — Finition** (1 semaine) : journal d'audit, notifications, données de démonstration, revue de sécurité, mise en production.

### 9.2 Pilote (4 semaines)

Déploiement dans 2 ou 3 officines volontaires de Bamako, formation sur place, correction des irritants, puis ouverture commerciale.

### 9.3 V1 (environ 3 mois après le lancement)

Génériques équivalents, avoirs et retours clients, vente à crédit, historique patient, droits fins de l'adjoint, double authentification, factures et dettes fournisseurs, dépenses récurrentes, trésorerie par moyen de paiement, export comptable SYSCOHADA, marge et performance par vendeur, rapport mensuel automatique, catalogue national partagé.

### 9.4 V2

Multi-officines et transferts de stock, vue consolidée, clients conventionnés, programme de points, SMS aux patients, mode caisse hors ligne, étiquettes code-barres, comparaison des prix fournisseurs, télétransmission AMO si une interface devient disponible.

## 10. Critères de recette et livrables

Le MVP est accepté quand les 12 scénarios ci-dessous passent en recette, sans anomalie bloquante, et que les tests automatisés sont tous verts.

### 10.1 Scénarios de recette

| ID | Scénario | Résultat attendu |
|---|---|---|
| R-01 | Un vendeur de la pharmacie A saisit l'URL d'une vente de la pharmacie B | Page introuvable, aucune donnée affichée |
| R-02 | Abonnement expiré depuis 8 jours | Lecture seule ; caisse bloquée ; export possible |
| R-03 | Vente d'un produit dont seul un lot périmé reste en stock | Vente refusée, message explicite |
| R-04 | Vente de 15 unités, lots A (péremption 03/2027, 10 u.) et B (péremption 01/2027, 10 u.) | 10 u. sortent du lot B, 5 u. du lot A |
| R-05 | Vente AMO de l'exemple du § 5 | Part AMO 12 600, encaissé 6 660, créance 12 600, recette 6 660 |
| R-06 | Vendeur qui saisit 25 % de remise avec un plafond à 10 % | PIN du propriétaire demandé ; remise tracée |
| R-07 | Remise sur un client non privilégié | Champ remise indisponible |
| R-08 | Annulation d'une vente le jour même | Stock remis dans les lots d'origine, recette contre-passée, audit renseigné |
| R-09 | Commande générée depuis les suggestions puis envoyée | Email reçu par le fournisseur avec l'Excel joint ; statut « envoyée » |
| R-10 | Réception partielle d'une commande | Lots créés, stock mis à jour, statut « reçue partiellement » |
| R-11 | Bordereau de 10 créances, règlement de 9, 1 rejet | Bordereau « payé partiellement », recette du montant réglé, créance rejetée identifiée |
| R-12 | Clôture de caisse avec 500 FCFA manquants | Écart affiché, justification obligatoire, rapport Z généré |

### 10.2 Livrables

- [ ] Code source versionné (dépôt Git) avec historique par lot
- [ ] Migrations de base de données et données de démonstration
- [ ] Tests automatisés et rapport de couverture
- [ ] README d'installation (DDEV) et guide de mise en production
- [ ] Guide utilisateur illustré : propriétaire, vendeur, super admin
- [ ] Modèles de documents : ticket, facture, bordereau AMO, bon de commande Excel, rapport Z
- [ ] Liste des hypothèses et décisions prises (fichier tenu à jour)
- [ ] Environnement de recette accessible en ligne

## 11. Hypothèses, points ouverts et glossaire

### 11.1 Hypothèses retenues

- Le taux AMO est paramétrable par organisme (70 % par défaut), et historisé par date d'effet.
- Une autre assurance (privée, ONG, mutuelle) suit le même parcours que l'AMO, avec son taux ; seul l'AMO applique son prix de vente par médicament.
- Sur une vente AMO, la remise ne porte que sur la part assuré.
- La part AMO est une créance ; elle devient une recette au règlement du bordereau.
- L'abonnement est payé hors plateforme et activé manuellement.
- Le nom « PharmaGest » est un nom de travail.

### 11.2 Points ouverts

- [ ] Tarifs annuels des trois offres et durée de l'essai gratuit
- [ ] Format de bordereau exigé par chaque organisme AMO (colonnes, pièces jointes, fréquence)
- [ ] Existence de taux AMO différents selon le type de prestation ou de produit
- [ ] Liste officielle des médicaments remboursables : disponible en fichier importable ?
- [ ] TVA : médicaments exonérés et parapharmacie taxée à 18 % — à confirmer avec un fiscaliste avant de figer le paramétrage
- [ ] Prix publics réglementés : faut-il bloquer la modification du prix de vente de certains produits ?
- [ ] Politique par défaut pour la vente sans ordonnance d'un produit soumis à prescription (blocage ou confirmation)
- [ ] Hébergement : localisation du serveur et prestataire
- [ ] Démarches auprès de l'APDP pour les données de santé

### 11.3 Glossaire

| Terme | Définition |
|---|---|
| AMO | Assurance maladie obligatoire |
| CANAM | Caisse nationale d'assurance maladie, qui gère l'AMO |
| INPS, CMSS | Organismes gestionnaires délégués de l'AMO (salariés du privé, fonctionnaires) |
| APDP | Autorité de protection des données à caractère personnel du Mali |
| Bordereau | Relevé des ordonnances AMO envoyé à un organisme pour remboursement |
| Créance AMO | Part AMO d'une vente, due par l'organisme à la pharmacie |
| Prix de vente AMO | Prix d'un médicament fixé par l'AMO ; le taux AMO s'applique sur ce prix |
| Tiers payant | Organisme (AMO ou autre assurance) qui paie une partie de la vente à la place du client |
| DCI | Dénomination commune internationale (nom de la molécule) |
| FEFO | Premier périmé, premier sorti |
| Lot | Ensemble d'unités d'un produit fabriquées ensemble, avec un numéro et une date de péremption |
| PPM | Pharmacie populaire du Mali |
| Rapport Z | Synthèse de clôture d'une session de caisse |
| SYSCOHADA | Référentiel comptable de l'espace OHADA |
| Tenant | Pharmacie cliente, dont les données sont isolées des autres |
