# Gestionale Associazioni

Piattaforma **multi-tenant** per la gestione completa della vita di un'associazione: soci e tesseramento, iscrizioni dal sito, quote e pagamenti, eventi con prenotazioni e biglietti, economia, registro e organi sociali, comunicazione, volontari, spazi, progetti e riunioni. Una sola installazione serve molte associazioni; ognuna ha il proprio mini-sito pubblico su `/a/{slug}` dove i cittadini si iscrivono, prenotano e pagano, e un'area riservata per i soci.

Costruita con **Laravel 13 / PHP 8.4+**. Si installa anche su un **hosting condiviso senza terminale**: si carica il pacchetto via FTP, si apre il sito e un wizard fa il resto. Gli aggiornamenti si applicano **dalla dashboard**, con backup e rollback, sul modello dell'updater di [Pinakes](https://github.com/fabiodalez-dev/Pinakes).

![Dashboard](docs/screenshots/dashboard.png)

---

## Indice

- [Caratteristiche principali](#caratteristiche-principali)
- [Screenshot](#screenshot)
- [Funzionalità in dettaglio](#funzionalità-in-dettaglio)
- [Requisiti](#requisiti)
- [Installazione](#installazione)
- [Aggiornamenti](#aggiornamenti)
- [Pubblicare una versione](#pubblicare-una-versione)
- [Architettura](#architettura)
- [Sviluppo e test](#sviluppo-e-test)
- [Sicurezza e privacy](#sicurezza-e-privacy)
- [Licenza](#licenza)

---

## Caratteristiche principali

- 🏢 **Multi-tenant** — isolamento dei dati per associazione a livello di framework; il super admin passa da un'associazione all'altra; le associazioni possono iscriversi da sole alla piattaforma (con conferma email e approvazione).
- 🌐 **Mini-sito pubblico per associazione** — catalogo eventi, iscrizione soci con modulo configurabile, prenotazioni, donazioni, trasparenza, sostenitori, galleria, calendario ICS.
- 📝 **Iscrizioni** — modulo pubblico costruito dall'associazione (campi standard e personalizzati, consensi, testi), richieste da approvare con tutti i dati della persona in vista, modalità diretta o con approvazione, preiscrizione via QR.
- 🎫 **Tesseramento** — tessere con QR e PDF, verifica pubblica, check-in con scanner e kiosk, rinnovi self-service, campagna rinnovi, apertura anno sociale, stampa massiva.
- 💶 **Quote e pagamenti** — quote per tipo socio, link di pagamento, Stripe e PayPal con credenziali per associazione, ricevute numerate, solleciti, prima nota automatica.
- 🗓️ **Eventi** — capienza e lista d'attesa con promozione automatica, approvazione, eventi a pagamento, biglietti con QR, presenze, serie ricorrenti, feedback, galleria, budget e spese.
- 📋 **Registro e governance** — libro soci ufficiale (PDF/Excel), cessazioni, organi sociali e cariche, riunioni del direttivo con votazioni e verbale, assemblee dei soci con aventi diritto, appello, quorum, deleghe, voto online ed elezioni, verbali firmati, decisioni, adempimenti, scadenzario.
- 🤝 **Organizzazione** — volontari con turni, candidature e registro ore con attestato; spazi con prenotazioni e conflitti; attrezzature e prestiti; progetti con milestone e report; CRM a colonne, contatti esterni, sponsor, referral.
- ✉️ **Comunicazione** — email brandizzate con SMTP per associazione, coda e log, template, newsletter con opt-out, annunci e convocazioni, sondaggi, compleanni, notifiche.
- 👤 **Account e area soci** — registrazione con verifica email, area riservata (tessera, quote, prenotazioni, dati personali), richieste di modifica dati, export e cancellazione GDPR.
- 🔐 **Sicurezza** — 2FA, ruoli e permessi per modulo, perimetro per sede, audit di accessi e modifiche, blocco tentativi, CSP, honeypot, backup pianificati, API REST documentate con token.
- 📦 **Installazione e aggiornamento senza terminale** — pacchetto di release con tutto dentro, wizard web che inietta lo schema SQL nel database vuoto, aggiornamenti dalla dashboard con verifica del pacchetto, backup e rollback.

---

## Screenshot

### Installazione guidata
Requisiti del server e configurazione del database: lo schema viene iniettato nel database indicato, senza terminale.

![Installer: requisiti](docs/screenshots/installer-requisiti.png)
![Installer: database](docs/screenshots/installer-database.png)

### Soci e richieste di iscrizione
![Soci](docs/screenshots/soci.png)
![Richieste iscrizione](docs/screenshots/richieste-iscrizione.png)

### Tessere, quote e rinnovi
![Tessere](docs/screenshots/tessere.png)
![Quote e pagamenti](docs/screenshots/quote.png)
![Campagna rinnovi](docs/screenshots/campagna-rinnovi.png)

### Eventi e calendario
![Eventi](docs/screenshots/eventi.png)
![Calendario](docs/screenshots/calendario.png)

### Economia e statistiche
![Prima nota](docs/screenshots/prima-nota.png)
![Statistiche annuali](docs/screenshots/statistiche.png)

### Organizzazione
![Riunioni del direttivo](docs/screenshots/riunioni.png)
![Volontari e turni](docs/screenshots/volontari-turni.png)
![CRM a colonne](docs/screenshots/crm.png)

### Configurazione e modulo di iscrizione
![Configurazioni](docs/screenshots/configurazioni.png)
![Costruttore del modulo di iscrizione](docs/screenshots/modulo-iscrizione.png)

### Mini-sito pubblico
![Home pubblica](docs/screenshots/frontend-home.png)
![Eventi pubblici](docs/screenshots/frontend-eventi.png)
![Modulo di iscrizione pubblico](docs/screenshots/frontend-iscriviti.png)

### Aggiornamenti dalla dashboard
![Aggiornamenti](docs/screenshots/aggiornamenti.png)

---

## Funzionalità in dettaglio

### Soci
Anagrafica completa con stati (in attesa, attivo, sospeso, radiato…), tipi e categorie, sedi, tag, gruppi dinamici, filtri salvati, ricerca globale ⌘K, note interne, storico attività, documenti del socio, foto. **Campi personalizzati** di nove tipi (testo, email, telefono, numero, data, url, area di testo, scelta, casella) con validazione per tipo, obbligatorietà separata per segreteria e modulo pubblico, modificabili anche con dati già inseriti. Import CSV con report degli errori, export CSV/Excel, rilevazione e unione dei duplicati, azioni in blocco, nuclei familiari, soci minorenni con tutore, cestino con ripristino, registro delle modifiche.

### Iscrizioni dal sito
Ogni associazione costruisce il proprio modulo (`/a/{slug}/iscriviti`): campi standard da mostrare o nascondere, campi personalizzati, obbligatorietà, testi e consensi. La richiesta crea un socio preiscritto e arriva agli admin con tutti i dati della persona in vista; approvazione con delibera, tessera e quota in un clic (email di benvenuto con link di pagamento), oppure rifiuto motivato. Modalità diretta senza approvazione. Preiscrizione da QR di invito, anche senza account. Richieste di modifica dati dall'area soci con approvazione.

### Tessere e rinnovi
Tessere con numero progressivo, QR e PDF da template configurabile, verifica pubblica (HTML e JSON), scanner e kiosk per il check-in, tessere automatiche all'approvazione. Rinnovo dall'area soci con pagamento online, campagna rinnovi con email personalizzate e promemoria multi-step, apertura del nuovo anno sociale con generazione delle quote, stampa massiva, storico tesseramento esportabile.

### Quote e pagamenti
Quote per anno e tipo socio, pagamento registrato in sede o online (Stripe, PayPal, credenziali per associazione), link pubblico di pagamento, ricevute PDF numerate, solleciti ai morosi con tracciamento, webhook idempotenti. Ogni incasso finisce in prima nota.

### Eventi
Eventi pubblici o riservati ai soci, bozze, capienza con **lista d'attesa e promozione automatica**, prenotazioni con o senza approvazione, eventi a pagamento con conferma offline o online, biglietti PDF con QR e check-in la sera dell'evento (doppio passaggio rifiutato), partecipanti e foglio presenze, serie ricorrenti modificabili in blocco, feedback post-evento solo a chi ha partecipato, galleria pubblica o privata, budget e spese, sincronizzazione con Google Calendar, export ICS.

### Economia
Prima nota con categorie e movimenti automatici da quote, prenotazioni e donazioni, report economico per anno con export, donazioni dal sito pubblico, scadenzario unificato, solleciti.

### Registro e governance
Libro soci ufficiale in PDF ed Excel, cessazioni formali con delibera, organi sociali e cariche con mandati in scadenza, riunioni del direttivo (convocazione, presenze, votazioni, bozza del verbale), **assemblee dei soci** (elenco degli aventi diritto con quota pagata, appello con lettura della tessera, quorum in prima e seconda convocazione, maggioranze per punto con esito calcolato, deleghe dall'area soci o dalla segreteria con limite per delegato, voto online dallo smartphone palese o segreto anche per i deleganti, partecipazione a distanza), **elezioni** degli organi (candidature dall'area soci, scheda segreta con preferenze, scrutinio con schede cartacee e regola di parità, proclamazione che aggiorna le cariche), verbali numerati con firmatari e approvazione, archivio delle decisioni, adempimenti ricorrenti con promemoria, documenti con cestino e link temporanei, sezione pubblica **Trasparenza**.

### Organizzazione
Volontari con mansioni, disponibilità, turni a posti limitati, candidature dall'area soci, conferme, presenze, registro ore e attestato PDF. Spazi con prenotazioni e rilevazione dei conflitti. Attrezzature con prestiti e restituzioni. Progetti con milestone, avanzamento e report PDF. CRM a colonne (kanban), contatti esterni, sponsor con sezione pubblica, referral porta-un-amico, statistiche annuali con confronto e PDF.

### Comunicazione
SMTP per associazione, layout email brandizzato, template modificabili, centro anteprime, coda con retry e log, notifiche di piattaforma. Annunci in bacheca, convocazioni di assemblea con email ai soci, **proposte dei soci** con raccolta di adesioni, soglia configurabile e inserimento nell'ordine del giorno, newsletter con anteprima e opt-out (doppio), sondaggi votabili dall'area soci e dagli account, auguri di compleanno, contatti rapidi.

### Piattaforma e amministrazione
Registrazione autonoma delle associazioni (conferma email, revisione del super admin), sedi e **perimetro per sede** (operatori che vedono solo la propria sede), amministratori su invito, ruoli dell'associazione (presidente, tesoriere, segretario…) con matrice dei permessi, configurazioni (dati, logo, tesseramento, campi obbligatori, pagamenti, email, moduli pubblici), impostazioni di sistema, audit accessi, job falliti, cestino unificato, health check, backup pianificati, API REST v1 (Sanctum e chiavi API) con documentazione OpenAPI, app installabile (PWA).

---

## Requisiti

- PHP **8.4 o successivo** con `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `curl`, `fileinfo`, `gd`, `zip`, `intl`
- **MySQL 5.7.8+** o **MariaDB 10.2.7+** (colonne `json`, collation `utf8mb4_unicode_ci`); consigliato MySQL 8
- Un cron ogni minuto (vedi sotto)
- Solo per lavorare sui sorgenti: Composer 2, Node.js 22 e npm

---

## Installazione

### Su hosting condiviso, senza terminale (consigliato)

1. Scarica `gestionale-associazioni-vX.Y.Z.zip` dall'ultima [release](https://github.com/fabiodalez-dev/Gestionale-Associazioni/releases): contiene già tutto (`vendor/`, asset compilati), non serve né Composer né npm.
2. Estrai lo zip: trovi una cartella `gestionale-associazioni/`. Carica via FTP o File Manager **il contenuto** di quella cartella in `public_html` (l'app risponde su `https://tuodominio.it`), oppure l'intera cartella (risponde su `https://tuodominio.it/gestionale-associazioni`).
3. Nel pannello dell'hosting seleziona **PHP 8.4 o successivo** e crea un database MySQL con un utente.
4. Apri il sito nel browser: parte il wizard `/install`, che crea `.env` e chiave dell'applicazione, verifica i requisiti, inietta lo schema nel database e crea la prima associazione con il super admin.
5. Alla fine il wizard mostra il comando per il cron (cPanel → Cron Jobs, ogni minuto): manda le email in coda, i promemoria e i backup.

Il `.htaccess` nella radice fa passare tutte le richieste da `public/`; `app/`, `storage/`, `vendor/` e `.env` non sono raggiungibili dal browser e ogni cartella interna ha il proprio `.htaccess` di diniego, valido anche senza mod_rewrite (in quel caso l'app risponde su `/public/`). Se puoi scegliere la document root, puntala a `public/`.

Lo schema non viene creato eseguendo le migration a runtime ma importando `database/installer/schema.sql`, un dump del database vuoto (struttura più registro delle migration) eseguito istruzione per istruzione via PDO: funziona anche senza `exec()` né client `mysql`, in pochi secondi, sempre uguale. Le tabelle dell'applicazione già presenti vengono ricreate vuote solo dietro conferma esplicita; un database con un'installazione completa non viene mai toccato dal wizard; le tabelle estranee restano intatte. Dettagli in [docs/INSTALLER.md](docs/INSTALLER.md).

### Da sorgenti

```bash
git clone https://github.com/fabiodalez-dev/Gestionale-Associazioni.git
cd Gestionale-Associazioni
composer install --no-dev
npm install && npm run build
```

Apri `/install` nel browser (in sviluppo con `php artisan serve --no-reload`: il wizard scrive il `.env` e senza quell'opzione il server di sviluppo si riavvierebbe a metà richiesta).

In alternativa, installazione manuale da terminale:

```bash
composer install
cp .env.example .env
php artisan key:generate
# configura DB_* nel .env
php artisan migrate --seed
npm install && npm run build
php artisan storage:link
```

### Avvio e attività in background

```bash
php artisan serve            # applicazione
php artisan queue:work       # coda email e job
# cron: * * * * * php artisan schedule:run
```

Su hosting condiviso, dove un worker permanente non si può tenere acceso, basta un cron ogni minuto:

```bash
cd /home/utente/public_html && php artisan schedule:run >> /dev/null 2>&1 && php artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1
```

### Comandi utili

```bash
php artisan app:disinstalla          # backup, poi svuota database e file caricati e riapre l'installer
php artisan admin:recupera-accesso   # recupero dell'accesso di un amministratore
php artisan installer:genera-schema  # rigenera database/installer/schema.sql dopo una migration
```

---

## Aggiornamenti

Il **super admin** trova in `/aggiornamenti` la versione installata e l'ultima release GitHub, i requisiti per aggiornare (PHP, zip, cartelle scrivibili, spazio libero, prova di scrittura reale), il pulsante di aggiornamento, il caricamento di un pacchetto per i server che non raggiungono GitHub, i backup scaricabili e lo storico.

L'aggiornamento, sul modello dell'updater di Pinakes: lock esclusivo, backup del database (spatie/laravel-backup, altrimenti dump in PHP puro se manca `mysqldump`), download del pacchetto della release verificato con il **digest sha256 dell'API GitHub** (fail-closed), estrazione con controllo anti zip-slip, prova a secco dei permessi prima di toccare un byte, manutenzione, backup dei file, copia preservando `.env`, `storage/` e upload, pulizia dei file orfani, `migrate` e pulizia cache in un processo separato, riavvio della coda. Se qualcosa fallisce dopo la copia i file tornano com'erano; se anche il ripristino fallisce il sito resta in manutenzione con il percorso del backup nel messaggio. Un pacchetto più vecchio di quello installato viene rifiutato.

Da terminale, come proprietario dei file (utile dove PHP del web server non può scrivere l'app):

```bash
php artisan app:aggiorna
```

```bash
php artisan app:aggiorna --pacchetto=/percorso/gestionale-associazioni-vX.Y.Z.zip
```

Dettagli in [docs/AGGIORNAMENTI.md](docs/AGGIORNAMENTI.md).

---

## Pubblicare una versione

1. Se ci sono migration nuove: `php artisan installer:genera-schema` (il test `SchemaSqlTest` fallisce altrimenti).
2. Aggiorna `VERSION` (semver; con `-rc.N` è una prerelease, proposta solo alle installazioni con `UPDATE_CHANNEL=rc`), committa e pusha.
3. `bash bin/create-release.sh X.Y.Z`: controlla il tree, crea e pusha il tag, aspetta la GitHub Action `release.yml` (che costruisce il pacchetto con `bin/build-release.sh` su PHP 8.4 e lo carica nella release con lo SHA256) e verifica asset, checksum e digest.

Il pacchetto si può costruire anche in locale con `bash bin/build-release.sh`.

---

## Architettura

| Aspetto | Implementazione |
|---|---|
| **Multi-tenancy** | Trait `BelongsToAssociazione` → global scope su `associazione_id`; middleware `tenant`; il super admin opera sull'associazione selezionata; perimetro per sede con scope dedicato |
| **Chiavi** | UUID (`HasUuids`) su tutte le entità di dominio |
| **Autenticazione** | Fortify + 2FA per gli amministratori (`utenti`); guard `socio` per l'area soci; guard `account` per il frontend pubblico; Sanctum e chiavi API per le API |
| **Autorizzazione** | Policy + permessi per modulo e azione (spatie/permission) con ruoli di piattaforma (`super_admin`, `admin_associazione`, `operatore`) e ruoli dell'associazione |
| **Audit** | spatie/activitylog sui modelli, registro degli accessi, registro delle modifiche |
| **Frontend pubblico** | Per associazione su `/a/{slug}`, risolto da `RisolviTenantPubblico` senza sessione admin; moduli pubblici configurabili |
| **Pagamenti** | Interfaccia `Pagabile` (quote, prenotazioni, donazioni) con webhook per gateway |
| **Installer** | `EnsureInstalled` (prepend al gruppo web) + `InstallerService`: schema SQL iniettato via PDO, lock, marcatore del wizard legato al browser |
| **Updater** | `UpdateService`: release GitHub, digest, preflight, backup, copia, orfani, rollback; `DumpDatabasePhp` come backup senza `mysqldump` |
| **Code & scheduler** | Driver `database`; scheduler in `routes/console.php` monitorato (schedule-monitor) |

Convenzioni: rotte per modulo in `routes/modules/`, controller in `app/Http/Controllers/<Modulo>/`, validazione con Form Request, viste sul design system condiviso (Bootstrap 5.3, tema chiaro/scuro). Specifiche dei moduli in [docs/specs](docs/specs).

A colpo d'occhio: **71 modelli**, **170 controller**, **574 rotte**, **66 migration**, **23 comandi artisan**, **1.962 test PHPUnit**, **110 test E2E**.

### Stack e librerie

**Laravel 13 · PHP 8.4–8.5 · MySQL 8** (test su SQLite in memoria). Fortify, Sanctum, Scout, spatie (permission, activitylog, backup, medialibrary, query-builder, data, settings, csp, honeypot, health, schedule-monitor, personal-data-export), Formello, maatwebsite/excel, barryvdh/laravel-dompdf, chillerlan/php-qrcode, propaganistas/laravel-phone, robertogallea/laravel-codicefiscale, mews/purifier, dedoc/scramble, stripe/stripe-php, srmklive/paypal; in sviluppo larastan, shipmonk/dead-code-detector, pint, debugbar.

---

## Sviluppo e test

```bash
php artisan test            # 1.962 test PHPUnit (SQLite in memoria)
npm run test:e2e            # 110 test E2E Playwright (DB dedicato, server automatico)
composer analyse            # phpstan: larastan livello 5 + rilevatore di codice morto, zero errori
vendor/bin/pint             # stile del codice
```

La suite comprende **60 percorsi reali** (`tests/Feature/Percorsi`): ogni test segue passo per passo quello che succede davvero in un'associazione, con più richieste HTTP fatte da attori diversi (richiedente sul sito, admin, operatore di sede, socio, volontario, scheduler): adesione e account, tesseramento e rinnovi, struttura e permessi, eventi, comunicazione e operatività, campi personalizzati e moduli.

I test che toccano un MySQL reale (iniezione dello schema, dump PHP) si attivano con una variabile:

```bash
INSTALLER_TEST_MYSQL_DSN="mysql://utente:password@127.0.0.1:3306/gestione_installer_test" php artisan test --filter=Mysql
```

Analisi statica: [docs/ANALISI-STATICA.md](docs/ANALISI-STATICA.md).

---

## Sicurezza e privacy

- Nessun dato dell'installazione nel repository: `.env` ignorato, il pacchetto di release non contiene `.env`; il wizard lo crea in produzione (`APP_DEBUG=false`).
- Il wizard non sovrascrive mai un database già installato; a schema installato gli step successivi restano al browser che ha avviato l'installazione; a installazione finita `/install` si chiude da solo.
- Export CSV/Excel protetti dalla formula injection, CSP, honeypot, blocco dei tentativi di accesso con audit per associazione, 2FA.
- Export dei dati personali e cancellazione dell'account (GDPR) dall'area riservata, con anonimizzazione e rimozione dei dati non ancora approvati.
- Backup del database prima di ogni aggiornamento; backup pianificati con spatie/laravel-backup.

---

## Licenza

[MIT](LICENSE).
