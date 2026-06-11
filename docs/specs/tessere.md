# Specifica funzionale — Modulo TESSERE

> Reverse-engineering dell'app PHP legacy (`pages/tessere.php`, `pages/genera-tessera-pdf.php`,
> `pages/stampa-tessera.php`, `verifica-tessera.php`, `scanner-tessera.php`, `includes/qrcode_helper.php`).
> Questa spec è autosufficiente: permette di reimplementare il modulo in Laravel senza leggere il codice vecchio.

---

## 1. Panoramica

Il modulo gestisce le **tessere associative** dei soci in un contesto SaaS multi-tenant
(isolamento per `associazione_id`). Funzionalità coperte:

1. **CRUD tessere** (lista filtrata, creazione, modifica, eliminazione) — area admin.
2. **Generazione massiva** ("Genera Tessere {anno}") per tutti i soci attivi senza tessera nell'anno.
3. **Emissione automatica + PDF tessera** (Dompdf, formato A4) con QR di verifica e template personalizzabile.
4. **Stampa tessera formato card** (HTML stampabile dal browser, 350×210 px).
5. **Verifica pubblica** della tessera via URL con UUID (pagina pubblica senza login, anche in JSON).
6. **Scanner QR** standalone (fotocamera via html5-qrcode + input manuale) che interroga l'endpoint di verifica.
7. **Email automatica** ("rinnovo_tessera") accodata alla creazione di una tessera singola.
8. **Grafici aggregati** (tesserati e ricavi per tipo socio) nella pagina di gestione.

---

## 2. Tabelle DB coinvolte

### 2.1 `tessere` (tabella principale)

| Colonna | Tipo | Note d'uso |
|---|---|---|
| `id` | CHAR(36) PK | UUID generato dall'app (`generateUuid()`). È il valore codificato nel QR. |
| `socio_id` | CHAR(36) NOT NULL, FK `soci(id)` ON DELETE CASCADE | |
| `associazione_id` | CHAR(36) NOT NULL, FK `associazioni(id)` ON DELETE CASCADE | Chiave di tenant: tutte le query admin filtrano su questa. |
| `numero_tessera` | VARCHAR(50) NOT NULL | UNIQUE insieme a `associazione_id`. Formati diversi a seconda del flusso (vedi §4.3). |
| `anno_validita` | INT NOT NULL | Anno di riferimento (es. 2026). Filtro primario della lista. |
| `data_emissione` | DATE NOT NULL | |
| `data_scadenza` | DATE NOT NULL | |
| `tipo_scadenza` | ENUM('solare','annuale') NOT NULL | Copiato dal default dell'associazione al momento dell'emissione. |
| `stato` | ENUM('Attiva','Scaduta','Sospesa','Annullata') DEFAULT 'Attiva' | 'Scaduta' è anche *derivata* a runtime (vedi §5.3). |
| `qr_code_url` | TEXT | URL completo di verifica, salvato quando si genera il PDF. |
| `template_tessera` | VARCHAR(100) | Etichetta tipo tessera (nome del tipo socio o 'Default'), valorizzata solo dall'emissione automatica via PDF. |
| `evento_creazione_id` | CHAR(36) NULL (+ indice) | Evento opzionale associato all'emissione (FK logica verso `eventi`, **non** vincolata). |
| `created_at` / `updated_at` | DATETIME | Default CURRENT_TIMESTAMP / ON UPDATE. |

Indici: `UNIQUE(associazione_id, numero_tessera)`, `(associazione_id, anno_validita)`, `(socio_id)`,
`(socio_id, associazione_id, data_emissione)`, `(template_tessera)`, `(data_scadenza)`, `(evento_creazione_id)`.

### 2.2 `tessera_templates` (template del testo tessera)

| Colonna | Tipo | Note |
|---|---|---|
| `id` | CHAR(36) PK | |
| `associazione_id` | CHAR(36) NOT NULL FK | |
| `tipo_socio_id` | CHAR(36) NULL FK `tipi_socio` ON DELETE SET NULL | NULL = template di default dell'associazione. |
| `titolo` | VARCHAR(255) DEFAULT 'Template Tessera' | |
| `contenuto` | TEXT NOT NULL | Testo con placeholder `{CHIAVE}` (vedi §6.4). |
| `attivo` | BOOLEAN DEFAULT TRUE | (Non controllato dal codice di generazione PDF legacy.) |
| `created_at`/`updated_at` | DATETIME | |

Vincolo `UNIQUE(associazione_id, tipo_socio_id)`: un solo template per tipo socio + uno generico.

### 2.3 Tabelle lette (non modificate dal modulo)

- **`soci`**: `id`, `nome`, `cognome`, `numero_socio`, `stato` (filtro 'Attivo'), `tipo_socio_id`,
  `categoria_socio_id`, `email`, `codice_fiscale`, `indirizzo`, `citta`, `provincia`, `cap`, `data_nascita`, `associazione_id`.
- **`associazioni`**: `id`, `nome`, `logo_url`, `tipo_scadenza_default` (ENUM/VARCHAR 'solare'|'annuale', fallback 'solare'),
  `giorni_notifica_scadenza`, `costo_tessera` (DECIMAL(10,2) NULL), `partita_iva`, `codice_fiscale`,
  `indirizzo`, `citta`, `provincia`, `cap`, `email`, `telefono`.
- **`tipi_socio`**: `id`, `nome`, `costo_tessera` (DECIMAL(10,2) NULL).
- **`categorie_socio`**: `id`, `nome`, `testo_tessera` (testo fallback per il corpo della tessera PDF).
- **`eventi`**: `id`, `titolo`, `data_evento` (dropdown "evento di creazione").
- Tabelle email (via `EmailService`): configurazione SMTP per associazione con flag `auto_rinnovo_tessera`,
  template email `rinnovo_tessera` con flag `attivo`, coda email.

> Nota legacy: il vecchio codice esegue una "auto-migrazione soft" (`ensureTesseraCostColumns`) che aggiunge
> a runtime `associazioni.costo_tessera`, `tipi_socio.costo_tessera` e `tessere.evento_creazione_id` se mancanti.
> In Laravel questo va sostituito da migration normali.

---

## 3. Autorizzazione e comportamenti per ruolo

- **Tutte le pagine admin** (gestione, PDF, stampa) richiedono: utente loggato **e** `associazione_id` in sessione.
  Senza login → redirect al login; senza associazione selezionata → redirect alla dashboard
  (stampa-tessera risponde invece 403 secco).
- **`verifica-tessera.php` è completamente pubblica** (nessuna auth, nessuna sessione): chiunque possieda
  l'UUID della tessera può verificarla. L'UUID funge da capability token.
- **`scanner-tessera.php` è pubblica e stateless**: nessun DB, nessuna sessione; parla solo con l'endpoint JSON di verifica.
- **Differenze per ruolo: NESSUNA nel codice legacy.** Il flag `$is_super_admin` viene calcolato in
  `tessere.php` ma **mai usato**. super_admin, admin_associazione e operatore hanno identiche capacità,
  sempre limitate all'associazione corrente in sessione (`$_SESSION['associazione_id']`). Il super_admin
  opera su altre associazioni solo cambiando l'associazione attiva in sessione (meccanismo esterno a questo modulo).
- **CSRF**: tutte le azioni POST (crea/modifica/elimina/genera massivo) richiedono token CSRF valido;
  in caso di token non valido si mostra errore e non si esegue nulla.

---

## 4. Gestione tessere (pagina admin `tessere.php`)

### 4.1 Configurazione caricata

All'apertura si legge `associazioni.tipo_scadenza_default` per l'associazione corrente
(fallback `'solare'` se NULL/vuoto). Questo valore **forza** il `tipo_scadenza` di ogni tessera
creata o modificata: l'utente non può sceglierlo (mostrato in form come badge read-only).

### 4.2 Creazione / Modifica tessera singola (POST)

Campi del form: `id` (vuoto = creazione), `socio_id` (select dei soli soci con `stato='Attivo'`),
`numero_tessera` (testo, default suggerito `{anno corrente}0001`), `anno_validita` (numero, default anno corrente),
`data_emissione` (default oggi), `data_scadenza` (default `{anno}-12-31`), `stato`
(select: **Attiva / Sospesa / Annullata** — 'Scaduta' NON è selezionabile manualmente, è derivata),
`evento_creazione_id` (select opzionale tra gli eventi dell'associazione, ordinati per `data_evento` DESC).

**Validazioni server-side:**
1. CSRF valido.
2. Il `socio_id` deve esistere ed appartenere all'associazione corrente; altrimenti errore
   "socio non valido per questa associazione" e nessuna scrittura.
3. `numero_tessera` passato per sanitizzazione (`cleanInput`: rimozione caratteri di controllo).
4. `tipo_scadenza` = sempre `tipo_scadenza_default` dell'associazione (qualsiasi valore in input viene ignorato).
5. Se `data_scadenza` è vuota (il form la marca required, ma il server gestisce il caso):
   - tipo **annuale** → `data_emissione + 1 anno`;
   - tipo **solare** → `{anno_validita}-12-31`.
6. `evento_creazione_id` vuoto → NULL. ⚠️ Il legacy NON verifica che l'evento appartenga
   all'associazione (gap da chiudere in Laravel).

**Update** (`id` presente): `UPDATE tessere SET socio_id, numero_tessera, anno_validita, data_emissione,
data_scadenza, stato, tipo_scadenza, evento_creazione_id WHERE id=? AND associazione_id=?`.
Nessuna email all'update.

**Insert** (`id` assente): nuova riga con UUID; poi, **solo in creazione**, invio email best-effort (vedi §4.5).

⚠️ Il legacy non gestisce esplicitamente la violazione del vincolo `UNIQUE(associazione_id, numero_tessera)`
nel percorso singolo (eccezione non catturata). In Laravel: validazione `unique` composta + messaggio chiaro.

### 4.3 Generazione massiva ("Genera Tessere {anno}")

Trigger: bottone/modale; POST con `generate_all`, `anno_validita` (il form lo fissa all'anno corrente)
ed `evento_creazione_id` opzionale (applicato a tutte le tessere generate).

Logica (in **transazione**, rollback su errore con messaggio generico e log):
1. Seleziona gli ID dei soci con `stato='Attivo'` dell'associazione che **non** hanno già una tessera
   con quel `anno_validita` nella stessa associazione (a prescindere dallo stato della tessera esistente:
   anche una tessera Annullata blocca la rigenerazione).
2. Per ciascun socio:
   - **Numero tessera** = `{anno}` + progressivo a 4 cifre zero-padded. Il progressivo è
     `COUNT(tessere dell'associazione per quell'anno) + numero già generato nel loop + 1`
     (es. prima tessera del 2026 → `20260001`).
   - `data_emissione` = oggi.
   - `data_scadenza`: tipo **solare** → `{anno}-12-31`; tipo **annuale** → oggi + 1 anno.
   - `tipo_scadenza` = default associazione; `stato` = default DB ('Attiva'); `evento_creazione_id` = quello scelto.
3. Messaggio finale: "Generate N nuove tessere per l'anno X".

Nessuna email viene inviata nel flusso massivo (solo nel singolo).

### 4.4 Eliminazione

POST `delete_id`: **hard delete** `DELETE FROM tessere WHERE id=? AND associazione_id=?`,
con confirm JS lato client. Nessun soft delete, nessun log.

### 4.5 Email automatica "rinnovo_tessera" (solo su creazione singola)

Best-effort (qualsiasi errore viene solo loggato, la creazione tessera resta valida). Condizioni tutte necessarie:
1. SMTP configurato per l'associazione (`EmailService::isConfigured()` e config caricabile);
2. flag di configurazione `auto_rinnovo_tessera` attivo;
3. template email `rinnovo_tessera` esistente e `attivo`;
4. il socio ha un'email.

Se tutte vere: render del template con i placeholder standard del socio/associazione più
`NUMERO_TESSERA`, `ANNO_VALIDITA`, `DATA_SCADENZA`, e accodamento in coda email
(destinatario "Nome Cognome <email socio>", tipo `rinnovo_tessera`, priorità 3).

### 4.6 Lista, filtri e ricerca

Query principale: `tessere t JOIN soci s` (INNER: tessere orfane non appaiono)
`LEFT JOIN associazioni a`, `LEFT JOIN tipi_socio ts` (sul tipo del socio), `LEFT JOIN eventi ev`
(su `evento_creazione_id`). Ordinamento: `anno_validita DESC, cognome ASC`.

Filtri (GET, applicati anche in modalità AJAX):
- **`anno`** (default: anno corrente) — **sempre applicato**: la lista mostra UN solo anno alla volta.
  Il dropdown anni è costruito dai `DISTINCT anno_validita` dell'associazione + anno corrente se assente, ordinati DESC.
- **`search`** (testo, debounce 300 ms lato client): LIKE `%term%` su `s.nome`, `s.cognome`,
  `CONCAT(cognome,' ',nome)`, `CONCAT(nome,' ',cognome)`, `s.numero_socio`, `t.numero_tessera`.
- **`stato`**: `all` (default) | `Attiva` | `Scaduta` | `Sospesa` | `Annullata`.
  Caso speciale **Scaduta**: `stato='Scaduta' OR (data_scadenza IS NOT NULL AND data_scadenza < CURDATE())`
  → include le tessere formalmente 'Attiva' ma con data scaduta. Gli altri stati filtrano sul valore esatto
  (quindi una tessera Attiva-ma-scaduta compare anche nel filtro "Attiva": incoerenza nota, vedi §9).

**Modalità AJAX**: con `?ajax=1` la pagina risponde JSON `{count: <int>, html: <markup risultati>}`
(stesso rendering server-side della pagina piena, già escapato). Il client sostituisce il contenitore
risultati, aggiorna il badge conteggio e riscrive l'URL con `history.replaceState` (senza `ajax`).

### 4.7 Stato visualizzato (derivato) e badge

Per ogni riga il legacy calcola lo **stato di visualizzazione**:
- `stato='Attiva'` ma `data_scadenza < adesso` → mostrato **"Scaduta"** (badge rosso) pur restando 'Attiva' nel DB;
- `Attiva` (non scaduta) → badge verde; `Scaduta` → rosso; `Sospesa` → giallo; `Annullata`/altro → grigio.

Colonna scadenza: badge aggiuntivo **"Scaduta"** se `data_scadenza < oggi`, **"In scadenza"** (giallo)
se la scadenza cade entro **30 giorni** (valore hardcoded; esiste `associazioni.giorni_notifica_scadenza`
ma qui NON viene usato).

### 4.8 Grafici aggregati

Due bar chart (Chart.js) che rispettano gli stessi filtri della lista (anno, ricerca, stato):

```sql
SELECT COALESCE(ts.nome, t.template_tessera, 'Default') AS tipo,
       COUNT(*) AS cnt,
       SUM(COALESCE(ts.costo_tessera, a.costo_tessera, 0)) AS ricavi
FROM tessere t
JOIN soci s ON t.socio_id = s.id
LEFT JOIN tipi_socio ts ON s.tipo_socio_id = ts.id
JOIN associazioni a ON a.id = t.associazione_id
WHERE t.anno_validita = ? AND t.associazione_id = ? [+ filtri]
GROUP BY tipo ORDER BY cnt DESC
```

- "Tesserati per Tipo": conteggio per tipo socio (fallback: etichetta `template_tessera`, poi 'Default').
- "Ricavi per Tipo": somma del **costo tessera teorico** con precedenza
  `tipi_socio.costo_tessera` → `associazioni.costo_tessera` → 0 (ricavi stimati, non incassi reali).
  I valori vengono arrotondati a interi per il grafico.

---

## 5. Anni di validità: solare vs annuale

Regola centrale del modulo, governata da `associazioni.tipo_scadenza_default`:

| Tipo | Significato | Calcolo `data_scadenza` |
|---|---|---|
| **solare** (default) | La tessera vale per l'anno civile | `{anno_validita}-12-31` (nel flusso PDF: `31/12 dell'anno corrente`) |
| **annuale** | La tessera vale 12 mesi dall'emissione | `data_emissione + 1 anno` (flusso singolo) oppure `oggi + 1 anno` (flussi massivo e PDF, dove emissione = oggi) |

Regole implicite:
- Il tipo è una proprietà **dell'associazione**, non della singola tessera: l'admin non può cambiarlo
  tessera per tessera (il form lo mostra read-only e il server lo forza).
- Il valore viene comunque **denormalizzato** su ogni tessera (`tessere.tipo_scadenza`) come fotografia
  al momento dell'emissione: cambiare il default dell'associazione non tocca le tessere esistenti.
- `anno_validita` resta sempre l'anno "etichetta" della tessera anche in modalità annuale
  (una tessera annuale emessa il 10/06/2026 ha `anno_validita=2026` e scadenza 10/06/2027).
- La scadenza effettiva ai fini di verifica/visualizzazione è **sempre `data_scadenza`**, mai `anno_validita`.

---

## 6. Generazione PDF tessera (`genera-tessera-pdf.php`)

### 6.1 Ingresso e auto-emissione (emissione/rinnovo implicito)

- Parametro: **`socio_id`** (GET). Senza parametro → redirect alla lista soci.
- Si carica il socio (scoped per associazione) con: categoria (`categorie_socio.nome`, `testo_tessera`),
  tipo (`tipi_socio.nome`, `costo_tessera`), dati completi dell'associazione (nome, logo, costo tessera,
  P.IVA, CF, indirizzo, città, provincia, CAP, email, telefono). Socio inesistente → redirect a soci.
- Si cerca la tessera del socio per l'**anno corrente** (`anno_validita = anno corrente`,
  `ORDER BY data_emissione DESC LIMIT 1` — prende l'ultima emessa se ce ne fosse più di una).
  ⚠️ Il lookup legacy non rifiltra per `associazione_id` (il socio è già scoped, quindi di fatto sicuro).
- **Se non esiste, la tessera viene CREATA automaticamente** (questo è il meccanismo di
  emissione/rinnovo "on demand": basta chiedere il PDF):
  - `numero_tessera` = `MAX(CAST(numero_tessera AS UNSIGNED)) + 1` su **tutte** le tessere
    dell'associazione (tutti gli anni), zero-padded a 4 cifre.
    ⚠️ Schema di numerazione **diverso** dal flusso massivo (`YYYYNNNN`): se convivono i due flussi
    la numerazione diventa incoerente (es. dopo una bulk `20260001`, il MAX cast vale 20260001 e il
    successivo numero "PDF" sarà `20260002` senza semantica anno). Da unificare in Laravel.
  - `tipo_scadenza` = default associazione (fallback 'solare'); scadenza: annuale → oggi+1 anno,
    solare → `31/12 anno corrente`.
  - `stato` = 'Attiva', `data_emissione` = oggi (CURDATE()).
  - `template_tessera` = nome del tipo socio, oppure `'Default'` se il socio non ha tipo.
- Errori DB → HTTP 500 con messaggio generico (dettaglio solo in error_log).

### 6.2 QR code

- URL di verifica: `https://{host}/{base}/verifica-tessera.php?t={tessera_id}` costruito da
  `buildTesseraVerificationUrl()` (vedi §8.3).
- QR generato come **SVG inline** (libreria chillerlan/php-qrcode, ECC livello M, scale 3, quiet zone)
  e incorporato nel PDF (90×90 px, didascalia "Scansiona per verificare").
- L'URL viene **salvato** in `tessere.qr_code_url` (UPDATE best-effort, errori solo loggati) —
  viene riscritto ad ogni generazione del PDF.

### 6.3 Costo tessera mostrato

Precedenza: `tipi_socio.costo_tessera` → `associazioni.costo_tessera` → non mostrato.
Formato: `€ 1.234,56` (formato italiano).

### 6.4 Template del testo tessera e placeholder

Selezione del testo del corpo (in ordine di precedenza):
1. **`tessera_templates`** (se la tabella esiste): riga con `associazione_id` corrente e
   `tipo_socio_id` = tipo del socio, **oppure** riga generica `tipo_socio_id IS NULL`;
   ordinamento `tipo_socio_id IS NULL ASC` → vince il template specifico per tipo.
   (Il flag `attivo` NON viene controllato dal legacy.)
2. Fallback: **`categorie_socio.testo_tessera`** della categoria del socio.
3. Nessuno dei due → nessun box testo nel PDF.

Il testo viene processato con sostituzione dei placeholder `{CHIAVE}` (chiavi sconosciute lasciate intatte),
poi HTML-escapato e con newline → `<br>`. Placeholder disponibili:

| Placeholder | Valore |
|---|---|
| `{ASSOCIAZIONE_NOME}` `{ASSOCIAZIONE_CODICE_FISCALE}` `{ASSOCIAZIONE_PARTITA_IVA}` `{ASSOCIAZIONE_INDIRIZZO}` `{ASSOCIAZIONE_CITTA}` `{ASSOCIAZIONE_PROVINCIA}` `{ASSOCIAZIONE_CAP}` `{ASSOCIAZIONE_EMAIL}` `{ASSOCIAZIONE_TELEFONO}` | Dati associazione |
| `{NOME}` `{COGNOME}` `{NOME_COMPLETO}` `{NUMERO_SOCIO}` `{CODICE_FISCALE}` `{INDIRIZZO}` `{CITTA}` `{PROVINCIA}` `{CAP}` | Dati socio |
| `{TIPO_SOCIO}` `{CATEGORIA_SOCIO}` | Nome tipo / categoria |
| `{NUMERO_TESSERA}` `{ANNO_VALIDITA}` `{DATA_EMISSIONE}` `{DATA_SCADENZA}` | Dati tessera (date formato `d/m/Y`; `DATA_EMISSIONE` = data odierna di generazione PDF, non quella DB) |
| `{QR_CODE}` `{QR_URL}` | SVG del QR / URL di verifica. ⚠️ Nel legacy l'output del template è HTML-escapato, quindi `{QR_CODE}` nel corpo NON produce un QR funzionante (il QR appare comunque nel layout fisso). |

### 6.5 Layout e output PDF

- Dompdf, A4 verticale, margini 18mm, font DejaVu Sans, `isRemoteEnabled=true` (per loghi remoti).
- Struttura: header (logo + nome associazione + "Tessera Associativa - {anno}", data odierna a destra);
  tabella dati (Nome e Cognome, Numero Socio, Categoria/Tipo, Numero Tessera, Costo Tessera) con QR a destra;
  box testo template (se presente); doppia riga firma ("Firma del socio", "Firma del Presidente/Segretario");
  nota a piè: "Valida fino al {data_scadenza}. Il presente documento, se firmato, vale come tessera associativa."
- **Logo**: se `logo_url` è http(s) → `<img>` remoto; se path locale → convertito in data URI base64
  con protezione path traversal (realpath confinato alla root dell'app); altrimenti omesso. Altezza 48px.
- Output: stream **inline** (non download), filename `tessera_{numero_socio}.pdf`.

### 6.6 Bug noto del legacy (da NON replicare)

La lista tessere linka il PDF con `?page=genera-tessera-pdf&tessera_id={id}`, ma la pagina legge
**solo `socio_id`** → dal listato tessere il bottone PDF redirige alla pagina soci senza generare nulla.
Funziona invece dai bottoni nella pagina soci (`&socio_id=`). In Laravel: la route PDF deve accettare
la **tessera** (o entrambi) e il flusso "emetti se manca" va separato dal flusso "stampa tessera esistente".

---

## 7. Stampa tessera card (`stampa-tessera.php`)

- Parametro: `id` = ID tessera (GET). Auth richiesta (login + associazione in sessione, altrimenti 403);
  tessera cercata con scope `associazione_id` → 404 se assente/altrui.
- Dati caricati: numero, anno, emissione, scadenza, nome/cognome socio, `data_nascita` (caricata ma non mostrata),
  nome associazione, `logo_url` (caricato ma il layout mostra un placeholder "LOGO" statico — incompleto nel legacy).
- Output: pagina HTML formato **carta di credito** (350×210 px, bordi arrotondati) con:
  header (placeholder logo + nome associazione), placeholder foto socio ("Foto"), nome socio,
  N. tessera, anno validità, scadenza (`d/m/Y`), **QR code** (PNG data URI, 50×50 px) con l'URL di verifica,
  footer "Tessera Associativa Personale".
- Bottone "Stampa" flottante (nascosto in `@media print`); stili print dedicati (sfondo bianco, bordo sottile).
- Nessun PDF: si affida alla stampa del browser. Non scrive nulla a DB (non aggiorna `qr_code_url`).

---

## 8. Verifica pubblica e scanner

### 8.1 `verifica-tessera.php` (endpoint pubblico)

- **Nessuna autenticazione.** Parametro `t` = UUID tessera.
- Validazione formato UUID con regex `^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$` (case-insensitive);
  formato errato → trattato come "non trovata" (nessuna query).
- Stati possibili:
  - `no_param`: nessun parametro → landing page con invito a scansionare + link allo scanner.
  - `not_found`: UUID non valido, tessera inesistente o errore DB (gli errori DB degradano a not_found).
  - `valida`: `stato = 'Attiva'` **AND** `data_scadenza >= oggi` (confronto su date `Y-m-d`).
  - `scaduta`: tutti gli altri casi (Attiva ma scaduta, Scaduta, **Sospesa**, **Annullata**) —
    la UI mostra "Tessera Scaduta / Non Attiva" includendo lo stato reale.
- Dati esposti pubblicamente (HTML): nome associazione, **nome e cognome del socio**, numero tessera,
  anno, data scadenza (e stato se non valida). ⚠️ Privacy: chi possiede l'UUID vede il nome del socio.
- **Modalità JSON** (`&json=1`, usata dallo scanner): risposta
  `{"stato": "...", "tessera": {numero_tessera, anno_validita, data_scadenza, stato_tessera, socio_nome, socio_cognome, associazione_nome}}`
  (`tessera` presente solo se trovata). Content-Type `application/json; charset=utf-8`, JSON_UNESCAPED_UNICODE.

### 8.2 `scanner-tessera.php` (scanner QR standalone)

- Pagina **stateless**: niente config, DB o sessione. Pubblica.
- Header di sicurezza: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
  `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(self)`,
  CSP (default-src 'self'; script/style/font da self + cdn.jsdelivr.net con 'unsafe-inline';
  img self+data+blob; media self+blob; connect-src 'self'; con `upgrade-insecure-requests` se HTTPS).
- Rilevamento HTTPS: `$_SERVER['HTTPS']` oppure header `X-Forwarded-Proto: https` (supporto reverse proxy).
- Libreria: **html5-qrcode 2.3.8** (CDN). Fotocamera posteriore (`facingMode: environment`),
  10 fps, riquadro scan 250×250.
- Comportamenti:
  - **Cooldown 3 secondi** tra scansioni per evitare letture duplicate.
  - Dal testo scansionato: se è un URL si estrae il query param `t`, altrimenti si usa il testo grezzo come UUID.
  - **Input manuale** di fallback (campo UUID + bottone Verifica), e messaggio dedicato se la fotocamera non è disponibile.
  - Chiamata `fetch` a `verifica-tessera.php?t={uuid}&json=1` (URL calcolato server-side da host/percorso correnti);
    spinner durante il caricamento, alert su errore di rete.
  - Risultato come card colorata: verde "Tessera Valida" / rosso "Tessera Scaduta / Non Attiva" /
    giallo "Tessera Non Trovata", con dettagli (associazione, socio, n. tessera, anno, scadenza `d/m/Y`, stato).
    Tutto costruito via DOM API (no innerHTML) per evitare XSS dai dati.
  - Bottone "Scansiona altra tessera" che resetta risultato e input (lo scanner resta attivo).
- PWA-ready: manifest, theme-color brand `#FF7B11`, apple-touch-icon.

### 8.3 Helper QR (`qrcode_helper.php`)

Libreria: **chillerlan/php-qrcode**. Tre funzioni:
- `generateQrSvg(data, scale=4)`: SVG markup raw (non base64), ECC **M**, quiet zone, fill attributes —
  pensato per l'embed in Dompdf.
- `generateQrDataUri(data, scale=5)`: PNG (GD) come data URI base64, ECC M, quiet zone —
  per i tag `<img>` HTML (usato dalla stampa card, scale 3 nei chiamanti).
- `buildTesseraVerificationUrl(tesseraId)`: `{scheme}://{HTTP_HOST}{basePath}/verifica-tessera.php?t={uuid}`;
  scheme da HTTPS/X-Forwarded-Proto; se invocato da `pages/` risale di un livello.
  ⚠️ Usa l'header `Host` non validato (rischio host header injection nel QR): in Laravel usare
  `config('app.url')` / URL firmati, mai l'Host della richiesta.

---

## 9. Edge case e regole implicite (riepilogo)

1. **'Scaduta' è uno stato ibrido**: esiste nell'ENUM ma nessun processo lo scrive automaticamente;
   la scadenza effettiva è derivata da `data_scadenza` in lista, filtri e verifica pubblica.
   Una tessera può essere 'Attiva' nel DB ma mostrata/verificata come scaduta.
2. **Filtro "Attiva" include tessere scadute di fatto** (filtra solo sull'ENUM), mentre il filtro
   "Scaduta" usa la condizione estesa. Incoerenza da risolvere (consigliato: scope `valid()`/`expired()` espliciti).
3. **Doppio schema di numerazione**: massivo = `YYYY` + progressivo 4 cifre per anno;
   auto-emissione PDF = solo progressivo globale 4 cifre via `MAX(CAST(... AS UNSIGNED))+1`.
   Il progressivo massivo è inoltre calcolato con COUNT + offset in loop (fragile con cancellazioni/concorrenza:
   se si eliminano tessere, il COUNT può rigenerare numeri già esistenti → violazione UNIQUE).
4. **L'unicità del numero tessera** è per associazione (`UNIQUE(associazione_id, numero_tessera)`),
   non globale e non per anno. Il default del form (`{anno}0001`) può collidere alla seconda creazione manuale.
5. La generazione massiva **salta** i soci che hanno già una tessera per l'anno, anche se Annullata/Sospesa:
   non esiste un vero flusso di "ri-emissione" se non eliminare la vecchia tessera.
6. **La richiesta del PDF emette la tessera** se manca per l'anno corrente (side effect GET!).
   In Laravel separare: azione POST "emetti/rinnova" + GET idempotente "scarica PDF".
7. L'email `rinnovo_tessera` parte **solo** dalla creazione singola, non dal massivo né dall'auto-emissione PDF.
8. `qr_code_url` viene popolato solo quando si genera il PDF (e riscritto ogni volta);
   tessere mai stampate hanno il campo NULL. La verifica funziona comunque (si basa solo sull'ID).
9. `evento_creazione_id` non è validato contro il tenant e non ha FK reale; gli eventi eliminati
   lasciano riferimenti pendenti (la lista usa LEFT JOIN, quindi mostra "—").
10. Tessera con socio eliminato: FK ON DELETE CASCADE elimina la tessera (e i JOIN INNER nelle
    pagine di verifica/stampa renderebbero comunque la tessera introvabile).
11. Soglia "In scadenza" = 30 giorni hardcoded; `associazioni.giorni_notifica_scadenza` esiste ma
    non è usato in questo modulo (probabile uso nelle notifiche email).
12. La verifica pubblica non distingue "scaduta" da "sospesa/annullata" nello stato macro (entrambe
    `scaduta`), ma espone `stato_tessera` nel dettaglio.
13. `template_tessera` su `tessere` è una **etichetta** denormalizzata (nome tipo socio al momento
    dell'emissione PDF), usata come fallback nei grafici e mostrata in lista; non è una FK ai template.
14. Il form di modifica permette di cambiare `socio_id` di una tessera esistente (riassegnazione).
15. Date sempre mostrate `d/m/Y`; salvate `Y-m-d`. Confronto validità con confronto stringa su `Y-m-d`
    (corretto perché formato ordinabile).

---

## 10. Note per l'implementazione Laravel

### Modelli e migration
- `Tessera` (tabella `tessere`): UUID PK (`HasUuids`), `belongsTo` Socio, Associazione, Evento (nullable);
  cast `data_emissione`/`data_scadenza` a `date`; enum PHP per `tipo_scadenza` ('solare','annuale') e
  `stato` ('Attiva','Scaduta','Sospesa','Annullata'). Indici e UNIQUE come da §2.1. Valutare `softDeletes`
  al posto dell'hard delete (con audit).
- `TesseraTemplate` (tabella `tessera_templates`): UNIQUE (associazione, tipo_socio nullable);
  rispettare il flag `attivo` (il legacy lo ignorava — fixarlo).
- Aggiungere FK reale su `evento_creazione_id` (`nullOnDelete`) + validazione tenant.

### Logica di dominio
- **Service `TesseraIssuer`** unico per emissione/rinnovo, usato da: creazione singola, generazione
  massiva, emissione on-demand. Centralizza: calcolo scadenza (solare/annuale dal default associazione),
  numerazione, stato iniziale, etichetta template, evento opzionale, dispatch email.
- **Numerazione unificata**: consigliato formato `{anno}{progressivo 4 cifre}` con progressivo per
  (associazione, anno) ottenuto in modo concorrenza-safe (riga contatore con `lockForUpdate()` in
  transazione, oppure retry su violazione unique). Niente COUNT/MAX runtime.
- **Stato derivato**: accessor `isValid()` = `stato === Attiva && data_scadenza >= today()`;
  scope `expired()`, `expiringWithin($days)`; usare ovunque la stessa definizione (lista, filtri, verifica).
  Valutare un job schedulato che marca `stato='Scaduta'` (opzionale, mantenendo comunque la derivazione).
- **Soglia "in scadenza"**: leggere `associazioni.giorni_notifica_scadenza` con default 30, non hardcodare.
- Generazione massiva: `DB::transaction()`, chunking dei soci, evento/job; restituire conteggio generato.
- Email: evento `TesseraCreata` → listener che accoda `Mailable`/notifica se la config associazione
  (`auto_rinnovo_tessera`) e il template sono attivi; coda Laravel al posto della tabella custom.

### Routing / Controller
- Admin (middleware auth + tenant): `GET tessere` (lista + filtri; per AJAX restituire partial/JSON o
  usare Livewire/Inertia), `POST tessere`, `PUT tessere/{tessera}`, `DELETE tessere/{tessera}`,
  `POST tessere/genera-massivo`, `GET tessere/{tessera}/pdf`, `GET tessere/{tessera}/card`.
- Correggere il bug §6.6: il PDF si genera **dalla tessera**; l'emissione on-demand per socio diventa
  `POST soci/{socio}/tessere` (emetti) seguito dal download — niente side effect su GET.
- Pubblico (no auth, rate-limited): `GET /verifica-tessera/{uuid}` (HTML) +
  `GET /api/verifica-tessera/{uuid}` (JSON, CORS same-origin), `GET /scanner-tessera`.
  Route model binding su UUID con regex; 404 → stato `not_found`.
- Policy: nel legacy non c'è differenza tra ruoli; se si vogliono introdurre restrizioni
  (es. operatore senza delete), definire `TesseraPolicy` — altrimenti basta il middleware tenant.

### PDF e QR
- PDF: `barryvdh/laravel-dompdf` (o Browsershot) con view Blade che replica il layout §6.5;
  logo via storage locale → base64 (mantenere la protezione path) o URL pubblico.
- QR: `chillerlan/php-qrcode` o `simplesoftwareio/simple-qrcode`; ECC M; SVG per PDF, PNG data-URI per HTML.
- **URL di verifica**: generare con `route('tessera.verify', $tessera)` basato su `APP_URL`
  (mai `Host` header). Valutare URL firmati (`URL::signedRoute`) se si vuole revocabilità;
  attenzione però: i QR già stampati devono restare verificabili → l'UUID semplice è più robusto.
- Persistere `qr_code_url` al momento dell'**emissione** (non della stampa), così è sempre valorizzato.

### Validazioni (FormRequest)
- `socio_id`: exists su soci del tenant e `stato='Attivo'` (per emissione).
- `numero_tessera`: required, max 50, `unique` composto (associazione_id, numero_tessera) con ignore in update.
- `anno_validita`: integer, range ragionevole (es. 2000–2100).
- `data_emissione`/`data_scadenza`: date; `data_scadenza >= data_emissione`; se assente → calcolo automatico per tipo.
- `stato`: in Attiva/Sospesa/Annullata (mai 'Scaduta' da input utente).
- `evento_creazione_id`: nullable, exists su eventi **del tenant** (fix del gap legacy).
- `tipo_scadenza`: mai da input — sempre dal default associazione.

### Sicurezza e privacy
- Endpoint pubblico di verifica: rate limiting (es. `throttle:30,1`), nessun leak di errori DB,
  log degli accessi opzionale. Valutare di esporre solo iniziale del cognome se la privacy lo richiede
  (il legacy espone nome+cognome completi).
- Scanner: mantenere CSP severa; preferire asset locali (html5-qrcode in `public/vendor`) per
  eliminare la dipendenza CDN e semplificare la CSP.
- Output sempre escapato (Blade default); nello scanner mantenere il rendering via DOM/text.

### Grafici
- Endpoint/metodo dedicato che restituisce l'aggregato §4.8 (stessa whereclause della lista);
  ricavi = costo teorico con precedenza tipo→associazione→0. Se in futuro esistono pagamenti reali
  (modulo quote), distinguere "ricavi teorici tesseramento" da incassi.
