# Specifica funzionale — Core Helpers, Routing, Sidebar, Login/Logout Admin

Modulo: **core-helpers**
Fonti analizzate (app PHP legacy): `config.php`, `index.php`, `includes/sidebar.php`, `auth/login.php`, `auth/logout.php`.

Questa specifica descrive in modo completo il comportamento del nucleo dell'applicazione "Gestione Associazioni" (SaaS multi-tenant per la gestione di soci di associazioni) così come implementato nel codice legacy, per consentire la reimplementazione in Laravel senza consultare il codice originale.

---

## 1. Contesto architetturale

- Applicazione **multi-tenant**: ogni dato è isolato per associazione tramite la colonna `associazione_id` (CHAR(36), UUID v4).
- **Tutte le chiavi primarie** sono UUID v4 in formato `CHAR(36)` generate lato applicazione (non auto-increment, non `UUID()` MySQL).
- Due tipologie di utenti autenticati:
  - **Utenti backend** (tabella `utenti`): amministratori/operatori che usano il pannello.
  - **Soci** (tabella `soci`): hanno un'area soci separata, fuori dallo scope di questo modulo.
- Ruoli backend (colonna `utenti.ruolo`, valori stringa):
  - `super_admin` — gestore della piattaforma; tipicamente con `associazione_id = NULL`; può operare su tutte le associazioni selezionandone una.
  - `admin_associazione` — amministratore di una singola associazione (sempre legato a un `associazione_id`).
  - `operatore` — utente operativo di una singola associazione (sempre legato a un `associazione_id`).
- DB: MySQL/MariaDB, charset `utf8mb4`, accesso via PDO con `ERRMODE_EXCEPTION`, fetch associativo, prepared statements reali (`EMULATE_PREPARES = false`).
- UI: Bootstrap 5.3.2 servito in locale, brand color arancione `#FF7B11`, icone Bootstrap Icons, font Inter (woff2 locale). L'app è una PWA (manifest + service worker registrato in homepage).

---

## 2. Bootstrap applicativo (`config.php`)

Il file `config.php` è incluso da ogni entrypoint e svolge, nell'ordine:

### 2.1 Security headers HTTP

Inviati **prima di qualsiasi output**, MA saltati se:
- è definita la costante `INSTALLER_ACTIVE` (richiesta dall'installer web), oppure
- è definita la costante `API_REQUEST` (le API REST gestiscono i propri header), oppure
- gli header sono già stati inviati (`headers_sent()`).

Header inviati:

| Header | Valore |
|---|---|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `X-XSS-Protection` | `1; mode=block` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=()` |
| `Content-Security-Policy` | vedi sotto |

**Content-Security-Policy** (costruita dinamicamente):
- `default-src 'self'`
- `style-src` / `style-src-elem`: `'self' 'unsafe-inline'` + CDN jsdelivr e cdnjs (sia https che http)
- `script-src`: `'self' 'unsafe-inline'` + CDN; **aggiunge `'unsafe-eval'` SOLO se** la pagina corrente (`$_GET['page']`) è una di: `email-templates`, `comunicazioni`, `configurazioni` (pagine che usano l'editor GrapesJS, che richiede eval)
- `img-src 'self' data:`
- `connect-src 'self'`
- `font-src 'self'` + CDN
- `worker-src 'self'`
- Se la richiesta è HTTPS (rilevata da `$_SERVER['HTTPS']` oppure `X-Forwarded-Proto: https`), la CSP è prefissata con `upgrade-insecure-requests;`. In HTTP locale NON viene aggiunta (evita ERR_CONNECTION_CLOSED in sviluppo).

### 2.2 Avvio sessione sicura

Eseguito solo se la sessione non è già attiva E non è definita `API_REQUEST` (le API sono stateless):

- `session.cookie_httponly = 1`
- `session.cookie_secure = 1` solo se HTTPS attivo (stessa rilevazione HTTPS/X-Forwarded-Proto di cui sopra), altrimenti `0`
- `session.use_strict_mode = 1`
- `session.cookie_samesite = Strict`
- `session_start()`
- **Rigenerazione periodica anti session-fixation**: in sessione viene tenuto `$_SESSION['last_regeneration']` (timestamp). Se assente → `session_regenerate_id(true)` e set del timestamp. Se più vecchio di **3600 secondi** → rigenera di nuovo e aggiorna il timestamp.

### 2.3 Caricamento ambiente (.env)

- Se esiste un file `.env` nella root, viene parsato riga per riga: righe che iniziano con `#` sono commenti; righe con `=` vengono splittate in `chiave=valore` (split sul primo `=`), trim su entrambi, e caricate in `$_ENV`.
- Costanti DB (definite solo se non già definite): `DB_HOST` (default `127.0.0.1`), `DB_NAME` (default `associazione_soci_saas`), `DB_USER` (default `root`), `DB_PASS` (default stringa vuota — nessuna password di default per sicurezza), `DB_CHARSET` = `utf8mb4`.
- Costanti percorso: `APP_ROOT` = directory del file config, `UPLOADS_PATH` = `APP_ROOT . '/uploads'`.
- Costante `APP_ENCRYPTION_KEY` da `$_ENV['APP_ENCRYPTION_KEY']` (default stringa vuota): deve essere una stringa esadecimale di 64 caratteri (chiave AES-256 da 32 byte).

### 2.4 Verifica installazione (lock file)

- L'installazione è considerata completata se esiste il file `.installed` nella root.
- Se `.installed` NON esiste: redirect a `install.php`, TRANNE quando:
  - è definita `INSTALLER_ACTIVE` o `API_REQUEST`, oppure
  - lo script corrente (basename di `PHP_SELF`) è in una whitelist di pagine pubbliche che funzionano senza installazione: `install.php`, `verifica-tessera.php`, `checkin.php`, `scanner-tessera.php`, `preiscrizione.php`, `privacy-policy.php`, `pagamento.php`.

### 2.5 Connessione DB

- PDO MySQL con DSN `mysql:host=...;dbname=...;charset=utf8mb4` e opzioni: `ERRMODE_EXCEPTION`, `FETCH_ASSOC` di default, `EMULATE_PREPARES = false`.
- In caso di `PDOException` alla connessione:
  - Se NON installer/API/pagina in whitelist → redirect a `install.php` (assunto: DB non configurato = installazione mancante).
  - Altrimenti: log dell'errore con `error_log()`; se `API_REQUEST` → risposta HTTP 500 JSON `{"success": false, "error": "Errore di connessione al database."}`; altrimenti `die()` con messaggio generico in italiano. **Mai esporre il messaggio dell'eccezione all'utente.**

### 2.6 Directory upload

Alla fine del bootstrap vengono create (se mancanti) le directory `uploads/` e `uploads/logos/` con permessi `0755` ricorsivi.

---

## 3. Helper functions globali

Tutte le funzioni sono definite con guard `function_exists()` (idempotenza in caso di include multipli). In Laravel diventeranno helper globali, metodi di servizi, o saranno sostituite da feature native (vedi §9).

### 3.1 Sanitizzazione input/output

| Funzione | Firma | Comportamento | Note |
|---|---|---|---|
| `sanitizeInput($input)` | string → string | `htmlspecialchars(trim($input), ENT_QUOTES \| ENT_HTML5, 'UTF-8')` | **LEGACY**: HTML-encoda al salvataggio. Mantenuta solo per retrocompatibilità; NON usare per nuovi salvataggi. |
| `cleanInput($input)` | string → string | `trim()` + rimozione caratteri di controllo ASCII non stampabili (`\x00-\x08`, `\x0B`, `\x0C`, `\x0E-\x1F`, `\x7F`) preservando CR, LF e TAB. **Nessun HTML-encoding.** | Pattern preferito: dati salvati "puliti" ma raw, escape solo al render. |
| `escapeOutput($data)` | mixed → mixed | Se array: applica ricorsivamente se stessa via `array_map`. Altrimenti `htmlspecialchars($data, ENT_QUOTES \| ENT_HTML5, 'UTF-8')` | Da usare SEMPRE al render. |

**Regola di business implicita**: il dato in DB può essere "raw" (cleanInput) oppure già HTML-encodato (legacy sanitizeInput); il render fa sempre escape. Questo significa che dati legacy possono apparire doppio-encodati — comportamento accettato.

### 3.2 CSRF

| Funzione | Comportamento |
|---|---|
| `generateCSRFToken()` | Se `$_SESSION['csrf_token']` non esiste, lo genera con `bin2hex(random_bytes(32))` (64 char hex). Ritorna il token. **Un token per sessione**, non per-form. |
| `validateCSRFToken($token)` | Ritorna `true` solo se il token di sessione esiste E `hash_equals($_SESSION['csrf_token'], $token)` (confronto timing-safe). |

Uso convenzionale nell'app: ogni form POST include `<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">` e ogni handler POST chiama `validateCSRFToken($_POST['csrf_token'] ?? '')` prima di processare.

### 3.3 UUID

`generateUuid()`: genera un **UUID v4** in formato canonico `xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx` (36 caratteri, lowercase hex) usando `mt_rand` con i bit di versione (4) e variante (8/9/a/b) corretti. Usato per **ogni** chiave primaria inserita dall'applicazione.

> Nota: l'implementazione legacy usa `mt_rand` (non crittograficamente sicuro). In Laravel usare `Str::uuid()` che è equivalente funzionalmente e più sicuro.

### 3.4 Validazione input

`validateInput($input, $type = 'string', $max_length = 255)` → ritorna il valore validato/trimmato oppure **`false`** se non valido:

| `$type` | Regola |
|---|---|
| `email` | `FILTER_VALIDATE_EMAIL`; se valido ritorna `trim($input)` |
| `int` | `FILTER_VALIDATE_INT` (ritorna l'int o `false`) |
| `uuid` | regex `^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$` case-insensitive; ritorna l'input invariato |
| `alpha` | regex `^[a-zA-Z\s]+$`; ritorna trim |
| `alphanumeric` | regex `^[a-zA-Z0-9\s_-]+$` (lettere, numeri, spazi, underscore, trattino); ritorna trim |
| `string` (default) | trim; valido se `strlen <= $max_length` |

**Edge case**: il chiamante deve distinguere `false` (invalido) da valori falsy validi (es. `"0"`). Il router usa `=== false`.

### 3.5 Autenticazione e redirect

| Funzione | Comportamento |
|---|---|
| `isUserLoggedIn(array $allowed_roles = [])` | `false` se mancano `$_SESSION['user_id']` O `$_SESSION['user_role']`. Se `$allowed_roles` è vuoto → `true` (basta essere loggati). Altrimenti `true` solo se `$_SESSION['user_role']` è dentro `$allowed_roles` (es. `isUserLoggedIn(['super_admin','admin_associazione'])`). |
| `redirect($url)` | Se gli header non sono ancora inviati: `header("Location: $url")` + `exit`. **Fallback**: se gli header sono già partiti, emette `<script>window.location.href = '...';</script>` (URL escaped con `htmlspecialchars` ENT_QUOTES) + `exit`. |

### 3.6 Logging attività socio

`logSocioActivity($pdo, $associazione_id, $socio_id, $tipo_attivita, $descrizione, $dettagli_json = null)`:

- INSERT nella tabella **`storico_attivita_socio`** con colonne:
  - `id` — nuovo UUID generato
  - `associazione_id` — tenant
  - `socio_id` — socio interessato
  - `utente_id` — `$_SESSION['user_id']` se presente, altrimenti `NULL` (azioni di sistema o area soci senza utente backend)
  - `tipo_attivita` — stringa libera categorizzante (es. "creazione", "modifica", "tessera_emessa", ...)
  - `descrizione` — testo leggibile
  - `dettagli_json` — JSON opzionale con dettagli strutturati (nullable)
- **Fail-silent**: qualsiasi `PDOException` viene catturata e loggata con `error_log()`; il logging non deve MAI far fallire l'operazione principale.

### 3.7 File upload

| Funzione | Comportamento |
|---|---|
| `validateFileUpload($file, $allowed_types = ['jpg','jpeg','png','pdf','doc','docx'], $max_size = 5242880)` | Ritorna **array di errori** (vuoto = OK). Controlli in ordine: (1) `is_uploaded_file($file['tmp_name'])` — se fallisce ritorna subito `["File non valido"]`; (2) size > max (default 5MB) → errore con MB formattati; (3) estensione (lowercase) non in whitelist → errore; (4) MIME reale via `finfo` confrontato con mappa estensione→MIME attesa (`jpg/jpeg`→`image/jpeg`, `png`→`image/png`, `pdf`→`application/pdf`, `doc`→`application/msword`, `docx`→MIME openxml) — mismatch → errore. Edge case: se l'estensione non è nella mappa MIME, il check MIME viene saltato. |
| `generateSecureFileName($original_name, $prefix = '')` | `prefix + uniqid() + '_' + time() + '.' + estensione_originale`. Il nome originale non viene mai riusato (anti path traversal / collisioni). |
| `sanitizeFilePath($path)` | Rimuove sequenze `../` e `..\`, rimuove null byte, poi sostituisce con `_` ogni carattere diverso da `[a-zA-Z0-9._-]`. |

### 3.8 Crittografia simmetrica

| Funzione | Comportamento |
|---|---|
| `encryptValue(string $plaintext): string` | AES-256-CBC. Chiave = `hex2bin(APP_ENCRYPTION_KEY)`; se la chiave non è 32 byte → `RuntimeException` con messaggio "APP_ENCRYPTION_KEY must be a 64-char hex string.". IV random 16 byte. Output: `base64_encode(iv . ciphertext)`. |
| `decryptValue(string $encoded): string` | Decodifica base64 strict; richiede lunghezza ≥ 17 byte (16 IV + almeno 1 cifrato), altrimenti `RuntimeException`. Estrae IV (primi 16 byte) e decifra. Failure → `RuntimeException`. |

Usata per segreti di pagamento (chiavi Stripe/PayPal) salvati cifrati nel DB.

### 3.9 Introspezione schema e auto-migrazione soft

| Funzione | Comportamento |
|---|---|
| `tableExists(PDO $pdo, $tableName)` | Query su `information_schema.TABLES` con `TABLE_SCHEMA = DATABASE()`. `false` anche su eccezione. |
| `columnExists(PDO $pdo, $table, $column)` | Idem su `information_schema.COLUMNS`. |
| `ensureTesseraCostColumns(PDO $pdo)` | **Auto-migrate soft** (best effort, eccezioni ignorate): aggiunge se mancanti (1) `associazioni.costo_tessera DECIMAL(10,2) NULL` dopo `telefono`; (2) `tipi_socio.costo_tessera DECIMAL(10,2) NULL` dopo `descrizione`; (3) `tessere.evento_creazione_id CHAR(36) NULL` dopo `template_tessera` + indice `idx_tessere_evento_creazione`. |

> In Laravel queste funzioni spariscono: si usano migration vere. Vanno però **portate le colonne** nelle migration (vedi §9).

### 3.10 Template e presentazione

| Funzione | Comportamento |
|---|---|
| `renderTemplatePlaceholders($template, array $vars)` | Sostituisce placeholder nel formato `{KEY}` (solo `A-Z`, `0-9`, `_` maiuscoli) con `$vars['KEY']` castato a stringa. **Placeholder non presenti in `$vars` restano invariati nel testo** (non vengono svuotati). |
| `formatDate($date, $format = 'd/m/Y')` | Formato data italiana. Input vuoto/null → stringa vuota. Parsing via `DateTime`; data non parsabile → stringa vuota (mai eccezioni al chiamante). |
| `showAnimatedNotification($message, $type = 'success', $autoHide = true)` | Emette un blocco `<script>` che, al DOMContentLoaded, chiama `window.animationManager.showNotification(message, type)` (messaggio e tipo passati via `json_encode` — sicuro per XSS). Se `$autoHide` è true E il tipo NON è `danger`/`error`, la notifica si auto-nasconde dopo 5 secondi con animazione GSAP (collasso altezza/opacità 0.5s). Gli errori restano visibili finché l'utente non li chiude. |

### 3.11 Configurazione campi obbligatori per associazione

`loadRequiredFieldsConfig(PDO $pdo, string $associazione_id): array`

- Legge la colonna **`associazioni.campi_obbligatori_config`** (JSON, nullable) per l'associazione data.
- Ritorna un array a due contesti, `backend` e `preiscrizione`, dove ogni chiave-campo è un booleano "obbligatorio sì/no".
- **Default se config NULL/mai salvata** (regola di business importante):

| Campo | backend | preiscrizione |
|---|---|---|
| `telefono` | false | false |
| `codice_fiscale` | false | false |
| `data_nascita` | **true** | **true** |
| `indirizzo` | false | false |
| `citta` | false | false |
| `provincia` | false | false |
| `cap` | false | false |
| `tipo_socio_id` | false | false |
| `categoria_socio_id` | false | false |
| `sede_id` | false | — (non esiste nel contesto preiscrizione) |
| `privacy_consenso` | false | **true** |
| `note` | false | — (non esiste nel contesto preiscrizione) |

- **Merge difensivo**: il JSON salvato viene mergiato sui default; chiavi sconosciute nel JSON vengono **ignorate** (solo i campi presenti nei default possono essere sovrascritti); i valori vengono castati a bool. Errori DB → log + ritorno dei default.

`isFieldRequired(array $config, string $context, string $field_name): bool` → `!empty($config[$context][$field_name])`. Contesto o campo inesistente → `false` (non obbligatorio).

### 3.12 Configurazione gateway di pagamento

`loadPaymentGatewayConfig(PDO $pdo, string $associazione_id): ?array`

- Se la tabella **`payment_gateway_settings`** non esiste → `null` (tolleranza per installazioni non aggiornate).
- `SELECT * FROM payment_gateway_settings WHERE associazione_id = ? LIMIT 1` (una riga di config per associazione).
- Se non c'è riga → `null`.
- Decifra in-place i campi cifrati aggiungendo le chiavi in chiaro alla riga:
  - `stripe_secret_key_encrypted` → `stripe_secret_key`
  - `stripe_webhook_secret_encrypted` → `stripe_webhook_secret`
  - `paypal_client_secret_encrypted` → `paypal_client_secret`
- Per ciascuno: se il campo cifrato è vuoto → chiave in chiaro = stringa vuota; se la decifratura lancia `RuntimeException` → chiave in chiaro = stringa vuota (mai propagare l'errore). Errori PDO → log + `null`.

---

## 4. Router principale (`index.php`)

### 4.1 Flusso

1. `ob_start()` — output buffering attivo per consentire redirect anche dopo output parziale.
2. `require config.php` (bootstrap completo: sessione, DB, helper).
3. **Guard di autenticazione**: se `!isUserLoggedIn()` e la pagina richiesta (`$_GET['page']`, default `dashboard`) non è `login` → `redirect('auth/login.php')`. (Il guard NON controlla ruoli: ogni pagina fa i propri check di ruolo internamente.)
4. **Risoluzione pagina**: `$page_key = validateInput($_GET['page'] ?? 'dashboard', 'alphanumeric', 50)`; se validazione fallisce → `dashboard`.
5. Lookup in whitelist `$allowed_pages`; se la chiave non esiste → `http_response_code(404)` + pagina `pages/404.php` (resa DENTRO il layout).
6. **Pagine raw** (senza layout): `genera-tessera-pdf`, `stampa-tessera` → include diretto del file e `exit` (producono output standalone: PDF/stampa).
7. Tutte le altre pagine: render del **layout HTML completo** con la pagina inclusa dentro `<main>`.
8. `ob_end_flush()` finale.

### 4.2 Mappa route (whitelist `page` → file)

| `?page=` | File | Area |
|---|---|---|
| `dashboard` (default) | `pages/dashboard.php` | Menu principale |
| `soci` | `pages/soci.php` | Menu principale |
| `eventi` | `pages/eventi.php` | Menu principale |
| `quote` | `pages/quote.php` | Menu principale |
| `tessere` | `pages/tessere.php` | Menu principale |
| `comunicazioni` | `pages/comunicazioni.php` | Email & Notifiche |
| `documenti` | `pages/documenti.php` | Menu principale |
| `verbali` | `pages/verbali.php` | (non in sidebar) |
| `scadenze` | `pages/scadenze.php` | (non in sidebar) |
| `partecipanti` | `pages/partecipanti.php` | (non in sidebar) |
| `configurazioni` | `pages/configurazioni.php` | Amministrazione |
| `amministratori` | `pages/amministratori.php` | Amministrazione |
| `categorie-socio` | `pages/categorie-socio.php` | Amministrazione |
| `tipi-socio` | `pages/tipi-socio.php` | Amministrazione |
| `sezioni` | `pages/sezioni.php` | (non in sidebar) |
| `config_campi` | `pages/config_campi.php` | Amministrazione |
| `config_tags` | `pages/config_tags.php` | Amministrazione |
| `config_gruppi` | `pages/config_gruppi.php` | Amministrazione |
| `socio_dettaglio` | `pages/socio_dettaglio.php` | (drill-down da soci) |
| `stampa-tessera` | `pages/stampa-tessera.php` | **RAW, no layout** |
| `genera-tessera-pdf` | `pages/genera-tessera-pdf.php` | **RAW, no layout** |
| `associazioni` | `pages/associazioni.php` | Amministrazione (solo super_admin) |
| `email-impostazioni` | `pages/email_impostazioni.php` | Email & Notifiche |
| `email-templates` | `pages/email_templates.php` | Email & Notifiche |
| `email-coda` | `pages/email_coda.php` | Email & Notifiche |
| `email-log` | `pages/email_log.php` | Email & Notifiche |
| `api-keys` | `pages/api_keys.php` | API REST |
| `api-docs` | `pages/api_docs.php` | API REST |
| `export` | `api/export.php` | Azione export (dentro layout — peculiarità legacy) |

**Nota sui nomi route**: contengono sia trattini (`tipi-socio`) che underscore (`config_campi`) — il validatore `alphanumeric` accetta entrambi. In Laravel normalizzare ma mantenere gli URL o predisporre redirect.

### 4.3 Layout HTML

- `<html lang="it">`, `<title>` = `$_SESSION['app_name']` (escaped) con fallback "Associazione Soci Manager".
- Asset locali: Bootstrap CSS/JS bundle, Bootstrap Icons, `assets/css/style.css`, `assets/js/main.js`.
- PWA: `<meta name="theme-color" content="#FF7B11">`, `apple-mobile-web-app-capable`, `manifest.json`, apple-touch-icon `assets/icons/icon-192.png`, registrazione `service-worker.js` (errori ignorati).
- **Mobile header** (visibile solo `d-md-none` e solo se loggato): bottone toggle sidebar (`#sidebarToggle`, icona hamburger + label "Menu"), nome associazione centrato, spacer 70px; più overlay `#sidebarOverlay` per chiusura sidebar mobile.
- La sidebar è inclusa **solo se l'utente è loggato**.
- Contenuto principale: `<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content" id="mainContent">` contenente `<div class="page-container" data-page="<page_key>">` (l'attributo `data-page` è usato dal JS per animazioni/comportamenti per-pagina). ⚠️ Nel legacy `$page_key` è stampato senza escape: in Laravel fare escape comunque (è già validato alphanumeric, ma difesa in profondità).

---

## 5. Sidebar (`includes/sidebar.php`)

Markup: `<nav class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse" id="mainSidebar">`, contenuto sticky.

### 5.1 Brand

In alto: icona `bi-people-fill` + **nome dell'associazione corrente** da `$_SESSION['associazione_nome']` (escaped), fallback "Manager".

### 5.2 Selettore associazione (SOLO `super_admin`)

Visibile solo se `$_SESSION['user_role'] === 'super_admin'`:

- Carica `SELECT id, nome FROM associazioni WHERE attiva = 1 ORDER BY nome` (errori DB → lista vuota, nessun crash).
- `<select>` con prima opzione vuota "-- Seleziona --" e un'opzione per associazione attiva; selezionata quella con id uguale a `$_SESSION['associazione_id']` (confronto come stringhe).
- `onchange` JS:
  - valore scelto → `window.location = 'index.php?page=dashboard&assoc_id=<id>'` (la dashboard gestisce il **context switch**: imposta `$_SESSION['associazione_id']` e `associazione_nome` su quella scelta — comportamento implementato in `pages/dashboard.php`, fuori da questi file ma implicito nel contratto).
  - opzione vuota → `window.location = 'index.php?page=dashboard&switch_assoc=1'` (deseleziona l'associazione corrente: il super_admin torna in modalità "nessuna associazione").

**Regola di business**: il super_admin "impersona" un tenant alla volta. Tutto il menu operativo dipende dall'aver selezionato un'associazione.

### 5.3 Variabile chiave `sidebar_has_assoc`

`$sidebar_has_assoc = !empty($_SESSION['associazione_id'])`. Per `admin_associazione` e `operatore` è sempre true (legati a un tenant); per `super_admin` è true solo dopo la selezione.

### 5.4 Sezione "Menu Principale" (visibile solo se `sidebar_has_assoc`)

| Voce | Link | Icona | Stato attivo |
|---|---|---|---|
| Dashboard | `index.php?page=dashboard` | `bi-speedometer2` | attivo anche quando `page` assente (default) |
| Soci | `?page=soci` | `bi-people` | `page == 'soci'` |
| Tessere | `?page=tessere` | `bi-credit-card-2-front` | |
| Quote | `?page=quote` | `bi-credit-card` | |
| Eventi | `?page=eventi` | `bi-calendar-event` | |
| **Email & Notifiche** (submenu collassabile `#emailSubmenu`) | — | `bi-envelope` | aperto/espanso se `page` ∈ {`comunicazioni`,`email-templates`,`email-coda`,`email-log`,`email-impostazioni`} |
| ↳ Invia Comunicazione | `?page=comunicazioni` | `bi-send` | |
| ↳ Template Email | `?page=email-templates` | `bi-palette` | |
| ↳ Coda Invio | `?page=email-coda` | `bi-hourglass-split` | |
| ↳ Storico Email | `?page=email-log` | `bi-clock-history` | |
| ↳ Impostazioni SMTP | `?page=email-impostazioni` | `bi-gear` | |
| Documenti | `?page=documenti` | `bi-file-earmark-text` | |

Se `sidebar_has_assoc` è false (super_admin senza selezione): al posto del menu compare un alert warning "Seleziona un'associazione per accedere al menu."

### 5.5 Sezione "Amministrazione" (sempre presente come heading)

| Voce | Link | Icona | Visibilità |
|---|---|---|---|
| Associazioni | `?page=associazioni` | `bi-buildings` | **solo `super_admin`** |
| Impostazioni | `?page=configurazioni` | `bi-gear` | solo con associazione selezionata |
| Utenti | `?page=amministratori` | `bi-person-badge` | solo con associazione selezionata |
| Sedi | `?page=configurazioni&section=sedi` | `bi-geo-alt` | solo con associazione selezionata; attiva quando `page=configurazioni` E `section=sedi` (sottosezione via query param aggiuntivo) |
| Campi Personalizzati | `?page=config_campi` | `bi-journal-plus` | solo con associazione selezionata |
| Gestione Tag | `?page=config_tags` | `bi-tags` | solo con associazione selezionata |
| Categorie Socio | `?page=categorie-socio` | `bi-bookmark-star` | solo con associazione selezionata |
| Tipi Socio | `?page=tipi-socio` | `bi-person-badge` | solo con associazione selezionata |
| Gruppi Dinamici | `?page=config_gruppi` | `bi-collection` | solo con associazione selezionata |
| **API REST** (submenu `#apiSubmenu`) | — | `bi-hdd-network` | visibile se associazione selezionata **OPPURE** ruolo `super_admin` (il super_admin vede le API anche senza tenant: esistono chiavi API globali) |
| ↳ Chiavi API | `?page=api-keys` | `bi-key` | |
| ↳ Documentazione | `?page=api-docs` | `bi-book` | |

**Comportamento per ruolo (riassunto)**:
- `super_admin`: selettore associazione; voce "Associazioni" sempre; menu principale e amministrazione tenant solo dopo selezione; "API REST" sempre.
- `admin_associazione` e `operatore`: **nella sidebar legacy vedono le stesse voci** (nessuna differenziazione admin/operatore a livello di menu — eventuali restrizioni per `operatore` sono dentro le singole pagine). Niente selettore, niente voce "Associazioni".

### 5.6 Footer sidebar

- Blocco utente: icona `bi-person-circle`, `$_SESSION['user_nome_completo']` (escaped, fallback "Utente") e sotto, in piccolo, il **ruolo raw** (`$_SESSION['user_role']`, fallback "ruolo").
- Bottone Logout: link a `auth/logout.php`, stile `btn-outline-danger btn-sm w-100`, icona `bi-box-arrow-right`.

---

## 6. Login admin (`auth/login.php`)

### 6.1 Flusso

1. Include `config.php` (sessione attiva).
2. Se già loggato (`isUserLoggedIn()` senza ruoli) → redirect a `../index.php?page=dashboard`.
3. Su POST:
   - `$email = sanitizeInput($_POST['email'] ?? '')`; `$password = $_POST['password'] ?? ''` (la password NON viene sanitizzata — deve restare raw per `password_verify`).
   - **Validazione**: se email o password vuote → errore "Email e password sono obbligatori."
   - Query: `SELECT id, associazione_id, nome, cognome, email, password_hash, ruolo, attivo FROM utenti WHERE email = ?`.
   - Verifica password con `password_verify($password, $user['password_hash'])` (hash bcrypt/argon via `password_hash`).
   - Se utente non trovato O password errata → errore generico **"Credenziali non valide."** (stesso messaggio per entrambi i casi: niente user enumeration).
   - Se credenziali OK ma `attivo` falsy → errore "Il tuo account è stato disattivato." (⚠️ il check `attivo` avviene DOPO la verifica password — un account disattivato riceve un messaggio diverso, quindi conferma implicitamente che le credenziali sono giuste; replicare comunque il comportamento).
   - Se tutto OK:
     1. `session_regenerate_id(true)` (anti session-fixation al privilege change).
     2. Variabili di sessione impostate:
        - `user_id` = `utenti.id`
        - `user_email` = `utenti.email`
        - `user_nome_completo` = `nome . ' ' . cognome`
        - `user_role` = `utenti.ruolo`
        - `associazione_id` = `utenti.associazione_id` (può essere `NULL` per super_admin — fondamentale per il multi-tenancy)
     3. Se `associazione_id` non nullo: `SELECT nome FROM associazioni WHERE id = ?` → `$_SESSION['associazione_nome']`; altrimenti `associazione_nome = null`.
     4. `UPDATE utenti SET last_login = CURRENT_TIMESTAMP WHERE id = ?`.
     5. Redirect a `../index.php?page=dashboard`.
   - `PDOException` → errore generico "Errore del sistema di autenticazione. Riprova più tardi." + `error_log()` del dettaglio.

### 6.2 Note implicite

- **Nessun token CSRF sul form di login** nel legacy (in Laravel aggiungerlo: è gratis).
- **Nessun rate limiting / lockout** nel legacy (in Laravel aggiungere throttle).
- La query per email non filtra per `attivo`: il filtro avviene a livello applicativo dopo la verifica password.
- Si assume `utenti.email` univoca (fetch singolo).
- Form: campi `email` (type email, required) e `password` (required), submit "Accedi". Pagina standalone (non usa il layout), brand box arancione, card bianca, errore mostrato in alert rosso sopra la card (escaped).

### 6.3 Tabelle/colonne usate dal login

| Tabella | Colonne lette | Colonne scritte |
|---|---|---|
| `utenti` | `id`, `associazione_id`, `nome`, `cognome`, `email`, `password_hash`, `ruolo`, `attivo` | `last_login` (timestamp) |
| `associazioni` | `id`, `nome` | — |

---

## 7. Logout admin (`auth/logout.php`)

1. Include `config.php` (riattiva la sessione esistente).
2. `$_SESSION = []` (svuota tutte le variabili).
3. Se `session.use_cookies`: invalida il cookie di sessione impostandolo scaduto (`time() - 42000`) con gli stessi parametri (path, domain, secure, httponly) della sessione corrente.
4. `session_destroy()`.
5. Redirect a `login.php` (relativo: `auth/login.php`).

Nessuna conferma, nessun check CSRF (è un GET link dal bottone sidebar). In Laravel: usare POST + CSRF per il logout, mantenendo un redirect alla login.

---

## 8. Riepilogo tabelle DB toccate da questo modulo

| Tabella | Colonne usate qui | Note |
|---|---|---|
| `utenti` | `id` CHAR(36) PK, `associazione_id` CHAR(36) NULL, `nome`, `cognome`, `email` (unique), `password_hash`, `ruolo` ENUM/string (`super_admin`,`admin_associazione`,`operatore`), `attivo` BOOL, `last_login` TIMESTAMP NULL | Account backend |
| `associazioni` | `id` CHAR(36) PK, `nome`, `attiva` BOOL, `telefono`, `costo_tessera` DECIMAL(10,2) NULL, `campi_obbligatori_config` JSON/TEXT NULL | Tenant |
| `storico_attivita_socio` | `id` CHAR(36) PK, `associazione_id`, `socio_id`, `utente_id` NULL, `tipo_attivita`, `descrizione`, `dettagli_json` NULL (+ presumibilmente created_at di default) | Audit trail socio |
| `tipi_socio` | `costo_tessera` DECIMAL(10,2) NULL (dopo `descrizione`) | Toccata solo dall'auto-migrate |
| `tessere` | `evento_creazione_id` CHAR(36) NULL + indice | Toccata solo dall'auto-migrate |
| `payment_gateway_settings` | `associazione_id` (1 riga per tenant), `stripe_secret_key_encrypted`, `stripe_webhook_secret_encrypted`, `paypal_client_secret_encrypted` + altre colonne config (lette con `SELECT *`) | Segreti cifrati AES-256-CBC |

Stato sessione gestito dal modulo: `user_id`, `user_email`, `user_nome_completo`, `user_role`, `associazione_id`, `associazione_nome`, `csrf_token`, `last_regeneration`, `app_name` (letto dal layout, impostato altrove).

---

## 9. Note per l'implementazione Laravel

1. **Sessione/CSRF**: eliminare `generateCSRFToken`/`validateCSRFToken` e la rigenerazione manuale — usare il middleware `VerifyCsrfToken`, `@csrf` nei form e `session()->regenerate()` al login (Laravel lo fa con `Auth::login` + regenerate). Configurare `session.same_site = strict`, `http_only = true`, `secure` dinamico via `SESSION_SECURE_COOKIE`.
2. **Auth**: guard `web` con provider sulla tabella `utenti` (model `Utente`), campo password `password_hash` (override `getAuthPasswordName()`), login via email. Replicare: messaggio unico "Credenziali non valide.", check `attivo` (consigliato spostarlo PRIMA del messaggio differenziato o accettare il leak come da legacy — decidere), update `last_login`, set in sessione di `associazione_id`/`associazione_nome` (o meglio: derivarli dal model + un servizio `TenantContext`). Aggiungere throttle login (`ThrottlesLogins` o RateLimiter) e CSRF, assenti nel legacy.
3. **Ruoli**: `isUserLoggedIn($roles)` → middleware `role:super_admin,admin_associazione` + Gate/Policy. I tre ruoli sono stringhe su `utenti.ruolo`. Nel core legacy `operatore` non ha restrizioni di menu: le differenze stanno nelle pagine, non qui.
4. **Multi-tenancy**: sostituire `$_SESSION['associazione_id']` con un servizio `TenantContext` (sessione + utente). Il **context switch del super_admin** (select in sidebar → `assoc_id` / `switch_assoc=1` sulla dashboard) va reimplementato come route dedicata (es. `POST /tenant/switch`) che valida che l'associazione esista e sia `attiva = 1`, aggiorna il contesto e ridirige alla dashboard. Lo "switch off" (nessun tenant) è uno stato valido per il solo super_admin.
5. **Routing**: la whitelist `?page=` diventa route nominate Laravel; mantenere gli stessi URL pubblici non è necessario, ma mappare 1:1 le 28 pagine (vedi §4.2). `genera-tessera-pdf` e `stampa-tessera` sono response senza layout (PDF/print view). Pagina inesistente → `abort(404)` con view 404 dentro il layout. Il default è la dashboard. Il guard auth va su tutto il gruppo tranne login.
6. **UUID**: `HasUuids` sui model (o `Str::uuid()`), colonne `CHAR(36)`/`uuid`. Sostituisce `generateUuid()` con generazione crittograficamente sicura (miglioria compatibile).
7. **Helper di sanitizzazione**: Blade fa già escape al render (`{{ }}`) → `escapeOutput` sparisce. `cleanInput` (strip control chars, no HTML-encode) può diventare un cast/`Str` macro o un middleware `TrimStrings` esteso. NON riprodurre `sanitizeInput` per i nuovi salvataggi; serve solo da conoscere per i **dati legacy già HTML-encodati** in un'eventuale migrazione dati.
8. **Validazione**: `validateInput` → Form Request / regole `email`, `integer`, `uuid`, regex per alpha/alphanumeric (attenzione: la regola alphanumeric legacy ammette spazi, `_` e `-`).
9. **Audit socio**: `logSocioActivity` → servizio/trait `LogsSocioActivity` o evento+listener che inserisce in `storico_attivita_socio`; deve restare **fail-silent** (try/catch + `report()`), con `utente_id` = utente autenticato o null.
10. **Crittografia**: `encryptValue`/`decryptValue` → `Crypt::encryptString`. ⚠️ Se si migrano dati esistenti, i segreti in `payment_gateway_settings` sono in formato custom `base64(iv16 + AES-256-CBC)` con chiave hex `APP_ENCRYPTION_KEY`: serve un comando di migrazione che decifri col formato vecchio e ricifri con `Crypt`.
11. **Upload**: `validateFileUpload`/`generateSecureFileName`/`sanitizeFilePath` → regole `file|max:5120|mimes:jpg,jpeg,png,pdf,doc,docx` (Laravel verifica il MIME reale) + `Storage` con nomi hash automatici.
12. **Config campi obbligatori**: portare `loadRequiredFieldsConfig` come servizio/value object `RequiredFieldsConfig` con gli **stessi default** (§3.11 — `data_nascita` obbligatoria di default in entrambi i contesti, `privacy_consenso` obbligatoria di default solo in preiscrizione) e lo stesso merge difensivo. Cast `array`/`AsArrayObject` sulla colonna JSON.
13. **Security headers/CSP**: middleware dedicato che replica gli header §2.1; la CSP con `unsafe-eval` condizionale per le pagine GrapesJS va legata alle route corrispondenti (comunicazioni, email-templates, configurazioni). Valutare nonce CSP al posto di `unsafe-inline` come miglioria.
14. **Installer**: il lock-file `.installed` e il redirect a `install.php` vanno ripensati (es. check `migrate:status` o file flag in `storage/`); l'elenco di pagine pubbliche bypass (§2.4) corrisponde alle route pubbliche da escludere dal middleware: verifica tessera, check-in, scanner, preiscrizione, privacy policy, pagamento.
15. **Auto-migrazioni soft**: eliminare `ensureTesseraCostColumns`/`tableExists`/`columnExists`; includere nelle migration le colonne `associazioni.costo_tessera`, `tipi_socio.costo_tessera`, `tessere.evento_creazione_id` (+ indice) e la tabella `payment_gateway_settings`.
16. **Sidebar**: componente Blade unico con: brand = nome tenant; select switch tenant (solo super_admin, associazioni `attiva = 1` ordinate per nome); menu principale visibile solo con tenant attivo (altrimenti alert "Seleziona un'associazione"); sezione Amministrazione con "Associazioni" solo super_admin; submenu collassabili Email e API (stato aperto se la route corrente appartiene al gruppo); footer con nome utente, ruolo e logout (in Laravel: form POST). Mobile: header con toggle + overlay come da §4.3.
17. **Logout**: `Auth::logout()` + `session()->invalidate()` + `regenerateToken()` + redirect a login; passare da GET a POST.
18. **Notifiche animate**: `showAnimatedNotification` → flash session + componente toast frontend; conservare la regola "gli errori non si auto-nascondono, i successi spariscono dopo 5s".
19. **Template placeholder**: portare `renderTemplatePlaceholders` tale e quale (regex `\{[A-Z0-9_]+\}`, placeholder ignoti lasciati intatti) — è usato da tessere/email.
20. **Date**: `formatDate` → Carbon `format('d/m/Y')` con tolleranza a null/invalid → stringa vuota (helper o cast).
