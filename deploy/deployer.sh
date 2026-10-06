#!/usr/bin/env bash
#
# PharmaGest — déploiement d'une version (à lancer sur le serveur, en tant que www-data).
#   deploy/deployer.sh v1.0.0
#
# Chaque version est installée dans releases/<date>, partage var/fichiers et .env.local avec les précédentes,
# puis le lien « current » bascule d'un coup. En cas de problème : repointer « current » vers la version précédente
# (les migrations, elles, ne reviennent pas en arrière : restaurer la sauvegarde si une migration doit être annulée).
#
set -euo pipefail

VERSION="${1:?Version (tag Git) à déployer}"
RACINE=/var/www/pharmagest
DEPOT=https://github.com/Babasky/pharmagest.git
RELEASE="$RACINE/releases/$(date +%Y%m%d%H%M%S)"

git clone --depth 1 --branch "$VERSION" "$DEPOT" "$RELEASE"
cd "$RELEASE"

ln -s "$RACINE/shared/.env.local" .env.local
mkdir -p var "$RACINE/shared/fichiers"
ln -s "$RACINE/shared/fichiers" var/fichiers

export APP_ENV=prod
composer install --no-dev --optimize-autoloader --no-interaction --no-progress
composer dump-env prod
php bin/console importmap:install
php bin/console asset-map:compile
php bin/console cache:clear
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

ln -sfn "$RELEASE" "$RACINE/current"
sudo systemctl reload php8.3-fpm
sudo supervisorctl restart pharmagest-messenger

# On garde les 5 dernières versions.
ls -1dt "$RACINE"/releases/* | tail -n +6 | xargs -r rm -rf
echo "Version $VERSION déployée."
