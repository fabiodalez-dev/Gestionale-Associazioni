# Report finale — Migrazione a Laravel 12

Data: 2026-06-11 · Repo: `gestione-associazioni-laravel` · App legacy di origine: `gestione-associazioni` (PHP procedurale)

La migrazione è **completa**: tutti i 12 moduli funzionali sono implementati (rotte, controller, Form Request, Policy, viste Blade, test feature), i 25 miglioramenti pianificati sono presenti nel codice e l'intera suite di test passa.

---

## 1. Stato dei moduli

Ogni modulo segue la convenzione: rotte in `routes/modules/<modulo>.php`, controller in `app/Http/Controllers/<Modulo>/`, test in `tests/Feature/<modulo>/`.

| Modulo | Rotte | Controller | Test feature | Stato |
|---|---|---|---|---|
| **Auth admin** (Fortify + 2FA, switch tenant) | `routes/modules/00-auth.php` | `app/Http/Controllers/Auth/` | `tests/Feature/Auth/` (Login, Profile, TenantSwitch) | ✅ Completo |
| **Dashboard** (widget, statistiche) | `routes/modules/01-dashboard.php` | `app/Http/Controllers/Dashboard/` | `tests/Feature/Dashboard/` | ✅ Completo |
| **Area soci** (guard `socio`, profilo, quote, tessere, opt-out email, set/reset password) | `routes/modules/02-area-soci.php` | `app/Http/Controllers/AreaSoci/` | `tests/Feature/area_soci/` (7 file) | ✅ Completo |
| **Soci** (CRUD, stati, ricerca Scout, filtri salvati, import/export Excel, campi personalizzati, tag, gruppi dinamici, categorie/tipi) | `routes/modules/soci.php` | `app/Http/Controllers/Soci/` | `tests/Feature/Soci/` (Crud, Filtri, Stato, Config, ImportDocumenti) | ✅ Completo |
| **Tessere** (CRUD, generazione massiva, PDF/stampa, template, QR, verifica pubblica, check-in) | `routes/modules/tessere.php` | `app/Http/Controllers/Tessere/` | `tests/Feature/Tessere/` (6 file) | ✅ Completo |
| **Quote e pagamenti** (CRUD quote, pagina pagamento pubblica, link pagamento, offline, webhook Stripe/PayPal, impostazioni gateway) | `routes/modules/quote-pagamenti.php` | `app/Http/Controllers/Quote/` + `app/Services/Pagamenti/PaymentService.php` | `tests/Feature/quote_pagamenti/` (7 file, inclusi WebhookStripe/WebhookPayPal) | ✅ Completo |
| **Eventi** (CRUD, partecipanti, check-in via QR/API) | `routes/modules/eventi.php` | `app/Http/Controllers/Eventi/` | `tests/Feature/Eventi/` (Crud, Partecipanti, CheckinApi) | ✅ Completo |
| **Email** (comunicazioni, template, coda, log, SMTP per-tenant, promemoria, unsubscribe) | `routes/modules/email.php` | `app/Http/Controllers/Email/` + `app/Jobs/ProcessEmailQueueJob.php` + `app/Console/Commands/Emails*.php` | `tests/Feature/Email/` (7 file) | ✅ Completo |
| **Documenti, verbali, scadenze** (MediaLibrary, cestino soft-delete, download firmati, link temporanei, promemoria scadenze) | `routes/modules/documenti-verbali-scadenze.php` | `app/Http/Controllers/DocumentiVerbaliScadenze/` | `tests/Feature/documenti_verbali_scadenze/` (4 file) | ✅ Completo |
| **Struttura** (associazioni/tenant, sedi, amministratori, configurazioni, preiscrizione pubblica, privacy policy) | `routes/modules/struttura.php` | `app/Http/Controllers/Struttura/` | `tests/Feature/Struttura/` (5 file) | ✅ Completo |
| **API v1** (Sanctum + api_keys legacy, soci/tessere/quote/eventi/associazioni, docs OpenAPI Scramble) | `routes/modules/api-v1.php` | `app/Http/Controllers/Api/V1/` + `app/Http/Controllers/ApiV1/` (UI key/token) | `tests/Feature/api_v1/` (6 file) | ✅ Completo |
| **Operations** (health check `/health`, backup, scheduler monitorato) | `routes/modules/operations.php` | spatie/laravel-health + rotte dedicate | `tests/Feature/Operations/HealthRouteTest.php` | ✅ Completo |

Trasversali: multi-tenancy (`app/Models/Concerns/BelongsToAssociazione.php`, global scope su `associazione_id`), UUID `HasUuids` su tutti i modelli, audit (`app/Models/Concerns/HasDefaultActivityLog.php`, usato da 26 modelli), Policy + permessi spatie in `app/Policies/`, seeder ruoli `database/seeders/RolesSeeder.php`.

Totale: **63 controller**, **799 righe di rotte modulari**, **58 file di test**.

---

## 2. Esito dei test

Comando eseguito: `php artisan test` (SQLite in-memory, `RefreshDatabase`).

```
Test:       484 passati / 484 (0 falliti)
Asserzioni: 1.675
Durata:     ~21 s
Exit code:  0
```

Qualità aggiuntiva: Laravel Pint (code style) e Larastan livello 5 (`phpstan.neon`, baseline minima di 19 righe in `phpstan-baseline.neon`).

---

## 3. Rotte principali

L'app registra **235 rotte**. Nota: in Laravel 12 l'opzione `--columns` di `route:list` non esiste più; l'elenco sotto sintetizza l'output di `php artisan route:list | head -100` (escluse le rotte Debugbar, presenti solo in sviluppo).

```
GET|HEAD  /                                              home
GET|HEAD  dashboard                                      dashboard
# Struttura
GET/POST  associazioni                                   associazioni.index/store
PUT/DEL   associazioni/{associazione}                    associazioni.update/destroy
GET/POST  amministratori (+ {utente}, reinvia-invito)    amministratori.*
GET|HEAD  configurazioni                                 configurazioni.index
POST      configurazioni/{info,logo,pagamenti,privacy-policy,sedi,template-email,tessera-template,tesseramento,campi-obbligatori}
DELETE    configurazioni/sedi/{sede}                     sedi.destroy
# Soci
GET/POST  soci (+ {socio}, stato, import, export)        soci.*
GET/POST  tipi-socio, categorie-socio                    tipi-socio.* / categorie-socio.*
GET/POST  config-campi (+ preset), config-tags, config-gruppi
GET/POST  filtri-salvati                                 saved-filters.*
# Tessere
GET/POST  tessere (+ generazione massiva, pdf, stampa, template)
GET|HEAD  verifica-tessera/{tessera}                     pubblica
GET|HEAD  api/verifica-tessera/{tessera}                 tessere.verifica.json
GET|HEAD  checkin                                        eventi.checkin (scanner)
# Quote e pagamenti
GET/POST  quote (+ registra pagamento, link pagamento)   quote.*
GET|HEAD  pagamento/{token}                              pagina pubblica Stripe/PayPal
POST      webhook/stripe, webhook/paypal                 webhooks gateway
# Eventi
GET/POST  eventi (+ {evento}, partecipanti, checkin)     eventi.*
# Email
GET/POST  comunicazioni (+ conteggio-destinatari)        comunicazioni.*
GET/POST  email/template, email/coda, email/log          email-*.{index,...}
POST      email/coda/annulla-pendenti                    email-coda.cancel-pending
GET/POST  impostazioni-smtp                              smtp.*
GET|HEAD  unsubscribe/{token}                            pubblica
# Documenti / verbali / scadenze
GET/POST  documenti (+ cestino, ripristina, download, link-temporaneo, eliminazione definitiva)
GET/POST  documenti-socio (+ download, link-temporaneo, ripristina)
GET|HEAD  condivisi/documenti/{id}, condivisi/documenti-socio/{id}   download pubblici firmati
GET/POST  verbali, scadenze                              verbali.* / scadenze.*
# Area soci (guard `socio`)
GET/POST  area-soci/login, logout, password/email, set-password/{token}
GET|HEAD  area-soci (dashboard), profilo, quote, tessere (+ pdf), opt-out/{token}
# Pubbliche
GET/POST  preiscrizione (honeypot anti-spam)             preiscrizione.*
GET|HEAD  privacy-policy                                 pubblica
GET|HEAD  health                                         health check
# API v1 (Sanctum + api_keys legacy)
GET|HEAD  api/v1                                         api.v1.ping
GET|HEAD  api/v1/associazioni                            api.v1.associazioni.index
GET|HEAD  api/v1/soci (+ search, {id})  PUT api/v1/soci/{id}
GET|HEAD  api/v1/tessere (+ search, verify, {id})
GET|HEAD  api/v1/quote (+ {socio_id})
GET|HEAD  api/v1/eventi (+ {id})  POST api/v1/eventi/{id}/checkin
ANY       api/v1/{path}                                  405 method-not-allowed
GET/POST  api-keys (+ toggle), api-tokens                gestione chiavi/token
GET|HEAD  api-docs, docs/api, docs/api.json              docs OpenAPI (Scramble)
```

Elenco completo e sempre aggiornato: `php artisan route:list`.

---

## 4. I 25 miglioramenti implementati (con riferimenti ai file)

Descrizione estesa in `docs/MIGLIORAMENTI.md`; qui ogni voce con i file principali.

### Sicurezza (1–8)
1. **2FA TOTP per gli admin** — laravel/fortify: `app/Providers/FortifyServiceProvider.php`, `config/fortify.php`, viste in `resources/views/auth/`.
2. **Audit trail completo** — spatie/laravel-activitylog: `app/Models/Concerns/HasDefaultActivityLog.php` (applicato a 26 modelli), `config/activitylog.php`.
3. **Ruoli e permessi granulari** — spatie/laravel-permission: `database/seeders/RolesSeeder.php`, `app/Policies/`, `config/permission.php`.
4. **Rate limiting** — throttle su login admin/soci, API e form pubblici: `app/Providers/FortifyServiceProvider.php`, `routes/modules/02-area-soci.php`, `routes/modules/api-v1.php`, `routes/modules/struttura.php` (preiscrizione).
5. **Content-Security-Policy + security headers** — spatie/laravel-csp: `config/csp.php`.
6. **Honeypot anti-spam** sulla preiscrizione — spatie/laravel-honeypot: `config/honeypot.php`, form in `resources/views/struttura/`.
7. **Sanitizzazione HTML centralizzata** — mews/purifier: `app/Services/Email/EmailHtmlSanitizer.php`, usata da `app/Http/Controllers/Email/ComunicazioniController.php`, `EmailTemplateController.php`, `Struttura/ConfigurazioniController.php`.
8. **Isolamento multi-tenant a livello framework** — global scope su `associazione_id`: `app/Models/Concerns/BelongsToAssociazione.php`, middleware tenant, test in `tests/Feature/Foundation/TenancyTest.php`.

### Gestione dati (9–16)
9. **Validazione codice fiscale con checksum** — robertogallea/laravel-codicefiscale: `app/Http/Requests/Soci/SocioRequest.php` (regola `codice_fiscale`).
10. **Validazione telefoni internazionale** — propaganistas/laravel-phone: `app/Http/Requests/Soci/SocioRequest.php` (regola `Phone`).
11. **Soft delete + cestino** — `SoftDeletes` su `app/Models/Socio.php`, `Documento.php`, `DocumentoSocio.php`, `Evento.php`, `Verbale.php`; rotte cestino/ripristino in `routes/modules/documenti-verbali-scadenze.php`.
12. **Import soci da Excel/CSV** con report errori riga per riga — maatwebsite/excel: `app/Imports/SociImport.php`, test `tests/Feature/Soci/SociImportDocumentiTest.php`.
13. **Export Excel nativo (.xlsx)** — `app/Exports/SociExport.php`.
14. **Ricerca full-text sui soci** — Laravel Scout (driver database): trait `Searchable` in `app/Models/Socio.php`, `config/scout.php`.
15. **DTO tipizzati** — spatie/laravel-data: `app/Data/ApiV1/` (`SocioListItemData`, `TesseraListItemData`, `QuotaData`, ...).
16. **Documenti con MediaLibrary** — spatie/laravel-medialibrary: `InteractsWithMedia` in `app/Models/Documento.php` e `DocumentoSocio.php`; download firmati/link temporanei in `app/Http/Controllers/DocumentiVerbaliScadenze/`.

### Affidabilità e operazioni (17–21)
17. **Backup automatici pianificati** — spatie/laravel-backup: `config/backup.php`, schedulazione in `routes/console.php` (richiede `mysqldump` nel PATH).
18. **Coda email robusta** con retry e backoff — `app/Jobs/ProcessEmailQueueJob.php`, `app/Console/Commands/EmailsProcessQueue.php`, driver queue `database` (`config/queue.php`).
19. **Scheduler integrato e monitorato** — spatie/laravel-schedule-monitor: `routes/console.php`, `config/schedule-monitor.php`; promemoria in `app/Console/Commands/EmailsSendReminders.php` e `InviaPromemoriaScadenze.php`.
20. **Health check `/health`** — spatie/laravel-health: `config/health.php`, rotta in `routes/modules/operations.php`, test `tests/Feature/Operations/HealthRouteTest.php`.
21. **Test automatici + Pint + Larastan** — `tests/` (484 test, 1.675 asserzioni), `phpstan.neon` (livello 5), `phpunit.xml`.

### API e CRM (22–25)
22. **API documentate automaticamente (OpenAPI/Swagger)** — dedoc/scramble: `config/scramble.php`, UI su `/docs/api`, pagina interna `app/Http/Controllers/ApiV1/ApiDocsPageController.php`.
23. **Token API Sanctum** con abilities e revoca, in affiancamento alle api_keys legacy — `app/Http/Controllers/ApiV1/ApiTokenController.php`, `ApiKeyController.php`, `app/Models/ApiKey.php`, `app/Providers/ApiV1ServiceProvider.php`.
24. **Filtri/sort/paginazione standard sulle API** — spatie/laravel-query-builder: `app/Http/Controllers/Api/V1/` (es. `SocioApiController`, `TesseraApiController`), servizi in `app/Services/ApiV1/`.
25. **Notifiche unificate (database + email)** — `app/Notifications/PromemoriaScadenzaNotification.php`, `app/Notifications/Quote/PagamentoQuotaNotification.php`, opt-out per socio (`app/Http/Controllers/AreaSoci/EmailOptOutController.php`).

---

## 5. Come partire

### Requisiti
PHP 8.3+ (consigliato 8.4) con `pdo_mysql`, `mbstring`, `zip`, `gd`, `intl` · MySQL 8 · Composer 2 · Node.js 20+ · `mysqldump` nel PATH (per i backup).

### Installazione

```bash
git clone <repository> gestionale-associazioni
cd gestionale-associazioni

composer install
cp .env.example .env
php artisan key:generate

# configura DB_* nel .env, poi:
php artisan migrate --seed        # schema + ruoli/permessi + dati demo
npm install && npm run build      # asset frontend (Vite)
php artisan storage:link
```

Utenti demo creati dal seeder: `superadmin@example.com` (super_admin), `admin@associazione-demo.test` (admin_associazione), `operatore@associazione-demo.test` (operatore).

### Import dei dati reali dal vecchio DB (`app:import-legacy`)

Lo schema nuovo è compatibile 1:1 con quello legacy (stesse tabelle, stessi UUID CHAR(36), fonte di verità `docs/schema-reale-mysql.sql`). Configura nel `.env` le variabili della connessione legacy:

```dotenv
LEGACY_DB_HOST=127.0.0.1
LEGACY_DB_PORT=3306
LEGACY_DB_DATABASE=associazione_soci_saas
LEGACY_DB_USERNAME=...
LEGACY_DB_PASSWORD=...
```

Poi:

```bash
php artisan app:import-legacy --dry-run                       # anteprima senza scrivere nulla
php artisan app:import-legacy                                 # import completo (28 tabelle, ordine FK-safe)
php artisan app:import-legacy --tables=soci,tessere --force   # solo alcune tabelle, anche se non vuote
php artisan app:import-legacy --chunk=1000                    # dimensione blocchi di insert
```

Il comando (`app/Console/Commands/ImportLegacy.php`) copia riga per riga le colonne comuni alle due tabelle, salta le tabelle di destinazione non vuote (salvo `--force`) e sincronizza i ruoli spatie/permission con la colonna `utenti.ruolo`. Dopo l'import: `php artisan scout:import "App\Models\Socio"` per indicizzare la ricerca.

### Servizi runtime (obbligatori in produzione)

```bash
php artisan queue:work --tries=3        # worker coda (email, job) — tenerlo vivo con supervisor/systemd
```

```cron
* * * * * cd /percorso/app && php artisan schedule:run >> /dev/null 2>&1
```

Dopo ogni deploy: `php artisan schedule-monitor:sync`. In sviluppo basta `composer dev` (serve + queue + log + Vite in parallelo).

### Verifiche

```bash
php artisan test                               # suite completa (SQLite in-memory)
vendor/bin/pint --test                         # code style
vendor/bin/phpstan analyse --memory-limit=1G   # analisi statica livello 5
curl http://localhost:8000/health              # health check
```

---

## 6. Punti aperti e limitazioni note

1. **Tabella `payment_gateway_settings` assente dal dump legacy** — presente nel codice della vecchia app (commit pagamenti Stripe/PayPal) ma non migrata nel DB di produzione: `app:import-legacy` la salta se non esiste nel DB sorgente; le credenziali gateway vanno riconfigurate da Configurazioni → Pagamenti.
2. **Scout con driver `database`** — la "ricerca full-text" usa il driver database di Scout (sufficiente per i volumi attuali); per dataset molto grandi conviene passare a Meilisearch/Typesense (solo configurazione, `config/scout.php`).
3. **Coda con driver `database`** — scelta deliberata per compatibilità shared hosting; con volumi email elevati valutare Redis + Horizon.
4. **2FA disponibile ma non obbligatoria** — gli admin possono attivarla dal profilo; non è imposta a livello di policy.
5. **Webhook Stripe/PayPal testati con gateway simulati** — i test (`tests/Feature/quote_pagamenti/Webhook*.php`) mockano le chiamate esterne; prima del go-live serve una prova end-to-end in sandbox con chiavi reali e URL webhook pubblici.
6. **Email in produzione** — l'invio reale dipende dalle impostazioni SMTP per-tenant (modulo `impostazioni-smtp`); in assenza di configurazione la coda accumula `pending`.
7. **Baseline Larastan** — `phpstan-baseline.neon` contiene poche eccezioni residue (19 righe) da azzerare progressivamente.
8. **`route:list --columns` rimosso in Laravel 12** — l'opzione di scripting legacy non esiste più; usare `php artisan route:list` (eventualmente `--json` per output machine-readable).
9. **Import legacy non incrementale** — `app:import-legacy` è pensato per il cutover una tantum (tabelle vuote o `--force`); non fa sync bidirezionale né merge di dati divergenti.
10. **PWA** — manifest e service worker sono portati in `public/`; le funzionalità offline avanzate vanno verificate sul dominio finale (richiedono HTTPS).
