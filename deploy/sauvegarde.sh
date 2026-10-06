#!/usr/bin/env bash
#
# PharmaGest — sauvegarde nocturne (§ 6 : chaque nuit, chiffrée, conservée 30 jours, copie hors du serveur).
#
# Sauvegarde la base MySQL et les fichiers des pharmacies, chiffre l'archive avec GPG (clé publique de l'éditeur :
# le serveur ne peut pas déchiffrer ses propres sauvegardes), la copie sur un stockage distant avec rclone, puis
# supprime les sauvegardes de plus de 30 jours (localement et à distance).
#
# Lancement : timer systemd ou cron à 2 h, heure de Bamako (voir docs/mise-en-production.md).
# Prérequis : mysqldump, gpg (clé importée), rclone (distant « sauvegardes » configuré), fichier
# /etc/pharmagest/sauvegarde.env (droits 600) qui définit :
#   MYSQL_DEFAULTS_FILE=/etc/pharmagest/mysql-sauvegarde.cnf   # [client] user=… password=…
#   BASE=pharmagest
#   DOSSIER_APP=/var/www/pharmagest/current
#   DOSSIER_SAUVEGARDES=/var/backups/pharmagest
#   GPG_DESTINATAIRE=sauvegardes@pharmagest.ml
#   DISTANT=sauvegardes:pharmagest
#
set -euo pipefail

# shellcheck source=/dev/null
source /etc/pharmagest/sauvegarde.env

HORODATAGE="$(TZ=Africa/Bamako date +%Y-%m-%d_%H%M)"
TRAVAIL="$(mktemp -d)"
trap 'rm -rf "$TRAVAIL"' EXIT
mkdir -p "$DOSSIER_SAUVEGARDES"

# Base : instantané cohérent sans verrouiller les tables InnoDB.
mysqldump --defaults-extra-file="$MYSQL_DEFAULTS_FILE" --single-transaction --routines --triggers \
    --default-character-set=utf8mb4 "$BASE" > "$TRAVAIL/base.sql"

# Fichiers des pharmacies (logos, justificatifs, ordonnances, exports d'archivage).
tar -C "$DOSSIER_APP/var" -czf "$TRAVAIL/fichiers.tar.gz" fichiers

ARCHIVE="$DOSSIER_SAUVEGARDES/pharmagest-$HORODATAGE.tar.gpg"
tar -C "$TRAVAIL" -cf - base.sql fichiers.tar.gz | gpg --batch --yes --trust-model always \
    --recipient "$GPG_DESTINATAIRE" --encrypt --output "$ARCHIVE"

# Copie hors du serveur principal.
rclone copy "$ARCHIVE" "$DISTANT/"

# Conservation : 30 jours.
find "$DOSSIER_SAUVEGARDES" -name 'pharmagest-*.tar.gpg' -mtime +30 -delete
rclone delete --min-age 30d "$DISTANT/"

echo "Sauvegarde $ARCHIVE terminée."
