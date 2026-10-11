# SoftPharm face à WinPharma : comparatif et argumentaire commercial

11 oct. 2026 · @Modibo

> Version de travail partagée : [document en ligne](https://claude.ai/code/artifact/0249fb07-806d-4087-a250-16e3a3555e6f).
> Ce fichier en est la copie dans le dépôt, à mettre à jour en même temps.

## Résumé

WinPharma est le logiciel dominant à Bamako (77 % des officines dans une étude de 93 officines), mais la satisfaction des pharmaciens envers leurs logiciels reste faible (25 à 37 % en moyenne). C'est l'ouverture pour SoftPharm.

SoftPharm gagne sur ce qui est propre au Mali : AMO et autres assurances avec prix AMO par médicament, suivi des créances et bordereaux, mobile money (Orange Money, Moov Money, Wave), FCFA, accès en ligne depuis n'importe quel poste ou téléphone, multi-officines, abonnement annuel sans serveur à acheter.

SoftPharm perd aujourd'hui sur trois points qu'un pharmacien remarquera vite : pas de mode hors ligne, pas de lecture code-barres, pas de transmission directe des commandes au grossiste (Laborex). Il est aussi nouveau, sans références en officine.

Message clé pour le pharmacien malien : « SoftPharm est fait pour l'officine malienne : votre créance AMO sous contrôle, votre caisse mobile money juste, et vos pharmacies visibles depuis votre téléphone, sans serveur à entretenir. »

## Sources et méthode

Les informations sur SoftPharm viennent du dépôt (branche main du 11/10/2026 : cahier des charges, hypothèses, code). Celles sur WinPharma viennent de recherches web : l'accès direct aux sites a été bloqué depuis l'environnement de rédaction, seuls les extraits renvoyés par le moteur de recherche ont été lus. Chaque affirmation est marquée :

- **Vérifié** : lu dans le code de SoftPharm, ou dans au moins une source publique citée en fin de document.
- **Supposé** : déduit, ou propre à la version française de WinPharma sans preuve qu'il en va de même au Mali. À confirmer auprès de pharmaciens utilisateurs avant de l'utiliser en rendez-vous.

Point d'attention : le WinPharma installé au Mali n'est peut-être pas identique à la version française d'Everys (pas de SESAM-Vitale ni de Dossier Pharmaceutique au Mali). Avant toute démarche, voir 2 ou 3 officines équipées pour noter la version, le distributeur local et le prix réellement payé.

## WinPharma en bref

| Point | Ce qu'on sait | Statut |
| --- | --- | --- |
| Éditeur | Everys, Arzon (Morbihan, France), éditeur indépendant fondé en 1993 | Vérifié |
| Parc en France | Plus de 7 800 officines, 98 % de satisfaction annoncée (chiffres de l'éditeur) | Vérifié (source éditeur) |
| Technologie | Logiciel Windows installé sur les postes de l'officine (client lourd), fonctionne hors ligne ; une offre cloud est aussi mentionnée | Vérifié, sources divergentes |
| Modèle commercial | Contrat annuel reconductible, matériel facturé à part, modules en option, tarifs non publiés | Vérifié (France) |
| Fonctions France | Délivrance, tiers payant SESAM-Vitale, dossier patient, interactions médicamenteuses, stock et périmés, commandes automatiques (winAutopilote), robotique, application patient | Vérifié (France) |
| Présence au Mali | 77,4 % des 93 officines de Bamako étudiées l'utilisent ; seul logiciel avec télétransmission des commandes au grossiste Laborex | Vérifié (étude 2019) |
| Satisfaction au Mali | Satisfaction moyenne des logiciels : 25 % (94 officines) à 37 % (93 officines) ; WinPharma environ deux fois la moyenne ; fonctions préférées : approvisionnement et clôture de caisse | Vérifié (études maliennes) |
| Support au Mali | Distributeur local non identifié (une page « Winpharma Gabon » existe) | Supposé |
| Prix au Mali | Inconnu : licence ou contrat annuel, plus PC et serveur | Supposé |

Autres logiciels présents à Bamako d'après leurs éditeurs : Meditect, Phénix (AS PHARM), FlexPharm (iSamaTech), KiboERP. Ils sont à surveiller, car ils visent le même client que SoftPharm.

## SoftPharm en bref

SoftPharm est un logiciel en ligne (SaaS), utilisé depuis un navigateur, vendu par abonnement annuel en trois offres (Essentiel, Standard, Premium ; tarifs encore à fixer). Tout ce qui suit est en place dans la version actuelle (vérifié dans le code).

- **Caisse** : rôles vendeur et caissier (le vendeur envoie la vente à la caisse ou encaisse lui-même), ticket 80 mm et facture A4, espèces, Orange Money, Moov Money, Wave et carte, clôture avec rapport Z et écart justifié.
- **AMO et assurances** : INPS, CANAM et autres assurances (ONG, entreprises) avec taux par organisme, prix AMO par médicament, créances, bordereaux Excel et PDF avec ordonnances en annexe, suivi des règlements.
- **Stock** : lots et dates de péremption, sortie automatique du lot qui périme en premier, blocage de la vente d'un lot périmé, alertes de seuil, inventaires, ajustements et destructions.
- **Achats** : suggestions de commande, bon de commande Excel et PDF envoyé par email, réceptions partielles.
- **Multi-officines** : un propriétaire passe d'une pharmacie à l'autre et transfère du stock entre elles.
- **Pilotage** : dépenses avec justificatif, recettes automatiques, tableau de bord, rapports ventes, remises et finances exportés en Excel et PDF.
- **Contrôle** : remises plafonnées avec code PIN du propriétaire, journal d'audit, notifications, export complet des données à tout moment.
- **Prise en main** : import Excel des produits, fournisseurs et clients, guide d'utilisation par rôle.

Pas encore disponible : mode hors ligne, lecture code-barres (retirée le 08/10/2026), vente à crédit, avoirs et retours, historique patient, export SYSCOHADA, SMS, base d'interactions médicamenteuses, transmission directe au grossiste.

## Comparatif fonction par fonction

SoftPharm prend l'avantage sur 9 critères sur 16, WinPharma sur 6, et un critère est à égalité. Ce qui est noté « supposé » pour WinPharma doit être confirmé sur le terrain.

| Critère | SoftPharm | WinPharma (Mali) | Avantage |
| --- | --- | --- | --- |
| Installation | Navigateur, rien à installer, mises à jour automatiques | Logiciel Windows sur chaque poste, serveur local | SoftPharm |
| Accès à distance | Depuis n'importe où, téléphone compris | Sur place, sauf accès distant installé en plus (supposé) | SoftPharm |
| Sauvegarde | Serveur centralisé, export complet à tout moment | Locale, dépend de l'officine (supposé) | SoftPharm |
| Coupure internet | Caisse bloquée | Continue de fonctionner | WinPharma |
| Coupure de courant | Reprise sur un téléphone ou un PC portable | Bloqué si le serveur s'arrête (supposé) | SoftPharm |
| AMO INPS / CANAM | Taux par organisme, prix AMO par médicament, créances, bordereaux, règlements | Gestion du tiers payant adaptée au Mali non documentée (supposé) | SoftPharm |
| Autres assurances | ONG et entreprises, taux propres | Non documenté | SoftPharm |
| Mobile money | Orange Money, Moov Money, Wave distingués à la caisse et dans la clôture | Non documenté | SoftPharm |
| Péremptions | Lots, sortie du lot le plus proche, vente d'un périmé bloquée | Gestion des périmés | Égalité |
| Commandes grossiste | Bon Excel ou PDF par email | Télétransmission vers Laborex | WinPharma |
| Code-barres | Non | Oui (France) | WinPharma |
| Interactions et dossier patient | Non | Oui (France), usage au Mali inconnu | WinPharma |
| Multi-officines | Bascule et transferts de stock intégrés | Non documenté au Mali | SoftPharm |
| Contrôle des remises et audit | Plafond, PIN du propriétaire, journal d'audit | Non documenté | SoftPharm |
| Ancienneté et références | Nouveau, pilote à venir | Depuis 1993, 77 % des officines de Bamako | WinPharma |
| Formation et support local | À construire | Réseau en place, habitudes acquises (supposé) | WinPharma |

Le coût n'apparaît pas dans le tableau, car aucun des deux prix n'est connu aujourd'hui. C'est le premier chiffre à obtenir (voir la dernière section).

## Forces de SoftPharm

La force principale de SoftPharm est d'avoir été conçu pour l'officine malienne, là où WinPharma est un logiciel français adapté après coup.

1. **La créance AMO sous contrôle.** Chaque vente AMO crée une créance ; les bordereaux se génèrent avec les ordonnances en annexe, et les impayés ou rejets sont visibles. C'est souvent plusieurs millions de FCFA de trésorerie bloquée par officine (ordre de grandeur supposé).
2. **Le prix AMO par médicament et les autres assurances.** Le calcul part du bon prix et du bon taux, sans calculette au comptoir.
3. **Une caisse qui colle à la réalité des paiements.** Orange Money, Moov Money et Wave sont séparés des espèces, donc la clôture tombe juste et les écarts se voient.
4. **Le propriétaire voit tout, de partout.** Ventes, caisse, stock et remises depuis son téléphone, même en déplacement ; plusieurs officines dans un seul compte, avec transferts de stock.
5. **Moins de pertes.** Vente d'un lot périmé impossible, remises plafonnées avec code PIN, journal d'audit de chaque action sensible.
6. **Pas de serveur, pas de technicien.** Rien à installer, sauvegarde centralisée, mises à jour sans déplacement ; une panne de PC ne fait pas perdre les données.
7. **Un éditeur local et joignable.** Évolutions décidées pour le Mali (exemple : l'ajout du rôle caissier ou du prix AMO en quelques jours).
8. **Les données appartiennent au pharmacien.** Export complet Excel et fichiers à tout moment, même abonnement expiré.

## Faiblesses de SoftPharm et comment les traiter

Deux faiblesses peuvent faire perdre une vente dès la démonstration : la dépendance à internet et l'absence de code-barres. Elles sont à traiter avant l'ouverture commerciale.

| Faiblesse | Pourquoi ça compte | Réponse proposée | Priorité |
| --- | --- | --- | --- |
| Pas de mode hors ligne | Une coupure internet bloque la caisse, argument n°1 de WinPharma | Court terme : box 4G de secours et double opérateur conseillés dans l'offre. Moyen terme : avancer le mode caisse dégradé (VE-14, prévu en V2) | Haute |
| Pas de code-barres | Le comptoir va plus vite avec une douchette ; habitude des utilisateurs WinPharma | Réintroduire la recherche par code-barres (retirée le 08/10/2026) | Haute |
| Pas de transmission au grossiste | WinPharma envoie les commandes à Laborex directement | Contacter Laborex Mali et les autres grossistes pour un format d'échange ; d'ici là, bon Excel par email | Moyenne |
| Pas de références | Un pharmacien suit ses confrères | Pilote dans 2 ou 3 officines de Bamako (prévu au plan), puis témoignages chiffrés | Haute |
| Support à construire | Peur d'être seul en cas de panne | Numéro WhatsApp dédié, formation sur place incluse, engagement de délai de réponse | Haute |
| Vente à crédit, avoirs, historique patient absents | Pratiques courantes en officine (clients connus, retours) | Déjà prévus en V1 ; les prioriser selon les retours du pilote | Moyenne |
| Pas d'interactions médicamenteuses | Argument de sécurité patient | Peu utilisé au Mali (supposé) ; à vérifier pendant le pilote avant d'investir | Basse |
| Coût de la migration | Ressaisie du stock et des clients, formation | Import Excel existant + reprise des données faite par SoftPharm (voir l'offre de migration) | Haute |

## Argumentaire commercial

On ne vend pas « un logiciel de plus » mais de l'argent récupéré et du temps gagné : créance AMO encaissée, caisse juste, périmés évités, contrôle à distance.

### Le pitch en 30 secondes

« Docteur, combien vous doivent l'INPS et la CANAM aujourd'hui, et depuis quand ? Avec SoftPharm, vous le voyez en un clic, et vos bordereaux sortent tout seuls avec les ordonnances. Votre caisse sépare espèces, Orange Money, Moov Money et Wave, donc le soir le compte tombe juste. Et vous suivez votre officine depuis votre téléphone, sans serveur à entretenir. On vous installe, on reprend vos données et on forme votre équipe sur place. »

### Les questions qui ouvrent la discussion

- Combien de bordereaux AMO sont en attente de paiement, et lesquels ont été rejetés ?
- Le soir, l'argent mobile money et les espèces correspondent-ils toujours au logiciel ?
- Savez-vous quelles remises vos vendeurs ont accordées ce mois-ci ?
- Combien avez-vous détruit de périmés l'an dernier ?
- Que se passe-t-il si le PC qui sert de serveur tombe en panne demain ?
- Pouvez-vous voir vos ventes du jour sans être à l'officine ?

### Arguments par profil

| Profil | Ce qui l'intéresse | Argument SoftPharm |
| --- | --- | --- |
| Titulaire d'une officine | Trésorerie, vols et remises, tranquillité | Créance AMO suivie, remises sous PIN, journal d'audit, suivi depuis le téléphone |
| Propriétaire de plusieurs officines | Vue d'ensemble, équilibre des stocks | Un seul compte, bascule entre pharmacies, transferts de stock tracés |
| Jeune pharmacien qui s'installe | Coût de départ, simplicité | Pas de serveur à acheter, abonnement annuel, import Excel du catalogue, démarrage rapide |
| Pharmacien adjoint | Commandes, stock, AMO au quotidien | Suggestions de commande, réceptions partielles, alertes de péremption, bordereaux automatiques |
| Vendeur et caissier | Rapidité au comptoir | Recherche par nom ou DCI, étagère affichée, vente envoyée à la caisse en un clic |

### Réponses aux objections

| Objection | Réponse |
| --- | --- |
| « WinPharma marche, pourquoi changer ? » | Il marche pour vendre. SoftPharm vous dit aussi combien l'AMO vous doit, où part votre marge et ce que font vos vendeurs, de partout. |
| « Et si internet coupe ? » | Nous fournissons une solution de secours 4G avec l'abonnement. Vos données, elles, ne dépendent plus d'un PC qui peut brûler ou être volé. (À renforcer par le mode hors ligne.) |
| « Mes données sur internet, c'est sûr ? » | Chaque pharmacie est isolée, l'accès est par rôle, chaque action sensible est tracée, et vous exportez tout à tout moment. L'éditeur ne voit pas vos ventes ni vos clients. |
| « La migration va bloquer mon officine » | Nous reprenons votre catalogue, vos fournisseurs et vos clients par import Excel, et basculons un jour calme avec une personne sur place. |
| « Mes employés sont habitués » | Formation sur place par rôle et guide illustré ; l'écran de caisse s'utilise au clavier. |
| « C'est combien ? » | Un abonnement annuel en FCFA, sans serveur ni licence à acheter (montants à fixer, voir ci-dessous). |
| « Vous êtes nouveaux, et si vous disparaissez ? » | Vos données restent exportables à tout moment, même abonnement expiré, et nous vous montrons des pharmacies déjà équipées. |

## Offre de migration et prochaines étapes

Pour faire bouger un pharmacien équipé de WinPharma, il faut que le changement ne lui coûte ni argent ni journée de travail. Proposition d'offre de bascule, à chiffrer :

- **Reprise des données incluse** : export du catalogue, des fournisseurs et des clients depuis WinPharma, import dans SoftPharm par notre équipe.
- **Installation et formation sur place** : une demi-journée par rôle, guide d'utilisation remis.
- **Essai ou premiers mois offerts** pendant que le contrat WinPharma court encore, pour éviter de payer deux fois.
- **Kit de continuité** : box 4G de secours conseillée ou fournie.
- **Parrainage** : un mois offert au pharmacien qui recommande un confrère.

Prochaines étapes :

- [ ] Fixer les tarifs annuels des trois offres en FCFA (encore « à fixer » dans le cahier des charges).
- [ ] Rencontrer 3 officines équipées de WinPharma : version, distributeur, prix payé, points de frustration, format d'export des données.
- [ ] Lancer le pilote dans 2 ou 3 officines de Bamako et mesurer : temps de vente, créance AMO récupérée, écarts de caisse.
- [ ] Décider si le mode hors ligne et le code-barres passent avant l'ouverture commerciale.
- [ ] Contacter Laborex Mali pour un format d'échange des commandes.

## Sources

Pages trouvées par recherche web le 11/10/2026 ; l'accès direct étant bloqué depuis l'environnement de rédaction, seuls leurs extraits ont été lus.

- [Outils technologiques de gestion dans les officines de Bamako au Mali (AJOL)](https://www.ajol.info/index.php/stss/article/view/192628) : 77,4 % d'utilisation de WinPharma, satisfaction moyenne 37 %, télétransmission Laborex.
- [Même étude, version PDF](https://revuesciences-techniquesburkina.org/index.php/sciences_de_la_sante/article/download/155/646)
- [État des lieux des outils technologiques de gestion dans les officines du district de Bamako (Bibliosanté)](https://bibliosante.ml/handle/123456789/13179) : 94 officines, satisfaction moyenne 25 %.
- [Winpharma, site de l'éditeur](https://www.winpharma.com/) et [Le réseau Winpharma](https://www.winpharma.com/le-reseau-winpharma/)
- [Winpharma : avis (Lonasante)](https://www.lonasante.com/winpharma/) : 7 800 officines, contrat annuel, tarifs non publiés.
- [Winpharma (La Fabrique du Net)](https://www.lafabriquedunet.fr/logiciel/winpharma)
- [Les meilleurs logiciels LGO (Skello)](https://www.skello.io/blog/logiciels-pharmacie) : client lourd, hors ligne.
- [Winpharma (Foxeet)](https://foxeet.fr/contenu/logiciel-gestion-officines-winpharma) : cloud et sur site.
- [Liste des logiciels certifiés (Ordre des pharmaciens)](https://www.ordre.pharmacien.fr/content/download/64832/272747?version=1)
- [Winpharma Gabon (Facebook)](https://www.facebook.com/p/Winpharma-Gabon-61554361079519/)
- Concurrents locaux : [Meditect](https://www.meditect.com/?lng=en), [Phénix AS PHARM](https://www.as-pharm.com/), [FlexPharm](https://flexpharm.isamatech.com/), [KiboERP](https://kiboerp.com/erp/pharmacie)
- SoftPharm : [cahier des charges](cahier-des-charges.md), [hypothèses](hypotheses.md) et code du dépôt.
