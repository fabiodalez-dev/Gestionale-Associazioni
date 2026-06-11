# Specifica funzionale — API REST v1, Export, Notifiche, Backup, Area Soci, PWA

Modulo: **API + Area Soci** dell'applicazione "Gestione Associazioni" (SaaS multi-tenant).
Documento derivato dall'analisi completa del codice PHP legacy. Permette la reimplementazione in Laravel senza consultare il codice originale.

Contesto multi-tenant: ogni dato applicativo è scopato da `associazione_id` (CHAR(36) UUID v4). Tutte le PK sono UUID CHAR(36). Ruoli amministrativi: `super_admin` (accesso cross-associazione), `admin_associazione`, `operatore` (sessione admin con `$_SESSION['user_id']`, `$_SESSION['user_role']`, `$_SESSION['associazione_id']`). I soci hanno un login separato (sessione `$_SESSION['socio_id']`) sulla tabella `soci`.

---

## 1. API REST v1

### 1.1 Architettura generale

- **Base URL**: `/api/v1/{endpoint}`. Un router unico (`api/v1/index.php`) smista le richieste; un `.htaccess` riscrive ogni path inesistente su `index.php/{path}` (`RewriteCond !-f / !-d`, `RewriteRule ^(.*)$ index.php/$1 [QSA,L]`), preserva l'header `Authorization` (`SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`) e imposta header CORS anche a livello Apache.
- **Stateless**: la costante `API_REQUEST` viene definita prima di caricare la configurazione; questo impedisce l'avvio della sessione PHP e i redirect verso l'installer. L'API non usa mai sessioni né CSRF.
- **Risoluzione endpoint**: il path viene letto da `PATH_INFO` o `ORIG_PATH_INFO`; in fallback dal query param `?endpoint=` (utile per server senza PATH_INFO). Il path viene trimmato di `/` ed esploso in segmenti.
- **Metodo HTTP**: `$_SERVER['REQUEST_METHOD']` maiuscolo, con supporto override `_method` nei POST form (`$_POST['_method']` → es. PUT via form). In Laravel: il method spoofing è nativo.
- **Auto-migrazione**: ad ogni richiesta il router invoca `ensureApiKeysTable()` che crea la tabella `api_keys` se mancante e, se esiste con `associazione_id NOT NULL`, la migra a `NULL`-able ricreando la FK con `ON DELETE SET NULL`. In Laravel: sostituire con migration normale (vedi §9).

### 1.2 CORS e preflight

- Header su ogni risposta: `Access-Control-Allow-Origin: *`, `Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS`, `Access-Control-Allow-Headers: Authorization, Content-Type, Accept`, `Access-Control-Max-Age: 3600`.
- Richieste `OPTIONS` (preflight): risposta `204 No Content` immediata con i soli header CORS, senza autenticazione.

### 1.3 Formato risposte

- **Successo**: JSON con `Content-Type: application/json; charset=utf-8`, flag `JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT`, sempre con chiave `"success": true` + payload specifico. Liste paginate includono `total`, `page`, `limit` e l'array della risorsa (`soci`, `tessere`, `eventi`, `quote`). Le ricerche (non paginate) includono `count` + array.
- **Errore**: status HTTP appropriato e body:
  ```json
  { "success": false, "error": "Messaggio in italiano", "code": "codice_macchina" }
  ```
- **Codici errore usati** (status → code):
  - 400 `invalid_tessera_id`, `missing_query`, `invalid_id`, `missing_socio`, `socio_not_active`, `invalid_stato`, `empty_body`, `no_valid_fields`, `invalid_json`
  - 401 `auth_missing`, `auth_invalid`
  - 403 `key_disabled`, `association_disabled`, `key_expired`, `permission_denied`, `global_only`
  - 404 `not_found`, `tessera_not_found`, `socio_not_found`
  - 405 `method_not_allowed`
  - 409 `duplicate_email`
  - 500 `db_error`

### 1.4 Autenticazione — Bearer token su tabella `api_keys`

**Tabella `api_keys`** (colonne usate):

| Colonna | Tipo | Note |
|---|---|---|
| `id` | CHAR(36) PK | UUID v4 |
| `associazione_id` | CHAR(36) NULL, FK → `associazioni.id` ON DELETE SET NULL | `NULL` = **chiave globale** (solo super_admin) |
| `api_key` | VARCHAR(64) UNIQUE NOT NULL | 64 char esadecimali = 256 bit (`bin2hex(random_bytes(32))`) |
| `nome` | VARCHAR(255) NOT NULL | etichetta descrittiva |
| `permessi` | JSON NOT NULL | array di stringhe, es. `["tessere:read","soci:*"]` |
| `attiva` | BOOLEAN DEFAULT TRUE | toggle on/off |
| `ultimo_utilizzo` | DATETIME NULL | aggiornata a NOW() ad ogni richiesta autenticata |
| `scadenza` | DATE NULL | NULL = senza scadenza |
| `created_at` / `updated_at` | DATETIME | default CURRENT_TIMESTAMP / ON UPDATE |
| Indici | `idx_api_key(api_key)`, `idx_api_assoc(associazione_id)` | |

**Flusso di autenticazione** (`apiAuthenticate`):
1. Estrai il token dall'header `Authorization` (anche da `REDIRECT_HTTP_AUTHORIZATION` o `apache_request_headers()` come fallback CGI/FastCGI). Deve matchare esattamente `^Bearer\s+([a-fA-F0-9]{64})$` — token non esadecimale o di lunghezza diversa = come mancante.
2. Token assente → 401 `auth_missing` con messaggio che spiega il formato `Authorization: Bearer {api_key}`.
3. Lookup: `SELECT ak.*, a.nome as associazione_nome, a.attiva as associazione_attiva FROM api_keys ak LEFT JOIN associazioni a ON ak.associazione_id = a.id WHERE ak.api_key = ?`. Nessuna riga → 401 `auth_invalid`.
4. `attiva = 0` → 403 `key_disabled`.
5. Chiave scopata (`associazione_id NOT NULL`) con associazione **non attiva** (`associazioni.attiva = 0`) → 403 `association_disabled`. Le chiavi globali saltano questo check.
6. `scadenza NOT NULL` e `scadenza < oggi` (confronto stringa `Y-m-d`) → 403 `key_expired`. La chiave scade quindi **dal giorno successivo** alla data di scadenza (il giorno stesso è ancora valido).
7. Decodifica `permessi` (JSON → array, fallback `[]`), calcola `is_globale = (associazione_id === NULL)`.
8. Aggiorna `ultimo_utilizzo = NOW()` fire-and-forget (eventuale errore solo loggato, non blocca la richiesta).

**Chiavi globali (super_admin)**: una chiave con `associazione_id = NULL` accede ai dati di **tutte** le associazioni. Comportamenti specifici:
- Endpoint `GET /associazioni` disponibile **solo** per chiavi globali (le scopate ricevono 403 `global_only`).
- Le liste/ricerche per chiavi globali aggiungono ai risultati le colonne `associazione_nome` e `associazione_id` (JOIN extra su `associazioni`).
- Filtro opzionale `?associazione_id={uuid}` sul query string: per chiavi globali restringe i risultati a una sola associazione (validato con regex `^[a-f0-9-]{36}$` case-insensitive; se non valido viene ignorato silenziosamente e si ritorna tutto). Per chiavi scopate il parametro è ignorato: il filtro è sempre forzato all'associazione della chiave.

**Sistema permessi** (`apiHasPermission`):
- `*` nel JSON = accesso totale.
- Match diretto: `"soci:read"` ∈ permessi.
- Wildcard di categoria: `"soci:*"` copre `soci:read` e `soci:write`.
- Permessi definiti: `tessere:read`, `tessere:write` (riservato, nessun endpoint lo usa oggi), `soci:read`, `soci:write`, `eventi:read`, `eventi:write`, `quote:read`, `*`.
- Permesso mancante → 403 `permission_denied` con messaggio `"Permesso '{permission}' non concesso a questa API key."`.

**Rate limiting**: ⚠️ **NON implementato** nel codice legacy (citato solo nel docblock del middleware). In Laravel è raccomandato aggiungerlo (vedi §9).

**Body JSON**: per POST/PUT il body è letto da `php://input`; vuoto → array vuoto; JSON malformato (non array) → 400 `invalid_json`.

### 1.5 Endpoint root — `GET /api/v1/`

Autenticato. Restituisce un indice "self-documenting":
```json
{
  "success": true,
  "message": "Associazione Soci Manager API v1",
  "endpoints": { "GET /tessere": "Lista tessere", ... },
  "associazione": "Nome associazione"   // oppure "Tutte (chiave globale)"
}
```
Per chiavi globali aggiunge `"GET /associazioni"` alla lista endpoint.

### 1.6 `GET /associazioni` (solo chiavi globali)

- Metodo diverso da GET → 405. Chiave non globale → 403 `global_only`.
- Query: `SELECT id, nome, email, citta, provincia, attiva FROM associazioni WHERE attiva = 1 ORDER BY nome`.
- Risposta: `{ success, count, associazioni: [...] }`.

### 1.7 Endpoint Tessere

Tabelle: `tessere` (id, numero_tessera, anno_validita, data_emissione, data_scadenza, stato, tipo_scadenza, socio_id, associazione_id), `soci` (nome, cognome, numero_socio), `associazioni` (nome).
Stati tessera (enum): `Attiva`, `Scaduta`, `Sospesa`, `Annullata`.

**`GET /tessere/verify?tessera_id={UUID}` — PUBBLICO, senza autenticazione.**
- Intercettato dal router **prima** dell'autenticazione (i primi due segmenti devono essere `tessere/verify`).
- `tessera_id` validato con regex UUID completa `^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$` (case-insensitive); mancante/invalido → 400 `invalid_tessera_id`.
- Query con JOIN soci + associazioni su `t.id = ?` (cross-tenant: nessun filtro associazione — l'UUID stesso è il segreto, proviene dal QR code stampato sulla tessera).
- Non trovata → 404 `tessera_not_found`.
- **Regola di validità**: `valid = (stato === 'Attiva' AND data_scadenza >= oggi)`.
- Risposta: `{ success, valid, tessera: {numero_tessera, anno_validita(int), data_emissione, data_scadenza, stato}, socio: {nome, cognome, numero_socio}, associazione: "<nome>" }`. **Non** espone l'associazione_id né dati sensibili del socio.
- Lo stesso handler è raggiungibile anche autenticato via `GET /tessere/verify` (richiede comunque `tessere:read` perché passa dal dispatcher tessere).

**Tutti gli altri endpoint tessere**: solo GET (altrimenti 405), permesso `tessere:read`.

**`GET /tessere/search?q={query}`**
- `q` obbligatorio, min 1 carattere (trim) → altrimenti 400 `missing_query`.
- LIKE `%q%` su: `t.numero_tessera`, `s.cognome`, `s.nome`, `s.numero_socio`. JOIN `soci`.
- Filtro associazione (§1.4). LIMIT 50, ordinata per `anno_validita DESC, numero_tessera`.
- Campi: id, numero_tessera, anno_validita, data_emissione, data_scadenza, stato, socio_nome, socio_cognome, numero_socio, socio_id (+ associazione_nome/associazione_id se globale).
- Risposta `{ success, count, tessere }`.

**`GET /tessere/{id}`** (segmento 1 matcha `^[a-f0-9-]{36}$`)
- `SELECT t.*` + dati socio; per chiavi scopate aggiunge `AND t.associazione_id = ?` (una tessera di un altro tenant risulta 404, non 403). Non trovata → 404 `not_found`.
- Risposta `{ success, tessera }` (tutte le colonne della tessera).

**`GET /tessere`** (lista paginata)
- Parametri: `page` (default 1, min 1), `limit` (default 20, range 1–100), `anno` (numerico → filtro `anno_validita`), `stato` (whitelist `Attiva|Scaduta|Sospesa|Annullata`, valori fuori lista ignorati).
- Count query separata, poi pagina ordinata `anno_validita DESC, numero_tessera` con LIMIT/OFFSET.
- Risposta `{ success, total, page, limit, tessere }` (stessi campi della search).

### 1.8 Endpoint Soci

Tabelle: `soci` (id, numero_socio, nome, cognome, email, telefono, data_nascita, codice_fiscale, indirizzo, citta, provincia, cap, data_iscrizione, stato, note, tipo_socio_id, categoria_socio_id, associazione_id), `tipi_socio` (nome), `categorie_socio` (nome), `socio_tags` (socio_id, tag_id), `tags` (nome_tag, colore), `tessere`.
Stati socio (enum): `Attivo`, `Sospeso`, `Radiato`, `Deceduto`, `Trasferito`.

**`GET /soci/search?q={query}`** — permesso `soci:read`
- `q` min **2** caratteri → altrimenti 400 `missing_query`.
- LIKE `%q%` su: `cognome`, `nome`, `numero_socio`, `email`, `codice_fiscale`.
- LIMIT 50, ordine `cognome, nome`. Campi: id, numero_socio, nome, cognome, email, telefono, stato, data_iscrizione (+ extra globale).
- Risposta `{ success, count, soci }`.

**`GET /soci/{id}`** — permesso `soci:read`
- Dettaglio: anagrafica completa (campi sopra) + `tipo_socio` e `categoria_socio` (nomi via LEFT JOIN). Scoping come per tessere (404 cross-tenant).
- Arricchimenti:
  - `tags`: lista `{nome_tag, colore}` via `socio_tags JOIN tags` (⚠️ nel legacy questa query NON è scopata per associazione, filtra solo per socio_id).
  - `tessere`: ultime 5 tessere `{id, numero_tessera, anno_validita, data_scadenza, stato}` ordinate `anno_validita DESC` (con filtro associazione per chiavi scopate).
- Risposta `{ success, socio }` (tags e tessere annidate dentro `socio`).

**`PUT /soci/{id}`** — permesso `soci:write`
- Body JSON obbligatorio non vuoto → altrimenti 400 `empty_body`.
- Verifica esistenza socio (scopata per chiavi non globali) → 404 `not_found`.
- **Campi aggiornabili (whitelist)**: `nome`, `cognome`, `email`, `telefono`, `indirizzo`, `citta`, `provincia`, `cap`, `stato`, `note`. Campi non in whitelist ignorati silenziosamente.
- I valori stringa passano da `cleanInput()` (strip caratteri di controllo, trim); valori non-stringa passano invariati.
- `stato` validato contro l'enum; valore fuori lista → 400 `invalid_stato` (richiesta interamente rifiutata).
- Nessun campo valido presente → 400 `no_valid_fields`.
- UPDATE dinamico con il solo set di campi forniti; WHERE include `associazione_id` per chiavi scopate.
- Violazione UNIQUE (messaggio contiene "Duplicate entry") → 409 `duplicate_email` ("Email già presente per un altro socio"); altri errori DB → 500 `db_error` (dettaglio solo in error_log).
- Successo: `{ success: true, message: "Socio aggiornato." }` (anche se 0 righe modificate).

**`GET /soci`** (lista paginata) — permesso `soci:read`
- Parametri: `page`, `limit` (1–100, default 20), `stato` (whitelist enum). Ordine `cognome, nome`. Risposta `{ success, total, page, limit, soci }`.

- Qualsiasi altra combinazione metodo/path → 405 `method_not_allowed`.

### 1.9 Endpoint Eventi

Tabelle: `eventi` (id, titolo, descrizione, data_evento, luogo, associazione_id, …), `eventi_partecipanti` (id, associazione_id, evento_id, socio_id, stato_partecipazione, data_conferma), `soci`, `tessere`.

**`POST /eventi/{id}/checkin`** — permesso `eventi:write`. Cuore dell'app di check-in QR.
1. `{id}` validato regex UUID-like → 400 `invalid_id`.
2. Verifica evento esistente (scopato per chiavi non globali) → 404 `not_found`. ⚠️ Da qui in poi tutte le lookup usano `eventAssocId = evento.associazione_id` (l'associazione **dell'evento**, non quella della chiave) — rilevante per chiavi globali.
3. **Identificazione del socio** dal body JSON, in ordine di priorità (il primo che risolve vince):
   1. `socio_id` (UUID socio, usato direttamente)
   2. `tessera_id` (UUID tessera, da QR code): `SELECT socio_id FROM tessere WHERE id = ? AND associazione_id = ?`
   3. `numero_tessera`: stessa lookup per numero, `ORDER BY anno_validita DESC LIMIT 1` (prende la tessera più recente con quel numero)
   4. `numero_socio`: `SELECT id FROM soci WHERE numero_socio = ? AND associazione_id = ?`
   - Nessuno risolve → 400 `missing_socio` con messaggio che elenca i 4 campi.
4. Verifica socio: deve esistere **nell'associazione dell'evento** → 404 `socio_not_found`. Deve avere `stato = 'Attivo'` → 400 `socio_not_active` (il messaggio include lo stato attuale).
5. **Idempotenza**: cerca riga in `eventi_partecipanti` per `(evento_id, socio_id)`.
   - Esiste con `stato_partecipazione = 'Confermato'` → 200 `{ success: true, already_checked_in: true, message: "Socio già registrato per questo evento.", socio: {nome, cognome, numero_socio} }` (NON è un errore).
   - Esiste con altro stato (es. invitato/in attesa) → UPDATE a `stato_partecipazione = 'Confermato', data_conferma = NOW()`.
   - Non esiste → INSERT nuova riga `(id=UUID, associazione_id=eventAssocId, evento_id, socio_id, 'Confermato', NOW())`.
6. Risposta successo: `{ success: true, already_checked_in: false, message: "Check-in effettuato con successo.", socio: {nome, cognome, numero_socio}, evento: "<titolo>" }`. Errore DB → 500 `db_error`.

**`GET /eventi/{id}`** — permesso `eventi:read`
- `SELECT e.*` (scoping come al solito; 404 se assente). Arricchito con `partecipanti`: lista `{stato_partecipazione, data_conferma, socio_id, nome, cognome, numero_socio}` (JOIN soci, filtrata per `evento_id` + `associazione_id` dell'evento, ordine cognome/nome) e `totale_partecipanti` (count dell'array).
- Risposta `{ success, evento }`.

**`GET /eventi`** (lista paginata) — permesso `eventi:read`
- Parametri: `page`, `limit` (1–100), `futuri=1` (→ `data_evento >= NOW()`), `oggi=1` (→ `DATE(data_evento) = CURDATE()`); combinabili.
- Campi lista: id, titolo, descrizione, data_evento, luogo (+ extra globale). Ordine `data_evento DESC`. Risposta `{ success, total, page, limit, eventi }`.
- Metodi diversi da GET/POST-checkin → 405.

### 1.10 Endpoint Quote

Tabella: `quote` (id, socio_id, associazione_id, anno, importo, tipo, data_scadenza, data_pagamento, stato, note).
Stati quota (enum filtro): `Pagata`, `Da Pagare`, `Scaduta`, `In Scadenza`.
Solo GET (altrimenti 405), permesso `quote:read`.

**`GET /quote/{socio_id}`** — tutte le quote di un socio
- Verifica esistenza socio (scopata per chiavi non scopate → 404). Le quote sono poi filtrate per `socio_id` + `associazione_id` **del socio** (corretto anche per chiavi globali).
- Risposta: `{ success, socio: {id, nome, cognome, numero_socio [, associazione_id se globale]}, quote: [{id, anno, importo, data_scadenza, data_pagamento, stato, tipo, note}] }`, quote ordinate `anno DESC`, senza paginazione.

**`GET /quote`** (lista paginata)
- Parametri: `page`, `limit` (1–100), `anno` (numerico), `stato` (whitelist enum sopra). JOIN `soci` per nome/cognome/numero_socio.
- Ordine `anno DESC, cognome, nome`. Risposta `{ success, total, page, limit, quote }`.

### 1.11 Pattern comune di scoping multi-tenant (riassunto)

Funzione `apiAssociationFilter(column, associazioneId, filterAssocId)`:
- Chiave scopata → WHERE `colonna = :assoc_chiave` (obbligatorio, sempre).
- Chiave globale + `?associazione_id=` valido (regex `^[a-f0-9-]{36}$`) → WHERE su quel valore.
- Chiave globale senza filtro → `1=1` (tutti i tenant).
Per gli accessi a risorsa singola (`/{id}`): chiave scopata aggiunge `AND associazione_id = ?` al WHERE → la risorsa di un altro tenant produce **404**, mai 403 (non rivela esistenza).

---

## 2. Pagina amministrativa "Chiavi API" (`pages/api_keys.php`)

Pagina interna (sessione admin, layout Bootstrap). Guard: utente loggato; i non-super_admin devono avere `associazione_id` in sessione (altrimenti redirect al login).

### 2.1 Azioni POST (tutte con CSRF token obbligatorio; CSRF fallito → messaggio errore, nessuna azione)

**`create`**:
- Input: `nome` (cleanInput, obbligatorio → errore se vuoto), `scadenza` (data opzionale, vuoto → NULL), `permessi[]` (checkbox multiple; se vuoto/non-array → default `['tessere:read']`), `globale` (checkbox visibile/efficace solo per super_admin).
- `isGlobale = super_admin && globale==1`. Se non globale e nessuna `associazione_id` in sessione → errore "Seleziona un'associazione…".
- Genera `api_key = bin2hex(random_bytes(32))`, `id = UUID`, `associazione_id = NULL` se globale altrimenti quella di sessione. INSERT.
- La chiave in chiaro è mostrata **una sola volta** nel messaggio di successo ("Copiala ora, non sarà più visibile per intero"); in tabella appare poi mascherata: primi 8 + `...` + ultimi 4 caratteri. ⚠️ Nel legacy la chiave è salvata **in chiaro** nel DB (il mascheramento è solo UI) — vedi nota Laravel §9.

**`toggle`** (`key_id`): `UPDATE api_keys SET attiva = NOT attiva`. super_admin → su qualunque chiave; admin normale → solo con `AND associazione_id = ?` della propria associazione (quindi non può toccare chiavi globali né di altri tenant).

**`delete`** (`key_id`): DELETE con la stessa regola di scoping del toggle. Conferma JS lato client ("azione irreversibile").

### 2.2 Visualizzazione elenco (regole per ruolo)

| Contesto | Chiavi mostrate |
|---|---|
| super_admin **senza** associazione selezionata | TUTTE le chiavi di tutte le associazioni + globali (LEFT JOIN per nome associazione; ordinate: prima le globali, poi per associazione e created_at DESC) + alert "stai vedendo tutte" |
| super_admin **con** associazione selezionata | chiavi di quell'associazione + chiavi globali |
| admin_associazione / operatore | solo chiavi della propria associazione (mai le globali) |

Colonne tabella: nome, chiave mascherata, ambito (badge "Globale" giallo o nome associazione), permessi (badge per ciascuno), stato (Attiva/Disattivata), ultimo utilizzo (`d/m/Y H:i` o "Mai"), scadenza (`d/m/Y` in rosso se passata, o "Nessuna"), azioni toggle/delete.

### 2.3 Modal di creazione

- Switch "Chiave Globale (tutte le associazioni)" solo per super_admin, con warning evidenziato quando attivato.
- Checkbox permessi: lista §1.4 con `tessere:read` pre-selezionato; selezionando `*` un JS disabilita e auto-seleziona tutte le altre.
- Campo data scadenza opzionale.

---

## 3. Pagina "Documentazione API" (`pages/api_docs.php`)

Pagina interna statica (guard: login + `associazione_id` in sessione — qui anche super_admin deve avere un'associazione selezionata). Calcola dinamicamente il Base URL dall'host corrente (`https?://HOST/path/api/v1`). Contenuti: autenticazione Bearer, tabella permessi, formato errori con tabella codici, documentazione di ogni endpoint con esempi di richiesta/risposta, esempi client in 4 tab (cURL, JavaScript fetch, Python requests, PHP cURL), sezione "QR + Mobile" che documenta il flusso: il QR della tessera contiene l'URL `{base}/verifica-tessera.php?t={UUID}` → l'app estrae il parametro `t` e chiama `/tessere/verify` (senza auth) oppure `/eventi/{id}/checkin` (con auth). Include esempio completo con libreria `html5-qrcode`.

In Laravel: può diventare una pagina Blade statica o essere sostituita da OpenAPI/Scribe; conservare la sezione QR perché documenta il contratto del QR code.

---

## 4. Export CSV (`api/export.php`)

Endpoint web a sessione admin (NON API key). Guard: `isUserLoggedIn() && $_SESSION['associazione_id']`, altrimenti 403 con testo "Accesso negato.".

- Risposta: `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="{type}_{Y-m-d}.csv"` (⚠️ nel legacy `type` finisce nel filename senza sanitizzazione — sanitizzarlo in Laravel). Il file inizia con **BOM UTF-8** (`EF BB BF`) per compatibilità Excel. Intestazione CSV = nomi colonna del result set (alias SQL); righe via `fputcsv` (delimitatore virgola, quoting standard).
- Se il result set è vuoto, il CSV contiene solo il BOM (nessun header).
- Tipi supportati (`?type=`): `soci`, `tessere`, `quote`. Tipo sconosciuto → testo "Tipo di export non valido.". Errori DB → log + "Errore database. Riprova più tardi.".

### 4.1 `type=soci` — export soci con filtri avanzati

Colonne esportate: `numero_socio, nome, cognome, email, telefono, data_nascita, data_iscrizione, stato, sede, categoria, numero_tessera, template_tessera, data_scadenza, tessera_stato`.

Query base: `soci s LEFT JOIN sedi se ON se.id = s.sede_id LEFT JOIN categorie_socio cs ON cs.id = s.categoria_socio_id LEFT JOIN tessere tt` dove `tt` è **l'ultima tessera emessa** del socio (sub-select `MAX(data_emissione)` per socio+associazione).

Filtri da query string (rispecchiano i filtri della pagina elenco soci):
- `assoc_id`: solo se l'utente è **super_admin** e il valore ≠ `all` → esporta quell'associazione; altrimenti sempre l'associazione di sessione.
- `search`: LIKE `%…%` su nome, cognome, email.
- `status` (≠ `all`): `s.stato = ?`.
- `sede_ids[]` (array, vince su `sede_id`): `s.sede_id IN (...)`; altrimenti `sede_id` singolo ≠ `all`.
- `categoria_ids[]`: `s.categoria_socio_id IN (...)`.
- `tessera_template` (≠ `all`): `tt.template_tessera = ?`.
- `tessera_scadenza`: `in_scadenza` → `tt.data_scadenza BETWEEN oggi AND oggi + giorni_notifica_scadenza` (parametro per-associazione letto da `associazioni.giorni_notifica_scadenza`, default 30); `scadute` → `tt.data_scadenza < oggi`. Entrambi richiedono `data_scadenza IS NOT NULL`.
- `has_tessera`: `yes` → `tt.numero_tessera IS NOT NULL`; `no` → IS NULL.
- `tessera_stato` (default `attive`!): `attive` → tessera presente AND `stato='Attiva'` AND (scadenza NULL o ≥ oggi); `scadute` → tessera presente AND (`stato='Scaduta'` OR scadenza < oggi). ⚠️ Edge case implicito: **il default esclude i soci senza tessera attiva** — per esportare tutti i soci serve passare esplicitamente `tessera_stato=all` (o altro valore ≠ attive/scadute).
- Ordine: `cognome, nome`.

### 4.2 `type=tessere`

Colonne: `numero_tessera, socio (nome+cognome concatenati), associazione (nome), anno_validita, data_emissione, data_scadenza, stato`. JOIN soci + associazioni.
Filtri: `assoc_id` (stessa regola super_admin), `q` (LIKE su nome, cognome, "cognome nome", "nome cognome", numero_tessera), `status` (default **`Attiva`**; `all` = nessun filtro; `Scaduta` ha logica speciale: `stato='Scaduta' OR data_scadenza < oggi`; altri valori → match esatto su `stato`), `from_year`/`to_year` (4 cifre, range su `anno_validita`). Ordine: `anno_validita DESC, cognome ASC`.

### 4.3 `type=quote`

Query fissa, solo associazione di sessione: `socio (concat), anno, importo, tipo, data_scadenza, data_pagamento, stato` da `quote JOIN soci`, ordine `data_scadenza DESC`. Nessun filtro aggiuntivo.

---

## 5. Notifiche header (`api/notifications.php`)

Endpoint JSON a sessione admin per il campanello di notifica della UI. Guard: login + `associazione_id` (403 `{"error":"Accesso negato"}`).

Due sorgenti, ciascuna max 5 elementi:
1. **Tessere in scadenza**: `tessere t JOIN soci s` con `t.associazione_id = ?`, `t.stato='Attiva'`, `data_scadenza BETWEEN oggi AND oggi+30 giorni` (finestra fissa di 30 gg, NON usa `giorni_notifica_scadenza`), ordine scadenza ASC. → item `{ id: "tessera_{uuid}", tipo: "Tessera in Scadenza", messaggio: "<Nome Cognome>", link: "index.php?page=tessere" }`.
2. **Quote scadute non pagate**: `quote q JOIN soci s` con `data_pagamento IS NULL AND data_scadenza < oggi`, ordine scadenza DESC. → item `{ id: "quota_{uuid}", tipo: "Quota Scaduta", messaggio: "<Nome Cognome> - Scad. dd/mm/YYYY", link: "index.php?page=quote" }`.

Risposta: `{ success: true, count: N, notifications: [...] }`. Errore DB → 500 `{"error":"Errore database. Riprova più tardi."}`. È un endpoint di sola lettura, idempotente, pensato per polling.

---

## 6. Backup (`api/backup.php`)

Endpoint JSON riservato ai **super_admin** (`isUserLoggedIn(['super_admin'])`; altrimenti 403 `{"error":"Permesso negato. Funzione riservata ai Super Amministratori."}`).

⚠️ **Stato attuale: STUB.** La logica di backup originale è stata rimossa per la migrazione SaaS; oggi accetta `?action=` (ignorato) e risponde sempre `{ success: true, message: "Funzionalità di backup in manutenzione per architettura SaaS. Accesso consentito." }`. Il commento nel codice indica l'intento futuro: backup per singola associazione o totale.

In Laravel: reimplementare da zero (es. spatie/laravel-backup o job di mysqldump), mantenendo il vincolo di ruolo super_admin e prevedendo il backup scoped per associazione.

---

## 7. Ricerca utenti per super_admin (`api/users_search.php`)

Endpoint JSON di autocomplete riservato ai super_admin (403 `{"success":false,"error":"forbidden"}`).

- `q`: se vuoto o < 2 caratteri → `{ success: true, items: [] }` (200, non errore).
- Query su tabella **`users`** (⚠️ nome legacy; nel resto dell'app gli admin sono in `utenti` — verificare/uniformare in Laravel): `SELECT id, username, email, role, associazione_id WHERE (username LIKE ? OR email LIKE ?) … ORDER BY role DESC, username LIMIT 20`.
- Flag opzionali:
  - `only_admin_assoc=1` → `role IN ('admin_associazione','super_admin')`.
  - `available=1` → solo utenti non legati a un'associazione (`associazione_id IS NULL OR = ''`), per assegnare un admin a una nuova associazione.
  - `include_excluded=1` → disattiva il filtro `available` in SQL e invece annota ogni riga con `excluded: bool` e `reason: 'linked'|null` (così la UI mostra anche i non assegnabili, barrati).
- Risposta `{ success: true, items: [...] }`; errore DB → 500 `{"success":false,"error":"db_error"}`.

---

## 8. Area Soci (portale member-facing, `/area-soci/`)

Portale separato dall'amministrazione: autenticazione propria basata sulla tabella `soci`, layout Bootstrap con sidebar dedicata, colore brand #FF7B11.

### 8.1 Tabella `soci` — colonne usate dal portale

`id` (UUID), `associazione_id`, `numero_socio`, `nome`, `cognome`, `email`, `telefono`, `data_nascita`, `codice_fiscale`, `indirizzo`, `citta`, `cap`, `data_iscrizione`, `stato`, **`password_hash`** (NULL = socio senza accesso al portale), **`password_reset_token`** (64 hex), **`password_reset_expires`** (DATETIME).
Tabelle correlate: `quote`, `tessere`, `campi_personalizzati` (id, nome_campo) + `valori_campi_personalizzati` (campo_id, socio_id, valore), `socio_tags` + `tags` (nome_tag, colore).

### 8.2 Login socio (`area-soci/login.php`)

Pagina standalone con due tab: Login e Reset Password.

**Login** (POST `action=login`, campi `email`, `password`):
- `SELECT * FROM soci WHERE email = ? AND password_hash IS NOT NULL` — i soci senza password impostata non possono autenticarsi e ricevono lo stesso errore generico.
- ⚠️ Lookup **globale per email, cross-tenant**: l'email è di fatto l'identificatore unico del socio sul portale; se la stessa email esistesse in due associazioni, verrebbe presa la prima riga. In Laravel valutare unique(email) globale o login con selezione associazione.
- `password_verify()` contro `password_hash` (bcrypt/PASSWORD_DEFAULT).
- Successo: `session_regenerate_id(true)` (anti session-fixation), poi `$_SESSION['socio_id'] = id` e `$_SESSION['socio_nome'] = "nome cognome"`, redirect a `index.php`.
- Fallimento (email inesistente, senza hash, o password errata): messaggio unico "Credenziali non valide." (niente enumeration sul login).
- ⚠️ Nessun CSRF token sul form di login legacy; nessun rate-limit/throttling.

**Richiesta reset/set password** (POST `action=reset_password`, campo `email_reset`):
- Cerca il socio per email. Se non trovato → errore "Nessun socio trovato con questa email." (⚠️ il legacy fa user-enumeration qui; in Laravel preferire risposta neutra).
- Se trovato: genera `token = bin2hex(random_bytes(32))`, `expires = now + 3600s` (1 ora), salva in `password_reset_token`/`password_reset_expires` (sovrascrivendo eventuale token precedente — un solo token valido per socio).
- Costruisce il link `http://{host}{dir}/set-password.php?token={token}` (host sanificato con whitelist caratteri `[a-zA-Z0-9.\-:_]` contro header injection; ⚠️ schema hard-coded `http`).
- ⚠️ Il legacy **non invia email**: mostra il link direttamente a schermo con la nota "normalmente verrebbe inviato via email". In Laravel: inviare via Mailable/notification, non mostrare mai il link.
- Lo stesso flusso serve sia per il **primo set password** (socio creato dall'admin senza password) sia per il reset.

### 8.3 Set password (`area-soci/set-password.php`)

- GET con `?token=`: token vuoto → "Token non valido o mancante."; altrimenti `SELECT * FROM soci WHERE password_reset_token = ? AND password_reset_expires > NOW()`; nessun match → "Token non valido o scaduto. Richiedi un nuovo link." (il form non viene mostrato).
- POST (solo se token valido): **CSRF obbligatorio**; campi `password` + `password_confirm`.
  - Validazione: le due password devono coincidere E lunghezza ≥ 8 caratteri (unico requisito di complessità). Errore unico "Le password non corrispondono o sono troppo corte (min. 8 caratteri)." e il form resta compilabile.
  - Successo: `password_hash = password_hash($pw, PASSWORD_DEFAULT)`, azzera `password_reset_token = NULL` e `password_reset_expires = NULL` (token monouso). Messaggio successo + bottone "Vai al Login". Non effettua auto-login.

### 8.4 Router area soci (`area-soci/index.php`)

- Guard: `$_SESSION['socio_id']` presente, altrimenti redirect a `login.php`.
- Ricarica a ogni richiesta il record completo del socio (`SELECT * FROM soci WHERE id = ?`); se il socio è stato cancellato → `session_destroy()` + redirect login (logout forzato).
- Routing per whitelist: `?page=` ∈ {`dashboard` (default), `profilo`, `quote`, `tessere`}; chiave sconosciuta → include `pages/404.php`. Le pagine sono include che ricevono `$socio_loggato` (array riga soci) e `$pdo`.
- Layout: sidebar con menu (Dashboard, Il Mio Profilo, Le Mie Quote, Le Mie Tessere), nome socio da sessione e bottone Logout. `<title>` = "Area Riservata - Nome Cognome".
- Tutte le query delle sottopagine filtrano per `socio_id = $socio_loggato['id']` (l'isolamento è per socio, non serve filtro associazione: il socio vede solo i propri dati).

### 8.5 Dashboard socio (`pages/dashboard.php`)

- **Prossima quota**: `SELECT * FROM quote WHERE socio_id = ? AND data_pagamento IS NULL AND data_scadenza >= CURDATE() ORDER BY data_scadenza ASC LIMIT 1`. Se presente: card "La tua prossima quota di €{importo} scade il dd/mm/YYYY" + tipo; altrimenti "Non hai quote in scadenza. Sei in regola con i pagamenti!" (⚠️ implicito: quote già scadute e non pagate NON appaiono qui).
- **Tessera**: `SELECT * FROM tessere WHERE socio_id = ? AND stato = 'Attiva' ORDER BY anno_validita DESC LIMIT 1`. Card "La tua tessera (N. X) è Attiva fino al dd/mm/YYYY" oppure invito a contattare l'associazione.
- Card "Comunicazioni Recenti": placeholder statico (nessuna logica).

### 8.6 Profilo socio (`pages/profilo.php`)

- **Campi sola lettura** (input disabled, non modificabili dal socio): nome, cognome, email, numero_socio, data_nascita, data_iscrizione, stato.
- **Campi modificabili** (POST con CSRF obbligatorio): `telefono`, `indirizzo`, `citta`, `cap` — sanificati con `sanitizeInput()` e aggiornati con `UPDATE soci SET … WHERE id = ?`. Dopo il salvataggio i dati mostrati vengono aggiornati in pagina. Errore DB → messaggio generico, dettaglio in log.
- Sezione "Dati Personalizzati e Tag" (visibile solo se presenti): valori dei campi personalizzati (`valori_campi_personalizzati JOIN campi_personalizzati` per socio_id) in sola lettura + badge colorati dei tag del socio.

### 8.7 Quote socio (`pages/quote.php`)

- `SELECT * FROM quote WHERE socio_id = ? ORDER BY anno DESC, data_scadenza DESC` — tabella con Anno, Tipo, Importo (formato it: `number_format(2, ',', '.')` con prefisso €), Scadenza, Stato, Data Pagamento.
- **Stato calcolato dinamicamente** (ignora la colonna `stato` del DB!):
  1. `data_pagamento` valorizzata → **Pagata** (badge verde)
  2. `data_scadenza` < oggi → **Scaduta** (rosso)
  3. `data_scadenza` < oggi+30gg → **In Scadenza** (giallo)
  4. altrimenti → **Da Pagare** (azzurro)
- Card "Pagamento Quote": placeholder ("funzionalità in sviluppo" — nel frattempo il repo ha integrazione Stripe/PayPal in altro modulo).

### 8.8 Tessere socio (`pages/tessere.php`)

- `SELECT t.* FROM tessere WHERE socio_id = ? ORDER BY anno_validita DESC, data_emissione DESC` — tabella con Anno, Numero, Emissione, Scadenza, Tipo (`tipo_scadenza`), Stato, Documento.
- **Stato calcolato**: se `stato` ≠ 'Attiva' → mostra quello del DB; altrimenti se `data_scadenza` < oggi → "Scaduta"; se < oggi+30gg → "In Scadenza"; sennò "Attiva". Badge: Attiva=verde, In Scadenza=giallo, Scaduta=rosso, altri=grigio.
- **Download PDF tessera**: convenzione su filesystem — se esiste il file `uploads/documents/tessera_{numero_socio_sanificato}_{anno_validita}.pdf` (numero_socio ripulito con `[^A-Za-z0-9_-]` → ''), mostra bottone PDF che vi punta direttamente; altrimenti "—". ⚠️ Il file è servito staticamente, senza access-control applicativo: in Laravel servire da storage privato tramite controller autenticato.

### 8.9 Logout (`area-soci/logout.php`)

`session_destroy()` + redirect a `login.php`. ⚠️ Nel legacy la sessione è condivisa tra area admin e area soci (stesso cookie): il logout socio distrugge anche un'eventuale sessione admin. In Laravel: usare guard separati (`web` per admin, `soci` per il portale) così il problema sparisce.

---

## 9. PWA

### 9.1 `manifest.json`

```
name: "Gestione Associazioni"  · short_name: "Associazioni"
description: "Gestione soci, tessere, eventi e quote"
start_url: "./index.php" · scope: "./" · display: standalone · orientation: any
theme_color: #FF7B11 · background_color: #F3F4F6 · lang: it-IT
icons: icon.svg (any, maskable-less, purpose any), icon-192.png, icon-512.png
```

### 9.2 `service-worker.js`

Versionamento cache: `CACHE_VERSION = 'v1'` → cache `static-v1` e `dynamic-v1`.

- **install**: precache di offline.html, CSS/JS Bootstrap locali, bootstrap-icons, `assets/css/style.css`, `assets/js/main.js`, le 3 icone, manifest.json; poi `skipWaiting()`.
- **activate**: elimina tutte le cache con nome diverso dalle due correnti; `clients.claim()`.
- **fetch** — strategie:
  1. Ignora richieste non-GET e cross-origin (passano al browser).
  2. **Network-only per le API**: qualsiasi URL il cui path contenga `/api/` non viene mai intercettato/cachato (i dati devono essere sempre freschi; il check-in non deve mai rispondere da cache).
  3. **Navigazioni** (`request.mode === 'navigate'`): network-first; la risposta di rete viene clonata e salvata in `dynamic-v1`; in caso di errore di rete si serve la copia cache della pagina, e in ultima istanza `offline.html`.
  4. **Asset statici** (estensioni css/js/woff2/ttf/eot/svg/png/jpg/jpeg/gif/ico/webp/json o path contenente `/assets/`): cache-first con **revalidate in background** (stale-while-revalidate: serve la cache subito, aggiorna `static-v1` con la risposta di rete se `response.ok`).

### 9.3 `offline.html`

Pagina di fallback standalone (CSS inline, nessuna dipendenza esterna — deve funzionare offline): card centrata con icona wifi barrato arancione, titolo "Sei Offline", messaggio e bottone "Riprova" (`window.location.reload()`). Theme color #FF7B11.

---

## 10. Note per l'implementazione Laravel

**Routing & struttura**
- API v1 → `routes/api.php` con prefix `v1`: controller per `AssociazioniController` (index), `TessereController` (index, show, search, verify), `SociController` (index, show, search, update), `EventiController` (index, show, checkin), `QuoteController` (index, perSocio). `tessere/verify` fuori dal middleware auth.
- Mantenere **identici** i path, i nomi dei campi JSON, i codici errore (`code`) e la forma delle risposte (`success`, `total/page/limit`, `count`) per compatibilità con client mobili/scanner esistenti. Creare un trait/Response macro `apiResponse()` / `apiError()`.
- Method override `_method` e fallback `?endpoint=` possono essere abbandonati (Laravel gestisce nativamente lo spoofing; il rewrite è garantito).

**Autenticazione API**
- Middleware custom (o Sanctum personalizzato) che replica `apiAuthenticate`: estrazione Bearer con regex 64-hex, lookup, controlli attiva/associazione attiva/scadenza, decodifica permessi, `is_globale`, touch `ultimo_utilizzo` (meglio in `dispatchAfterResponse`/queue per non rallentare).
- **Migliorìa consigliata**: salvare in DB solo l'hash della chiave (`hash('sha256', $key)`) e un prefisso per il mascheramento UI; il legacy salva la chiave in chiaro. Decidere consapevolmente: cambia la UX della pagina chiavi (masking calcolato dal prefisso salvato).
- Sistema permessi come Gate/metodo sul model `ApiKey`: `hasPermission('soci:read')` con wildcard `*` e `categoria:*`.
- **Rate limiting**: assente nel legacy — aggiungere `throttle` (es. 60/min per chiave, più severo su `/tessere/verify` che è pubblico) e throttling sul login soci.

**Multi-tenancy**
- Replicare `apiAssociationFilter` come scope Eloquent: `scopeForApiKey($q, ApiKey $key, ?string $filterAssocId)`. Chiave scopata = filtro obbligatorio; globale = opzionale via `?associazione_id`. Risorsa singola di altro tenant → 404 (usare `firstOrFail` sul query scopato).
- Nel checkin ricordare la regola: tutte le lookup socio/tessera usano l'`associazione_id` **dell'evento** (fondamentale per chiavi globali).
- Fix da applicare: la query tags in `GET /soci/{id}` va scopata; uniformare la tabella admin (`users` vs `utenti`) per `users_search`.

**Area soci**
- Guard dedicato `soci` (provider Eloquent sul model `Socio`, campo `password_hash` → mappare `getAuthPassword()`), sessioni separate dall'admin (cookie/guard distinti) per eliminare il conflitto di logout.
- Reset password: usare il broker nativo di Laravel su tabella dedicata (o conservare le colonne `password_reset_token`/`expires` con TTL 1h e token monouso) e **inviare email reale** (Mailable); risposta neutra anche per email inesistente. HTTPS nel link (usare `url()`/APP_URL, non HTTP_HOST).
- Regole password: min 8 (legacy) — consigliato `Password::defaults()`.
- Profilo: form request con soli campi `telefono, indirizzo, citta, cap`; tutto il resto read-only server-side.
- Stati derivati di quote (Pagata/Scaduta/In Scadenza/Da Pagare, finestra 30gg) e tessere: implementarli come accessor sul model, NON fidarsi della colonna `stato` per le quote nel portale.
- PDF tessere: spostare in storage privato e servire via route autenticata che verifica `socio_id`; mantenere la convenzione `tessera_{numero_socio}_{anno}.pdf` solo come chiave di lookup.

**Export CSV**
- Controller dedicato con `streamDownload`, BOM UTF-8, `fputcsv`. Sanificare `type` nel filename. Replicare fedelmente i filtri (incluso il default insidioso `tessera_stato=attive` per i soci e `status=Attiva` per le tessere — verificare con il frontend se mantenerli o esplicitarli). `giorni_notifica_scadenza` letto dall'associazione (default 30).
- Per ruoli: il bypass tenant con `assoc_id` è permesso solo a super_admin; admin/operatore esportano solo il proprio tenant.

**Notifiche / Backup / Users search**
- Notifiche: endpoint JSON leggero per polling (o Laravel Echo in futuro); mantenere shape `{success, count, notifications:[{id, tipo, messaggio, link}]}` e i due tipi con LIMIT 5; la finestra tessere è fissa 30gg nel legacy (valutare se unificare con `giorni_notifica_scadenza`).
- Backup: il legacy è uno stub — reimplementare (spatie/laravel-backup) con policy super_admin-only e opzione per-associazione.
- Users search: endpoint autocomplete super_admin-only con i 3 flag (`only_admin_assoc`, `available`, `include_excluded`) e LIMIT 20.

**PWA**
- Riusare manifest/service-worker/offline.html quasi invariati; aggiornare i path degli asset (Vite) e la lista di precache; mantenere le tre strategie (network-only `/api/`, network-first navigazioni, cache-first+revalidate asset) e il bump di `CACHE_VERSION` ad ogni release degli asset.

**Sicurezza — gap del legacy da chiudere**
1. Nessun rate limit su API e login soci → aggiungere throttle.
2. API key in chiaro nel DB → hash.
3. CSRF assente sul login soci → nativo in Laravel.
4. User enumeration sul reset password soci → risposta neutra.
5. Link reset in pagina anziché email + schema http hard-coded → Mailable + APP_URL https.
6. PDF tessere serviti staticamente → storage privato.
7. `type` non sanificato nel filename CSV → sanificare.
8. CORS `*` con Authorization: valutare whitelist di origin configurabile.
