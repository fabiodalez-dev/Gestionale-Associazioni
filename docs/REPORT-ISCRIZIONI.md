# Report — Iscrizioni e preiscrizioni: form personalizzabile, approvazioni, aperture configurabili

Implementazione completa del piano `docs/PIANO-ISCRIZIONI.md` (workflow multi-agente: fondamenta → 3 moduli paralleli → rifiniture: notifiche, seeder demo, test). Chiude il cerchio dell'iscrizione sul mini-sito pubblico `/a/{slug}`: account → form di iscrizione configurabile → socio "In Attesa" → approvazione admin → socio Attivo collegato + tessera opzionale; in parallelo, eventi con approvazione richiesta sulle prenotazioni.

## Cosa è stato costruito

### Schema dati (migrations additive, compatibili SQLite)
- `associazioni` + `iscrizioni_socio_aperte` (bool, default true), `modalita_iscrizione` (`preiscrizione`|`diretta`, default `preiscrizione`), `iscrizione_form_config` (json nullable).
- `eventi` + `iscrizioni_aperte` (bool, default true — chiusura manuale che entra in `isPrenotabile()` oltre alla finestra `iscrizioni_dal/al`), `richiede_approvazione` (bool, default false).
- `prenotazioni` + stato `in_approvazione` (`Prenotazione::STATO_IN_APPROVAZIONE`, i posti CONTANO come occupati), `approvata_at`, `approvata_da` (FK utenti nullOnDelete), `motivo_rifiuto`.
- Nuova tabella `richieste_iscrizione` (UUID, `associazione_id`, `account_id` cascade, `socio_id` nullOnDelete, `stato` `in_attesa`|`approvata`|`rifiutata`, `dati_form` json snapshot, `motivo_rifiuto`, `gestita_da`, `gestita_at`; indice `(associazione_id, stato)`). Una sola richiesta `in_attesa` per account (check applicativo).

Migrations: `2026_06_11_140000…140003`.

### Form personalizzabile (schema-driven, nessun pacchetto nuovo)
`App\Services\Iscrizioni\FormIscrizioneService`:
- `schema(Associazione)` — risolve `iscrizione_form_config` (default sensato se assente): `intro_html` purificato (mews/purifier), campi standard ordinati con visibile/obbligatorio (telefono, codice_fiscale, data_nascita, indirizzo, citta, provincia, cap), campi personalizzati come modelli `CampoPersonalizzato` (riuso tabella `campi_personalizzati` di config_campi).
- `rules(Associazione)` — regole dinamiche: telefono → `phone:INTERNATIONAL,IT` (laravel-phone), codice fiscale → regola laravel-codicefiscale, data_nascita → date before today, campi personalizzati per tipo reale (text/email/tel/number/date/url/textarea/select/checkbox); input `custom_field[<uuid>]`.
- `salva(Account, array)` — transazione: crea il Socio (numero progressivo via service soci, stato `In Attesa` o `Attivo` secondo modalità, anagrafica SOLO dai campi visibili — anti-tamper), salva `valori_campi_personalizzati`, storicizza l'attività, crea la `RichiestaIscrizione` con snapshot integrale `dati_form`, set `richiesta_socio_at`; in modalità `diretta` collega subito `account.socio_id` e la richiesta nasce `approvata`.

Nome, cognome ed email arrivano dall'account loggato (mai nel form).

### Frontend pubblico `/a/{slug}/iscriviti`
- GET (guard account + email verificata): pagina "iscrizioni chiuse" se `iscrizioni_socio_aperte=false`; redirect a tessera & quote se già socio; pagina di stato se richiesta `in_attesa`; altrimenti form renderizzato dallo schema (con il motivo dell'ultimo rifiuto, per riprovare).
- POST (spatie-honeypot + throttle 5/m): validazione con le `rules()` dinamiche (`IscrizioneSocioRequest`), salvataggio, notifica best-effort agli admin, redirect alla pagina di stato (o alla tessera in modalità diretta).
- La vecchia "diventa socio" dell'area riservata reindirizza a `/iscriviti`; la dashboard account mostra lo stato della richiesta (in attesa / approvata / rifiutata con motivo).

### Admin
- **Configurazioni → Modulo di iscrizione** (`/configurazioni/modulo-iscrizione`): toggle iscrizioni aperte, modalità (preiscrizione/diretta), intro purificata, builder dei campi standard (visibile/obbligatorio/ordine), multiselect dei campi personalizzati esistenti (link a config_campi), anteprima del form pubblico.
- **Richieste iscrizione** (`/richieste-iscrizione`, `RichiestaIscrizionePolicy` su permessi `soci.view`/`soci.update`): lista pendenti + storico con `dati_form` espandibile; **Approva** (socio → Attivo, collega `account.socio_id`, checkbox "genera tessera" via `PaymentService::createTesseraForSocio`, notifica all'account) / **Rifiuta** con motivo obbligatorio (il socio preiscritto viene eliminato, notifica con motivo). Badge contatore in sidebar. Logica in `App\Services\Iscrizioni\ApprovazioneIscrizioneService`.
- **Eventi**: nel form Pubblicazione i due toggle `iscrizioni_aperte` e `richiede_approvazione`; nel tab prenotazioni le `in_approvazione` con azioni approva/rifiuta.

### Prenotazioni con approvazione (`PrenotazioneService`)
- `crea()` su evento con `richiede_approvazione` (e posti disponibili) → `in_approvazione`: posti riservati, NESSUNA scadenza pagamento; il flusso di pagamento parte solo dopo l'approvazione.
- `approva(Prenotazione, Utente)` → `confermata` (gratuito) o `in_attesa_pagamento` + finestra 30' (a pagamento); `rifiuta(Prenotazione, Utente, string $motivo)` → `annullata` con motivo + promozione FIFO della lista d'attesa. Entrambi SEMPRE dentro il mutex per-evento esistente (`lockForUpdate` sulla riga eventi), con verifica tenant dell'attore.
- Promozioni dalla lista d'attesa su eventi con approvazione → `in_approvazione` (non confermata).
- `iscrizioni_aperte=false` o fuori finestra → evento non prenotabile (CTA disabilitata con motivo); lo scheduler scadenze ignora le `in_approvazione`.

### Notifiche (fase Rifiniture — questo modulo)
Tutte `ShouldQueue`, canali **database + mail**, SMTP del tenant quando configurato (`TenantNotificationMailer`) con fallback al mailer di default. Invio **sempre fuori dalle transazioni** di dominio, best-effort con log su errore (in `PrenotazioneService` via coda interna `accodaNotifica`/`inviaNotifichePendenti` smaltita dopo il commit).

| Notifica | Destinatario | Trigger |
|---|---|---|
| `Iscrizioni\RichiestaIscrizioneRicevutaNotification` | admin attivi del tenant | POST del form `/iscriviti` (anche in modalità diretta) |
| `Iscrizioni\IscrizioneApprovataNotification` (con eventuale numero tessera) | account | approvazione admin della richiesta |
| `Iscrizioni\IscrizioneRifiutataNotification` (motivo) | account | rifiuto admin della richiesta |
| `Prenotazioni\InApprovazioneNotification` | account | prenotazione creata/promossa su evento con approvazione |
| `Prenotazioni\DaApprovareNotification` | admin attivi del tenant | prenotazione entrata in `in_approvazione` |
| `Prenotazioni\ConfermataNotification` / `InAttesaPagamentoNotification` | account | approvazione admin (gratuito / a pagamento — fungono da "prenotazione approvata") |
| `Prenotazioni\RifiutataNotification` (motivo) | account | rifiuto admin della prenotazione |

### Dati demo (installer web)
`DemoSeeder` aggiornato (niente factory, compatibile `composer install --no-dev`):
- form di iscrizione configurato sul tenant demo (modalità preiscrizione, intro, campi standard, campo personalizzato "Professione");
- evento pubblicato "Visita guidata in cantina" con `richiede_approvazione=true`;
- account `giulia@account-demo.test` / `demo1234` con richiesta di iscrizione `in_attesa` (socio preiscritto "In Attesa" n. 004 + snapshot `dati_form` + valore del campo personalizzato), pronta in "Richieste iscrizione".

## Rotte

### Frontend pubblico (`routes/modules/03-frontend-pubblico.php`, middleware tenant pubblico)
| Metodo | Rotta | Nome | Note |
|---|---|---|---|
| GET | `/a/{slug}/iscriviti` | `pubblico.iscriviti` | guard account verificato; stati chiusa/già socio/in attesa/form |
| POST | `/a/{slug}/iscriviti` | `pubblico.iscriviti.store` | honeypot + throttle 5/m, rules dinamiche |
| GET/POST | `/a/{slug}/account/diventa-socio` | `pubblico.account.diventa-socio` | reindirizza al nuovo form |

### Admin (`auth` + `tenant`)
| Metodo | Rotta | Nome | Note |
|---|---|---|---|
| GET | `/richieste-iscrizione` | `richieste-iscrizione.index` | lista pendenti + storico (`routes/modules/iscrizioni.php`) |
| POST | `/richieste-iscrizione/{richiesta}/approva` | `richieste-iscrizione.approva` | opzione `genera_tessera` |
| POST | `/richieste-iscrizione/{richiesta}/rifiuta` | `richieste-iscrizione.rifiuta` | motivo obbligatorio |
| GET | `/configurazioni/modulo-iscrizione` | `configurazioni.modulo-iscrizione` | builder del form (`routes/modules/struttura.php`) |
| POST | `/configurazioni/modulo-iscrizione` | `configurazioni.modulo-iscrizione.salva` | |
| POST | `/eventi/{evento}/prenotazioni/{prenotazione}/approva` | `eventi.prenotazioni.approva` | (`routes/modules/eventi.php`) |
| POST | `/eventi/{evento}/prenotazioni/{prenotazione}/rifiuta` | `eventi.prenotazioni.rifiuta` | motivo obbligatorio |

## Come provare

### In locale (dati demo)
1. Installa con l'installer web spuntando i dati dimostrativi (oppure, su un DB di prova, `php artisan db:seed --class=DemoSeeder` — MAI sul DB di sviluppo `associazione_soci_laravel`).
2. **Form iscrizione**: login su `/a/associazione-demo/login` con un nuovo account registrato (o `demo@account-demo.test` / `demo1234`, non ancora socio) → `/a/associazione-demo/iscriviti` → compila e invia → pagina "richiesta in attesa".
3. **Approvazione**: da admin → "Richieste iscrizione" → c'è già la richiesta pendente di Giulia Neri → espandi i dati, Approva con "genera tessera" → l'account vede tessera e quote nell'area riservata. Oppure Rifiuta con motivo → l'account vede il motivo in dashboard e può riprovare.
4. **Builder form**: Configurazioni → Modulo di iscrizione → cambia visibilità/obbligatorietà dei campi, modalità diretta, salva → il form pubblico cambia di conseguenza.
5. **Preiscrizione eventi**: da account, prenota "Visita guidata in cantina" → stato "in approvazione" (niente QR) → da admin, tab Prenotazioni dell'evento → Approva (→ confermata con QR) o Rifiuta con motivo (→ annullata + promozione del primo in coda).

### Test automatici
```bash
# Feature (SQLite in-memory)
php artisan test tests/Feature/Iscrizioni tests/Feature/Prenotazioni/ApprovazionePrenotazioniTest.php tests/Feature/Installer/DemoSeederTest.php

# E2E Playwright (DB dedicato associazione_soci_e2e, fixtures E2E_FIXTURES=1)
npx playwright test tests/e2e/specs/06-tessere-qr.spec.ts tests/e2e/specs/07-iscrizione-associazione.spec.ts tests/e2e/specs/08-preiscrizione-eventi.spec.ts tests/e2e/specs/09-flusso-completo.spec.ts
```
Copertura Feature: config form (salvataggio schema, sanitizzazione intro), rules dinamiche (telefono/CF invalidi), flusso preiscrizione completo, modalità diretta, iscrizioni chiuse, doppia richiesta bloccata, transizioni `in_approvazione` (posti riservati, rifiuto → promozione), toggle evento, isolamento tenant, notifiche con `Notification::fake()` (`NotificheIscrizioniTest`, `RichiesteIscrizioneAdminTest`, `ApprovazionePrenotazioniTest`), seeder demo (`DemoSeederTest`).

Copertura E2E (26 nuovi test, specs 06–09): tessere/QR e area soci (8), iscrizione associazione dal form configurato (10), preiscrizione eventi con approvazione (6), regressione "cerchio chiuso" catalogo → iscrizione → approvazione → prenotazione → check-in + KPI (2).

## Limitazioni note

- **Notifica "prenotazione approvata"**: non esiste una classe dedicata `PrenotazioneApprovataNotification`; l'approvazione riusa `ConfermataNotification` (gratuito, con biglietto) o `InAttesaPagamentoNotification` (a pagamento, con link e scadenza), che comunicano già l'esito in modo più utile per l'utente.
- **Code**: le notifiche sono `ShouldQueue`; con `QUEUE_CONNECTION=sync` partono inline, altrimenti serve un worker (`php artisan queue:work`).
- **Modalità diretta**: crea il socio `Attivo` senza passaggio admin; la quota/tessera NON viene generata automaticamente (l'admin può emetterla dalla scheda socio). Gli admin ricevono comunque la notifica di richiesta ricevuta come traccia.
- **Rifiuto richiesta**: il socio preiscritto viene eliminato (come da piano); lo snapshot resta in `richieste_iscrizione.dati_form` ma a una nuova richiesta l'utente ricompila il form da zero.
- **Un valore per campo personalizzato per socio**: il form riusa `valori_campi_personalizzati`, quindi nessuno storico delle versioni dei valori.
- **`intro_html`**: purificato con il profilo di default di mews/purifier (niente script/iframe); formattazioni avanzate richiedono di estendere la config del purifier.
- **Throttle** del POST pubblico: 5/min, condiviso con il limiter delle altre rotte pubbliche; non configurabile per tenant.
