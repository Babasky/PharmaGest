# Hypothèses de conception

Ce fichier consigne les hypothèses retenues lorsque le cahier des charges laisse un point ouvert.
Chaque hypothèse est datée et peut être remise en cause : il suffit de la modifier ici et d'ouvrir un ticket.

> **À compléter.** Le § 13 du cahier des charges liste quatre hypothèses, validées comme saines lors du cadrage.
> Leur texte exact n'a pas été conservé dans cette session de travail : il sera recopié ici mot pour mot
> dès que le cahier des charges sera versionné dans `docs/cahier-des-charges.md`.

## H-A — Un propriétaire, une pharmacie (MVP), sans relation 1-1 codée en dur

*Source : § 3 du cahier des charges. Retenue le 03/10/2026.*

- Pour le MVP, un compte `ROLE_PROPRIETAIRE` gère **une seule** pharmacie.
- Le modèle ne stocke **pas** la pharmacie directement sur l'utilisateur. L'accès passe par une **table
  d'affectation utilisateur–pharmacie** (`affectation` : utilisateur, pharmacie, rôle dans la pharmacie, actif).
- Conséquences :
  - au MVP, l'application refuse de créer une deuxième affectation active pour un même propriétaire ;
  - plus tard, autoriser plusieurs pharmacies reviendra à lever cette règle et à ajouter un sélecteur de pharmacie
    (la pharmacie courante est conservée en session et alimente le `TenantFilter`) ;
  - les vendeurs suivent le même modèle (une affectation vers la pharmacie qui les emploie).

## Hypothèses du § 13

| N° | Hypothèse | Statut |
|----|-----------|--------|
| H1 | *à recopier depuis le § 13* | validée au cadrage |
| H2 | *à recopier depuis le § 13* | validée au cadrage |
| H3 | *à recopier depuis le § 13* | validée au cadrage |
| H4 | *à recopier depuis le § 13* | validée au cadrage |

## Choix techniques du Lot 0

- **Montants** : entiers en FCFA ; arrondi unique à l'unité, demi vers le haut (`App\Util\Fcfa::arrondir`).
  Affichage `12 500 FCFA` avec espaces insécables pour qu'un montant ne soit jamais coupé en fin de ligne.
- **Téléphones** : stockés normalisés `+223XXXXXXXX`, affichés `+223 XX XX XX XX` (`App\Util\Telephone`).
- **Fuseau horaire** : `Africa/Bamako` (UTC+0, sans heure d'été) fixé dans le `Kernel`, Twig, PHP (DDEV) et le conteneur DDEV.
- **Super admin** : n'hérite d'aucun rôle d'officine ; sa sidebar ne montre que l'administration de la plateforme.
