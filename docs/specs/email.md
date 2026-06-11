# Specifica funzionale — Modulo Email & Notifiche

Modulo email multi-tenant dell'applicazione "Gestione Associazioni". Ogni associazione (tenant, identificata da `associazione_id` CHAR(36) UUID) ha la propria configurazione SMTP, i propri template, la propria coda di invio e il proprio storico. Questa specifica descrive il comportamento osservato nell'app PHP legacy, comprese le regole implicite, e permette la reimplementazione in Laravel senza consultare il codice originale.

Fonti analizzate (sola lettura): `includes/EmailService.php`, `includes/email_helpers.php`, `pages/email_templates.php`, `pages/email_coda.php`, `pages/email_log.php`, `pages/email_impostazioni.php`, `pages/comunicazioni.php`, `api/email_unsubscribe.php`, `api/email_preview.php`, `api/email_test.php`, `api/email_queue_process.php`, `cron/process_email_queue.php`, `cron/send_reminders.php`, `cron/send_renewal_reminders.php`, `templates/email_base.html`, `database/schema.sql`.

---

## 1. Panoramica funzionale

| # | Funzionalità | Entry point legacy |
|---|---|---|
| 1 | Impostazioni SMTP per tenant + toggle notifiche automatiche + rate limit | `pages/email_impostazioni.php` |
| 2 | Invio email di test SMTP (AJAX) | `api/email_test.php` |
| 3 | Template email con placeholder e editor visuale GrapesJS | `pages/email_templates.php` |
| 4 | Comunicazioni massive con destinatari filtrati e rendering per-socio | `pages/comunicazioni.php` |
| 5 | Coda di invio con priorità, retry, annullamento, elaborazione manuale | `pages/email_coda.php` + `api/email_queue_process.php` |
| 6 | Elaborazione coda via cron (tutte le associazioni) | `cron/process_email_queue.php` |
| 7 | Log/storico invii con filtri e dettaglio | `pages/email_log.php` + `api/email_preview.php` |
| 8 | Disiscrizione pubblica (unsubscribe) con token | `api/email_unsubscribe.php` |
| 9 | Promemoria scadenze tessere e quote (cron giornaliero) | `cron/send_reminders.php` |
| 10 | Promemoria rinnovo tessera con link rinnovo + link pagamento online (cron giornaliero) | `cron/send_renewal_reminders.php` |

Componente centrale: classe `EmailService` (un'istanza per associazione), wrapper di PHPMailer che gestisce configurazione, invio, accodamento, rate limiting, template e logging.

---

## 2. Tabelle del database

### 2.1 `smtp_settings` — configurazione SMTP per tenant (1 riga per associazione)

| Colonna | Tipo | Default | Note |
|---|---|---|---|
| `id` | CHAR(36) PK | — | UUID |
| `associazione_id` | CHAR(36) NOT NULL | — | UNIQUE (`uniq_smtp_assoc`), FK → `associazioni(id)` ON DELETE CASCADE |
| `smtp_host` | VARCHAR(255) NOT NULL | `''` | |
| `smtp_port` | INT NOT NULL | 587 | |
| `smtp_user` | VARCHAR(255) NOT NULL | `''` | |
| `smtp_pass_encrypted` | TEXT NOT NULL | — | Password cifrata AES-256-CBC (vedi §10.3) |
| `smtp_encryption` | ENUM('tls','ssl','none') | 'tls' | tls = STARTTLS (587), ssl = SMTPS (465), none = nessuna (e disattiva auto-TLS) |
| `from_email` | VARCHAR(255) NOT NULL | `''` | Mittente |
| `from_name` | VARCHAR(255) NOT NULL | `''` | |
| `reply_to_email` | VARCHAR(255) NULL | NULL | Opzionale |
| `reply_to_name` | VARCHAR(255) NULL | NULL | Opzionale |
| `is_verified` | BOOLEAN | FALSE | Impostato a 1 quando un'email di test ha successo |
| `max_per_hour` | INT | 100 | Rate limit invii/ora; 0 o negativo = illimitato (ma la UI forza min 1) |
| `auto_benvenuto` | BOOLEAN | TRUE | Toggle email benvenuto nuovi soci |
| `auto_scadenza_tessera` | BOOLEAN | TRUE | Toggle promemoria scadenza tessera (usato da entrambi i cron §9 e §10) |
| `auto_scadenza_quota` | BOOLEAN | TRUE | Toggle promemoria quote da pagare |
| `auto_rinnovo_tessera` | BOOLEAN | FALSE | Toggle conferma rinnovo tessera |
| `auto_pagamento_quota` | BOOLEAN | FALSE | Toggle conferma pagamento quota |
| `last_test_at` | DATETIME NULL | NULL | Timestamp ultimo test riuscito |
| `created_at` / `updated_at` | DATETIME | CURRENT_TIMESTAMP | `updated_at` ON UPDATE |

### 2.2 `email_templates` — template per tenant

| Colonna | Tipo | Note |
|---|---|---|
| `id` | CHAR(36) PK | UUID |
| `associazione_id` | CHAR(36) NOT NULL | FK → associazioni, ON DELETE CASCADE |
| `codice` | VARCHAR(50) NOT NULL | Codice logico (es. `benvenuto`); UNIQUE (`associazione_id`,`codice`) |
| `nome` | VARCHAR(255) NOT NULL | Nome leggibile |
| `oggetto` | VARCHAR(500) NOT NULL DEFAULT '' | Oggetto con placeholder |
| `corpo_html` | TEXT NOT NULL | HTML renderizzato (output GrapesJS: `getHtml()` + `<style>` con `getCss()`) |
| `corpo_json` | TEXT NULL | Project data GrapesJS serializzato (per ricaricare l'editor) |
| `attivo` | BOOLEAN DEFAULT TRUE | Template disattivato = non utilizzabile (render restituisce null) |
| `created_at` / `updated_at` | DATETIME | |

### 2.3 `email_queue` — coda di invio

| Colonna | Tipo | Note |
|---|---|---|
| `id` | CHAR(36) PK | UUID |
| `associazione_id` | CHAR(36) NOT NULL | FK → associazioni, CASCADE |
| `batch_id` | CHAR(36) NOT NULL | UUID che raggruppa un invio massivo; indice `idx_eq_batch` |
| `socio_id` | CHAR(36) NULL | FK → soci, ON DELETE SET NULL |
| `to_email` | VARCHAR(255) NOT NULL | |
| `to_name` | VARCHAR(255) DEFAULT '' | |
| `subject` | VARCHAR(500) NOT NULL | Già renderizzato (placeholder risolti al momento dell'accodamento) |
| `body_html` | TEXT NOT NULL | Già renderizzato |
| `template_codice` | VARCHAR(50) NULL | Codice template di origine (NULL per email libere) |
| `priority` | TINYINT DEFAULT 5 | Priorità: ordinamento ASC (numero più basso = elaborata prima). Tutto il codice attuale usa 5 |
| `status` | ENUM('pending','sending','sent','failed','cancelled') DEFAULT 'pending' | Indice `idx_eq_status (status, created_at)` |
| `attempts` | INT DEFAULT 0 | Tentativi effettuati |
| `max_attempts` | INT DEFAULT 3 | Limite tentativi |
| `last_attempt_at` | DATETIME NULL | |
| `error_message` | TEXT NULL | Ultimo errore SMTP |
| `sent_at` | DATETIME NULL | |
| `created_at` | DATETIME DEFAULT CURRENT_TIMESTAMP | |

### 2.4 `email_log` — storico permanente (audit)

| Colonna | Tipo | Note |
|---|---|---|
| `id` | CHAR(36) PK | UUID |
| `associazione_id` | CHAR(36) NOT NULL | FK CASCADE; indice |
| `batch_id` | CHAR(36) NULL | Indice `idx_el_batch` |
| `socio_id` | CHAR(36) NULL | FK → soci, SET NULL |
| `to_email`, `to_name`, `subject`, `template_codice` | come queue | |
| `status` | ENUM('sent','failed') NOT NULL | Solo esiti finali: si logga al primo invio riuscito o quando i tentativi sono esauriti |
| `error_message` | TEXT NULL | |
| `sent_by` | CHAR(36) NULL | FK → `utenti(id)` SET NULL. Valorizzato da `$_SESSION['user_id']` se l'invio avviene in contesto web; NULL nei cron → mostrato come "Sistema" |
| `sent_at` | DATETIME DEFAULT CURRENT_TIMESTAMP | Indice `idx_el_sent_at` |

Nota implicita: il body HTML NON viene salvato nel log; il dettaglio email lo recupera con un JOIN sulla queue (vedi §7.2).

### 2.5 Colonne usate su tabelle esterne

- `soci`: `email`, `nome`, `cognome`, `numero_socio`, `data_iscrizione`, `stato` (filtro `= 'Attivo'`), `categoria_socio_id`, `sede_id`, `tipo_socio_id`, **`email_opt_out`** BOOLEAN DEFAULT FALSE, **`email_opt_out_token`** CHAR(64) NULL, **`rinnovo_token`** CHAR(64) NULL (+ indice), **`rinnovo_token_expires`** DATETIME NULL.
- `associazioni`: `nome`, `email`, `logo_url`, `attiva` (filtro nei cron), `giorni_notifica_scadenza` INT DEFAULT 30, `costo_tessera`.
- `tessere`: `id`, `numero_tessera`, `data_scadenza`, `anno_validita`, `stato` (filtro `= 'Attiva'`), `socio_id`, `associazione_id`.
- `quote`: `id`, `importo`, `anno`, `data_scadenza`, `tipo`, `data_pagamento` (NULL = non pagata), `stato` (`'Da Pagare'`), `socio_id`, `associazione_id`.
- `categorie_socio`, `sedi`, `tags` (`nome_tag`), `socio_tags` (pivot `socio_id`,`tag_id`), `gruppi_dinamici` (`nome_gruppo`, `filtri_json`): usate per filtrare i destinatari.
- `utenti`: `nome`, `cognome` per visualizzare "Inviata da" nel log.
- `tipi_socio`: `costo_tessera` per calcolare l'importo della quota di rinnovo.

---

## 3. EmailService — comportamento core

Istanziato con (connessione DB, `associazione_id`). Tutte le query sono scoping per tenant.

### 3.1 Caricamento configurazione
- `loadSmtpConfig()`: `SELECT * FROM smtp_settings WHERE associazione_id = ? LIMIT 1`, con cache in memoria per istanza (caricata una sola volta, anche se NULL).
- `isConfigured()`: vero se esiste la riga E `smtp_host !== ''` E `from_email !== ''`. **Nota:** la password e lo user non sono richiesti per considerare configurato.

### 3.2 Invio singolo (`sendSingle`)
1. Se config assente → errore "SMTP non configurato.".
2. Verifica rate limit (§3.6); se superato → errore "Limite invii orario raggiunto.".
3. Crea il mailer PHPMailer:
   - `isSMTP()`, host/port da config, `SMTPAuth = true`, username da config;
   - password decifrata da `smtp_pass_encrypted` (AES-256-CBC, §10.3);
   - encryption: `tls` → STARTTLS; `ssl` → SMTPS; `none` → nessun secure + `SMTPAutoTLS = false`;
   - `setFrom(from_email, from_name)` (from_name vuoto se non impostato);
   - `addReplyTo(reply_to_email, reply_to_name)` solo se `reply_to_email` non vuoto;
   - charset UTF-8, HTML mode, body con `msgHTML()` (genera anche AltBody testuale).
4. Eccezioni PHPMailer: log su error_log, ritorno `{success: false, error: <messaggio eccezione>}`. Il messaggio d'errore SMTP grezzo viene salvato in `error_message` di queue/log e mostrato nell'esito del test (mai stack trace).

### 3.3 Email di test (`sendTestEmail`)
- Oggetto: `Test SMTP — {from_name}` (fallback "Associazione" se from_name vuoto).
- Corpo fisso: `<h2>Test riuscito!</h2><p>La configurazione SMTP funziona correttamente.</p>`.
- **Non passa dal rate limit** e non viene loggata in `email_log`.
- In caso di successo: `UPDATE smtp_settings SET last_test_at = NOW(), is_verified = 1 WHERE associazione_id = ?`.

### 3.4 Accodamento
- `queueEmail(to, name, subject, html, socioId?, batchId, codice?, priority=5)` → INSERT in `email_queue` con UUID, ritorna l'id.
- `queueBulk(recipients[], subject, html, codice?)` → genera un `batch_id` unico, una INSERT per destinatario (stesso subject/body per tutti), priorità fissa 5, ritorna il batch_id. (Nota: la pagina comunicazioni NON usa queueBulk perché deve personalizzare il body per socio — fa INSERT dirette con lo stesso schema.)

### 3.5 Elaborazione coda (`processQueue(batchSize = 20)`)
Algoritmo (per singola associazione):
1. Seleziona fino a `batchSize` righe con `status IN ('pending','failed') AND attempts < max_attempts`, ordinate per `priority ASC, created_at ASC`. **Regola implicita:** anche le righe `failed` con tentativi residui vengono riprese automaticamente ad ogni run (retry automatico senza intervento).
2. Per ogni riga:
   a. Se il rate limit orario è raggiunto → interrompe il ciclo (le restanti rimangono in coda).
   b. `UPDATE ... SET status='sending', last_attempt_at=NOW(), attempts=attempts+1` (marcatura prima dell'invio).
   c. Invia con `sendSingle`.
   d. Successo → `status='sent', sent_at=NOW(), error_message=NULL` + riga in `email_log` con status `sent`.
   e. Fallimento → se `attempts+1 >= max_attempts` allora `status='failed'` E riga in `email_log` con status `failed` e l'errore; altrimenti `status='pending'` (ritenterà al run successivo) e solo `error_message` aggiornato. **Quindi in `email_log` finiscono solo gli esiti definitivi.**
3. Ritorna `{sent, failed, remaining}` dove `remaining` = COUNT delle righe ancora `pending` con `attempts < max_attempts`. (`failed` nel risultato conta i fallimenti del run, anche non definitivi.)

Edge case noti del legacy (da correggere in Laravel, vedi §11):
- Nessun lock/transazione: due elaborazioni concorrenti (cron + bottone UI) possono inviare duplicati.
- Se il processo muore tra (b) e (d), la riga resta `sending` per sempre (nessun recupero degli item "stuck in sending").
- Il rate limit conta su `email_log` (solo esiti definitivi `sent` nell'ultima ora), non sulla queue.

### 3.6 Rate limiting (`checkRateLimit`)
- `max_per_hour` dalla config (default 100); valore `<= 0` = illimitato.
- Conteggio: `SELECT COUNT(*) FROM email_log WHERE associazione_id = ? AND status='sent' AND sent_at >= NOW() - 1 HOUR`; consenti se `count < max_per_hour`.
- Finestra mobile di 60 minuti, per tenant.

### 3.7 Annullamento batch (`cancelBatch`)
- `UPDATE email_queue SET status='cancelled' WHERE batch_id=? AND associazione_id=? AND status='pending'`; ritorna il numero di righe. Solo le pending sono annullabili (mai sent/sending/failed).

### 3.8 Template (`getTemplate`, `renderTemplate`, `seedDefaultTemplates`)
- `getTemplate(codice)`: lookup per (associazione_id, codice).
- `renderTemplate(codice, placeholders)`: NULL se il template non esiste **o non è attivo**; altrimenti `{subject, body}` con i placeholder sostituiti in oggetto e corpo.
- `seedDefaultTemplates()`: `INSERT IGNORE` (idempotente; non sovrascrive personalizzazioni) degli 8 template di default (§4.2). Eseguito automaticamente: (a) alla prima apertura della pagina Template se il tenant non ha template; (b) al primo salvataggio della configurazione SMTP (solo su INSERT, non su UPDATE).

---

## 4. Sistema di placeholder e template

### 4.1 Sintassi e rendering
- Sintassi placeholder: `{NOME_MAIUSCOLO}` — regex `\{([A-Z0-9_]+)\}`.
- Funzione globale `renderTemplatePlaceholders(template, vars)`: sostituisce ogni `{KEY}` con `vars[KEY]`; **se la chiave non è presente nella mappa, il placeholder resta invariato nel testo** (non viene rimosso né svuotato).
- I valori sono HTML-escaped (`htmlspecialchars` ENT_QUOTES UTF-8) al momento della costruzione della mappa, perché vengono iniettati in HTML.

### 4.2 Template di default (codici fissi, contenuto in italiano)

| Codice | Nome | Oggetto default | Uso |
|---|---|---|---|
| `benvenuto` | Benvenuto nuovo socio | `Benvenuto in {NOME_ASSOCIAZIONE}, {NOME}!` | Auto su nuovo socio (toggle `auto_benvenuto`) |
| `scadenza_tessera` | Promemoria scadenza tessera | `La tua tessera {NUMERO_TESSERA} sta per scadere` | Cron §9 e §10 |
| `scadenza_quota` | Promemoria quota da pagare | `Promemoria: quota associativa da pagare` | Cron §9 |
| `assemblea` | Convocazione assemblea | `Convocazione: {TITOLO_EVENTO}` | Manuale |
| `comunicazione` | Comunicazione generica | `{NOME_ASSOCIAZIONE} — Comunicazione` | Comunicazioni massive |
| `rinnovo_tessera` | Conferma rinnovo tessera | `Tessera rinnovata — {NOME_ASSOCIAZIONE}` | Auto (toggle `auto_rinnovo_tessera`) |
| `pagamento_quota` | Conferma pagamento quota | `Pagamento registrato — {NOME_ASSOCIAZIONE}` | Auto (toggle `auto_pagamento_quota`) |
| `nuova_preiscrizione` | Notifica nuova pre-iscrizione | `Nuova pre-iscrizione: {NOME} {COGNOME}` | Notifica admin su pre-iscrizione pubblica |

I corpi HTML default sono semplici frammenti (h2/p/table) con i placeholder; vanno riportati 1:1 (sono nel metodo `getDefaultTemplates()` del legacy — replicarli come seeder Laravel).

### 4.3 Placeholder disponibili per codice (`getEmailPlaceholders`)

Comuni a tutti: `NOME`, `COGNOME`, `EMAIL`, `NUMERO_SOCIO`, `DATA_ISCRIZIONE`, `NOME_ASSOCIAZIONE`, `EMAIL_ASSOCIAZIONE`, `LOGO_ASSOCIAZIONE`, `ANNO`, `DATA_CORRENTE`, `LINK_DISISCRIZIONE`.

Extra per codice:
- `scadenza_tessera` e `rinnovo_tessera`: `DATA_SCADENZA`, `NUMERO_TESSERA`, `LINK_RINNOVO`, `LINK_PAGAMENTO`
- `nuova_preiscrizione`: `TELEFONO`, `CODICE_FISCALE`, `LINK_ADMIN`
- `scadenza_quota`: `DATA_SCADENZA`, `IMPORTO`, `LINK_PAGAMENTO`
- `pagamento_quota`: `IMPORTO`, `DATA_PAGAMENTO`
- `assemblea`: `TITOLO_EVENTO`, `DATA_EVENTO`, `LUOGO_EVENTO`

### 4.4 Costruzione valori (`buildPlaceholderValues(associazioneId, socioId?, extra[])`)
- Sempre: `ANNO` = anno corrente, `DATA_CORRENTE` = `d/m/Y` oggi.
- Da `associazioni` (per id): `NOME_ASSOCIAZIONE`, `EMAIL_ASSOCIAZIONE`, `LOGO_ASSOCIAZIONE` (da `logo_url`), tutti escaped.
- Se `socioId` fornito (con scoping `associazione_id`): `NOME`, `COGNOME`, `EMAIL`, `NUMERO_SOCIO`, `DATA_ISCRIZIONE` (formattata `d/m/Y`, stringa vuota se assente) + **`LINK_DISISCRIZIONE`**: assicura l'esistenza del token opt-out (lo genera se mancante, §8.1) e costruisce l'URL pubblico.
- I valori `extra` passati dal chiamante sovrascrivono/aggiungono chiavi e vengono escaped a loro volta. (Attenzione: questo significa che URL passati come extra subiscono htmlspecialchars — ok dentro attributi HTML.)

### 4.5 Wrapper base responsive (`wrapInEmailTemplate`)
- File `templates/email_base.html`: layout email-safe a tabelle, larghezza 600px, header con barra arancione brand `#FF7B11` + logo + nome associazione, area body bianca, footer con © anno + link "Disiscriviti dalle email".
- Segnaposto del layout (sintassi doppia graffa, distinta dai placeholder utente): `{{BODY}}`, `{{ASSOCIAZIONE_NOME}}`, `{{LOGO_URL}}`, `{{UNSUBSCRIBE_URL}}`, `{{ANNO}}`.
- Se il file non esiste/non leggibile, ritorna il body nudo (fail-safe).
- Nota: nel codice attuale il wrapper esiste come helper, ma le pagine analizzate accodano il body così com'è (il wrapping è applicato dai flussi che lo richiamano, es. preiscrizione). In Laravel: applicare il layout in modo uniforme a tutte le email in uscita (vedi §11).

---

## 5. Pagina "Template Email" (editor GrapesJS)

**Accesso:** utente loggato con `associazione_id` in sessione (vedi §12 ruoli).

**Comportamento:**
1. Al caricamento, se il tenant non ha alcun template → seed automatico dei default.
2. Lista template come tab di navigazione, ordinati con ordine fisso: `benvenuto, scadenza_tessera, scadenza_quota, assemblea, comunicazione, rinnovo_tessera, pagamento_quota` (MySQL `FIELD(...)`; i codici non in lista, es. `nuova_preiscrizione`, finiscono in testa per il comportamento di FIELD=0 — in Laravel definire un ordine esplicito completo). Tab con badge "Off" se `attivo = 0`.
3. Template corrente selezionato da query string `?t=<codice>` (default: primo della lista).
4. Form di modifica:
   - Oggetto (testo libero con placeholder, suggerimento con elenco placeholder validi per quel codice);
   - Switch "Attivo";
   - Editor **GrapesJS** con plugin `grapesjs-preset-newsletter`, `storageManager: false`, upload asset disabilitato;
   - Caricamento contenuto: prova prima `corpo_json` (project data GrapesJS, `loadProjectData`), in fallback `corpo_html` (`setComponents`);
   - Al submit (via JS): campo nascosto `corpo_html` = `editor.getHtml() + '<style>' + editor.getCss() + '</style>'`; campo `corpo_json` = `JSON.stringify(editor.getProjectData())`;
   - Dropdown "Inserisci Placeholder" che appende `{PLACEHOLDER}` al componente selezionato o al canvas;
   - Bottone "Anteprima": modale con iframe sandbox (`allow-same-origin`) in cui viene scritto l'HTML corrente dell'editor (anteprima client-side, senza sostituzione placeholder).
5. **Salvataggio (POST):** validazione CSRF; UPDATE di `oggetto`, `corpo_html`, `corpo_json`, `attivo`, `updated_at` per (associazione_id, codice). Il `codice` e il `nome` non sono modificabili dall'utente. Non è prevista creazione/eliminazione di template dalla UI (solo gli 8 predefiniti). `corpo_html` e `corpo_json` NON vengono sanitizzati (HTML libero — è il contenuto dell'email); l'oggetto passa per pulizia dei caratteri di controllo.
6. Errori DB: log interno + messaggio generico "Errore durante il salvataggio." (mai dettagli tecnici all'utente).

---

## 6. Pagina "Invia Comunicazione" (invio massivo)

**Accesso:** utente loggato con `associazione_id` in sessione.

**Precondizione:** se SMTP non configurato (`isConfigured()` false) → alert con link alle impostazioni, bottone di invio disabilitato; un POST comunque inviato viene rifiutato con errore.

**Form:**
- Select "Template": opzione "Comunicazione libera" + tutti i template del tenant con `attivo = 1` (ordinati per nome). Alla selezione (client-side) precompila oggetto e carica nell'editor `corpo_json` (o `corpo_html` in fallback).
- Oggetto (obbligatorio).
- Editor GrapesJS identico a §5 (preset newsletter, no storage, no upload).
- Dropdown placeholder (set del codice `comunicazione`, cioè i soli comuni).
- Selettore destinatari (`recipient_type`): `all` (default) | `category` | `section` | `tag` | `gruppo`, con select secondaria mostrata dinamicamente (categorie socio, sedi, tag, gruppi dinamici del tenant).
- Badge con conteggio destinatari "tutti i soci attivi" calcolato server-side al page load (il conteggio NON si aggiorna al cambio filtro nel legacy).

**POST di invio (`send_communication`):**
1. CSRF valido, SMTP configurato, `subject` e `corpo_html` non vuoti (altrimenti errore "Oggetto e corpo email sono obbligatori.").
2. Query destinatari — base comune (regola di eleggibilità):
   ```sql
   SELECT s.id, s.email, s.nome, s.cognome FROM soci s
   WHERE s.associazione_id = ?
     AND s.email IS NOT NULL AND s.email != ''
     AND s.stato = 'Attivo'
     AND (s.email_opt_out = 0 OR s.email_opt_out IS NULL)
   ```
   più, in base al tipo:
   - `category`: `AND s.categoria_socio_id = ?`
   - `section`: `AND s.sede_id = ?`
   - `tag`: `AND EXISTS (SELECT 1 FROM socio_tags st WHERE st.socio_id = s.id AND st.tag_id = ?)`
   - `gruppo`: **incompleto nel legacy** — legge `filtri_json` da `gruppi_dinamici` ma non lo applica: di fatto invia a tutti i soci attivi. In Laravel implementare il parsing di `filtri_json` o rimuovere l'opzione (vedi §11).
3. Se zero destinatari → warning "Nessun destinatario trovato per i criteri selezionati." e nessun accodamento.
4. **Rendering per-socio:** per ogni destinatario costruisce la mappa placeholder (§4.4, include il link disiscrizione personale) e renderizza oggetto e corpo. Ogni email in coda ha quindi contenuto personalizzato.
5. Accodamento: genera un `batch_id` UUID; una riga `email_queue` per destinatario con `socio_id`, `to_name` = "nome cognome", `template_codice = 'comunicazione'` (fisso, anche per comunicazioni libere o partite da altri template), `priority = 5`.
6. Messaggio di successo: "N email accodate con successo!" con link alla coda. **L'invio effettivo è demandato alla coda** (bottone "Elabora Coda" o cron).

**Sezione "Invii Recenti":** ultime 10 aggregazioni per `batch_id` da `email_log` (`batch_id IS NOT NULL`): data minima, oggetto (MIN), totale, conteggio sent, conteggio failed.

---

## 7. Coda e storico

### 7.1 Pagina "Coda Invio Email"

**Accesso:** utente loggato con tenant in sessione. Tutte le operazioni scoping per `associazione_id`.

- **Stat cards:** pending, sending, failed (GROUP BY status sulla queue) + "inviate oggi" (`status='sent' AND DATE(sent_at) = CURDATE()` sulla queue).
- **Tabella** paginata (25/pagina, paginazione numerica): tutte le righe della queue del tenant, ordinate per stato (`sending, pending, failed, sent, cancelled` — gli attivi in alto), poi `priority ASC`, poi `created_at DESC`. Colonne: priorità (badge), destinatario (email + nome socio se collegato, via LEFT JOIN soci), oggetto troncato a 50 char, stato (badge colore: pending=warning, sending=info, sent=success, failed=danger, cancelled=secondary) con eventuale `error_message` troncato a 60 char, tentativi `attempts/max_attempts`, data creazione.
- **Azioni per riga:**
  - `pending` → "Annulla": `UPDATE ... SET status='cancelled' WHERE id=? AND associazione_id=? AND status='pending'`.
  - `failed` → "Riprova": `UPDATE ... SET status='pending', attempts=0, error_message=NULL WHERE id=? AND associazione_id=? AND status='failed'` (reset completo dei tentativi).
- **Azioni globali:**
  - "Annulla Pendenti" (con conferma): annulla tutte le pending del tenant, mostra il conteggio.
  - "Elabora Coda": chiamata AJAX POST a `api/email_queue_process.php` (auth sessione + CSRF) che esegue `processQueue(50)` per il tenant corrente e ritorna `{success, sent, failed, remaining}`; la UI mostra una progress bar e ricarica la pagina dopo 2s.
- Tutte le POST della pagina richiedono token CSRF.

### 7.2 Pagina "Storico Email" (email_log)

- **Filtri GET combinabili (AND):**
  - `status`: whitelist rigida `sent` | `failed` (altri valori ignorati);
  - `template`: uguaglianza su `template_codice`; la dropdown è popolata con `SELECT DISTINCT template_codice` dal log del tenant (non NULL, ordinati);
  - `q` (ricerca testo): `LIKE %q%` su `to_email` OR `to_name` OR `subject`;
  - `from` / `to` (date): `DATE(sent_at) >= from` / `DATE(sent_at) <= to`.
- Paginazione 25/pagina con conservazione dei filtri in query string; badge con totale risultati.
- **Tabella:** data (`d/m/Y H:i`), destinatario (email + nome/cognome socio via LEFT JOIN `soci`), oggetto troncato, badge template, stato (sent verde / failed rosso) + errore troncato, "Inviata da" = nome/cognome da LEFT JOIN `utenti` su `sent_by`, fallback **"Sistema"** se NULL (invii cron).
- **Dettaglio (modale AJAX):** GET `api/email_preview.php?log_id=...` (auth sessione; il CSRF è passato ma non verificato nel legacy). Risposta JSON con i metadati del log + `body_html` recuperato con:
  ```sql
  SELECT el.*, eq.body_html FROM email_log el
  LEFT JOIN email_queue eq ON eq.batch_id = el.batch_id
    AND eq.to_email = el.to_email AND eq.associazione_id = el.associazione_id
  WHERE el.id = ? AND el.associazione_id = ?
  ```
  Il body viene mostrato in un iframe sandbox. **Regola implicita:** il corpo è ricostruito dalla queue (match batch+email); se la riga di coda è stata cancellata o il match è ambiguo, il body può mancare o essere impreciso. In Laravel salvare il body anche nel log (o referenziare l'id della riga di coda).

---

## 8. Disiscrizione (unsubscribe)

### 8.1 Token
- `soci.email_opt_out_token`: 64 char hex (`bin2hex(random_bytes(32))`), generato lazy la prima volta che serve (quando si costruisce `LINK_DISISCRIZIONE`), persistito e riusato. Nessuna scadenza.
- URL: `<base_url>/api/email_unsubscribe.php?id=<socio_id>&token=<token>` (in Laravel: route firmata o `/unsubscribe/{socio}/{token}`).
- Base URL: in CLI usa `APP_URL` da env (fallback `http://localhost`); in web ricostruita da schema+host+path dello script.

### 8.2 Endpoint pubblico (nessuna autenticazione, nessun CSRF)
Flusso:
1. `id` e `token` obbligatori → altrimenti HTTP 400 con pagina "Link non valido.".
2. Lookup: `SELECT ... FROM soci s JOIN associazioni a ON a.id = s.associazione_id WHERE s.id = ? AND s.email_opt_out_token = ?`. Nessun match → HTTP 404 "Link non valido o scaduto.". (Nota: nessun confronto constant-time nel legacy; in Laravel usare `hash_equals` o query + verifica.)
3. Se già `email_opt_out = 1` → pagina "Già disiscritto" (idempotente).
4. GET → pagina di conferma con email del socio e bottone "Conferma Disiscrizione" (form POST self, double opt-out per evitare disiscrizioni da prefetch dei link).
5. POST con `confirm_unsubscribe` → `UPDATE soci SET email_opt_out = 1 WHERE id = ?` → pagina di conferma avvenuta.
6. Errori → HTTP 500 con messaggio generico, dettaglio solo su error_log.

Pagina standalone HTML brandizzata (card bianca, header arancione #FF7B11, footer con nome associazione o "Gestione Associazioni").

### 8.3 Effetto dell'opt-out
Tutti i flussi di selezione destinatari (comunicazioni massive §6, reminder §9, rinnovi §10) escludono i soci con `email_opt_out = 1` tramite condizione `(email_opt_out = 0 OR email_opt_out IS NULL)`. Non esiste re-iscrizione self-service (solo da gestionale, fuori da questo modulo).

---

## 9. Cron: promemoria scadenze (`send_reminders.php`)

- **Esecuzione:** CLI only (HTTP → 403); schedulazione consigliata: ogni giorno alle 8:00.
- **Selezione tenant:** `associazioni` con `attiva = 1` E riga in `smtp_settings` (INNER JOIN). Per ognuna ricontrolla `isConfigured()`.
- Finestra di preavviso: `giorni = associazioni.giorni_notifica_scadenza` (fallback 30 se 0/null); `deadline = oggi + giorni`.

### 9.1 Promemoria tessere in scadenza
Condizioni di attivazione: `smtp_settings.auto_scadenza_tessera = 1` E template `scadenza_tessera` esistente e attivo.

Query destinatari:
- `tessere.stato = 'Attiva'` E `data_scadenza BETWEEN oggi AND deadline`, JOIN socio (stesso tenant);
- socio: `stato='Attivo'`, email presente, non opt-out;
- **anti-duplicato:** `NOT EXISTS` riga in `email_log` per quel `socio_id` con `template_codice='scadenza_tessera'` e `sent_at >= oggi - 30 giorni` (quindi max 1 promemoria tessera/30gg per socio, indipendentemente dall'esito o dalla tessera).

Per ogni riga: placeholder socio + extra `NUMERO_TESSERA`, `ANNO_VALIDITA`, `DATA_SCADENZA` (qui passata nel formato DB `Y-m-d`, non formattata — incoerenza col cron rinnovi che formatta `d/m/Y`; in Laravel uniformare a `d/m/Y`), render del template, accodamento con `template_codice='scadenza_tessera'`, priorità 5, un `batch_id` per associazione/run.

### 9.2 Promemoria quote non pagate
Condizioni: `auto_scadenza_quota = 1` E template `scadenza_quota` attivo.

Query: `quote.data_pagamento IS NULL` E `data_scadenza <= oggi + 30 giorni` (**include le quote già scadute/overdue**, finestra fissa 30 giorni non legata a `giorni_notifica_scadenza`), stesso filtro soci; anti-duplicato identico su `template_codice='scadenza_quota'` negli ultimi 30 giorni per socio.

Extra placeholder: `IMPORTO`, `ANNO` (sovrascrive l'anno corrente con l'anno della quota), `DATA_SCADENZA` (formato DB). Accodamento con codice `scadenza_quota`.

L'invio effettivo avviene poi tramite il cron della coda (§3.5/§9.3): i due cron sono disaccoppiati (accoda ≠ invia).

### 9.3 Cron elaborazione coda (`process_email_queue.php`)
- CLI only; consigliato ogni 5 minuti.
- `SELECT DISTINCT associazione_id FROM email_queue WHERE status='pending'`; per ogni tenant: se SMTP non configurato → skip (le email restano pending); altrimenti `processQueue(50)`.
- Output testuale per logging (timestamp, per-assoc sent/failed/remaining, totale). Exit code 0 ok / 1 errore.

---

## 10. Cron: promemoria rinnovo con link (`send_renewal_reminders.php`)

- CLI only; consigliato giornaliero (es. 9:00).
- **Guard schema:** verifica che esista la colonna `soci.rinnovo_token`; altrimenti esce con errore (in Laravel: garantito dalle migration, non serve).
- Tenant: `associazioni.attiva=1` con `smtp_settings.auto_scadenza_tessera=1` (stesso toggle del §9.1 — i due cron possono sovrapporsi, vedi nota sotto) e `isConfigured()`; template `scadenza_tessera` esistente e attivo.
- Query tessere: identica a §9.1 ma con **anti-duplicato a 7 giorni** (non 30): `NOT EXISTS email_log ... template_codice='scadenza_tessera' ... ultimi 7 giorni`.

Per ogni socio selezionato:
1. **Token rinnovo:** genera `rinnovo_token` (64 hex) con `rinnovo_token_expires = +30 giorni`, salvato su `soci` (sovrascrive il precedente). URL rinnovo: `<base_url>/preiscrizione.php?assoc=<associazione_id>&token=<token>` (form pre-iscrizione precompilato in modalità rinnovo).
2. **Link pagamento (opzionale):** se il tenant ha Stripe o PayPal abilitati (via `PaymentService`):
   - cerca una quota esistente `socio_id + anno = annoCorrente+1 + stato='Da Pagare'`;
   - se assente, determina l'importo: `tipi_socio.costo_tessera` del tipo socio del socio, fallback `associazioni.costo_tessera`; se importo > 0 crea la quota (`PaymentService::createQuotaForSocio(..., 'Quota Associativa', 'online')`);
   - se esiste/creata: genera un payment token con validità 720 ore (30 giorni) e costruisce `LINK_PAGAMENTO = <base_url>/pagamento.php?token=...`;
   - se nessun gateway o importo 0 → `LINK_PAGAMENTO` = stringa vuota.
3. Placeholder: comuni + `NUMERO_TESSERA`, `ANNO_VALIDITA`, `DATA_SCADENZA` (formattata `d/m/Y`), `LINK_RINNOVO`, `LINK_PAGAMENTO`; render template `scadenza_tessera`; accodamento (codice `scadenza_tessera`, priorità 5, batch per run/tenant).

**Nota di coerenza (bug latente del legacy):** §9.1 e §10 usano lo stesso template/codice e lo stesso toggle ma finestre anti-duplicato diverse (30 vs 7 giorni); se entrambi i cron sono schedulati, l'ordine di esecuzione determina chi invia. In Laravel consolidare in un unico job (vedi §11).

---

## 11. Note per l'implementazione Laravel

**Architettura suggerita**
- `MailSetting` (model, 1:1 con `Associazione`), cast `encrypted` di Laravel per la password SMTP (in import dei dati legacy: decifrare AES-256-CBC `base64(iv16 + ciphertext)` con chiave hex 64 char `APP_ENCRYPTION_KEY` e ri-cifrare con la APP_KEY Laravel).
- Mailer dinamico per tenant: costruire la config mailer runtime (`Mail::build(...)` o `config(['mail.mailers.tenant' => ...])`) dai dati di `MailSetting`; mapping encryption: `tls` → scheme smtp+STARTTLS, `ssl` → smtps, `none` → nessuna crittografia e niente auto-TLS.
- `EmailTemplate`, `EmailQueueItem`, `EmailLogEntry` come model Eloquent con global scope tenant; UUID come PK (`HasUuids` o UUID string per compatibilità import).
- **Coda:** mantenere la tabella `email_queue` come coda di dominio visibile all'utente (con stati/priorità/retry/cancel) e usare i job Laravel solo come motore di elaborazione (job `ProcessEmailQueue` schedulato ogni 5 min + azione manuale "Elabora coda"). In alternativa: usare le queue native con una tabella di proiezione, ma la UI richiede annulla/riprova/priorità per riga, quindi la tabella dedicata è più semplice.
- Correzioni da apportare rispetto al legacy:
  - lock pessimistico/`SELECT ... FOR UPDATE SKIP LOCKED` (o `lockForUpdate`) sull'estrazione batch per evitare doppi invii con elaborazioni concorrenti;
  - recupero righe bloccate in `sending` (es. ri-pending se `last_attempt_at < now - 15 min`);
  - salvare `body_html` (o FK alla riga di coda) in `email_log` invece del JOIN euristico batch+email;
  - backoff tra i retry (il legacy ritenta a ogni run senza attesa);
  - implementare davvero il filtro `gruppo` (parsing `filtri_json` dei gruppi dinamici) o nascondere l'opzione;
  - verificare il CSRF anche su `email_preview` (in Laravel: route auth standard);
  - uniformare il formato date dei placeholder a `d/m/Y`;
  - unificare `send_reminders` + `send_renewal_reminders` in un solo comando Artisan con finestra anti-duplicato unica e configurabile;
  - aggiornare il conteggio destinatari via AJAX al cambio filtro (il legacy mostra solo il totale "tutti");
  - applicare sistematicamente il layout base (`wrapInEmailTemplate` → Blade layout email) a tutte le email in coda, con link unsubscribe sempre presente.
- **Comandi schedulati (Kernel):** `emails:process-queue` (everyFiveMinutes, batch 50 per tenant, skip tenant senza SMTP), `emails:send-reminders` (dailyAt 08:00), eventuale `emails:send-renewal-reminders` se tenuto separato (dailyAt 09:00). Tutti CLI-only by design.
- **Rate limiting:** mantenere la regola "count di `email_log` sent nell'ultima ora < max_per_hour per tenant"; valutare `RateLimiter` di Laravel ma persistito, perché la finestra deve sopravvivere ai restart.
- **Unsubscribe:** route pubblica GET+POST (esclusa da auth e CSRF sul POST o con token nel form), oppure `URL::signedRoute`; mantenere il token persistente per compatibilità con i link già inviati dal sistema legacy. Aggiungere header `List-Unsubscribe` alle email (miglioria).
- **GrapesJS:** conservare il doppio storage `corpo_html` (render finale: `getHtml()` + CSS inline in `<style>`) e `corpo_json` (project data per riaprire l'editor). L'editor è asset front-end (preset newsletter, storageManager off, upload off); il backend tratta i due campi come testo opaco. Niente sanitizzazione del corpo (contenuto fidato dell'admin), ma escape sistematico dei valori placeholder.
- **Placeholder:** porting diretto di `renderTemplatePlaceholders` (regex `\{([A-Z0-9_]+)\}`, placeholder sconosciuti lasciati intatti) — non usare Blade per i template utente (rischio injection).
- **Seeding template:** observer/azione "firstOrCreate" per tenant: al primo accesso alla pagina template o alla creazione delle impostazioni SMTP, seed idempotente degli 8 default senza sovrascrivere.
- **Test email:** endpoint POST auth+CSRF, validazione `FILTER_VALIDATE_EMAIL` equivalente (`email:rfc`), bypass del rate limit, no log, aggiorna `is_verified`/`last_test_at` solo su successo; il messaggio d'errore SMTP può essere mostrato all'admin (utile per debug), mai stack trace.
- **Sicurezza/multitenancy:** ogni query con scope tenant; `cancel`/`retry` devono verificare appartenenza riga al tenant E stato corrente (pending per cancel, failed per retry); anteprime HTML email sempre in iframe sandboxed.

---

## 12. Comportamenti per ruolo

Il legacy non differenzia i ruoli all'interno del modulo email: **tutte** le pagine (impostazioni, template, comunicazioni, coda, log) e gli endpoint AJAX richiedono solo (a) utente loggato (`utenti`), (b) `associazione_id` presente in sessione. Quindi:

- **super_admin:** opera sul tenant attualmente selezionato in sessione (può cambiare associazione altrove nell'app); nessuna vista cross-tenant del modulo email — vede coda/log/impostazioni di un'associazione alla volta.
- **admin_associazione:** accesso completo a tutto il modulo per la propria associazione (configura SMTP, modifica template, invia comunicazioni, gestisce coda, consulta log).
- **operatore:** nel codice attuale ha lo stesso accesso dell'admin (nessun check su `$_SESSION['user_role']` in questi file). *Raccomandazione Laravel:* introdurre policy esplicite — es. operatore può inviare comunicazioni e consultare coda/log ma non modificare impostazioni SMTP né template; admin_associazione tutto sul proprio tenant; super_admin tutto su qualsiasi tenant. Documentare la scelta, perché è un inasprimento rispetto al comportamento legacy.
- **Cron:** girano come "Sistema" (nessun utente): `sent_by = NULL` nel log, mostrato come "Sistema" in UI.
- **Endpoint pubblico:** solo `email_unsubscribe` (nessuna auth, protetto dal token per socio).

---

## 13. Edge case e regole implicite (riepilogo)

1. `isConfigured()` non richiede password/user: host + from_email bastano; un SMTP senza auth valida fallirà solo all'invio (errore in `error_message`).
2. La password SMTP nel form di impostazioni: campo vuoto = mantieni la precedente; valorizzato = ri-cifra e sostituisci. Su primo INSERT senza password viene salvata stringa vuota cifrata? No: stringa vuota *non cifrata* (`''`) — l'invio fallirà alla decifratura; in Laravel rendere la password obbligatoria al primo salvataggio.
3. Il salvataggio impostazioni non resetta `is_verified` anche se host/credenziali cambiano (miglioria possibile: reset a false on change).
4. Rate limit raggiunto durante `processQueue`: il batch si interrompe ma le righe già marcate `sending` in quel ciclo... no — la marcatura avviene per-item dopo il check, quindi nessuna riga resta orfana per rate limit; restano `pending` e verranno riprese.
5. Retry manuale da UI azzera `attempts` (ricomincia da 0/3); il retry automatico invece consuma i tentativi residui.
6. `remaining` ritornato da processQueue conta solo `pending` (non i `failed` rielaborabili).
7. Le email "failed" definitive restano in coda (visibili, ritentabili manualmente); non c'è purge automatico della coda (valutare retention/cleanup in Laravel).
8. Anti-duplicato reminder basato su `email_log`: se l'email accodata non viene mai elaborata (SMTP rotto), il cron riaccoda ogni giorno nuovi duplicati in coda (il log resta vuoto). In Laravel considerare anche la coda pending nel NOT EXISTS.
9. Placeholder non riconosciuti restano letterali nell'email inviata.
10. `DATA_SCADENZA`: formato `Y-m-d` nel cron reminders, `d/m/Y` nel cron rinnovi — uniformare.
11. Il dettaglio log può non avere body (JOIN euristico con la coda).
12. Conferma unsubscribe è un form POST senza CSRF (endpoint pubblico): accettabile (azione idempotente a basso rischio) ma in Laravel si può firmare la route.
13. Stat "Inviate oggi" della pagina coda legge da `email_queue.sent_at`; le stat della pagina impostazioni leggono da `email_log` — possono divergere se la coda viene ripulita.
14. L'ordinamento dei template usa `FIELD()` MySQL: i codici fuori lista (es. `nuova_preiscrizione`) compaiono per primi; definire ordine esplicito.
15. `email_test` e `email_queue_process` rispondono sempre HTTP 200 con `success: false` nel JSON per gli errori (client si basa sul payload, non sullo status code).
