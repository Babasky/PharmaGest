# Guide de mise en production

Ce guide installe PharmaGest sur un serveur Linux (Ubuntu 24.04 LTS) avec Nginx, PHP-FPM 8.3, MySQL 8, un worker
Messenger supervisé, un certificat HTTPS et des sauvegardes chiffrées (§ 7.1 et § 6 du
[cahier des charges](cahier-des-charges.md)). Les fichiers d'exemple sont dans [`deploy/`](../deploy).

L'hébergeur et la localisation du serveur restent à choisir (point ouvert § 11.2). Dimensionnement de départ pour
500 pharmacies (§ 6) : 4 vCPU, 8 Go de RAM, 100 Go de disque SSD **chiffré**, et un stockage distant pour les
sauvegardes (autre prestataire ou autre région).

## 1. Serveur

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server supervisor git unzip certbot python3-certbot-nginx gnupg rclone \
    php8.3-fpm php8.3-cli php8.3-intl php8.3-mysql php8.3-zip php8.3-gd php8.3-mbstring php8.3-xml php8.3-opcache
# Composer : https://getcomposer.org/download/
sudo timedatectl set-timezone Africa/Bamako
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

Réglages PHP (`/etc/php/8.3/fpm/conf.d/90-pharmagest.ini` et la même chose pour `cli`) :

```ini
date.timezone = Africa/Bamako
memory_limit = 256M
upload_max_filesize = 10M
post_max_size = 12M
expose_php = Off
opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
realpath_cache_size = 4096K
realpath_cache_ttl = 600
```

Dans `/etc/php/8.3/fpm/pool.d/www.conf`, activer `catch_workers_output = yes` : les erreurs de l'application
(Monolog, sortie d'erreur) arrivent alors dans le journal de PHP-FPM.

## 2. Base de données

```bash
sudo mysql_secure_installation
sudo mysql -e "CREATE DATABASE pharmagest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'pharmagest'@'localhost' IDENTIFIED BY '<mot de passe long>';
  GRANT ALL ON pharmagest.* TO 'pharmagest'@'localhost';
  CREATE USER 'sauvegarde'@'localhost' IDENTIFIED BY '<autre mot de passe>';
  GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, PROCESS ON *.* TO 'sauvegarde'@'localhost';"
```

MySQL n'écoute que sur `127.0.0.1` (valeur par défaut d'Ubuntu) ; ne pas l'ouvrir sur le réseau.

## 3. Application

Arborescence : `/var/www/pharmagest/{releases,shared,current}`, propriétaire `www-data`.

```bash
sudo mkdir -p /var/www/pharmagest/{releases,shared/fichiers} /var/log/pharmagest
sudo chown -R www-data: /var/www/pharmagest /var/log/pharmagest
```

Fichier `/var/www/pharmagest/shared/.env.local` (droits `600`, propriétaire `www-data`) :

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<64 caractères aléatoires : php -r "echo bin2hex(random_bytes(32));">
DEFAULT_URI=https://app.pharmagest.ml
DATABASE_URL="mysql://pharmagest:<mot de passe>@127.0.0.1:3306/pharmagest?serverVersion=8.0.32&charset=utf8mb4"
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
MAILER_DSN=smtp://<utilisateur>:<mot de passe>@<serveur SMTP>:587
MAILER_FROM="PharmaGest <no-reply@pharmagest.ml>"
```

Coordonnées de l'éditeur (factures d'abonnement, page « compte suspendu ») : paramètre `app.editeur` dans
`config/services.yaml`, à renseigner avant la première facture.

Premier déploiement, puis à chaque version (tag Git) :

```bash
sudo -u www-data /var/www/pharmagest/current/deploy/deployer.sh v1.0.0   # ou depuis un clone du dépôt
```

Le script ([`deploy/deployer.sh`](../deploy/deployer.sh)) installe la version dans `releases/`, partage les fichiers
des pharmacies et `.env.local`, installe les dépendances sans les outils de développement, compile les assets,
applique les migrations, bascule le lien `current` puis relance PHP-FPM et le worker. Autoriser ces deux commandes à
`www-data` dans `sudoers` (`systemctl reload php8.3-fpm`, `supervisorctl restart pharmagest-messenger`).

Après le premier déploiement, créer le compte de l'éditeur :

```bash
cd /var/www/pharmagest/current && sudo -u www-data php bin/console app:super-admin:creer admin@pharmagest.ml "PharmaGest"
```

Ne jamais lancer `composer demo` ni charger les données de démonstration en production.

## 4. Nginx et HTTPS

```bash
sudo cp deploy/nginx.conf /etc/nginx/sites-available/pharmagest     # adapter server_name
sudo ln -s /etc/nginx/sites-available/pharmagest /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default
sudo certbot --nginx -d app.pharmagest.ml                            # renouvellement automatique (timer certbot)
sudo nginx -t && sudo systemctl reload nginx
```

Le port 80 redirige vers HTTPS ; l'application ajoute HSTS et ses en-têtes de sécurité
([revue de sécurité](securite.md)). Seul `public/` est servi : les fichiers des pharmacies restent dans
`var/fichiers` et passent toujours par un contrôleur qui vérifie les droits.

## 5. Worker et tâches planifiées

Le worker Messenger envoie les emails (activation, mot de passe, rappels, commandes fournisseurs, archivage) et
exécute les tâches planifiées de `App\Schedule` :

| Heure (Bamako) | Tâche |
|---|---|
| 3 h 00 | `app:pharmacies:archiver` : annonce 30 jours avant, puis archivage 12 mois après l'échéance avec envoi de l'export complet |
| 7 h 30 | `app:notifications:generer` : notifications du jour (ruptures, péremptions, échéances, bordereaux impayés) |
| 7 h 50 | `app:abonnements:rappels` : emails J-30, J-15, J-7 |

```bash
sudo cp deploy/supervisor-messenger.conf /etc/supervisor/conf.d/pharmagest.conf
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

Les messages en échec après 3 essais vont dans la file `failed` : `php bin/console messenger:failed:show` puis
`messenger:failed:retry`.

## 6. Sauvegardes et restauration

Exigence (§ 6) : chaque nuit, chiffrée, conservée 30 jours, copie hors du serveur principal, restauration testée
chaque mois. Le script [`deploy/sauvegarde.sh`](../deploy/sauvegarde.sh) sauvegarde la base et `var/fichiers`, chiffre
avec la **clé publique** GPG de l'éditeur (la clé privée n'est jamais sur le serveur), copie l'archive avec `rclone`
et applique la rétention de 30 jours.

```bash
sudo install -d -m 700 /etc/pharmagest
sudo nano /etc/pharmagest/sauvegarde.env            # variables décrites en tête du script
sudo nano /etc/pharmagest/mysql-sauvegarde.cnf      # [client] user=sauvegarde password=…
gpg --import cle-publique-sauvegardes.asc && rclone config   # distant « sauvegardes »
echo '0 2 * * * root /var/www/pharmagest/current/deploy/sauvegarde.sh >> /var/log/pharmagest/sauvegarde.log 2>&1' | sudo tee /etc/cron.d/pharmagest-sauvegarde
```

**Restauration** (à tester chaque mois sur un serveur de recette, jamais sur la production) :

```bash
gpg --decrypt pharmagest-AAAA-MM-JJ_HHMM.tar.gpg | tar -xf -       # sur un poste qui détient la clé privée
mysql pharmagest_recette < base.sql
tar -xzf fichiers.tar.gz -C /var/www/pharmagest/current/var/
```

Noter la date et le résultat de chaque test de restauration dans le registre d'exploitation.

## 7. Supervision

- Disponibilité visée : 99,5 % par mois. Surveiller `https://app.pharmagest.ml/connexion` (code 200) avec un service
  de surveillance externe, et l'espace disque, la mémoire et l'état de `pharmagest-messenger`.
- Journaux : `/var/log/nginx/pharmagest_*.log`, journal de PHP-FPM (erreurs applicatives en JSON),
  `/var/log/pharmagest/messenger.log`, `/var/log/pharmagest/sauvegarde.log`.
- Maintenances annoncées 48 h à l'avance, après 22 h (§ 6).

## Exploitation courante

| Fréquence | Action |
|---|---|
| Chaque jour | Vérifier la sauvegarde de la nuit et la copie distante ; regarder les messages en échec (`messenger:failed:show`) |
| Chaque semaine | Mises à jour de sécurité du système (`apt upgrade`), redémarrage de PHP-FPM |
| Chaque mois | Test de restauration ; `composer audit` sur la version déployée ; relecture des accès au serveur |
| À chaque version | CI verte, sauvegarde manuelle avant migration, `deploy/deployer.sh <tag>`, vérification de la connexion et d'une vente de test sur la recette |

## Check-list avant l'ouverture

- [ ] Hébergement choisi (point ouvert § 11.2), disque chiffré, DNS et certificat en place
- [ ] `APP_SECRET` unique, `APP_ENV=prod`, `.env.local` en `600`
- [ ] Coordonnées de l'éditeur renseignées (`app.editeur`)
- [ ] Emails : SPF, DKIM et DMARC configurés pour le domaine d'envoi, test d'un email d'activation
- [ ] Worker supervisé, tâches planifiées visibles dans `messenger.log` le lendemain matin
- [ ] Première sauvegarde chiffrée copiée à distance, et restauration testée
- [ ] Super admin créé, première pharmacie pilote créée depuis l'espace plateforme
- [ ] Points ouverts de la [revue de sécurité](securite.md) traités ou acceptés
- [ ] Déclaration APDP déposée (données de santé)
