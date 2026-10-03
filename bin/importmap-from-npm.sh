#!/usr/bin/env bash
#
# Alternative à `php bin/console importmap:install` pour les environnements
# où le CDN jsDelivr est inaccessible (ex. : conteneurs cloud avec filtrage
# réseau). Les paquets déclarés dans importmap.php sont récupérés depuis le
# registre npm puis regroupés en un seul module ESM (esbuild), exactement à
# l'emplacement où AssetMapper les attend dans assets/vendor/.
#
# En local (DDEV) ou en production, utilisez simplement :
#   php bin/console importmap:install
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VENDOR="$ROOT/assets/vendor"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Les versions doivent rester alignées sur importmap.php.
BOOTSTRAP=5.3.8
POPPER=2.11.8
ICONS=1.13.1
CHARTJS=4.5.1
KURKLE=0.3.4
STIMULUS=3.2.2
TURBO=8.0.23

cd "$WORK"
npm init -y >/dev/null
npm install --silent --no-audit --no-fund \
  "bootstrap@$BOOTSTRAP" "@popperjs/core@$POPPER" "bootstrap-icons@$ICONS" \
  "chart.js@$CHARTJS" "@kurkle/color@$KURKLE" \
  "@hotwired/stimulus@$STIMULUS" "@hotwired/turbo@$TURBO" esbuild@0.25

bundle() { # $1 = point d'entrée, $2 = fichier de sortie, $3.. = dépendances externes
  local entry="$1" out="$2"; shift 2
  local ext=()
  for e in "$@"; do ext+=("--external:$e"); done
  mkdir -p "$(dirname "$out")"
  npx esbuild "$entry" --bundle --format=esm --minify --log-level=warning ${ext[@]+"${ext[@]}"} --outfile="$out"
}

bundle node_modules/@hotwired/stimulus/dist/stimulus.js "$VENDOR/@hotwired/stimulus/stimulus.index.js"
bundle node_modules/@hotwired/turbo/dist/turbo.es2017-esm.js "$VENDOR/@hotwired/turbo/turbo.index.js"
bundle node_modules/@popperjs/core/lib/index.js "$VENDOR/@popperjs/core/core.index.js"
bundle node_modules/bootstrap/dist/js/bootstrap.esm.js "$VENDOR/bootstrap/bootstrap.index.js" @popperjs/core
bundle node_modules/@kurkle/color/dist/color.esm.js "$VENDOR/@kurkle/color/color.index.js"
bundle node_modules/chart.js/dist/chart.js "$VENDOR/chart.js/chart.js.index.js" @kurkle/color

mkdir -p "$VENDOR/bootstrap/dist/css" "$VENDOR/bootstrap-icons/font/fonts"
cp node_modules/bootstrap/dist/css/bootstrap.min.css "$VENDOR/bootstrap/dist/css/"
sed 's#/\*\# sourceMappingURL=.*\*/##' node_modules/bootstrap-icons/font/bootstrap-icons.min.css \
  > "$VENDOR/bootstrap-icons/font/bootstrap-icons.min.css"
cp node_modules/bootstrap-icons/font/fonts/bootstrap-icons.woff* "$VENDOR/bootstrap-icons/font/fonts/"
sed -i 's#/\*\# sourceMappingURL=.*\*/##' "$VENDOR/bootstrap/dist/css/bootstrap.min.css"

echo "assets/vendor/ prêt."
