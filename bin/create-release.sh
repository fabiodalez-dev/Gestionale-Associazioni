#!/usr/bin/env bash
# Pubblica una release, come scripts/create-release.sh di Pinakes: controlli
# locali, tag annotato, push, attesa della GitHub Action "Release"
# (.github/workflows/release.yml, che costruisce e carica il pacchetto) e
# verifica finale degli asset pubblicati. Non costruisce nulla in locale.
#
# Uso: bash bin/create-release.sh X.Y.Z [--yes]
#      bash bin/create-release.sh X.Y.Z-rc.N [--yes]   (prerelease)
#
# Prima: aggiorna il file VERSION, rigenera database/installer/schema.sql se
# ci sono migration nuove, committa e pusha sul branch da rilasciare.
set -euo pipefail

usage() { echo "Uso: bash bin/create-release.sh X.Y.Z [--yes]" >&2; }
fail() { printf 'ERRORE: %s\n' "$*" >&2; exit 1; }
info() { printf '==> %s\n' "$*"; }

[[ $# -ge 1 && $# -le 2 ]] || { usage; exit 1; }
VERSIONE="$1"
SI=false
[[ "${2:-}" == "--yes" ]] && SI=true
[[ "$VERSIONE" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc)\.[0-9]+)?$ ]] || fail "versione '$VERSIONE' non valida: atteso X.Y.Z o X.Y.Z-rc.N"
TAG="v$VERSIONE"
PRERELEASE=false
[[ "$VERSIONE" == *-* ]] && PRERELEASE=true

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
NOME="gestionale-associazioni"

info "Controlli preliminari"
for c in git gh jq; do command -v "$c" >/dev/null || fail "manca il comando $c"; done
gh auth status >/dev/null 2>&1 || fail "GitHub CLI non autenticata: gh auth login"
REPO="$(gh repo view --json nameWithOwner --jq .nameWithOwner)"

[[ -z "$(git status --porcelain)" ]] || fail "working tree non pulito: committa o scarta le modifiche"
BRANCH="$(git branch --show-current)"
git fetch -q origin
[[ "$(git rev-parse HEAD)" == "$(git rev-parse "origin/$BRANCH")" ]] || fail "HEAD non coincide con origin/$BRANCH: pusha prima"

[[ "$(tr -d '[:space:]' < VERSION)" == "$VERSIONE" ]] || fail "il file VERSION contiene '$(tr -d '[:space:]' < VERSION)', non $VERSIONE"
git rev-parse -q --verify "refs/tags/$TAG" >/dev/null && fail "il tag $TAG esiste già in locale"
git ls-remote --exit-code --tags origin "refs/tags/$TAG" >/dev/null 2>&1 && fail "il tag $TAG esiste già su origin"

info "Schema dell'installer allineato alle migration?"
php -r '
require "vendor/autoload.php";
$sql = file_get_contents("database/installer/schema.sql");
$dump = App\Services\Installer\SchemaSql::migrazioni($sql);
$file = App\Services\Installer\SchemaSql::migrazioniDelProgetto("database/migrations");
if ($dump !== $file) { fwrite(STDERR, "database/installer/schema.sql è indietro: php artisan installer:genera-schema\n"); exit(1); }
echo "   ok: ".count($dump)." migration registrate\n";
'

if ! $SI; then
    nota=""; [[ "$PRERELEASE" == true ]] && nota=" come prerelease"
    read -r -p "Creo e pusho il tag $TAG su $REPO ($BRANCH)${nota}? [s/N] " r
    [[ "$r" =~ ^[sS]$ ]] || fail "annullato"
fi

info "Tag $TAG"
git tag -a "$TAG" -m "Release $TAG"
git push origin "refs/tags/$TAG"

info "Attendo la GitHub Action Release"
RUN_ID=""
for _ in $(seq 1 24); do
    RUN_ID="$(gh run list --workflow=release.yml --event=push --json databaseId,headBranch --jq ".[] | select(.headBranch==\"$TAG\") | .databaseId" | head -1)"
    [[ -n "$RUN_ID" ]] && break
    sleep 5
done
[[ -n "$RUN_ID" ]] || fail "nessuna esecuzione del workflow trovata per $TAG (controlla su GitHub → Actions)"
gh run watch "$RUN_ID" --exit-status || fail "il workflow Release è fallito: gh run view $RUN_ID --log-failed"

info "Verifica degli asset pubblicati"
ASSET="$NOME-$TAG.zip"
gh release view "$TAG" --json assets --jq '.assets[].name' | grep -qx "$ASSET" || fail "asset $ASSET assente nella release"
gh release view "$TAG" --json assets --jq '.assets[].name' | grep -qx "$ASSET.sha256" || fail "asset $ASSET.sha256 assente"
DIGEST="$(gh api "repos/$REPO/releases/tags/$TAG" --jq ".assets[] | select(.name==\"$ASSET\") | .digest // empty")"
[[ "$DIGEST" =~ ^sha256:[0-9a-f]{64}$ ]] || fail "l'API GitHub non espone un digest sha256 per $ASSET: l'updater in-app lo rifiuterebbe"
SHA_FILE="$(gh release download "$TAG" --pattern "$ASSET.sha256" --output - | cut -d' ' -f1)"
[[ "sha256:$SHA_FILE" == "$DIGEST" ]] || fail "lo sha256 pubblicato ($SHA_FILE) non coincide con il digest dell'API ($DIGEST)"

# La Action pubblica già i tag con trattino come prerelease: qui si verifica
if [[ "$PRERELEASE" == true ]]; then
    [[ "$(gh release view "$TAG" --json isPrerelease --jq .isPrerelease)" == "true" ]] || fail "la release $TAG non è marcata prerelease"
fi

info "Release $TAG pubblicata: $(gh release view "$TAG" --json url --jq .url)"
echo "   digest $DIGEST"
if [[ "$PRERELEASE" == true ]]; then
    echo "   l'updater in-app la proporrà solo alle installazioni con UPDATE_CHANNEL=rc"
else
    echo "   l'updater in-app (/aggiornamenti) la proporrà alle installazioni"
fi
