# Gestione Associazioni — Laravel

Piattaforma SaaS multi-tenant per la gestione completa di associazioni: soci, tessere
(con QR code e verifica pubblica), quote e pagamenti online (Stripe/PayPal), eventi con
check-in, comunicazioni email, documenti, verbali e scadenze, area soci dedicata e
API REST documentate.

È la riscrittura in **Laravel 12** dell'app PHP procedurale `gestione-associazioni`,
con piena compatibilità con i dati esistenti: stesse tabelle, chiavi **UUID CHAR(36)**
e isolamento multi-tenant per `associazione_id` (global scope a livello framework).

- Piano di migrazione: [docs/PIANO-MIGRAZIONE.md](docs/PIANO-MIGRAZIONE.md)
- I 25 miglioramenti rispetto all'app legacy: [docs/MIGLIORAMENTI.md](docs/MIGLIORAMENTI.md)
- Schema dati di riferimento (dump reale MySQL): [docs/schema-reale-mysql.sql](docs/schema-reale-mysql.sql)

## Requisiti

- PHP **8.3+** (consigliato 8.4) con estensioni `pdo_mysql`, `mbstring`, `zip`, `gd`, `intl`
- **MySQL 8** (i test girano su SQLite in-memory, nessun setup richiesto)
- Composer 2
- Node.js 20+ e npm (asset Vite)
- `mysqldump` nel PATH (necessario per i backup del database)

## Installazione

```bash
git clone <repository> gestione-associazioni-laravel
cd gestione-associazioni-laravel

composer install
cp .env.example .env
php artisan key:generate
```

Configura nel `.env` la connessione MySQL (`DB_*`) e, se devi importare i dati
della vecchia app, anche le variabili `LEGACY_DB_*`. Poi:

```bash
php artisan migrate --seed     # schema + ruoli/permessi + dati demo
npm install && npm run build   # asset frontend (Vite)
php artisan storage:link
```

Il seeder crea gli utenti demo:

| Utente | Email | Ruolo |
|---|---|---|
| Super Admin | `superadmin@example.com` | `super_admin` (tutti i tenant) |
| Mario Rossi | `admin@associazione-demo.test` | `admin_associazione` |
| Operatore | `operatore@associazione-demo.test` | `operatore` |

### Queue worker (obbligatorio per email e job)

La coda usa il driver `database` (compatibile anche con shared hosting):

```bash
php artisan queue:work --tries=3
```

In produzione tieni vivo il worker con supervisor/systemd, ad esempio:

```ini
[program:gestione-associazioni-worker]
command=php /percorso/app/artisan queue:work --tries=3 --max-time=3600
autostart=true
autorestart=true
```

### Scheduler (cron)

Tutti i job pianificati (coda email, promemoria scadenze, backup, health check,
pulizia log) sono definiti in `routes/console.php` e monitorati da
spatie/laravel-schedule-monitor. Serve **una sola riga di cron**:

```cron
* * * * * cd /percorso/app && php artisan schedule:run >> /dev/null 2>&1
```

Dopo ogni deploy registra i task nel monitoraggio: `php artisan schedule-monitor:sync`.

In sviluppo basta `composer dev` (serve + queue + log + Vite in parallelo)
oppure `php artisan schedule:work` per il solo scheduler.

### Import dei dati dalla vecchia app

Lo schema è identico (stesse tabelle, stessi UUID), quindi l'import è una copia
1:1 limitata alle colonne comuni. Configura `LEGACY_DB_*` nel `.env` e:

```bash
php artisan app:import-legacy --dry-run   # anteprima senza scrivere
php artisan app:import-legacy             # import vero e proprio
php artisan app:import-legacy --tables=soci,tessere --force
```

Il comando importa le tabelle in ordine FK-safe, salta quelle non vuote
(se non usi `--force`) e sincronizza i ruoli spatie/permission con la colonna
`utenti.ruolo`.

## Struttura dei moduli

Ogni area funzionale è un modulo autonomo con convenzioni fisse:

| Componente | Percorso |
|---|---|
| Rotte | `routes/modules/<modulo>.php` (caricate automaticamente da `web.php`) |
| Controller | `app/Http/Controllers/<Modulo>/` |
| Form Request (validazione) | `app/Http/Requests/<Modulo>/` |
| Policy (autorizzazione) | `app/Policies/` |
| Viste Blade | `resources/views/<modulo>/` (layout `layouts.app`, Bootstrap 5.3, brand `#FF7B11`) |
| Test feature | `tests/Feature/<Modulo>/` (RefreshDatabase su SQLite in-memory) |

Moduli presenti: **auth** (Fortify + 2FA), **dashboard**, **soci** (ricerca Scout,
filtri salvati, import/export Excel), **tessere** (PDF, QR, verifica pubblica,
scanner/check-in), **quote-pagamenti** (Stripe + PayPal con webhook),
**eventi** (partecipanti e check-in), **email** (template, coda, log, SMTP
per-tenant), **documenti-verbali-scadenze** (MediaLibrary, promemoria),
**struttura** (associazioni, sedi, amministratori, configurazioni),
**area-soci** (guard `socio` dedicata), **api-v1** (Sanctum + api_keys legacy,
docs OpenAPI su `/docs/api`), **operations** (health check su `/health`).

Architettura trasversale:

- **Multi-tenancy**: trait `App\Models\Concerns\BelongsToAssociazione` applica un
  global scope su `associazione_id` (il `super_admin` non è limitato); middleware `tenant`.
- **UUID**: trait `HasUuids` su tutti i modelli di dominio.
- **Autorizzazione**: Policy + permessi granulari spatie/permission
  (ruoli `super_admin`, `admin_associazione`, `operatore`).
- **Audit**: spatie/laravel-activitylog su tutti i modelli (utente, IP, diff campi).

## Le 25 librerie e i 25 miglioramenti

Il progetto integra 25 pacchetti scelti per coprire sicurezza, dati, operazioni e
API — l'elenco completo con il ruolo di ciascuno è in
[docs/PIANO-MIGRAZIONE.md](docs/PIANO-MIGRAZIONE.md#le-25-librerie-laravel)
(Fortify, Sanctum, Scout, la suite spatie — permission, activitylog, backup,
medialibrary, query-builder, data, settings, csp, honeypot, health,
schedule-monitor —, maatwebsite/excel, dompdf, php-qrcode, laravel-phone,
laravel-codicefiscale, purifier, Scramble, Stripe, PayPal, Larastan, Debugbar).

I 25 miglioramenti funzionali rispetto all'app legacy (2FA, audit trail,
rate limiting, CSP, soft delete con cestino, import Excel, ricerca full-text,
backup pianificati, health check, API documentate, notifiche unificate, ecc.)
sono descritti uno per uno in [docs/MIGLIORAMENTI.md](docs/MIGLIORAMENTI.md).

## Comandi utili

```bash
# Import dati dalla vecchia app (vedi sezione dedicata)
php artisan app:import-legacy [--dry-run] [--tables=...] [--force]

# Backup database + file (spatie/laravel-backup, anche pianificato alle 02:00)
php artisan backup:run
php artisan backup:list
php artisan backup:clean

# Test (SQLite in-memory)
php artisan test
php artisan test --filter=NomeTest

# Code style (Laravel Pint)
vendor/bin/pint
vendor/bin/pint --test          # solo verifica, senza modificare

# Analisi statica (Larastan, livello 5 su app/ — vedi phpstan.neon)
vendor/bin/phpstan analyse --memory-limit=1G

# Documentazione API OpenAPI (Scramble)
php artisan scramble:export     # esporta api.json
# UI interattiva: http://localhost:8000/docs/api

# Operazioni
php artisan schedule-monitor:sync   # registra i task pianificati (a ogni deploy)
php artisan queue:work              # worker della coda
```

## Sviluppo

```bash
composer dev    # serve + queue + pail + vite in parallelo
```

Health check su `/health` (DB, coda, scheduler, spazio disco, debug mode);
Debugbar attiva automaticamente con `APP_DEBUG=true` in locale.

## Licenza

Distribuito con licenza [MIT](LICENSE).
