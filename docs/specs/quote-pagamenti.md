# Specifica funzionale — Modulo Quote Associative e Pagamenti Online

> Reverse-engineering completo dell'app PHP legacy. Fonti analizzate:
> `pages/quote.php`, `pagamento.php`, `includes/PaymentService.php`,
> `api/webhooks/stripe.php`, `api/webhooks/paypal.php`, più gli helper
> `loadPaymentGatewayConfig()` in `config.php` e lo schema `database/schema.sql`.
> Questa spec è autosufficiente: permette la reimplementazione in Laravel senza leggere il codice vecchio.

---

## 1. Panoramica del modulo

Il modulo gestisce le **quote associative** dei soci in un contesto SaaS multi-tenant
(isolamento dati per `associazione_id`). Funzionalità coperte:

1. CRUD quote (creazione, modifica, eliminazione) — area amministrativa
2. Registrazione manuale di un pagamento (contanti, bonifico, carta, altro)
3. Calcolo dinamico dello stato della quota (Pagata / Scaduta / In Scadenza / Da Pagare)
4. Generazione di un **link di pagamento pubblico** (token monouso con scadenza) per il socio
5. Pagina pubblica di pagamento (`pagamento.php`) con checkout Stripe e PayPal
6. Webhook Stripe (`checkout.session.completed`) e PayPal (`CHECKOUT.ORDER.APPROVED` + capture)
7. Post-pagamento: aggiornamento quota, log attività socio, **auto-attivazione socio** + creazione tessera, email di conferma/benvenuto (best-effort)
8. Configurazione gateway per associazione (`payment_gateway_settings`) con segreti cifrati
9. Helper statico per creare una quota a partire da altri flussi (es. iscrizione socio)

---

## 2. Tabelle DB coinvolte

### 2.1 `quote` (tabella principale)

| Colonna | Tipo | Note d'uso |
|---|---|---|
| `id` | CHAR(36) PK | UUID generato applicativamente (`generateUuid()`) |
| `socio_id` | CHAR(36) NOT NULL, FK → `soci(id)` ON DELETE CASCADE | |
| `associazione_id` | CHAR(36) NOT NULL, FK → `associazioni(id)` ON DELETE CASCADE | Chiave di tenant: **ogni query la filtra** |
| `anno` | INT NOT NULL | Anno di riferimento della quota |
| `importo` | DECIMAL(10,2) NOT NULL | In euro (o valuta configurata) |
| `data_scadenza` | DATE NOT NULL | Scadenza di pagamento |
| `data_pagamento` | DATE NULL | NULL = non pagata. **È la fonte di verità per "pagata"**, non la colonna `stato` |
| `metodo_pagamento` | VARCHAR(50) NULL | `contanti`, `bonifico`, `carta`, `altro`, `stripe`, `paypal` |
| `gateway` | VARCHAR(20) NULL | `stripe` o `paypal` (solo pagamenti online) |
| `gateway_transaction_id` | VARCHAR(255) NULL | PaymentIntent ID (Stripe) o Capture/Order ID (PayPal) |
| `payment_token` | CHAR(64) NULL, INDEX `idx_quote_payment_token` | Token esadecimale per il link pubblico |
| `payment_token_expires` | DATETIME NULL | Scadenza token |
| `stato` | ENUM('Pagata','Da Pagare','Scaduta','In Scadenza') DEFAULT 'Da Pagare' | **Denormalizzato e parzialmente inutilizzato**: la UI lo ricalcola sempre da `data_pagamento`/`data_scadenza`. Viene scritto a 'Pagata' solo dai webhook e da `createQuotaForSocio()` |
| `tipo` | VARCHAR(100) NOT NULL | Etichetta libera, default UI: "Quota Associativa" |
| `note` | TEXT NULL | Non usata dal modulo |
| `created_at` / `updated_at` | DATETIME | Auto |

### 2.2 `payment_gateway_settings` (config gateway per associazione)

| Colonna | Tipo | Note |
|---|---|---|
| `id` | CHAR(36) PK | UUID |
| `associazione_id` | CHAR(36) NOT NULL, UNIQUE, FK → `associazioni` ON DELETE CASCADE | 1 riga per associazione |
| `stripe_enabled` | BOOLEAN DEFAULT FALSE | |
| `stripe_publishable_key` | VARCHAR(255) DEFAULT '' | In chiaro |
| `stripe_secret_key_encrypted` | TEXT DEFAULT '' | **Cifrata at-rest** (decifrata a runtime in `stripe_secret_key`) |
| `stripe_webhook_secret_encrypted` | TEXT DEFAULT '' | Cifrata (→ `stripe_webhook_secret`) |
| `paypal_enabled` | BOOLEAN DEFAULT FALSE | |
| `paypal_client_id` | VARCHAR(255) DEFAULT '' | In chiaro |
| `paypal_client_secret_encrypted` | TEXT DEFAULT '' | Cifrata (→ `paypal_client_secret`) |
| `paypal_mode` | ENUM('sandbox','live') DEFAULT 'sandbox' | Seleziona environment SDK |
| `paypal_webhook_id` | VARCHAR(255) DEFAULT '' | Presente in schema ma **NON usato dal codice** (vedi §8.4: la verifica firma PayPal di fatto non avviene) |
| `auto_attivazione_pagamento` | BOOLEAN DEFAULT TRUE | Se TRUE, il pagamento online attiva automaticamente il socio "In Attesa di Pagamento" |
| `valuta` | VARCHAR(3) DEFAULT 'EUR' | Usata per Stripe (lowercase) e PayPal (uppercase) e mostrata in pagina pagamento |
| `created_at` / `updated_at` | DATETIME | |

L'helper `loadPaymentGatewayConfig(pdo, associazione_id)`:
- ritorna `null` se la tabella non esiste o non c'è riga per il tenant;
- decifra i tre segreti aggiungendo le chiavi runtime `stripe_secret_key`, `stripe_webhook_secret`, `paypal_client_secret`; se la decifratura fallisce (RuntimeException) il valore diventa stringa vuota (= gateway di fatto disabilitato, nessun errore esposto).

### 2.3 Tabelle correlate (lettura/scrittura secondaria)

- **`soci`**: letti `nome`, `cognome`, `email`, `stato`; aggiornati `stato`→'Attivo' e `data_iscrizione`=oggi in auto-attivazione. Stato rilevante: `'In Attesa di Pagamento'`.
- **`associazioni`**: letti `nome`, `logo_url` (pagina pagamento), `tipo_scadenza_default` ('solare' | 'annuale') per calcolare scadenze di quote e tessere.
- **`tessere`**: insert in auto-attivazione. Colonne usate: `id` (UUID), `associazione_id`, `socio_id`, `numero_tessera` (progressivo per associazione, zero-padded a 4 cifre, calcolato come `MAX(CAST(numero_tessera AS UNSIGNED)) + 1`), `anno_validita` (anno corrente), `data_emissione` (oggi), `data_scadenza`, `stato`='Attiva', `tipo_scadenza`, `qr_code_url` (update successivo se l'helper QR è disponibile).
- **Log attività socio** (`logSocioActivity`): scrive eventi 'Pagamento Quota', 'Pagamento Online', 'Attivazione automatica', 'Generazione Tessera'.
- **Coda email** (via `EmailService::queueEmail`): template `pagamento_quota` e `benvenuto`, priorità 3, con UUID di tracking.

---

## 3. Stati della quota — regole di business

### 3.1 Stato calcolato dinamicamente (UI lista quote)

Lo stato mostrato **non** è letto dalla colonna `stato` ma calcolato così, in ordine di priorità:

```
1. data_pagamento valorizzata           → "Pagata"
2. data_scadenza < oggi                 → "Scaduta"
3. data_scadenza < oggi + 30 giorni     → "In Scadenza"
4. altrimenti                           → "Da Pagare"
```

Badge UI: Pagata=success(verde), Da Pagare=info(azzurro), In Scadenza=warning(giallo), Scaduta=danger(rosso).

### 3.2 Colonna `stato` persistita (incoerenza nota da sanare)

- I pagamenti **manuali** aggiornano solo `data_pagamento` + `metodo_pagamento`, **non** la colonna `stato`.
- I pagamenti **online** (webhook) impostano `stato='Pagata'` + `data_pagamento=CURDATE()` + `gateway` + `gateway_transaction_id` + `metodo_pagamento`.
- `createQuotaForSocio()` imposta `stato` a 'Pagata' o 'Da Pagare' all'insert.
- Conclusione: trattare `data_pagamento IS NOT NULL` come unica fonte di verità; in Laravel conviene un accessor/enum calcolato e mantenere `stato` solo come cache o eliminarlo.

---

## 4. Funzionalità area amministrativa (`pages/quote.php`)

**Guard di accesso**: utente loggato (sessione admin) E `associazione_id` in sessione, altrimenti redirect a login/dashboard. Tutte le operazioni sono scoped sul tenant di sessione.

**CSRF**: ogni POST valida un token CSRF; se invalido → messaggio di errore "token CSRF non valido" (danger) e nessuna azione.

### 4.1 Lista quote con filtri

- Query: `quote q JOIN soci s ON q.socio_id=s.id JOIN associazioni a ON a.id=q.associazione_id WHERE q.associazione_id = :tenant`, ordinata per `data_scadenza DESC`.
  - Nota: il JOIN su `soci` è INNER → quote di soci cancellati non apparirebbero (in pratica il FK CASCADE le elimina).
- **Filtro anno** (`?year=`): select popolata con `SELECT DISTINCT anno ... ORDER BY anno DESC` del tenant; valore 'Tutti' = nessun filtro. Applicato in SQL.
- **Filtro stato** (`?status=`): valori `Tutti | Pagata | Da Pagare | In Scadenza | Scaduta`. Applicato **in memoria** (array_filter sul risultato) usando lo stato calcolato §3.1 — perché lo stato è dinamico, non in colonna.
- Colonne mostrate: Socio (Cognome Nome), Associazione, Tipo, Anno, Importo (formato it: `1.234,56` con prefisso €), Scadenza (`d/m/Y`), Stato (badge), Azioni. Layout doppio: tabella desktop + card mobile con gli stessi dati/azioni.

### 4.2 Azioni per riga

- **Paga** (solo se stato ≠ Pagata): link `?pay=<id>` che apre il modal di registrazione pagamento.
- **Genera link pagamento** (solo se stato ≠ Pagata E almeno un gateway visibile — vedi §4.6): bottone POST `generate_payment_link=<quota_id>`.
- **Modifica**: link `?edit=<id>` che apre il modal precompilato (fetch quota con LEFT JOIN soci per il nome).
- **Elimina**: POST `delete_id=<id>` con confirm JS; `DELETE FROM quote WHERE id=? AND associazione_id=?`. Hard delete, senza controlli su pagamenti già registrati. Messaggio "Quota eliminata con successo."

### 4.3 Creazione / modifica quota (modal)

Campi: Socio (autocomplete obbligatorio), Anno (number, default anno corrente), Importo (number step 0.01, default 50.00), Data Scadenza (date, required), Tipo (testo, default "Quota Associativa", sanitizzato).

- **Insert**: `id = UUID nuovo`, tutte le colonne sopra + `associazione_id` di sessione. Messaggio "Quota creata."
- **Update** (se `id` presente nel form): aggiorna `socio_id, anno, importo, data_scadenza, tipo` con `WHERE id=? AND associazione_id=?`. Messaggio "Quota aggiornata."
- **Autocomplete socio**: input testo con debounce 300ms su `api/soci_search.php?q=`, risposta JSON `[{id, nome_completo}]`; la selezione popola un hidden `socio_id`. Validazione client: blocca il submit se `socio_id` vuoto (campo marcato is-invalid). Min 1 carattere per la ricerca.
- Validazioni server-side: **assenti** nel legacy (oltre a CSRF e tenant-scoping) — in Laravel aggiungere FormRequest (vedi §10).

### 4.4 Registrazione pagamento (modal "Registra Pagamento", `?pay=<id>`)

Mostra Socio e Importo. Campi: `payment_method` (select: contanti, bonifico, carta, altro + "Genera link Stripe" / "Genera link PayPal" se i rispettivi gateway sono visibili) e `payment_date` (date, default oggi; il campo viene nascosto via JS quando si sceglie un metodo online, con nota informativa "Verrà generato un link di pagamento online").

Al POST (`pay_id`):

- **Metodo offline** (`contanti|bonifico|carta|altro`):
  1. `UPDATE quote SET data_pagamento=:payment_date, metodo_pagamento=:metodo WHERE id AND associazione_id` (la colonna `stato` NON viene toccata).
  2. Rilegge `socio_id, importo, anno` della quota e logga attività socio: tipo "Pagamento Quota", descrizione `Pagata quota di €{importo} per l'anno {anno} ({metodo}).`
  3. Messaggio "Pagamento registrato con successo."
  4. **Email best-effort** (mai bloccante, errori solo in error_log): se SMTP configurato E flag automazione `auto_pagamento_quota` attivo E template `pagamento_quota` esistente e attivo → renderizza il template con placeholder extra `IMPORTO`, `ANNO`, `DATA_PAGAMENTO` (= payment_date inserita) e accoda l'email al socio (solo se il socio ha email), priorità 3.

- **Metodo online** (`stripe|paypal`): NON registra il pagamento; genera invece un token di pagamento (vedi §5) e mostra in pagina il link completo `"Link pagamento generato: <base_url>/pagamento.php?token=<token>"` (messaggio success) perché l'operatore lo invii al socio. Nota: il metodo scelto (stripe vs paypal) NON vincola il gateway: nella pagina pubblica il socio vedrà tutti i gateway abilitati.

### 4.5 Generazione link pagamento diretta (`generate_payment_link`)

Identica al ramo online di §4.4: genera token e mostra il link. Disponibile dal bottone "Link" su ogni quota non pagata. Il legacy non verifica che la quota non sia già pagata né che esista (l'UPDATE semplicemente non matcha): in Laravel validare esplicitamente.

### 4.6 Visibilità opzioni gateway in admin

`hasStripe = config esiste AND stripe_enabled AND stripe_publishable_key non vuota`
`hasPayPal = config esiste AND paypal_enabled AND paypal_client_id non vuoto`

Attenzione: questi check (usati per mostrare i bottoni in admin) sono **più deboli** di quelli usati per il checkout (`PaymentService::isStripeEnabled/isPayPalEnabled`, che richiedono anche le chiavi segrete). È quindi possibile generare un link che poi mostra "Nessun metodo di pagamento online configurato". In Laravel uniformare i due check.

---

## 5. Token e link di pagamento pubblico

`PaymentService::generatePaymentToken(quotaId, expiryHours = 72)`:

- Token: **64 caratteri esadecimali** = `bin2hex(random_bytes(32))` (256 bit di entropia).
- Scadenza: `now + 72 ore` (default), salvata in `payment_token_expires`.
- Persistenza: `UPDATE quote SET payment_token=?, payment_token_expires=? WHERE id=? AND associazione_id=?` — il token precedente viene **sovrascritto** (un solo token attivo per quota; rigenerare invalida il precedente).
- Il token NON viene cancellato dopo il pagamento: la pagina pubblica resta raggiungibile e mostra "Pagamento già effettuato".
- URL risultante: `<base_url>/pagamento.php?token=<token>` (base URL da helper `getBaseUrl()`, senza trailing slash).

---

## 6. Pagina pubblica di pagamento (`pagamento.php`)

Pagina standalone **senza autenticazione** (l'autorizzazione è il token stesso), branding associazione (logo + nome, header arancio #FF7B11), responsive.

### 6.1 Validazioni in ingresso (in ordine)

1. `token` deve matchare `^[a-f0-9]{64}$` (case-insensitive) → altrimenti HTTP 400 "Link non valido."
2. Verifica che la colonna `payment_token` esista nella tabella (guardia di migrazione) → HTTP 500 "Configurazione non completata." / "Errore di sistema." su PDOException. (In Laravel non serve.)
3. Lookup: `SELECT q.*, dati socio (nome, cognome, email), dati associazione (nome, logo_url) FROM quote q JOIN soci JOIN associazioni WHERE q.payment_token=? LIMIT 1`. Nessuna riga → HTTP 404 "Link di pagamento non trovato o non valido." **Nota: il lookup è per token, senza filtro tenant — il tenant deriva dalla quota stessa.**
4. `tokenExpired` = `payment_token_expires` valorizzata E < now.
5. `alreadyPaid` = `data_pagamento` valorizzata.

### 6.2 Stati di rendering (in ordine di priorità)

1. `?result=success` → schermata verde "Pagamento completato con successo! Riceverai una conferma via email." (puramente informativa: la conferma reale arriva dal webhook, NON da questo parametro).
2. Token scaduto → "Link scaduto. Questo link di pagamento non è più valido. Contatta l'associazione per un nuovo link."
3. Già pagata → "Pagamento già effettuato. Questa quota risulta già pagata."
4. Altrimenti → dettagli (Socio, Tipo, Anno) + importo grande formattato it (`VALUTA 1.234,56`, valuta uppercase da config, default EUR) + bottoni gateway:
   - "Paga con Carta (Stripe)" se `isStripeEnabled()` (enabled + publishable + secret key)
   - "Paga con PayPal" se `isPayPalEnabled()` (enabled + client_id + client_secret)
   - Nessuno dei due → alert "Nessun metodo di pagamento online configurato. Contatta l'associazione."
   - `?result=cancel` mostra alert "Pagamento annullato. Puoi riprovare quando vuoi." sopra i bottoni (si può ripagare).

### 6.3 Avvio checkout (POST, campo `gateway`)

Eseguito **solo se** token non scaduto E quota non pagata (doppio guard server-side).

- `returnUrl = <base>/pagamento.php?token=<token>&result=success`
- `cancelUrl = <base>/pagamento.php?token=<token>&result=cancel`
- `gateway=stripe` (e Stripe abilitato) → `createStripeCheckout()`; se ritorna URL → redirect 302; altrimenti errore in pagina "Errore nella creazione del pagamento Stripe. Riprova."
- `gateway=paypal` (e PayPal abilitato) → `createPayPalOrder()`; idem con messaggio PayPal.
- Nota legacy: questo POST pubblico **non ha CSRF** (accettabile: nessuna sessione, azione idempotente lato nostro DB). Esiste un parametro `paypal_order_id` letto da GET ma mai usato (codice morto, non reimplementare).

### 6.4 Checkout Stripe (`createStripeCheckout`)

- Precondizioni: `isStripeEnabled()`, quota esistente nel tenant (altrimenti `null`).
- Stripe Checkout Session, `mode=payment`, `payment_method_types=['card']`:
  - 1 line item con `price_data`: currency = `valuta` config lowercase (default 'eur'); `unit_amount = (int) round(importo * 100)` (centesimi); `product_data.name = "{nome associazione} - {tipo quota}"`, `description = "Anno {anno}"`; quantity 1.
  - `success_url` / `cancel_url` = return/cancel URL.
  - **`metadata = { quota_id, associazione_id }`** — fondamentale: il webhook risale al tenant e alla quota da qui.
  - `customer_email` = email del socio (se presente) per precompilare il checkout.
- Ritorna `session->url`; su `ApiErrorException` logga e ritorna `null`.

### 6.5 Checkout PayPal (`createPayPalOrder`)

- Precondizioni: `isPayPalEnabled()`, quota esistente.
- SDK `paypal/paypal-server-sdk`; environment PRODUCTION se `paypal_mode==='live'`, altrimenti SANDBOX; auth client-credentials (client_id + client_secret).
- Order `intent=CAPTURE`, una purchase unit:
  - amount: currency = `valuta` uppercase (default 'EUR'), value = importo formattato `0.00` con punto decimale;
  - **`custom_id = quota_id`** — chiave di correlazione per il webhook;
  - description = `"{nome associazione} - {tipo} Anno {anno}"`.
- Ritorna l'`href` del link con `rel === 'approve'`; fallback se assente: `https://www.paypal.com/checkoutnow?token=<orderId>` (live) o `https://www.sandbox.paypal.com/checkoutnow?token=<orderId>`.
- Eccezioni → log + `null`. NB: returnUrl/cancelUrl sono accettati come parametri ma **non passati** all'ordine PayPal nel legacy (manca l'application_context) — il rientro avviene comunque perché PayPal usa il flusso webhook per il capture; **in Laravel passare esplicitamente `application_context.return_url/cancel_url`** per riportare l'utente sulla pagina.

---

## 7. Webhook

Entrambi gli endpoint sono pubblici, rispondono JSON, e su eccezione generica → HTTP 500 `{"error":"Internal error"}` con dettaglio solo in error_log.

### 7.1 Stripe — `POST /api/webhooks/stripe.php`

1. Legge body raw + header `Stripe-Signature`. Mancanti → 400 `{"error":"Missing payload or signature"}`.
2. JSON decode del body; invalido → 400 `{"error":"Invalid JSON"}`.
3. Estrae `data.object.metadata.associazione_id`:
   - assente → **200** `{"status":"ignored","reason":"no associazione_id in metadata"}` (eventi non-checkout vanno ack-ati per non far ritentare Stripe);
   - formato non UUID (`^[a-f0-9\-]{36}$`) → 400.
4. Istanzia `PaymentService(tenant)` → `handleStripeWebhook(payload, sigHeader)`:
   - se config assente o `stripe_webhook_secret` vuoto → false (→ 400);
   - **verifica firma** con `\Stripe\Webhook::constructEvent(payload, sig, webhook_secret)`; firma invalida → log + false (→ 400);
   - se `event.type !== 'checkout.session.completed'` → true (ack, nessuna azione);
   - estrae `metadata.quota_id` dalla session; assente → log + false;
   - `transactionId = session.payment_intent ?? session.id`;
   - chiama `onPaymentCompleted(quotaId, 'stripe', transactionId)` → true.
5. true → 200 `{"status":"ok"}`; false → 400 `{"error":"Webhook processing failed"}`.

### 7.2 PayPal — `POST /api/webhooks/paypal.php`

1. Body raw; vuoto → 400. JSON invalido → 400.
2. Raccoglie gli header `PAYPAL-*` (transmission-id ecc.) in un array… **che però non viene mai verificato** (vedi §8.4).
3. Estrae `resource.purchase_units[0].custom_id` (= quota_id):
   - assente → **200** `{"status":"ignored", ...}` (eventi non-order);
   - formato non UUID → 400.
4. Risale al tenant: `SELECT associazione_id FROM quote WHERE id=? LIMIT 1`; non trovata → 404 `{"error":"Quota not found"}`.
5. `PaymentService(tenant)->handlePayPalWebhook(payload, headers)`:
   - PayPal non abilitato per il tenant → false;
   - se `event_type !== 'CHECKOUT.ORDER.APPROVED'` → true (ack);
   - estrae `resource.id` (order id); assente → false;
   - **esegue il CAPTURE** dell'ordine via SDK (`captureOrder`): è il webhook a completare l'incasso, non il redirect;
   - se status capture ≠ 'COMPLETED' → log + false;
   - dalle purchase units capture-ate estrae `custom_id` (quota_id) e il primo `capture.id`;
   - quota_id assente → false; altrimenti `onPaymentCompleted(quotaId, 'paypal', captureId ?: orderId)` → true.
6. Mapping risposta come Stripe.

### 7.3 Post-pagamento comune — `onPaymentCompleted(quotaId, gateway, transactionId)`

Eseguito per entrambi i gateway:

1. `UPDATE quote SET stato='Pagata', data_pagamento=CURDATE(), gateway=?, gateway_transaction_id=?, metodo_pagamento=<gateway> WHERE id=? AND associazione_id=?`.
2. Ricarica la quota (se sparita: stop silenzioso).
3. Log attività socio: tipo "Pagamento Online", descrizione `Pagamento quota €{importo} anno {anno} via {gateway}, transazione: {transactionId}`.
4. **Auto-attivazione** se `auto_attivazione_pagamento` è TRUE nella config gateway:
   - cerca il socio della quota con `stato = 'In Attesa di Pagamento'` (solo in quel caso agisce);
   - `UPDATE soci SET stato='Attivo', data_iscrizione=CURDATE()`;
   - log "Attivazione automatica" (`Socio attivato dopo pagamento online ({gateway}).`);
   - **crea la tessera** (vedi sotto);
   - email di benvenuto best-effort (template `benvenuto`, flag automazione `auto_benvenuto`).
5. Email di conferma pagamento best-effort (template `pagamento_quota`, flag `auto_pagamento_quota`, placeholder `IMPORTO`, `ANNO`, `DATA_PAGAMENTO`=oggi `d/m/Y`; inviata solo se il socio ha email).

**Creazione tessera (`createTesseraForSocio`)** — idempotente per (associazione, socio, anno):
- se esiste già una tessera per socio+anno corrente → no-op;
- numero tessera = progressivo per associazione: `MAX(CAST(numero_tessera AS UNSIGNED)) + 1`, zero-padded a 4 cifre (es. `0042`). **Race condition possibile** sotto concorrenza: in Laravel usare lock o sequenza;
- `tipo_scadenza` dell'associazione (`tipo_scadenza_default`, default 'solare'): scadenza = `data_emissione + 1 anno` se 'annuale', `31/12 anno corrente` se 'solare';
- stato 'Attiva'; dopo l'insert genera e salva `qr_code_url` (URL di verifica tessera) se l'helper QR è disponibile;
- log attività "Generazione Tessera" (`Tessera {numero} generata dopo pagamento online.`).

---

## 8. Regole di business implicite ed edge case

1. **"Pagata" = `data_pagamento NOT NULL`**, non la colonna `stato` (§3.2). I filtri e i bottoni in UI derivano da questo.
2. **Soglia "In Scadenza" = 30 giorni** prima della scadenza.
3. **Idempotenza webhook**: il legacy NON è idempotente sull'UPDATE quota (un webhook duplicato ri-esegue UPDATE e log). L'auto-attivazione invece è naturalmente idempotente (condizione su stato 'In Attesa di Pagamento') così come la tessera (check esistenza). In Laravel: rendere idempotente l'intero handler (skip se `data_pagamento` già valorizzata o se `gateway_transaction_id` già registrato).
4. **Verifica firma PayPal ASSENTE**: gli header vengono raccolti ma mai validati e `paypal_webhook_id` non è usato. Mitigazione di fatto: il danno è limitato perché il capture avviene server-side con le credenziali del tenant (un payload forgiato senza ordine reale approvato fallisce il capture). **In Laravel implementare la verifica ufficiale** (API `/v1/notifications/verify-webhook-signature` con `paypal_webhook_id`) — è un fix di sicurezza richiesto.
5. **Verifica firma Stripe presente e obbligatoria** (`stripe_webhook_secret` per-tenant). Senza secret configurato il webhook fallisce sempre (400) → Stripe ritenta. Attenzione cross-tenant: l'`associazione_id` letto dai metadata determina QUALE webhook secret si usa per la verifica; un attaccante non può forgiare eventi senza conoscere il secret di quel tenant.
6. **Race redirect vs webhook**: il socio può tornare su `result=success` prima che il webhook arrivi; la pagina success è generica apposta ("riceverai conferma via email"). Se ricarica la pagina senza `result`, vedrà "Pagamento già effettuato" solo dopo che il webhook ha scritto `data_pagamento`.
7. **`result=success` non è una prova di pagamento**: chiunque può aggiungere il parametro all'URL. Nessuna logica di business vi si appoggia (corretto: mantenere così).
8. **Cancel non invalida nulla**: dopo `result=cancel` il token resta valido e si può ritentare.
9. **Token riusabile in lettura**: la pagina col token resta visitabile dopo il pagamento (mostra esito). Token sovrascritto a ogni rigenerazione; scadenza default 72h.
10. **Pagamento manuale di quota con link attivo**: se l'admin registra un pagamento offline mentre esiste un link, la pagina pubblica mostrerà "già pagata" (guard `alreadyPaid`). Coerente.
11. **Eliminazione quota**: hard delete senza vincoli, anche se pagata o con token attivo (il link darà 404). In Laravel valutare conferma rafforzata o soft delete.
12. **Modifica quota pagata**: il legacy consente di modificare anno/importo/scadenza anche di quote pagate (il form non blocca). Decidere policy in Laravel (consigliato: bloccare importo su quote pagate online).
13. **Valuta unica per tenant** (campo `valuta`), non per quota.
14. **Email sempre best-effort**: ogni errore email è solo loggato; il flusso pagamenti non fallisce mai per colpa delle email.
15. **Importo in centesimi Stripe**: `(int) round(importo * 100)` — usare round, non cast, per evitare errori di floating point.
16. **`createQuotaForSocio` (helper statico, usato dal flusso iscrizione soci)**: crea quota per l'anno corrente; scadenza = 31/12 anno corrente se `tipo_scadenza_default='solare'`, oggi+1 anno se 'annuale'; `stato`/'data_pagamento' coerenti col fatto che il pagamento sia contestuale (`dataPagamento != null` → 'Pagata') o meno ('Da Pagare').
17. **Webhook senza CSRF e senza auth applicativa** (corretto per design): la sicurezza è firma (Stripe) / capture server-side (PayPal).
18. Messaggi di errore pubblici sempre generici; dettagli solo in `error_log`.

---

## 9. Comportamenti per ruolo

Il modulo legacy distingue i ruoli in modo molto semplice:

- **Guard comune**: qualunque utente del pannello (super_admin, admin_associazione, operatore) accede a `pages/quote.php` purché loggato e con `associazione_id` in sessione. **Non esistono restrizioni per ruolo all'interno della pagina**: tutte le azioni (CRUD, registra pagamento, genera link) sono disponibili a chiunque acceda.
- **super_admin**: la variabile `is_super_admin` viene calcolata ma di fatto non cambia il comportamento della pagina; il super_admin opera sull'associazione correntemente selezionata in sessione (il cambio tenant avviene altrove, es. pages/associazioni.php). Vede quindi le quote di un tenant alla volta.
- **admin_associazione / operatore**: vincolati al proprio `associazione_id` di sessione; ogni query è scoped, quindi non possono leggere/modificare quote di altri tenant nemmeno manipolando gli ID (le WHERE includono sempre `associazione_id`).
- **Socio (non autenticato)**: interagisce solo con la pagina pubblica `pagamento.php` tramite token.

**Raccomandazione Laravel**: formalizzare con Policy — es. `operatore` può registrare pagamenti e generare link ma non eliminare quote; `admin_associazione` full CRUD sul proprio tenant; `super_admin` full CRUD su qualunque tenant. (Il legacy non lo fa; decidere col product owner se mantenere la parità o introdurre le restrizioni.)

---

## 10. Note per l'implementazione Laravel

### 10.1 Modellazione

- **Modelli**: `Quota` (tabella `quote`), `PaymentGatewaySetting`, più relazioni verso `Socio`, `Associazione`, `Tessera`.
- **Multi-tenancy**: global scope `BelongsToAssociazione` su `Quota` (e su tutto), tenant dalla sessione/utente. Il lookup per token in `pagamento` deve usare `withoutGlobalScope` (il tenant deriva dalla quota).
- **Stato quota**: enum PHP `QuotaStatus { Pagata, Scaduta, InScadenza, DaPagare }` + accessor calcolato `status` secondo §3.1; non fidarsi della colonna `stato` (mantenerla solo per compat o droppare con migration di normalizzazione).
- **Casts**: `importo` → `decimal:2`, `data_scadenza`/`data_pagamento` → `date`, `payment_token_expires` → `datetime`.
- **Segreti gateway**: cast `encrypted` di Eloquent su `stripe_secret_key`, `stripe_webhook_secret`, `paypal_client_secret` (sostituisce decryptValue custom). Rinominare le colonne senza suffisso `_encrypted` in migration.

### 10.2 Routing

| Legacy | Laravel suggerito |
|---|---|
| `index.php?page=quote` (+ filtri, edit, pay) | `GET /quote` (Controller resource) — auth web |
| POST CRUD/pagamento su stessa pagina | `POST/PUT/DELETE /quote/{quota}`, `POST /quote/{quota}/pagamento`, `POST /quote/{quota}/payment-link` |
| `pagamento.php?token=` | `GET /pagamento/{token}` route pubblica con route-model-binding custom sul token (regex `[a-f0-9]{64}`) |
| POST gateway su pagamento.php | `POST /pagamento/{token}/checkout` (campo gateway) |
| `/api/webhooks/stripe.php` | `POST /webhooks/stripe` — escludere da CSRF (`$except` in VerifyCsrfToken) |
| `/api/webhooks/paypal.php` | `POST /webhooks/paypal` — idem |

### 10.3 Service layer

Riproporre `PaymentService` come servizio iniettabile con interfaccia per gateway (`StripeGateway`, `PayPalGateway`) configurati per-tenant. Usare `stripe/stripe-php` e `paypal/paypal-server-sdk` (o chiamate HTTP dirette alle Orders API v2).

### 10.4 Webhook: requisiti rispetto al legacy

- Stripe: stessa logica (verify signature con secret per-tenant ricavato da `metadata.associazione_id`; processare solo `checkout.session.completed`; ack 200 per gli altri eventi e per eventi senza metadata).
- PayPal: stessa correlazione via `custom_id` → quota → tenant; capture nel webhook su `CHECKOUT.ORDER.APPROVED`; **aggiungere la verifica firma** con `paypal_webhook_id` (fix sicurezza, §8.4).
- **Aggiungere idempotenza**: dentro una transaction, skip se la quota ha già `data_pagamento` o `gateway_transaction_id`; rispondere comunque 200.
- Processare il post-pagamento (attivazione, tessera, email) preferibilmente in un Job/evento `QuotaPagata` per tenere il webhook veloce; le email come queued Mailables/Notifications (parità col sistema a coda legacy, priorità 3).

### 10.5 Validazioni da introdurre (mancanti nel legacy)

- FormRequest creazione/modifica quota: `socio_id` exists nel tenant, `anno` integer ragionevole (es. 2000–2100), `importo` numeric > 0, `data_scadenza` date, `tipo` string max 100.
- Registrazione pagamento: `payment_method` in whitelist, `payment_date` date obbligatoria per metodi offline; rifiutare se quota già pagata.
- Generazione link: rifiutare se quota già pagata; verificare che almeno un gateway sia **completamente** configurato (check forte di §6.2, non quello debole di §4.6).
- Eliminazione: policy/conferma; valutare blocco per quote con `gateway_transaction_id` (tracciabilità contabile).

### 10.6 Concorrenza e robustezza

- Numero tessera: generare dentro `DB::transaction` con `lockForUpdate`, o passare a sequenza dedicata per associazione.
- Pagamenti: transaction unica per UPDATE quota + attivazione socio + tessera; email fuori transaction.
- Token: tabella o colonna invariata va bene; valutare `hash('sha256', token)` at-rest se si vuole hardening (il legacy salva il token in chiaro).

### 10.7 UI (parità funzionale)

- Lista quote con filtro stato (calcolato — può ora essere fatto in SQL con CASE/whereRaw visto che la logica è deterministica su date) e filtro anno; badge colore come §3.1; formattazione importi it-IT; doppio layout responsive.
- Autocomplete soci (endpoint JSON scoped sul tenant, min 1 char, debounce).
- Modal pagamento con toggle campo data per metodi online e nota informativa.
- Dopo generazione link: mostrare il link copiabile (migliorabile: bottone copy + invio email diretto al socio, assente nel legacy).
- Pagina pubblica: replicare gli stati success/scaduto/già pagato/form e il branding per associazione (logo, nome, footer copyright); colore brand #FF7B11.

### 10.8 Configurazione gateway (pagina settings, da reimplementare)

CRUD della riga `payment_gateway_settings` per tenant (upsert, una riga per associazione): toggle Stripe/PayPal, chiavi (le secret mai ri-mostrate in chiaro), `paypal_mode` sandbox/live, `paypal_webhook_id`, `valuta` (ISO 3 lettere, default EUR), flag `auto_attivazione_pagamento` (default TRUE). I gateway risultano operativi solo con set completo di credenziali (§4.6/§6.2).

### 10.9 Testing minimo richiesto

- Unit: calcolo stato quota (4 rami + soglia 30gg), generazione token (formato/scadenza), conversione importo→centesimi.
- Feature: CRUD scoped per tenant (un tenant non tocca quote altrui), registrazione pagamento offline + log + email queued, generazione link, pagina pubblica nei 4 stati, webhook Stripe (firma valida/invalida, evento ignorato, success path), webhook PayPal (capture mock, custom_id mancante, quota inesistente → 404), auto-attivazione (socio In Attesa → Attivo + tessera idempotente), idempotenza webhook duplicato.
