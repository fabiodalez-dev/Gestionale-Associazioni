# Gestionale Associazioni

Piattaforma **SaaS multi-tenant** per la gestione completa della vita di un'associazione: soci e tesseramento, eventi con prenotazioni online, quote e pagamenti, registro ufficiale e governance, comunicazione, economia, volontari e organizzazione interna. Ogni associazione ha il proprio mini-sito pubblico brandizzato dove i cittadini si iscrivono e prenotano gli eventi.

Costruita in **Laravel 13 / PHP 8.5**, con isolamento multi-tenant a livello di framework, un'area pubblica per ogni associazione, un'area riservata per i soci, API REST documentate e oltre **1.700 test automatici** (PHPUnit + Playwright E2E).

![Dashboard](docs/screenshots/dashboard.png)

---

## Indice

- [Caratteristiche principali](#caratteristiche-principali)
- [Screenshot](#screenshot)
- [Funzionalità in dettaglio](#funzionalità-in-dettaglio)
- [Architettura](#architettura)
- [Stack e librerie](#stack-e-librerie)
- [Requisiti](#requisiti)
- [Installazione](#installazione)
- [Aggiornamenti in-app](#aggiornamenti-in-app)
- [Test](#test)
- [Licenza](#licenza)

---

## Caratteristiche principali

- 🏢 **Multi-tenant** — una sola installazione serve molte associazioni, con isolamento dei dati garantito da un global scope a livello di framework.
- 🌐 **Mini-sito pubblico per associazione** — catalogo eventi, iscrizione soci, prenotazioni, area riservata, pagina trasparenza, sezione sostenitori, a `/a/{slug}`.
- 🎫 **Tesseramento** — tessere con QR, verifica pubblica, rinnovi self-service, campagna rinnovi, stampa massiva, apertura anno sociale.
- 🗓️ **Eventi e prenotazioni** — pubblicazione, capienza con lista d'attesa e promozione automatica, eventi a pagamento (Stripe/PayPal), check-in con QR, calendario, ricorrenze, feedback post-evento.
- 👤 **Account ospite e soci** — registrazione pubblica, verifica email, area riservata ricca (prenotazioni, tessera, quote, GDPR), promozione a socio.
- 💶 **Economia** — quote e ricevute numerate, prima nota con movimenti automatici, donazioni online, report economici, budget eventi.
- 📋 **Registro e governance** — libro soci ufficiale, cessazioni formali, organi sociali, verbali numerati, riunioni del direttivo con votazioni e bozza verbale.
- 🤝 **Organizzazione** — volontari e turni con registro ore, spazi e prenotazioni interne, inventario e prestiti, progetti con rendicontazione.
- ✉️ **Comunicazione** — email brandizzate per tenant, template, newsletter, annunci, convocazioni assemblea, sondaggi, compleanni.
- 🔐 **Sicurezza** — 2FA, ruoli e permessi granulari, audit trail, rate limiting, CSP, honeypot, backup pianificati.
- 🔄 **Aggiornamenti dalla dashboard** — il super admin aggiorna l'applicazione con un clic, con backup automatico e rollback.

---

## Screenshot

### Dashboard amministrativa
![Dashboard](docs/screenshots/dashboard.png)

### Anagrafica soci
![Soci](docs/screenshots/soci.png)

### Eventi
![Eventi](docs/screenshots/eventi.png)

### Statistiche annuali (l'anno in cifre)
![Statistiche annuali](docs/screenshots/statistiche.png)

### Prima nota economica
![Prima nota](docs/screenshots/prima-nota.png)

### Mini-sito pubblico dell'associazione
![Frontend pubblico](docs/screenshots/frontend-eventi.png)

### Login
![Login](docs/screenshots/login.png)

---

## Funzionalità in dettaglio

Il sistema è stato costruito in round successivi. Ogni area è coperta da test automatici.

### Base — gestione associativa
Anagrafica soci con campi personalizzati, tag, gruppi dinamici, filtri salvati, ricerca full-text, import/export Excel. Tessere con QR, template, PDF, verifica pubblica e scanner. Quote e pagamenti online (Stripe/PayPal). Eventi e partecipanti. Email per tenant (template, coda, log, SMTP dedicato). Documenti, verbali, scadenze. Gestione associazioni, sedi, amministratori, configurazioni. API REST v1 con Sanctum e documentazione OpenAPI.

### Prenotazioni eventi (frontend pubblico)
Mini-sito brandizzato per ogni associazione: catalogo eventi, **account ospite** con verifica email, prenotazioni con **capienza e lista d'attesa** (promozione FIFO automatica), **eventi a pagamento** con Stripe/PayPal, **biglietto con QR** e PDF, **check-in** dallo scanner admin. Area riservata account completa: prenotazioni, storico, profilo, preferenze, **export e cancellazione GDPR**.

### Iscrizioni e preiscrizioni
**Form di iscrizione personalizzabile** per ogni associazione (campi configurabili, validazione codice fiscale e telefono), aperto anche agli **ospiti senza account**. Modalità preiscrizione (con approvazione) o diretta. Preiscrizione anche alle prenotazioni evento. Gestione delle richieste lato admin con approvazione, tessera e quota automatiche.

### Vita sociale (25 funzionalità)
Rinnovi self-service, campagna rinnovi, promemoria multi-step, apertura anno sociale. Calendario eventi con export ICS, eventi ricorrenti, feedback post-evento, eventi riservati ai soci. **Libro soci ufficiale**, cessazioni formali, organi sociali, verbali numerati. Layout email brandizzato, ricevute quote PDF, centro anteprime email. Slug editabile con QR di invito, documenti con scadenza, rilevazione duplicati, export multi-foglio.

### Gestione semplificata (25 funzionalità)
**Ricerca globale ⌘K**, azioni in blocco sui soci, note interne, attività admin, registro modifiche. Bacheca annunci, newsletter, sondaggi, compleanni, contatti rapidi. **Prima nota** con movimenti automatici da quote e prenotazioni, report economico, **donazioni online**, solleciti quote, scadenzario unificato. Richieste modifica dati, nuclei familiari, **kiosk check-in**, soci minorenni con tutore. Onboarding, cestino unificato, audit accessi.

### Organizzazione (25 funzionalità)
**Volontari e turni** con disponibilità, mansioni, auto-candidatura, registro ore e attestato PDF. **Spazi** con prenotazioni e rilevazione conflitti, **inventario e prestiti** attrezzature. **Progetti** con milestone, rendicontazione e report PDF. **Riunioni del direttivo** con ordine del giorno, votazioni e generazione bozza verbale, archivio decisioni, adempimenti ricorrenti. **Mini-CRM** contatti esterni, sponsor con sezione pubblica, **referral porta-un-amico**, galleria foto eventi, **statistiche annuali** con confronto anno-su-anno ed export PDF.

### Convocazioni e trasparenza
Annunci di tipo **convocazione assemblea** (data, luogo, ordine del giorno, invio email ai soci) e sezione pubblica **Trasparenza** dove pubblicare bilanci, statuto e documenti per categoria.

---

## Architettura

| Aspetto | Implementazione |
|---|---|
| **Multi-tenancy** | Trait `BelongsToAssociazione` → global scope su `associazione_id`; middleware `tenant`; il super_admin opera sull'associazione selezionata |
| **Chiavi** | UUID (`HasUuids`) su tutte le entità di dominio |
| **Autenticazione** | Fortify + 2FA per gli admin (`utenti`); guard `socio` per l'area soci; guard `account` per il frontend pubblico; Sanctum per le API |
| **Autorizzazione** | Policy + permessi granulari spatie/permission (ruoli `super_admin`, `admin_associazione`, `operatore`) |
| **Audit** | spatie/activitylog su tutti i modelli |
| **Frontend pubblico** | Per associazione su `/a/{slug}`, risolto da `RisolviTenantPubblico` senza sessione admin |
| **Pagamenti** | Interfaccia `Pagabile` (quote, prenotazioni, donazioni) con dispatch webhook per tipo |
| **Code & scheduler** | Driver `database`; scheduler in `routes/console.php` monitorato |

Convenzioni: rotte per modulo in `routes/modules/`, controller in `app/Http/Controllers/<Modulo>/`, validazione via Form Request, viste sul design system condiviso (Bootstrap 5.3, tema chiaro/scuro, brand `#FF7B11`).

A colpo d'occhio: **68 modelli**, **159 controller**, **232 rotte**, **58 migration**, **1.716 test**.

---

## Stack e librerie

**Laravel 13 · PHP 8.3–8.5 · MySQL 8** (test su SQLite in-memory).

Le 25 librerie principali: Fortify, Sanctum, Scout, spatie (permission, activitylog, backup, medialibrary, query-builder, data, settings, csp, honeypot, health, schedule-monitor), maatwebsite/excel, barryvdh/laravel-dompdf, chillerlan/php-qrcode, propaganistas/laravel-phone, robertogallea/laravel-codicefiscale, mews/purifier, dedoc/scramble, stripe/stripe-php, srmklive/paypal, larastan, debugbar.

---

## Requisiti

- PHP **8.3+** (consigliato 8.5) con `pdo_mysql`, `mbstring`, `zip`, `gd`, `intl`, `curl`, `openssl`
- **MySQL 8** (o MariaDB compatibile)
- Composer 2, Node.js 20+ e npm
- `mysqldump` nel PATH (per i backup)

---

## Installazione

### Installer web (consigliato)

```bash
git clone https://github.com/fabiodalez-dev/gestionale-associazioni.git
cd gestionale-associazioni
composer install --no-dev
cp .env.example .env
```

Apri **`/install`** nel browser (in sviluppo con `php artisan serve --no-reload`: il wizard scrive il `.env` e senza quell'opzione il server di sviluppo si riavvierebbe a metà richiesta): il wizard in 5 passi (requisiti → database → super admin → email → fine) genera l'`APP_KEY`, scrive il `.env`, inietta lo schema del database vuoto nel database indicato e crea il primo super admin. Al termine l'installer si blocca da solo.

Server supportati: MySQL 5.7.8 o successivo, MariaDB 10.2.7 o successivo (colonne `json` e collation `utf8mb4_unicode_ci`); il wizard rifiuta versioni più vecchie. Lo schema non viene creato eseguendo le migration a runtime ma importando `database/installer/schema.sql`, un dump del database vuoto (struttura + registro delle migration già applicate) eseguito istruzione per istruzione via PDO: funziona anche sugli hosting condivisi senza `exec()` né client `mysql`, è veloce e produce sempre lo stesso risultato. Le tabelle dell'applicazione già presenti nel database vengono ricreate vuote solo dietro conferma esplicita; le tabelle estranee restano intatte. Dopo l'installazione `php artisan migrate` (anche quello dell'aggiornamento in-app) applica soltanto le migration nuove.

#### Rigenerare lo schema dell'installer

Dopo ogni nuova migration va rigenerato il file, altrimenti il test `SchemaSqlTest` fallisce:

```bash
php artisan installer:genera-schema
```

Il comando crea un database temporaneo `gestione_associazioni_schema_tmp` con le credenziali MySQL del `.env`, ci esegue tutte le migration, scrive il dump e cancella il database temporaneo. Per provare l'iniezione su un MySQL reale:

```bash
INSTALLER_TEST_MYSQL_DSN="mysql://root:segreta@127.0.0.1:3306/gestione_installer_test" php artisan test --filter=InstallaSchemaMysqlTest
```

### Installazione manuale (CLI)

```bash
composer install
cp .env.example .env
php artisan key:generate
# configura DB_* nel .env
php artisan migrate --seed
npm install && npm run build
php artisan storage:link
```

### Avvio

```bash
php artisan serve            # applicazione
php artisan queue:work       # coda email e job (obbligatorio)
# cron: * * * * * php artisan schedule:run
```

---

## Aggiornamenti in-app

Il **super admin** trova in `/aggiornamenti` una pagina che controlla le GitHub Releases e mostra versione installata vs ultima disponibile. Con un clic l'applicazione si aggiorna da sola: backup automatico del database, download della release con verifica SHA256, modalità manutenzione, sovrascrittura dei file (preservando `.env` e `storage/`), `migrate`, pulizia cache e riavvio della coda — con **rollback automatico** dei file in caso di errore e storico completo degli aggiornamenti.

---

## Test

```bash
php artisan test            # ~1.716 test PHPUnit (SQLite in-memory)
npm run test:e2e            # 109 test E2E Playwright (DB dedicato, server automatico)
vendor/bin/pint             # code style
vendor/bin/phpstan analyse --memory-limit=1G   # analisi statica livello 5
```

---

## Licenza

[MIT](LICENSE).
