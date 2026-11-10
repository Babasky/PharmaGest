# Déploiement sur Upsun

PharmaGest se déploie sur Upsun avec la configuration [`.upsun/config.yaml`](../.upsun/config.yaml) :
Symfony 7.4 · PHP 8.3 · MySQL 8.0 · un worker Messenger/Scheduler · assets asset-mapper
(aucune étape Node).

## 1. Prérequis (une fois)

Installer la CLI Upsun et se connecter. L'installeur fournit le binaire **`platform`**
(CLI unifiée Platform.sh/Upsun : elle gère les projets Upsun) — c'est le nom à utiliser
dans toutes les commandes ci-dessous, pas `upsun`.

```bash
curl -fsS https://raw.githubusercontent.com/platformsh/cli/main/installer.sh | bash
```

```bash
platform auth:login
```

## 2. Créer le projet et le relier au dépôt

```bash
platform project:create --title PharmaGest --region fr-3.platform.sh
```

Depuis le dossier du projet, ajouter le remote Upsun (il s'ajoute à côté de `origin`) :

```bash
platform project:set-remote <PROJECT_ID>
```

## 3. Définir les variables d'environnement (secrets)

`DATABASE_URL` est fourni automatiquement par la relation `database`. Il reste à définir :

```bash
platform variable:create --level project --name env:APP_ENV --value prod
```

`APP_SECRET` — **indispensable** : il signe les liens de réinitialisation et les jetons CSRF.
Générer une valeur aléatoire unique :

```bash
platform variable:create --level project --name env:APP_SECRET --sensitive true --value "$(php -r 'echo bin2hex(random_bytes(32));')"
```

`MAILER_DSN` — le SMTP d'envoi des emails (valeur de votre `.env.local`, jamais versionnée) :

```bash
platform variable:create --level project --name env:MAILER_DSN --sensitive true --value "smtp://UTILISATEUR:MOTDEPASSE@HOTE:587"
```

`DEFAULT_URI` — l'URL publique réelle (sert aux liens dans les emails envoyés par le worker) :

```bash
platform variable:create --level project --name env:DEFAULT_URI --value "https://VOTRE-DOMAINE"
```

> Les autres variables (`MESSENGER_TRANSPORT_DSN=doctrine://default`, `LOCK_DSN=flock`,
> `APP_SHARE_DIR=var/share`, `MAILER_FROM`) proviennent du `.env` versionné et conviennent telles quelles.

## 4. Déployer

```bash
git add .upsun/config.yaml docs/deploiement-upsun.md && git commit -m "Déploiement Upsun"
```

```bash
platform push
```

Au push, Upsun exécute le hook **build** (composer, compilation des assets) puis le hook **deploy**
(migrations Doctrine, mise en place du transport Messenger, cache). L'URL s'affiche à la fin ; sinon :

```bash
platform url
```

## 5. Après le premier déploiement

Charger le référentiel / le compte super-admin se fait selon votre procédure habituelle, via SSH :

```bash
platform ssh -- php bin/console <votre-commande-d-amorçage>
```

Vérifier que le secret est bien chargé (doit renvoyer 64 caractères, pas une valeur vide) :

```bash
platform ssh -- php bin/console debug:container --parameter=kernel.secret
```

## Points d'attention

- **Mise à l'échelle web.** Les uploads (`var/share`) et le verrou `flock` sont locaux à un conteneur.
  Pour passer à plusieurs instances web, basculer `LOCK_DSN` sur la base de données et stocker les
  copies d'ordonnances sur un stockage partagé (service réseau ou objet) plutôt que sur le montage local.
- **Worker.** Un seul worker (`numprocs=1` équivalent) consomme `async` et `scheduler_default` :
  emails, notifications et tâches planifiées. Il se recycle toutes les heures (ou à 256 Mo) et redémarre seul.
- **Environnements de preview.** Pour déboguer une branche, `SYMFONY_ENV=dev` et `SYMFONY_DEBUG=1`
  (à retirer ensuite). La production reste en `prod`.
