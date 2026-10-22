#!/usr/bin/env bash
# Costruisce il pacchetto installabile su hosting condiviso:
#   releases/gestionale-associazioni-v<VERSION>.zip  (+ .sha256)
# con dentro UNA cartella gestionale-associazioni/ che contiene l'app
# completa: vendor/ (senza dipendenze di sviluppo), public/build (Vite),
# index.php e .htaccess di radice, nessun .env.
#
# Uso: bash bin/build-release.sh [--skip-npm]
#   --skip-npm   non rilancia npm ci / npm run build: usa public/build com'è
#
# Lo stesso script gira nella GitHub Action di release (tag v*).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NOME="gestionale-associazioni"
VERSIONE="$(tr -d '[:space:]' < "$ROOT/VERSION")"
STAGING="$ROOT/build/$NOME"
ZIP="$ROOT/releases/$NOME-v$VERSIONE.zip"
SKIP_NPM=0
for arg in "$@"; do
    case "$arg" in
        --skip-npm) SKIP_NPM=1 ;;
        *) echo "Opzione sconosciuta: $arg" >&2; exit 2 ;;
    esac
done

[ -n "$VERSIONE" ] || { echo "File VERSION vuoto" >&2; exit 1; }
for cmd in rsync composer zip shasum; do
    command -v "$cmd" >/dev/null || { echo "Manca il comando $cmd" >&2; exit 1; }
done

echo "==> Pacchetto $NOME v$VERSIONE"
rm -rf "$ROOT/build"
mkdir -p "$STAGING" "$ROOT/releases"

echo "==> Copia dei sorgenti (esclusioni in bin/release-exclude.txt)"
rsync -a --exclude-from="$ROOT/bin/release-exclude.txt" "$ROOT/" "$STAGING/"

echo "==> Dipendenze PHP di produzione"
(cd "$STAGING" && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress --quiet)

if [ "$SKIP_NPM" = 0 ]; then
    echo "==> Asset frontend (npm ci + vite build)"
    (cd "$ROOT" && npm ci --silent --no-audit --no-fund && npm run build --silent)
fi
[ -f "$ROOT/public/build/manifest.json" ] || { echo "public/build/manifest.json mancante: esegui npm run build" >&2; exit 1; }
rm -rf "$STAGING/public/build"
cp -R "$ROOT/public/build" "$STAGING/public/build"

echo "==> Diniego Apache anche in vendor/ (le altre cartelle hanno il .htaccess nel repo)"
cp "$STAGING/app/.htaccess" "$STAGING/vendor/.htaccess"

echo "==> Cartelle di runtime"
(cd "$STAGING" && mkdir -p storage/app/private storage/app/public storage/app/purifier \
    storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/testing \
    storage/logs storage/media-library/temp bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && rm -f .env)

echo "==> Controlli"
for f in vendor/autoload.php vendor/.htaccess public/build/manifest.json public/index.php index.php .htaccess .env.example VERSION artisan database/installer/schema.sql storage/.htaccess; do
    [ -e "$STAGING/$f" ] || { echo "Nel pacchetto manca $f" >&2; exit 1; }
done
[ ! -e "$STAGING/.env" ] || { echo "Il pacchetto contiene un .env" >&2; exit 1; }
[ ! -d "$STAGING/vendor/phpunit" ] || { echo "Il pacchetto contiene dipendenze di sviluppo" >&2; exit 1; }
[ ! -d "$STAGING/tests" ] || { echo "Il pacchetto contiene tests/" >&2; exit 1; }

echo "==> Zip"
rm -f "$ZIP" "$ZIP.sha256"
(cd "$ROOT/build" && zip -r -X -q "$ZIP" "$NOME")
(cd "$ROOT/releases" && shasum -a 256 "$(basename "$ZIP")" > "$(basename "$ZIP").sha256")

echo "==> Fatto: $ZIP ($(du -h "$ZIP" | cut -f1))"
cat "$ZIP.sha256"
