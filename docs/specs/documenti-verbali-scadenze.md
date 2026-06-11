# Specifica funzionale — Modulo Documenti, Verbali e Scadenze

> Reverse-engineering completo dei file legacy:
> - `pages/documenti.php` (gestione documenti associazione)
> - `pages/verbali.php` (verbali riunioni/assemblee con approvazione)
> - `pages/scadenze.php` (scadenze con promemoria e assegnazione)
>
> Obiettivo: permettere la reimplementazione in Laravel **senza consultare il codice vecchio**.

---

## 0. Contesto architetturale comune

- App **multi-tenant SaaS**: ogni record è scopato per `associazione_id` (CHAR(36), UUID).
  Il tenant corrente è in sessione (`$_SESSION['associazione_id']`); in Laravel sarà l'equivalente
  (es. tenant dell'utente autenticato o tenant selezionato dal super_admin).
- **Tutte le primary key sono UUID CHAR(36)** generati lato applicazione (`generateUuid()`).
- **Guard di accesso identico per le tre pagine**: utente loggato (tabella `utenti`, area admin)
  **e** `associazione_id` presente in sessione, altrimenti redirect a login.
  Non c'è alcuna differenziazione di ruolo dentro le pagine (vedi §5).
- **CSRF**: ogni form POST porta un token; token mancante/invalido → messaggio
  "Errore di sicurezza: token CSRF non valido." e nessuna operazione eseguita.
- **Gestione errori DB**: le `PDOException` non sono mai mostrate all'utente; vengono loggate
  (`error_log`) e l'utente vede un messaggio generico ("Errore durante il salvataggio. Riprova più tardi.").
  Sul fetch principale della lista un errore DB è fatale (pagina muore con messaggio generico).
- **Sanitizzazione input**: i campi testuali passano per `sanitizeInput()` (trim + HTML-encode legacy).
  In Laravel: validare e salvare raw, escapare in output (Blade lo fa di default).
- **Output**: tutto ciò che viene stampato è passato per `htmlspecialchars()`;
  i campi multilinea (presenti, odg, deliberazioni) sono resi con `nl2br(htmlspecialchars(...))`.
- **Pattern UI comune**: pagina unica con tabella elenco + modal Bootstrap per create/edit;
  l'edit avviene via `GET ?edit=<id>` che ricarica la pagina, precompila il form nel modal
  e lo apre automaticamente via JS al `DOMContentLoaded`. Le azioni distruttive hanno
  `confirm()` JS. I messaggi esito sono alert Bootstrap dismissibili
  (verde se `messageType === 'success'`, rosso in tutti gli altri casi).
- I form di filtro/ricerca sono GET e si auto-inviano al change dei select.

---

## 1. DOCUMENTI (`pages/documenti.php`)

### 1.1 Scopo
Repository di documenti dell'associazione (statuti, regolamenti, verbali scansionati, ecc.)
con upload file, categorizzazione libera, ricerca, download ed eliminazione.

> **Nota importante**: nel codice legacy questa pagina gestisce **solo documenti a livello di
> associazione**. Non esiste in questo file alcuna gestione di "documenti del singolo socio"
> (nessuna colonna `socio_id`): se il modulo Laravel deve supportare documenti-socio,
> è una **estensione nuova** (vedi §6) e non un comportamento da replicare.

### 1.2 Tabella DB: `documenti`

| Colonna | Tipo (dedotto) | Uso nel codice |
|---|---|---|
| `id` | CHAR(36) PK | UUID generato in app |
| `associazione_id` | CHAR(36) FK | tenant scoping su ogni query |
| `nome_file` | VARCHAR | nome file originale (`basename` dell'upload), mostrato in tabella e usato in ricerca |
| `percorso_file` | VARCHAR | path fisico relativo (`../uploads/documents/<uniqid>_<nome>`); usato per download (href diretto) e per `unlink` alla cancellazione |
| `descrizione` | TEXT nullable | testo libero, mostrato sotto il nome, ricercabile |
| `categoria` | VARCHAR nullable | testo libero (es. "Statuti", "Verbali", "Regolamenti"); mostrata come "N/D" se null |
| `caricato_da` | CHAR(36) nullable | **sempre inserito NULL** nel codice legacy (bug/incompletezza: l'utente uploader non viene registrato) |
| `created_at` | TIMESTAMP (default DB) | non impostato dall'INSERT; usato per ordinamento DESC e visualizzazione `d/m/Y H:i` |

### 1.3 Funzionalità

#### F1 — Upload documento (POST con `$_FILES['document_file']`)
Form multipart con: `description` (textarea, opzionale), `category` (testo libero, opzionale,
con placeholder "es. Statuti, Verbali, Regolamenti"), `document_file` (input file, `required` HTML).

Pipeline di validazione (tutti i check vengono eseguiti, vince il **primo messaggio** impostato):
1. CSRF valido.
2. Directory di destinazione `uploads/documents/` creata se assente (`mkdir 0755 ricorsivo`).
3. Nome destinazione: `uniqid() . "_" . basename(nomeOriginale)` → previene collisioni
   e path traversal sul nome.
4. **File già esistente** sul filesystem con lo stesso path target → errore "file già esistente"
   (con uniqid è praticamente impossibile, ma il check c'è).
5. **Dimensione max 10 MB** (10.000.000 byte esatti) → "Errore: file troppo grande (max 10MB)."
6. **Estensioni permesse** (case-insensitive, controllo SOLO sull'estensione, **non** sul MIME):
   `pdf, doc, docx, xls, xlsx, ppt, pptx, jpg, jpeg, png` → altrimenti errore con elenco formati.
7. Se ok: `move_uploaded_file`, poi INSERT in `documenti`
   `(id, associazione_id, nome_file, percorso_file, descrizione, categoria, caricato_da=NULL)`.
8. **Rollback compensativo**: se l'INSERT fallisce (PDOException), il file appena spostato
   viene cancellato (`unlink`) — il filesystem non deve restare orfano. Errore loggato,
   messaggio generico all'utente.
9. Successo → "Documento caricato con successo!".

Lato client: preview JS del nome file selezionato sotto l'input (icona + nome).

#### F2 — Eliminazione documento (POST con `delete_id`)
1. CSRF valido.
2. SELECT `percorso_file` con filtro `id = ? AND associazione_id = ?` (tenant check obbligatorio).
3. Se non trovato → "Documento non trovato." (errore).
4. Se trovato: **prima** DELETE dal DB, **poi** `unlink` del file fisico se esiste
   (file mancante sul disco non è un errore). Successo → "Documento eliminato con successo!".
5. Conferma JS prima del submit ("Sei sicuro di voler eliminare questo documento?").

#### F3 — Elenco, ricerca e filtro
- Query base: `SELECT * FROM documenti WHERE associazione_id = ?`.
- `?search=` → `AND (nome_file LIKE %term% OR descrizione LIKE %term%)`.
- `?category=` ≠ `'all'` → `AND categoria = ?` (match esatto).
- Ordinamento: `created_at DESC`.
- Le due form di filtro (search e categoria) sono GET **separate**: applicare un filtro
  azzera l'altro (comportamento legacy; in Laravel meglio unificarle preservando entrambi).
- Dropdown categorie popolato dinamicamente: `SELECT DISTINCT categoria ... WHERE associazione_id = ? ORDER BY categoria`
  (quindi compaiono solo categorie effettivamente usate dal tenant; le categorie sono folksonomy, nessuna tabella dedicata).
- Header tabella mostra il conteggio "N documenti trovati".

#### F4 — Download
- Link diretto `<a href="<percorso_file>" download>`: il file è servito **staticamente**
  dal webserver. ⚠️ Nessun controllo di autorizzazione sul download: chi conosce l'URL
  scarica il file anche senza login (debolezza legacy da NON replicare, vedi §6).

### 1.4 Colonne mostrate in tabella
Nome File (bold) + descrizione (small muted) | Categoria (o "N/D") | Data (`d/m/Y H:i` da `created_at`) | Azioni (Scarica, Elimina).

### 1.5 Edge case
- Upload senza CSRF → blocco totale, nessun file scritto.
- INSERT fallito → file rimosso (nessun orfano).
- DELETE di id appartenente ad altro tenant → "Documento non trovato" (lo scoping lo nasconde).
- `descrizione`/`categoria` null in output → fallback `''` / `'N/D'`.
- File assente su disco al delete → il record DB viene comunque eliminato senza errore.
- Non esiste funzione di modifica/rinomina del documento: solo upload e delete.
- Nessuna paginazione: tutta la lista in un'unica tabella.

---

## 2. VERBALI (`pages/verbali.php` — v2.0 SaaS multi-tenant)

### 2.1 Scopo
Gestione dei verbali di riunioni/assemblee: redazione (titolo, data, tipo, partecipanti,
ordine del giorno, deliberazioni), visualizzazione/stampa, e **workflow di approvazione**
a uno step con tracciamento di chi approva e quando.

> **Nota su "firmatari"**: il codice legacy NON ha una tabella/lista di firmatari strutturata.
> I "presenti/partecipanti" sono un **campo testuale libero** (`presenti`, textarea) e l'unica
> "firma" è l'approvazione (`approvato_da` = utente admin che approva). Se la nuova versione
> deve avere firmatari strutturati per-socio, è un'estensione (vedi §6).

### 2.2 Tabella DB: `verbali`

| Colonna | Tipo (dedotto) | Uso |
|---|---|---|
| `id` | CHAR(36) PK | UUID |
| `associazione_id` | CHAR(36) FK | tenant scoping |
| `titolo` | VARCHAR | obbligatorio (required HTML), ricercabile |
| `data_riunione` | DATE | obbligatoria, input date, ordinamento DESC |
| `tipo_riunione` | VARCHAR/ENUM | valori chiusi: `Assemblea Ordinaria`, `Assemblea Straordinaria`, `Consiglio Direttivo`, `Commissione` (select required) |
| `presenti` | TEXT | elenco partecipanti in testo libero; ricercabile; troncato a 50 char in tabella |
| `ordine_del_giorno` | TEXT | testo libero multilinea |
| `deliberazioni` | TEXT | testo libero multilinea |
| `approvato` | TINYINT(1) default 0 | flag stato; 0 = "In attesa", 1 = "Approvato" |
| `approvato_da` | CHAR(36) FK → `utenti.id`, nullable | settato all'approvazione con `$_SESSION['user_id']` |
| `data_approvazione` | DATE nullable | settata a `CURDATE()` all'approvazione |
| `created_at` / `updated_at` | TIMESTAMP | settati con `NOW()` a insert; `updated_at = NOW()` a update |

Join in lettura: `LEFT JOIN utenti u ON v.approvato_da = u.id` per ottenere
`CONCAT(u.nome, ' ', u.cognome)` come nome dell'approvatore (fallback "N/D" se utente cancellato).

### 2.3 Funzionalità

#### F1 — Creazione / Modifica verbale (POST, dispatch su presenza di `id`)
- Un unico handler: se `$_POST['id']` valorizzato → UPDATE, altrimenti INSERT con UUID nuovo.
- Campi: `titolo` (req), `data_riunione` (req), `tipo_riunione` (req, select),
  `presenti`, `ordine_del_giorno`, `deliberazioni` (textarea opzionali).
- UPDATE sempre con `WHERE id = ? AND associazione_id = ?` (tenant check).
- ⚠️ Comportamento legacy: l'UPDATE è possibile **anche su verbali già approvati** e
  non resetta il flag `approvato` (un verbale approvato resta approvato anche se modificato).
  Decidere in Laravel se mantenere o irrigidire (consigliato: bloccare/azzerare approvazione
  alla modifica — vedi §6).
- Edit flow: `GET ?edit=<uuid>` → fetch scoped del verbale → form precompilato nel modal
  che si auto-apre via JS.
- Messaggi: "Verbale aggiunto/aggiornato con successo!".

#### F2 — Approvazione (POST con `approve_id`)
- `UPDATE verbali SET approvato = 1, approvato_da = <utente corrente>, data_approvazione = CURDATE()
   WHERE id = ? AND associazione_id = ?`.
- Il pulsante "Approva" appare **solo se** il verbale non è già approvato.
- Conferma JS ("Approvare questo verbale?").
- Non esiste revoca dell'approvazione nella UI (irreversibile salvo edit DB).
- Chi approva: qualsiasi utente loggato del tenant (nessun check di ruolo nel legacy).

#### F3 — Eliminazione (POST con `delete_id`)
- `DELETE ... WHERE id = ? AND associazione_id = ?`, con conferma JS.
- È possibile eliminare anche verbali approvati (nessun blocco).
- Il messaggio di successo viene mostrato anche se l'id non esisteva (il DELETE non
  controlla le righe affette) — edge case legacy.

#### F4 — Elenco, ricerca, filtro
- `?search=` → `AND (titolo LIKE %t% OR presenti LIKE %t%)` (cerca anche nei partecipanti).
- `?type=` ≠ `all` → `AND tipo_riunione = ?` (i 4 valori fissi di cui sopra).
- Ordinamento: `data_riunione DESC`.
- Colonne tabella: Titolo (+ "Partecipanti: <primi 50 char>…"), Data Riunione (formattata),
  Tipo (badge info), Stato (badge verde "Approvato" con "da <nome cognome> il <data>" |
  badge giallo "In attesa"), Azioni.
- Conteggio "N verbali trovati".

#### F5 — Visualizzazione e stampa
- Per ogni riga un modal "Visualizza" (uno per verbale, id `viewModal<uuid>`) con:
  Data Riunione, Tipo, Partecipanti, Ordine del Giorno, Deliberazioni
  (testi multilinea resi con `nl2br`).
- Pulsante "Stampa" nel modal → `window.print()` (stampa della pagina, nessun template dedicato).

#### F6 — Export PDF
- Link "Esporta PDF" → `index.php?page=verbali&action=export`.
- ⚠️ L'handler `action=export` **non è implementato in questo file** (probabilmente nel
  router/altro file, o mai implementato). In Laravel va previsto un export PDF dell'elenco
  o del singolo verbale (es. dompdf/laravel-pdf).

### 2.4 Edge case
- Approvatore eliminato da `utenti` → LEFT JOIN restituisce null → mostrato "N/D".
- `data_approvazione` null con `approvato = 1` → la data viene semplicemente omessa.
- Modifica post-approvazione consentita senza reset stato (vedi F1).
- Nessuna paginazione; nessun allegato file al verbale (eventuale PDF firmato andrebbe nei Documenti con categoria "Verbali").

---

## 3. SCADENZE (`pages/scadenze.php` — v2.0 SaaS)

### 3.1 Scopo
Task/promemoria dell'associazione: adempimenti con data di scadenza, tipo, priorità, stato,
assegnazione a un utente interno e soglia di promemoria in giorni. Dashboard con contatori,
evidenziazione visiva di scadute/in scadenza, completamento rapido.

### 3.2 Tabella DB: `scadenze`

| Colonna | Tipo (dedotto) | Uso |
|---|---|---|
| `id` | CHAR(36) PK | UUID |
| `associazione_id` | CHAR(36) FK | tenant scoping |
| `titolo` | VARCHAR | obbligatorio, ricercabile |
| `descrizione` | TEXT nullable | opzionale, ricercabile, troncata a 80 char in tabella |
| `data_scadenza` | DATE | obbligatoria; base per calcolo giorni residui |
| `tipo_scadenza` | VARCHAR/ENUM | valori chiusi: `Quote`, `Documenti`, `Eventi`, `Generale` (select required) |
| `priorita` | VARCHAR/ENUM | `Bassa`, `Media`, `Alta`, `Critica` (default UI: Bassa, prima opzione) |
| `stato` | VARCHAR/ENUM | `Attiva`, `Completata`, `Annullata` (default UI: Attiva) |
| `assegnato_a` | CHAR(36) FK → `utenti.id`, nullable | stringa vuota dal form convertita a NULL (`?: null`) |
| `promemoria_giorni` | INT | default form: 7; min HTML: 1; soglia "in scadenza" |
| `created_at` / `updated_at` | TIMESTAMP | NOW() su insert; updated_at su ogni update/complete |

Join in lettura: `LEFT JOIN utenti u ON s.assegnato_a = u.id` →
`CONCAT(u.nome,' ',u.cognome) as assegnato_username`.

Dropdown assegnazione: `SELECT id, CONCAT(nome,' ',cognome), email FROM utenti
WHERE associazione_id = ? ORDER BY nome` — **solo utenti admin del tenant**, non soci.

### 3.3 Funzionalità

#### F1 — Creazione / Modifica (POST, dispatch su `id`)
- Stesso pattern dei verbali: `id` presente → UPDATE scoped, assente → INSERT con UUID.
- Campi form: titolo (req), descrizione, data_scadenza (req, date), promemoria_giorni
  (number, min 1, default 7), tipo_scadenza (req, select), priorita (select, default Bassa),
  assegnato_a (select utenti, default "Non assegnato" = NULL), stato (select, default Attiva).
- Lo `stato` è editabile direttamente dal form (si può creare una scadenza già Completata/Annullata
  o riattivare una completata via edit).
- Edit flow identico a verbali (`?edit=<uuid>` + modal auto-aperto).

#### F2 — Completamento rapido (POST con `complete_id`)
- `UPDATE scadenze SET stato = 'Completata', updated_at = NOW() WHERE id = ? AND associazione_id = ?`.
- Pulsante "Completa" visibile **solo se** `stato === 'Attiva'`, con conferma JS.
- Non registra chi/quando ha completato oltre a `updated_at` (nessun `completata_da`).

#### F3 — Eliminazione (POST con `delete_id`)
- DELETE scoped con conferma JS. Possibile su qualunque stato.

#### F4 — Elenco con 4 filtri indipendenti + ricerca
- `?search=` → `titolo LIKE` OR `descrizione LIKE`.
- `?status=` ∈ {Attiva, Completata, Annullata} (o `all`).
- `?type=` ∈ {Quote, Documenti, Eventi, Generale} (o `all`).
- `?priority=` ∈ {Critica, Alta, Media, Bassa} (o `all`).
- Anche qui ogni filtro è una form GET separata: cambiarne uno azzera gli altri (legacy).
- **Ordinamento composito** (importante, da replicare esattamente):
  1. stato: Attiva (1) → Annullata (2) → Completata (3)
  2. priorità: Critica (1) → Alta (2) → Media (3) → Bassa (4)
  3. `data_scadenza ASC`

#### F5 — Statistiche (card in testa pagina)
Calcolate **in PHP sul result-set filtrato** (quindi i contatori riflettono i filtri attivi):
- **Critiche**: `priorita = 'Critica' AND stato = 'Attiva'`
- **In Scadenza**: `stato = 'Attiva' AND data_scadenza <= oggi + 7 giorni`
  (⚠️ qui la soglia è **fissa a 7 giorni**, NON usa `promemoria_giorni`; include anche le già scadute)
- **Attive**: `stato = 'Attiva'`
- **Completate**: `stato = 'Completata'`

#### F6 — Logica promemoria / evidenziazione riga (regole di business chiave)
Per ogni scadenza, calcolo:
`giorni = floor((strtotime(data_scadenza) - now) / 86400)` → giorni residui (negativo se scaduta).
- `isScaduta = giorni < 0`
- `isInScadenza = giorni >= 0 AND giorni <= promemoria_giorni` (qui SÌ usa il campo per-record)

Rendering condizionale (solo se `stato === 'Attiva'`):
- riga `table-danger` se scaduta; `table-warning` se in finestra promemoria;
- etichetta sotto la data:
  - `giorni < 0` → "Scaduta da N giorni" (rosso, N = |giorni|)
  - `giorni == 0` → "Scade oggi" (giallo)
  - `0 < giorni <= promemoria_giorni` → "Scade tra N giorni" (giallo)
  - altrimenti → "Tra N giorni" (muted)
- Scadenze Completate/Annullate: nessuna etichetta né evidenziazione.

⚠️ Il "promemoria" nel legacy è **solo visuale** (evidenziazione in pagina): non esiste
alcun invio email/notifica schedulato in questo file. In Laravel è il punto naturale per
una notifica reale (vedi §6).

#### F7 — Badge colore
- Priorità: Critica=danger, Alta=warning, Media=info, Bassa=secondary.
- Stato: Attiva=success, Completata=primary, Annullata=dark.
- Tipo: badge info. Assegnatario: badge primary, oppure testo muted "Non assegnato".

#### F8 — Export CSV
- Link "Esporta CSV" → `index.php?page=scadenze&action=export`.
- ⚠️ Come per i verbali, l'handler non è in questo file: da implementare ex novo in Laravel
  (CSV dell'elenco corrente, idealmente rispettando i filtri attivi).

### 3.4 Edge case
- `assegnato_a` vuoto dal form → NULL in DB (mai stringa vuota).
- Utente assegnatario eliminato → LEFT JOIN null → "Non assegnato".
- `promemoria_giorni` ha min=1 solo lato HTML: il backend legacy non valida (Laravel: `integer|min:1`).
- Il calcolo giorni usa `time()` (ora corrente) vs mezzanotte di `data_scadenza`: una scadenza
  "oggi" può risultare `giorni = -1` o `0` a seconda dell'ora (legacy impreciso; in Laravel
  usare confronto tra date pure, `Carbon::today()`).
- Anche qui il DELETE/UPDATE non verifica righe affette: messaggio di successo anche su id inesistente.

---

## 4. Validazioni — riepilogo per campo

| Modulo | Campo | Regola legacy | Regola Laravel consigliata |
|---|---|---|---|
| Documenti | file | required (HTML), ext whitelist, ≤10MB | `required|file|max:10000|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png` + check MIME reale |
| Documenti | descrizione | opzionale | `nullable|string` |
| Documenti | categoria | opzionale, testo libero | `nullable|string|max:255` |
| Verbali | titolo | required | `required|string|max:255` |
| Verbali | data_riunione | required, date | `required|date` |
| Verbali | tipo_riunione | required, 1 di 4 valori | `required|in:Assemblea Ordinaria,Assemblea Straordinaria,Consiglio Direttivo,Commissione` |
| Verbali | presenti/odg/deliberazioni | opzionali | `nullable|string` |
| Scadenze | titolo | required | `required|string|max:255` |
| Scadenze | data_scadenza | required, date | `required|date` |
| Scadenze | tipo_scadenza | required, 1 di 4 | `required|in:Quote,Documenti,Eventi,Generale` |
| Scadenze | priorita | 1 di 4 (no required backend) | `required|in:Bassa,Media,Alta,Critica` |
| Scadenze | stato | 1 di 3 | `required|in:Attiva,Completata,Annullata` |
| Scadenze | assegnato_a | vuoto→NULL, nessuna verifica esistenza | `nullable|uuid|exists:utenti,id` **+ stesso tenant** |
| Scadenze | promemoria_giorni | min 1 solo HTML | `required|integer|min:1|max:365` |

⚠️ Il backend legacy **non valida** `data_riunione`, `tipo_riunione`, `data_scadenza`, `priorita`,
`stato`, `promemoria_giorni`: si fida del form. In Laravel validare sempre server-side.
⚠️ Legacy non verifica che `assegnato_a` appartenga al tenant: in Laravel aggiungere il vincolo.

---

## 5. Comportamenti per ruolo

**Stato legacy**: le tre pagine NON distinguono i ruoli. L'unico requisito è
"utente autenticato dell'area admin + tenant in sessione". Quindi `super_admin`,
`admin_associazione` e `operatore` possono tutti: caricare/eliminare documenti,
creare/modificare/approvare/eliminare verbali, gestire scadenze — sempre e solo
**sul tenant corrente in sessione** (il super_admin opera sul tenant che ha selezionato).
I soci (area soci, tabella `soci`) non hanno alcun accesso a queste pagine.

**Matrice consigliata per Laravel** (irrigidimento ragionevole, non presente nel legacy):

| Azione | super_admin | admin_associazione | operatore |
|---|---|---|---|
| Vedere/cercare documenti, verbali, scadenze | ✅ (ogni tenant) | ✅ (proprio tenant) | ✅ (proprio tenant) |
| Upload/elimina documenti | ✅ | ✅ | ✅ upload / ⛔ o ✅ delete (a scelta) |
| Creare/modificare verbali | ✅ | ✅ | ✅ |
| **Approvare** verbali | ✅ | ✅ | ⛔ (consigliato: solo admin) |
| Eliminare verbali approvati | ✅ | ✅ | ⛔ |
| CRUD scadenze + completa | ✅ | ✅ | ✅ |

Implementare con Policies; il vincolo *hard* irrinunciabile è lo scoping tenant
(global scope `associazione_id` su tutti i Model).

---

## 6. Note per l'implementazione Laravel

### Struttura
- **Migrations**: tre tabelle come da §1.2/§2.2/§3.2, PK `uuid`, FK `associazione_id`
  con `cascadeOnDelete`, indici su (`associazione_id`,`created_at`), (`associazione_id`,`data_riunione`),
  (`associazione_id`,`stato`,`data_scadenza`).
- **Models**: `Documento`, `Verbale`, `Scadenza` con `HasUuids` e un trait/global scope
  `BelongsToAssociazione`. Relazioni: `Verbale::approvatoDa()` e `Scadenza::assegnatoA()`
  verso il modello utenti admin; `Documento::caricatoDa()` (e finalmente valorizzarlo
  con `auth()->id()`, sanando il NULL fisso del legacy).
- **Controllers** resource: `DocumentoController` (index, store, destroy, download),
  `VerbaleController` (index, store, update, destroy + `approve`), `ScadenzaController`
  (index, store, update, destroy + `complete`). FormRequest per le validazioni di §4.
- **Enums PHP** backed-string per `tipo_riunione`, `tipo_scadenza`, `priorita`, `stato`
  (mantenere i valori italiani esatti per compatibilità dati migrati).

### Storage e sicurezza file (correzioni obbligatorie rispetto al legacy)
- Usare `Storage::disk('local')` (NON pubblico): download via route autenticata
  `GET /documenti/{documento}/download` con Policy + `Storage::download()`,
  eliminando l'accesso anonimo per URL del legacy.
- Salvare in DB `nome_file` originale e `percorso_file` come path relativo del disk;
  nome fisico random (`Str::uuid`), mai il nome utente.
- Validare il MIME reale, non solo l'estensione.
- Replicare il rollback compensativo: avvolgere store in transazione e cancellare il file
  se l'INSERT fallisce (o creare il record prima e fare lo `putFileAs` dopo).
- Alla delete: prima DB (in transazione), poi `Storage::delete()` tollerante al file mancante.

### Logiche da replicare fedelmente
- Ordinamento composito scadenze (stato→priorità→data) — usare `orderByRaw` con CASE
  o colonne di rank.
- Etichette giorni residui e soglie (`promemoria_giorni` per-record per riga;
  7 giorni fissi per la card "In Scadenza" — oppure, miglioria, usare il campo anche lì).
- Filtri verbali/scadenze e ricerca LIKE su più campi; unificare i form filtro in
  un'unica query string che preservi tutti i parametri (fix del difetto legacy).
- Workflow approvazione verbale one-shot con `approvato_da` + `data_approvazione`,
  pulsante solo su non-approvati; valutare `approvato_at` timestamp invece di date.
- Conferme distruttive lato client e flash messages (session flash).

### Estensioni richieste dal nuovo prodotto (NON presenti nel legacy)
1. **Documenti socio**: aggiungere `socio_id` nullable FK su `documenti` (o tabella
   `documenti_soci`): documento con `socio_id` null = documento associazione,
   altrimenti documento del fascicolo socio (visibile anche nell'area soci al diretto interessato).
2. **Firmatari verbali strutturati**: tabella pivot `verbale_firmatari`
   (`verbale_id`, `socio_id`/`utente_id`, `ruolo` es. Presidente/Segretario, `firmato_at`)
   in sostituzione/affiancamento del campo testo `presenti`. In migrazione dati,
   `presenti` resta come testo libero legacy.
3. **Promemoria reali per scadenze**: command schedulato giornaliero che seleziona le
   scadenze `Attiva` con `data_scadenza - oggi <= promemoria_giorni` e invia Notification
   (mail/database) all'assegnatario o agli admin del tenant; tracciare l'invio
   (es. `promemoria_inviato_at`) per evitare duplicati.
4. **Export**: implementare davvero `verbali → PDF` (singolo verbale, template dedicato
   con intestazione associazione, firme) e `scadenze → CSV` (rispettando i filtri attivi),
   visto che nel legacy i link esistono ma gli handler non sono nel file.
5. **Decisioni da prendere**: bloccare la modifica dei verbali approvati (o resettare
   l'approvazione alla modifica); aggiungere `completata_da`/`completata_at` alle scadenze;
   paginazione server-side su tutte le liste; categorie documenti come tabella opzionale
   con autocomplete invece del testo libero puro.

### Migrazione dati
- Le tre tabelle migrano 1:1 (stessi nomi colonna utilizzabili).
- `documenti.caricato_da` è NULL su tutto lo storico (accettare null).
- `documenti.percorso_file` legacy è un path relativo tipo `../uploads/documents/<uniqid>_<nome>`:
  in migrazione spostare i file nel nuovo disk e riscrivere il path.
- I valori enum sono stringhe italiane esatte: mantenerle nelle Enum per non dover
  trasformare i dati.
