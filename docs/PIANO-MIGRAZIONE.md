# Piano di Migrazione — Gestione Associazioni → Laravel 12

## Obiettivo
Trasformare l'app PHP procedurale `gestione-associazioni` in un'applicazione Laravel 12 completa,
con le stesse funzionalità e 25 miglioramenti, mantenendo la compatibilità con i dati esistenti
(stesse tabelle concettuali, chiavi UUID CHAR(36), multi-tenant per `associazione_id`).

**Fonte di verità per lo schema dati**: `docs/schema-reale-mysql.sql` (dump dal MySQL di produzione locale,
DB `associazione_soci_saas`), NON il vecchio `database/schema.sql`. Unica eccezione:
`payment_gateway_settings`, presente nel codice (commit pagamenti Stripe/PayPal) ma non ancora migrata nel DB.

## Architettura di destinazione

- **Laravel 12** · PHP 8.4 · MySQL 8 (sviluppo/test: SQLite in-memory per i test)
- **Multi-tenancy leggera**: trait `BelongsToAssociazione` + global scope su `associazione_id`,
  middleware `EnsureTenant`; il super_admin può cambiare tenant.
- **UUID**: trait `HasUuids` di Laravel su tutti i modelli (compatibile CHAR(36) esistente).
- **Auth**: Fortify (login, 2FA, reset password) per gli admin (`utenti`), guard separata `socio`
  per l'area soci (tabella `soci`), Sanctum per le API (in affiancamento alle `api_keys` legacy).
- **Autorizzazione**: spatie/laravel-permission (ruoli: super_admin, admin_associazione, operatore) + Policies.
- **Viste**: Blade + Bootstrap 5.3 (porting diretto del layout esistente, sidebar inclusa), brand #FF7B11.
- **Code/Queue**: driver `database` (compatibile shared hosting) per email e job pesanti.
- **Scheduler**: `routes/console.php` sostituisce i cron manuali (`cron/*.php`).

## Mappa moduli (vecchio → nuovo)

| Vecchio | Nuovo (Laravel) |
|---|---|
| `config.php` (helpers, PDO, sessioni, CSRF) | config/*, middleware, helpers nativi (CSRF nativo Blade) |
| `auth/login.php`, `auth/logout.php` | Fortify + controller dedicati |
| `pages/dashboard.php` | `DashboardController` + widget |
| `pages/soci.php`, `socio_dettaglio.php` | `SocioController` (resource), ricerca Scout, filtri salvati |
| `pages/tipi-socio.php`, `categorie-socio.php` | `TipoSocioController`, `CategoriaSocioController` |
| `pages/config_campi.php`, `config_tags.php`, `config_gruppi.php` | Campi personalizzati, Tag, Gruppi dinamici |
| `pages/tessere.php`, `genera-tessera-pdf.php`, `stampa-tessera.php` | `TesseraController`, PDF via dompdf, QR via chillerlan |
| `verifica-tessera.php`, `scanner-tessera.php`, `checkin.php` | Rotte pubbliche verifica + scanner + check-in eventi |
| `pages/quote.php`, `pagamento.php`, `PaymentService`, webhooks | `QuotaController`, `PaymentService` (Stripe + PayPal srmklive), webhook controller |
| `pages/eventi.php`, `partecipanti.php` | `EventoController`, `PartecipanteController` |
| `pages/comunicazioni.php`, email_* (templates, coda, log, impostazioni) | Mailables + coda database, editor template, log |
| `pages/documenti.php`, `verbali.php`, `scadenze.php` | MediaLibrary per documenti, `VerbaleController`, `ScadenzaController` |
| `pages/associazioni.php`, `sezioni.php`, `amministratori.php`, `configurazioni.php` | Gestione tenant, sedi, utenti admin, settings (spatie/laravel-settings) |
| `pages/api_keys.php`, `api_docs.php`, `api/v1/*` | API v1 con Sanctum + api_keys legacy, docs auto con Scramble |
| `api/*.php` (search, export, notifications, backup) | Endpoint interni JSON + spatie/laravel-backup |
| `area-soci/*` | Guard `socio`, portale soci completo |
| `preiscrizione.php`, `privacy-policy.php` | Rotte pubbliche con honeypot anti-spam |
| `cron/*.php` | Scheduler Laravel (`schedule:run`) monitorato da schedule-monitor |
| PWA (`manifest.json`, `service-worker.js`, offline) | Portati in `public/` |

## Le 25 librerie Laravel

| # | Pacchetto | Scopo |
|---|---|---|
| 1 | laravel/fortify | Autenticazione backend + 2FA TOTP |
| 2 | laravel/sanctum | Token API personali e SPA auth |
| 3 | laravel/scout | Ricerca full-text soci (driver database) |
| 4 | spatie/laravel-permission | Ruoli e permessi granulari |
| 5 | spatie/laravel-activitylog | Audit trail automatico su tutti i modelli |
| 6 | spatie/laravel-backup | Backup DB + file, pianificati e scaricabili |
| 7 | spatie/laravel-medialibrary | Gestione documenti/upload con conversioni |
| 8 | spatie/laravel-query-builder | Filtri/sort/include standard per le API |
| 9 | spatie/laravel-data | DTO tipizzati richiesta/risposta |
| 10 | spatie/laravel-settings | Impostazioni tipizzate per tenant (SMTP, gateway, ecc.) |
| 11 | spatie/laravel-csp | Content-Security-Policy headers |
| 12 | spatie/laravel-honeypot | Anti-spam form pubblici (preiscrizione) |
| 13 | spatie/laravel-health | Health check (DB, coda, scheduler, disco) |
| 14 | spatie/laravel-schedule-monitor | Monitoraggio esecuzioni scheduler |
| 15 | maatwebsite/excel | Import/export Excel e CSV dei soci |
| 16 | barryvdh/laravel-dompdf | PDF tessere, ricevute, verbali |
| 17 | chillerlan/php-qrcode | QR code tessere e check-in |
| 18 | propaganistas/laravel-phone | Validazione numeri di telefono |
| 19 | robertogallea/laravel-codicefiscale | Validazione codice fiscale italiano |
| 20 | mews/purifier | Sanitizzazione HTML (template email, privacy policy) |
| 21 | dedoc/scramble | Documentazione OpenAPI automatica delle API |
| 22 | stripe/stripe-php | Pagamenti Stripe (checkout quote) |
| 23 | srmklive/paypal | Pagamenti PayPal (SDK Laravel) |
| 24 | larastan/larastan (dev) | Analisi statica livello 5+ |
| 25 | barryvdh/laravel-debugbar (dev) | Profiling in sviluppo |

(In più, di serie: laravel/pint per il code style e PHPUnit per i test.)

## Fasi del workflow

1. **Studio** — agenti paralleli analizzano l'app vecchia per area funzionale e scrivono spec in `docs/specs/`.
2. **Fondamenta** — migrations (da `docs/schema-reale-mysql.sql`), modelli con relazioni/scope tenant, factories, seeders.
3. **Layout & Auth** — layout Blade + sidebar, Fortify, guard socio, middleware tenant, permission.
4. **Moduli** — agenti paralleli: soci, tessere, quote/pagamenti, eventi, email, documenti/verbali/scadenze, struttura/admin, area soci, API v1, dashboard.
5. **Trasversali** — scheduler, backup, health, PWA, API docs, seeders demo.
6. **Verifica** — `php artisan test`, pint, larastan; cicli di fix fino a verde.

## Convenzioni anti-conflitto per gli agenti

- Ogni modulo registra le rotte in `routes/modules/<modulo>.php` (incluse da `web.php`).
- Viste in `resources/views/<modulo>/`, controller in `app/Http/Controllers/<Modulo>/`.
- Nessun agente modifica file fuori dal proprio modulo, tranne dove esplicitamente indicato.
