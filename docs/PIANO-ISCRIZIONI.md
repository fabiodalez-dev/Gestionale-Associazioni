# Iscrizioni e preiscrizioni: form personalizzabile, approvazioni, aperture configurabili

## Obiettivo

Chiudere il cerchio dell'iscrizione sul mini-sito pubblico `/a/{slug}`:
1. **Iscrizione all'associazione** dal frontend con **form personalizzabile per tenant** (schema-driven, costruito su librerie già installate: campi personalizzati esistenti, laravel-phone, laravel-codicefiscale, spatie/honeypot, purifier per i testi).
2. **Preiscrizione alle prenotazioni evento**: il singolo evento può richiedere l'approvazione dell'admin prima della conferma.
3. **Aperture configurabili**: l'associazione decide se le iscrizioni socio sono aperte (e in che modalità: preiscrizione con approvazione o diretta) e, per ogni evento, se le prenotazioni sono aperte.
4. Gestione completa lato admin (richieste pendenti, approva/rifiuta con motivo, notifiche).
5. Flusso LINEARE con l'esistente: account → (form iscrizione) → Socio "In Attesa" (pipeline preiscrizioni già esistente, banner pending in /soci) → approvazione → socio Attivo collegato all'account → tessera opzionale (PaymentService::createTesseraForSocio).

## Schema dati (migrations additive, MAI modificare migrations esistenti)

**associazioni** +:
- `iscrizioni_socio_aperte` boolean default true
- `modalita_iscrizione` string(20) default 'preiscrizione' — valori: `preiscrizione` (crea Socio "In Attesa", admin approva) | `diretta` (crea Socio "Attivo" e collega subito l'account)
- `iscrizione_form_config` json null — schema del form (vedi sotto)

**eventi** +:
- `iscrizioni_aperte` boolean default true (chiusura manuale, oltre alla finestra `iscrizioni_dal/al` già esistente; entra in `isPrenotabile()`)
- `richiede_approvazione` boolean default false

**prenotazioni** +:
- nuovo stato `in_approvazione` (costante `STATO_IN_APPROVAZIONE`; i posti CONTANO come occupati, come in_attesa_pagamento)
- `approvata_at` timestamp null, `approvata_da` uuid null FK utenti nullOnDelete, `motivo_rifiuto` string null

**richieste_iscrizione** (nuova): traccia la richiesta socio dal form
- id uuid, associazione_id FK, account_id FK cascadeOnDelete, socio_id uuid null FK soci nullOnDelete (il preiscritto creato), `stato` string(20) default 'in_attesa' (`in_attesa`|`approvata`|`rifiutata`), `dati_form` json (snapshot di TUTTI i valori inviati), `motivo_rifiuto` string null, `gestita_da` uuid null FK utenti, `gestita_at` timestamp null, timestamps. Indice (associazione_id, stato). Una sola richiesta `in_attesa` per account (check applicativo).

## Form personalizzabile (schema-driven, niente pacchetti nuovi)

`iscrizione_form_config` json:
```json
{
  "intro_html": "<p>…</p>",            // sanitizzato con mews/purifier
  "campi_standard": [                    // ordine = ordine di render
    {"campo": "telefono", "visibile": true, "obbligatorio": true},
    {"campo": "codice_fiscale", "visibile": true, "obbligatorio": false},
    {"campo": "data_nascita", "visibile": true, "obbligatorio": true},
    {"campo": "indirizzo", "visibile": false, "obbligatorio": false},
    {"campo": "citta", "visibile": true, "obbligatorio": false},
    {"campo": "provincia", "visibile": false, "obbligatorio": false},
    {"campo": "cap", "visibile": false, "obbligatorio": false}
  ],
  "campi_personalizzati": ["<uuid campo>", "..."]   // riusa la tabella campi_personalizzati esistente (config_campi)
}
```
Nome, cognome, email arrivano dall'account loggato (non nel form). Default sensato quando config assente.

`App\Services\Iscrizioni\FormIscrizioneService`:
- `schema(Associazione): array` — schema risolto (standard + oggetti CampoPersonalizzato)
- `rules(Associazione): array` — regole dinamiche: telefono → `phone:INTERNATIONAL,IT` (propaganistas), codice_fiscale → regola del pacchetto codicefiscale, data_nascita → date before today, campi personalizzati → in base al loro tipo (testo/numero/data/select: usa i tipi reali della tabella)
- `salva(Account, array $dati): RichiestaIscrizione` — transazione: crea Socio (stato da modalità: 'In Attesa' o 'Attivo'; numero_socio progressivo via service soci esistente), salva valori campi personalizzati (tabella valori_campi_personalizzati), crea RichiestaIscrizione con snapshot, set `richiesta_socio_at` sull'account; se modalità diretta collega subito `account.socio_id`.

## Rotte e UI

**Frontend pubblico** (routes/modules/03-frontend-pubblico.php):
- `GET /a/{slug}/iscriviti` — guard account+verificato. Se `iscrizioni_socio_aperte=false` → pagina "iscrizioni chiuse". Se già socio collegato → redirect area tessera. Se richiesta in_attesa → pagina stato. Altrimenti form renderizzato dallo schema (+honeypot, throttle 5/m).
- `POST /a/{slug}/iscriviti` — valida con rules() dinamiche, salva, notifica admin, redirect a pagina stato con esito (in attesa/benvenuto socio se diretta).
- La pagina "diventa-socio" esistente dell'area riservata REINDIRIZZA a /iscriviti (il form sostituisce la semplice richiesta); la dashboard account mostra lo stato della richiesta (in attesa/approvata/rifiutata con motivo).

**Admin**:
- Configurazioni → nuova sezione "Modulo di iscrizione": toggle iscrizioni aperte, modalità, intro_html (textarea purificata), builder campi (tabella con visibile/obbligatorio/ordine per gli standard, multiselect dei campi personalizzati esistenti con link a config_campi). Anteprima link al form pubblico.
- Nuova pagina "Richieste iscrizione" (routes/modules/iscrizioni.php, permesso soci.view/update): lista pendenti+storico con dati form espandibili; APPROVA (socio → Attivo, collega account.socio_id, opzione checkbox "genera tessera" via PaymentService::createTesseraForSocio, notifica account) / RIFIUTA con motivo (socio preiscritto → soft delete o stato mantenuto 'In Attesa'? → eliminare il socio preiscritto creato, notifica con motivo). Badge contatore in sidebar.
- Eventi: nel form Pubblicazione i due nuovi toggle (`iscrizioni_aperte`, `richiede_approvazione`). Tab prenotazioni: le `in_approvazione` con azioni approva (→ confermata, o in_attesa_pagamento+scadenza se evento a pagamento) / rifiuta con motivo (→ annullata + promozione lista d'attesa). Riusa PrenotazioneService con nuovi metodi `approva(Prenotazione, Utente)` / `rifiuta(Prenotazione, Utente, string $motivo)` SEMPRE col mutex per-evento esistente.

**PrenotazioneService::crea()**: se evento.richiede_approvazione (e posti disponibili) → `in_approvazione` (posti riservati, nessuna scadenza); flusso pagamento parte SOLO dopo approvazione. Lista d'attesa: i promossi su eventi con approvazione vanno in `in_approvazione`. `iscrizioni_aperte=false` o fuori finestra → non prenotabile (CTA disabilitata con motivo).

## Notifiche (pattern esistente ShouldQueue database+mail)

- `RichiestaIscrizioneRicevutaNotification` → admin tenant
- `IscrizioneApprovataNotification` (con eventuale numero tessera) / `IscrizioneRifiutataNotification` (motivo) → account
- `PrenotazioneInApprovazioneNotification` → account; `PrenotazioneApprovataNotification` / `PrenotazioneRifiutataNotification` → account; `PrenotazioneDaApprovareNotification` → admin

## Test

**Feature PHPUnit** (ogni modulo): config form (salvataggio schema, sanitizzazione intro), rules dinamiche (telefono/CF invalidi), flusso preiscrizione completo (form → socio In Attesa → approva → Attivo+collegato+tessera; rifiuta → motivo), modalità diretta, iscrizioni chiuse, doppia richiesta bloccata, prenotazione con approvazione (tutte le transizioni, posti riservati, rifiuto → promozione), toggle evento, isolamento tenant ovunque.

**E2E Playwright: ALMENO 25 NUOVI test** (specs 06+; estendere e2e:fixture dove serve, helpers riutilizzabili):
- Tessere/QR (8): fixture socio+tessera; verifica-tessera pubblica valida/scaduta/inesistente; PDF tessera 200 application/pdf; check-in tessera via endpoint scanner admin; pagina scanner carica; area-soci: login socio (set-password via fixture), tessera con QR visibile, quota in elenco
- Iscrizione associazione (9): config form da admin (toggle campi, salva); form pubblico mostra i campi configurati; campo obbligatorio mancante → errore; telefono/CF invalidi → errori; flusso felice → stato "in attesa" in dashboard account; admin approva con tessera → account vede tessera in area riservata; rifiuto con motivo visibile; iscrizioni chiuse → pagina chiusa; modalità diretta → subito socio
- Preiscrizione eventi (6): evento con approvazione → prenotazione "in approvazione" (no QR); admin approva → confermata con QR; admin rifiuta → annullata con motivo + promozione del primo in coda; evento a pagamento con approvazione → dopo approvazione va in pagamento con countdown; toggle iscrizioni_aperte chiuso → CTA disabilitata; finestra iscrizioni futura → non prenotabile
- Regressione flusso completo (2+): catalogo → iscrizione socio → approvazione → prenotazione evento → check-in (il "cerchio chiuso"); KPI admin aggiornati

## REGOLE OPERATIVE CRITICHE per gli agenti

- ⚠️ MAI eseguire `migrate:fresh`/`db:wipe` sul database `associazione_soci_laravel` (è il DB di sviluppo con dati reali). PHPUnit usa SQLite in-memory (già configurato). Per artisan che richiede MySQL usare SEMPRE `DB_DATABASE=associazione_soci_e2e` con `E2E_FIXTURES=1`.
- Migrations additive e compatibili SQLite. Rotte solo in routes/modules/. Form Request + Policy. Viste sul design system. Niente commit git. Niente pacchetti composer nuovi (tutto il necessario è già installato).
- La suite PHPUnit esistente (856) e la suite E2E esistente (26) DEVONO restare verdi.
