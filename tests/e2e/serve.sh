#!/usr/bin/env bash
# Server dedicato alla suite E2E: DB isolato, mail su log, fixture abilitate.
# Le variabili esportate qui VINCONO sul .env (Dotenv non sovrascrive l'ambiente reale).
set -euo pipefail
cd "$(dirname "$0")/../.."

export APP_ENV=local
export APP_URL=http://127.0.0.1:8011
export DB_DATABASE=associazione_soci_e2e
export MAIL_MAILER=log
export QUEUE_CONNECTION=sync
export CACHE_STORE=file
export SESSION_DRIVER=file
export E2E_FIXTURES=1
# Honeypot attivo ma senza soglia temporale: Playwright compila i form
# più in fretta di un umano e il fake-success renderebbe i test flaky.
export HONEYPOT_SECONDS=0
export DEBUGBAR_ENABLED=false
export TELESCOPE_ENABLED=false

php artisan config:clear --quiet || true
exec php artisan serve --host=127.0.0.1 --port=8011
