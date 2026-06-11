# Specifica funzionale — Modulo Eventi, Partecipanti e Check-in QR

> Sorgenti analizzate (app PHP legacy): `pages/eventi.php`, `pages/partecipanti.php`,
> `checkin.php` (pagina mobile standalone) e relativo endpoint REST `api/v1/endpoints/eventi.php`.
> Schema DB di riferimento: `database/schema.sql`.
>
> Questa specifica è autosufficiente: permette di reimplementare il modulo in Laravel
> senza consultare il codice legacy.

---

## 1. Panoramica del modulo

Il modulo gestisce:

1. **CRUD eventi** dell'associazione (titolo, descrizione, data/ora, luogo).
2. **Gestione partecipanti** per singolo evento: iscrizione di soci (singola o massiva),
   aggiornamento dello stato di partecipazione, note, rimozione.
3. **Check-in all'ingresso** tramite pagina mobile standalone con scanner QR
   (o inserimento manuale del numero tessera), che dialoga **esclusivamente via REST API
   autenticata con API key Bearer** (nessuna sessione web).
4. **API REST** `GET /eventi`, `GET /eventi/{id}` (con partecipanti), `POST /eventi/{id}/checkin`.

Contesto multi-tenant: ogni dato è isolato per `associazione_id` (UUID CHAR(36)).
Tutte le chiavi primarie sono UUID v4 generati applicativamente (`generateUuid()`).

---

## 2. Tabelle DB coinvolte

### 2.1 `eventi`

| Colonna | Tipo | Note |
|---|---|---|
| `id` | CHAR(36) PK | UUID generato dall'app |
| `associazione_id` | CHAR(36) NOT NULL, FK → `associazioni.id` ON DELETE CASCADE | tenant |
| `titolo` | VARCHAR(255) NOT NULL | obbligatorio |
| `descrizione` | TEXT NULL | facoltativo |
| `data_evento` | DATETIME NOT NULL | data **e ora** dell'evento (input `datetime-local`) |
| `luogo` | VARCHAR(255) NULL | facoltativo |
| `created_at` | DATETIME DEFAULT CURRENT_TIMESTAMP | |
| `updated_at` | DATETIME ON UPDATE CURRENT_TIMESTAMP | |

### 2.2 `eventi_partecipanti`

| Colonna | Tipo | Note |
|---|---|---|
| `id` | CHAR(36) PK | UUID |
| `associazione_id` | CHAR(36) NOT NULL, FK → `associazioni.id` ON DELETE CASCADE | tenant (denormalizzato, sempre uguale a quello dell'evento) |
| `evento_id` | CHAR(36) NOT NULL, FK → `eventi.id` ON DELETE CASCADE | |
| `socio_id` | CHAR(36) NOT NULL, FK → `soci.id` ON DELETE CASCADE | |
| `stato_partecipazione` | ENUM('Confermato','Forse','Non Partecipa') DEFAULT 'Non Partecipa' | i 3 stati possibili, stringhe esatte in italiano |
| `data_conferma` | TIMESTAMP NULL | timestamp dell'ultimo aggiornamento di stato / check-in |
| `note` | TEXT NULL | note libere per partecipante |
| `created_at` | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |
| **Vincoli** | `UNIQUE (evento_id, socio_id)` | un socio può comparire una sola volta per evento |
| Indici | su `associazione_id`, `evento_id`, `socio_id` | |

> Nota: NON esistono colonne `updated_at` né un campo "check-in" dedicato:
> il check-in è modellato come `stato_partecipazione = 'Confermato'` + `data_conferma`.

### 2.3 Tabelle correlate (sola lettura per questo modulo)

- **`soci`**: colonne usate `id`, `nome`, `cognome`, `numero_socio`, `email`, `telefono`,
  `stato` (filtro su valore `'Attivo'`), `associazione_id`.
- **`tessere`**: colonne usate `id` (UUID, codificato nel QR), `numero_tessera`,
  `socio_id`, `associazione_id`, `anno_validita`, `evento_creazione_id`
  (FK verso `eventi.id`: indica che la tessera è stata emessa durante quell'evento).
- **`associazioni`**: `id`, `nome` (join solo per API key globali, vedi §6).

---

## 3. Funzionalità: CRUD Eventi (pagina admin `eventi`)

Accesso: utente autenticato dell'area amministrativa con `associazione_id` in sessione
(guard legacy: `isUserLoggedIn() && isset($_SESSION['associazione_id'])`, altrimenti redirect al login).
Tutte le POST richiedono token CSRF valido; in caso di token non valido si mostra
"Errore di sicurezza: token CSRF non valido" senza eseguire nulla.

### 3.1 Lista eventi

- Query: tutti gli eventi con `associazione_id = tenant corrente`, ordinati per
  `data_evento DESC` (i più recenti/futuri in alto).
- Per ogni evento è calcolato il contatore **"Tessere create"**:
  `SELECT COUNT(*) FROM tessere WHERE evento_creazione_id = evento.id`
  (subquery correlata; mostrato come badge se > 0, altrimenti "0" in grigio).
- **Ricerca testuale** (`?search=`): filtro `LIKE %term%` su `titolo` OR `descrizione` OR `luogo`
  (nota: nel legacy il form di ricerca non è renderizzato in pagina, ma il parametro GET è supportato).
- Colonne mostrate: Titolo, Data e Ora (formato `d/m/Y H:i`), Luogo, Tessere create, Azioni.
- Azioni per riga: link a Partecipanti (`?page=partecipanti&evento_id=...`),
  Modifica (apre il form precompilato), Elimina (con `confirm()` JS).
- Esiste un layout doppio desktop (tabella) / mobile (card): in Laravel basta un layout responsive.

### 3.2 Creazione evento

- Form modale con campi: `titolo` (testo, **required**), `data_evento`
  (input `datetime-local`, **required**), `luogo` (testo, opzionale),
  `descrizione` (textarea, opzionale).
- Insert con UUID nuovo e `associazione_id` del tenant.
- Messaggio di successo: "Evento creato con successo."
- Validazione legacy: solo `required` HTML lato client su titolo e data; il server
  sanitizza l'input (strip/encode) ma non valida formati. **In Laravel**: validare
  `titolo` required|max:255, `data_evento` required|date, `luogo` nullable|max:255,
  `descrizione` nullable.

### 3.3 Modifica evento

- `?edit={id}`: carica l'evento **solo se appartiene al tenant** (`id AND associazione_id`);
  se non trovato, semplicemente non apre il form (nessun errore).
- La data viene riconvertita nel formato `Y-m-d\TH:i` per il campo `datetime-local`.
- Il form modale si apre automaticamente al load della pagina (JS) quando è presente `?edit`.
- UPDATE su `titolo, descrizione, data_evento, luogo` con clausola
  `WHERE id = ? AND associazione_id = ?` (isolamento tenant anche in scrittura).
- Discriminazione create/update: presenza del campo hidden `id` nel POST.
- Messaggio: "Evento aggiornato con successo."

### 3.4 Eliminazione evento

- POST con `delete_id`; `DELETE FROM eventi WHERE id = ? AND associazione_id = ?`.
- Conferma JS lato client ("Eliminare questo evento?").
- **Effetto a cascata (a livello DB)**: i record di `eventi_partecipanti` collegati vengono
  eliminati per FK `ON DELETE CASCADE`. Le `tessere` con `evento_creazione_id` puntato
  NON vengono eliminate (comportamento da replicare: il riferimento va messo a NULL o
  gestito con `nullOnDelete()` in Laravel — verificare lo schema tessere in fase di
  implementazione).
- Messaggio: "Evento eliminato con successo."
- Edge case: eliminare un ID inesistente o di un altro tenant non produce errore,
  mostra comunque il messaggio di successo (idempotente).

---

## 4. Funzionalità: Partecipanti evento (pagina admin `partecipanti`)

Accesso: stesso guard della pagina eventi. Parametro obbligatorio `?evento_id=`:

- se assente → redirect alla lista eventi;
- se l'evento non esiste **o non appartiene al tenant** → redirect alla lista eventi.

### 4.1 Stati di partecipazione

Tre stati, stringhe esatte (sono anche valori ENUM nel DB):

| Stato | Significato | Badge UI |
|---|---|---|
| `Confermato` | parteciperà / ha fatto check-in | verde (success) |
| `Forse` | indeciso | giallo (warning) |
| `Non Partecipa` | invitato ma non parteciperà / default | rosso (danger) |

`Non Partecipa` è il **default DB** e lo stato assegnato dall'aggiunta massiva.

### 4.2 Vista lista partecipanti

- Query: join `eventi_partecipanti ep JOIN soci s ON ep.socio_id = s.id`
  con `WHERE ep.evento_id = ? AND ep.associazione_id = ?`,
  ordinata per `s.cognome, s.nome`.
- Campi mostrati per riga: nome completo (`nome + ' ' + cognome`), `numero_socio`,
  `email`, `telefono` (se presente), stato (badge colorato), `data_conferma`
  (formato `d/m/Y H:i`, "-" se NULL), `note` (troncate a ~150px con tooltip, "-" se vuote).
- **Filtro per stato** (`?status=`): valori `all` (default), `Confermato`, `Forse`,
  `Non Partecipa`; aggiunge `AND ep.stato_partecipazione = ?`. Reso come btn-group
  con il filtro attivo evidenziato.
- **Card statistiche** (calcolate sul set filtrato — vedi edge case §7):
  Confermati, Forse, Non Partecipa, Totale (count dei record caricati).
- Card aggiuntiva "Tessere create" se durante l'evento sono state emesse tessere:
  `SELECT COUNT(*) FROM tessere WHERE evento_creazione_id = ? AND associazione_id = ?`
  (mostrata solo se > 0).

### 4.3 Aggiunta singolo socio (`add_socio`)

- Modale con: select `socio_id` (**required**) popolata con i **soli soci attivi non ancora
  iscritti all'evento** (query: `soci.stato='Attivo' AND associazione_id=? AND id NOT IN
  (SELECT socio_id FROM eventi_partecipanti WHERE evento_id=?)`, ordinati per cognome, nome;
  etichetta opzione: `Nome Cognome (numero_socio)`); select `stato_partecipazione`
  (**required**, default UI "Non Partecipa"); textarea `note` opzionale.
- Insert: `INSERT ... ON DUPLICATE KEY UPDATE stato_partecipazione=VALUES(...),
  note=VALUES(...), data_conferma=NOW()` — quindi se il socio è già iscritto
  (race / doppio submit) **fa upsert** aggiornando stato e note invece di fallire.
- `data_conferma` e `created_at` valorizzati a NOW() all'inserimento.
- Se non ci sono soci disponibili: messaggio informativo nella modale e pulsante
  "Aggiungi" disabilitato; il bottone "Aggiungi Tutti i Soci" è nascosto.
- **Validazione mancante nel legacy (da aggiungere in Laravel)**: il server non verifica
  che `socio_id` appartenga al tenant né che sia attivo — la UI lo limita ma il POST
  è forgiabile. In Laravel validare: socio esistente, `stato='Attivo'`,
  stesso `associazione_id` dell'evento; `stato_partecipazione` in whitelist enum.
- Messaggio: "Socio aggiunto all'evento!"

### 4.4 Aggiunta massiva (`add_all_soci`)

- Bottone "Aggiungi Tutti i Soci" (visibile solo se esistono soci disponibili), con confirm JS.
- Logica: per ogni socio con `stato='Attivo'` del tenant, `INSERT IGNORE` in
  `eventi_partecipanti` con `stato_partecipazione='Non Partecipa'`,
  **senza** `data_conferma` (resta NULL), `created_at=NOW()`.
- I duplicati (soci già iscritti) vengono ignorati silenziosamente grazie a
  `INSERT IGNORE` + UNIQUE(evento_id, socio_id); si conta solo `rowCount > 0`.
- Messaggio: "Aggiunti N soci all'evento!" dove N = inserimenti effettivi.
- In Laravel: usare `insertOrIgnore` in batch oppure upsert; mantenere il conteggio reale.

### 4.5 Aggiornamento partecipazione (`update_partecipazione`)

- Modale per singolo partecipante: select stato (3 valori, **required**) + textarea note.
- UPDATE: `SET stato_partecipazione=?, note=?, data_conferma=NOW()
  WHERE id=? AND associazione_id=?`.
- **Regola implicita**: `data_conferma` viene SEMPRE riscritta a NOW() a ogni modifica,
  qualunque sia lo stato scelto (anche passando a "Non Partecipa"). È il timestamp
  "ultima variazione di stato", non solo della conferma.
- **Validazione mancante nel legacy (da aggiungere)**: il valore stato non è validato
  server-side contro l'enum (l'ENUM MySQL lo coercerebbe); non si verifica che il
  partecipante appartenga all'evento corrente (solo al tenant). In Laravel: `Rule::in`
  sull'enum e scoping `where('evento_id', $evento->id)`.
- Messaggio: "Partecipazione aggiornata con successo!"

### 4.6 Rimozione partecipante (`remove_partecipante`)

- POST con id del record `eventi_partecipanti`; confirm JS.
- `DELETE FROM eventi_partecipanti WHERE id=? AND associazione_id=?` (idempotente,
  nessun errore se inesistente).
- Messaggio: "Partecipante rimosso dall'evento!"

---

## 5. Funzionalità: Check-in mobile con QR (`checkin.php`)

### 5.1 Architettura

Pagina **standalone** (non include config dell'app, niente sessione, niente DB diretto):
una SPA vanilla-JS servita come singola pagina che parla solo con l'API REST `/api/v1`.
Pensata per l'uso da smartphone all'ingresso di un evento (anche da operatori senza
account web). In Laravel: una route pubblica che serve una Blade/SPA con lo stesso
comportamento, oppure componente Livewire/Vue — la logica resta client-side + API.

Caratteristiche di pagina:
- Header HTTP di sicurezza: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
  `Referrer-Policy: strict-origin-when-cross-origin`, **`Permissions-Policy: camera=(self)`**
  (necessario per la fotocamera), CSP con `self` + cdn.jsdelivr.net
  (con `upgrade-insecure-requests` se HTTPS, anche dietro proxy via
  `X-Forwarded-Proto`).
- L'URL base API è calcolato server-side dal path corrente e iniettato nel JS
  (`{schema}://{host}{basePath}/api/v1`).
- È una PWA: manifest, theme-color brand `#FF7B11`, apple-touch-icon.
- Libreria QR: `html5-qrcode@2.3.8` (CDN), camera posteriore (`facingMode: environment`),
  fps 10, area 250×250.

### 5.2 Flusso in 3 schermate

**Schermata 1 — Setup (API key):**
- Input password per la chiave API (placeholder `sk_...`).
- Alla conferma: chiamata di verifica `GET /api/v1/` con header
  `Authorization: Bearer <key>`; se `success` → salva la chiave in
  `localStorage['checkin_api_key']` e passa alla schermata eventi; altrimenti mostra
  l'errore restituito o "Chiave API non valida." / "Errore di connessione...".
- Al load della pagina, se la chiave è già in localStorage si tenta l'auto-connect
  (stessa verifica); se fallisce si torna al setup.
- Bottone "logout" (schermata eventi): rimuove la chiave da localStorage e torna al setup.

**Schermata 2 — Selezione evento:**
- `GET /api/v1/eventi?limit=100` → card cliccabili per ogni evento con titolo,
  data/ora formattata `dd/mm/yyyy hh:mm`, luogo (se presente).
- Gli eventi con `data_evento` di **oggi** sono evidenziati (bordo brand + badge "Oggi").
- Lista vuota → empty state "Nessun evento trovato."; errore rete → alert.
- Il rendering è fatto con metodi DOM sicuri (textContent), mai innerHTML con dati API
  (anti-XSS) — mantenere in Laravel.

**Schermata 3 — Scanner check-in:**
- Header con nome evento, bottone "Cambia" (torna alla lista, ferma lo scanner) e
  **contatore ingressi** della sessione corrente.
- Contatore e lista "ultimi check-in" sono persistiti in
  `sessionStorage` con chiavi per-evento (`checkin_count_<eventoId>`,
  `checkin_recent_<eventoId>`) → sopravvivono al cambio schermata ma non alla chiusura
  del browser. Il contatore conta solo i check-in **nuovi** effettuati da questo
  dispositivo (non i duplicati, non quelli di altri dispositivi).
- **Scanner QR** con cooldown di 2 secondi tra una scansione e l'altra (evita doppie
  letture dello stesso codice).
- **Parsing del QR**: se il testo scansionato è un URL valido con query param `t`
  → si invia `{tessera_id: <t>}` (il QR delle tessere codifica un URL con `?t=<uuid tessera>`);
  altrimenti il testo grezzo viene inviato come `{numero_tessera: <testo>}`.
- **Input manuale** di fallback: campo "Numero tessera..." + OK → invia
  `{numero_tessera: <valore trim>}`. Se la camera non è disponibile viene mostrato
  un warning e resta solo l'input manuale.
- Chiamata: `POST /api/v1/eventi/{eventoId}/checkin` con il payload sopra.
- **Feedback esito** (overlay full-screen auto-dismiss dopo 3s, o tap per chiudere):
  - verde "Check-in OK" + `Cognome Nome` del socio → incrementa contatore,
    aggiunge in cima ai recenti (icona check);
  - giallo "Già registrato" + nome (caso `already_checked_in: true`) → NON incrementa,
    aggiunto ai recenti con icona warning;
  - rosso con il messaggio `error` dell'API (o "Errore sconosciuto" / "Errore di connessione").
- Lista recenti: max 10 voci, formato `icona | nome | hh:mm`.

### 5.3 Endpoint API check-in — `POST /eventi/{id}/checkin`

Autenticazione: API key Bearer con permesso **`eventi:write`**. La chiave può essere
**scoped** (legata a un'associazione) o **globale** (solo super_admin, vede tutti i tenant).

Logica server (da replicare esattamente):

1. Validazione formato `{id}` evento: regex UUID `^[a-f0-9-]{36}$` (case-insensitive),
   altrimenti 400 `invalid_id`.
2. Lookup evento: per chiave scoped `WHERE id=? AND associazione_id=?`; per chiave
   globale solo `WHERE id=?`. Se assente → 404 `not_found`.
3. **Tutte le lookup successive usano l'`associazione_id` DELL'EVENTO** (rilevante per
   chiavi globali: il tenant è derivato dall'evento, non dalla chiave).
4. Identificazione del socio dal body JSON, in ordine di priorità:
   1. `socio_id` — usato direttamente se presente;
   2. `tessera_id` — `SELECT socio_id FROM tessere WHERE id=? AND associazione_id=?`;
   3. `numero_tessera` — `SELECT socio_id FROM tessere WHERE numero_tessera=?
      AND associazione_id=? ORDER BY anno_validita DESC LIMIT 1`
      (**regola implicita**: a parità di numero su più anni vince la tessera più recente);
   4. `numero_socio` — `SELECT id FROM soci WHERE numero_socio=? AND associazione_id=?`.
   Se nessuno identifica un socio → 400 `missing_socio`
   ("Socio non identificato. Fornisci socio_id, tessera_id, numero_tessera o numero_socio.").
5. Verifica socio: deve esistere `WHERE id=? AND associazione_id=<assoc evento>`
   → altrimenti 404 `socio_not_found`. Deve avere `stato='Attivo'`
   → altrimenti 400 `socio_not_active` con messaggio che include lo stato attuale.
6. Lookup partecipazione esistente `WHERE evento_id=? AND socio_id=?`:
   - se esiste con stato **`Confermato`** → risposta 200 con
     `{success: true, already_checked_in: true, message: 'Socio già registrato...',
     socio: {nome, cognome, numero_socio}}` — **nessuna modifica al DB** (idempotente);
   - se esiste con altro stato (`Forse`/`Non Partecipa`) → UPDATE a
     `stato_partecipazione='Confermato', data_conferma=NOW()`;
   - se non esiste → INSERT nuovo record con UUID,
     `associazione_id` dell'evento, `stato_partecipazione='Confermato'`,
     `data_conferma=NOW()` (note NULL).
   Risposta: `{success: true, already_checked_in: false, message: 'Check-in effettuato
   con successo.', socio: {...}, evento: <titolo>}`.
7. Errori DB → 500 `db_error` (messaggio generico, dettaglio solo in log).

**Regola di business chiave**: il check-in È la transizione a `Confermato`.
Non esiste flag separato di presenza: un partecipante "Confermato" da gestionale
risulterà "già registrato" al check-in (vedi edge case).

### 5.4 Endpoint API correlati (usati dalla pagina)

- `GET /eventi` (permesso `eventi:read`): lista paginata (`page`, `limit` max 100,
  default 20), filtri opzionali `futuri=1` (`data_evento >= NOW()`) e `oggi=1`
  (`DATE(data_evento)=CURDATE()`), ordinati `data_evento DESC`. Campi:
  `id, titolo, descrizione, data_evento, luogo` (+ `associazione_nome, associazione_id`
  per chiavi globali). Risposta con `total, page, limit, eventi[]`.
  Nota: la pagina check-in carica `?limit=100` senza filtro data: mostra anche eventi passati.
- `GET /eventi/{id}` (permesso `eventi:read`): dettaglio evento + array `partecipanti`
  (stato, data_conferma, socio_id, nome, cognome, numero_socio, ordinati per cognome/nome)
  + `totale_partecipanti`.

---

## 6. Comportamenti per ruolo

Nel codice legacy le pagine admin **non differenziano** i ruoli: il guard è solo
"autenticato + associazione in sessione". L'isolamento è per tenant, non per ruolo.
Mappatura da adottare in Laravel (coerente col resto dell'app):

| Funzione | super_admin | admin_associazione | operatore |
|---|---|---|---|
| CRUD eventi (propria associazione) | ✅ (sull'associazione attiva in sessione/selezionata) | ✅ | ✅ (legacy: nessuna restrizione; se si vuole, limitare la cancellazione ad admin) |
| Gestione partecipanti | ✅ | ✅ | ✅ |
| API key scoped (check-in propria associazione) | ✅ | ✅ (creata per la propria associazione) | usa la chiave fornita dall'admin |
| API key **globale** (cross-tenant) | ✅ esclusiva super_admin | ❌ | ❌ |
| Check-in via pagina mobile | chiunque possieda una API key valida con `eventi:write` (la pagina non ha login utente) | ← | ← |

Punti fermi da preservare:
- Con chiave **scoped**, eventi e soci visibili/scrivibili solo del tenant della chiave.
- Con chiave **globale**, l'endpoint check-in opera sul tenant dell'evento indicato.
- I permessi API sono granulari: `eventi:read` per lettura, `eventi:write` per check-in.

---

## 7. Edge case e comportamenti impliciti

1. **Doppio check-in**: idempotente — secondo passaggio dello stesso QR restituisce
   `already_checked_in: true` senza toccare `data_conferma` (conserva l'orario del primo
   ingresso). Il client mostra overlay giallo e non incrementa il contatore.
2. **Socio "Confermato" da gestionale ma mai entrato**: al check-in fisico risulterà
   "già registrato" (lo stato non distingue RSVP da presenza effettiva). Limite noto
   del modello dati; replicare così, eventualmente segnalarlo come miglioramento futuro.
3. **Cooldown scanner 2s**: previene letture multiple ravvicinate dello stesso QR.
4. **QR non-URL**: qualsiasi testo non parsabile come URL è trattato come `numero_tessera`.
5. **Numero tessera duplicato su più anni**: vince `anno_validita` più alto.
6. **Tessera/numero inesistente**: fallthrough fino a `missing_socio` (400) —
   il messaggio non distingue "tessera sconosciuta" da "body vuoto".
7. **Socio non attivo** (Sospeso/Cessato/ecc.): check-in rifiutato con stato esplicitato
   nel messaggio.
8. **Statistiche pagina partecipanti**: calcolate in PHP sull'array **già filtrato** per
   `?status=` — quindi col filtro attivo le card mostrano numeri parziali (es. filtro
   "Confermato" → Forse=0, Non Partecipa=0, Totale=solo confermati). Comportamento legacy;
   in Laravel è preferibile calcolarle sempre sull'intero evento (decisione da prendere,
   documentare la scelta).
9. **`data_conferma` semanticamente sovraccarica**: NOW() su add singolo, su ogni update
   di stato e sul check-in; NULL su aggiunta massiva. È "ultima transizione", non "conferma".
10. **Eliminazione evento**: cascade DB sui partecipanti; nessun warning se ci sono
    partecipanti o tessere collegate. Le tessere restano (riferiscono l'evento via
    `evento_creazione_id`).
11. **Aggiunta massiva ripetuta**: sicura, aggiunge solo i mancanti e riporta il conteggio reale.
12. **Persistenza client check-in**: API key in `localStorage` (persistente),
    contatore/recenti in `sessionStorage` per-evento (volatile). Il contatore NON riflette
    il totale reale a DB se più dispositivi fanno check-in: per il totale reale servirebbe
    `GET /eventi/{id}` (miglioramento possibile: inizializzare il contatore dai Confermati).
13. **Evento di un altro tenant** in URL admin (`evento_id` forgiato): redirect silenzioso
    alla lista eventi.
14. **`?edit` con id altrui/inesistente**: nessun errore, form non precompilato.
15. **CSRF fallito**: messaggio di errore, operazione non eseguita, pagina comunque renderizzata.
16. **Messaggi**: il legacy non usa flash session (re-render nella stessa request) —
    i POST non fanno redirect (pattern non-PRG: il refresh ripropone il submit).
    In Laravel usare PRG + flash messages.

---

## 8. Note per l'implementazione Laravel

### Modelli e migration
- `Evento` (`eventi`), `EventoPartecipante` (`eventi_partecipanti`) con
  `HasUuids` (PK string/uuid, `$keyType='string'`, `$incrementing=false`).
- Relazioni: `Evento belongsTo Associazione`, `Evento hasMany EventoPartecipante`,
  `EventoPartecipante belongsTo Socio/Evento/Associazione`;
  `Evento hasMany Tessera (evento_creazione_id)` per il contatore "tessere create"
  (usare `withCount('tessereCreate')` al posto della subquery legacy).
- Vincolo unico composito `(evento_id, socio_id)` in migration; FK con `cascadeOnDelete`
  su associazione/evento/socio.
- `stato_partecipazione`: PHP backed enum `StatoPartecipazione: string`
  { Confermato, Forse, NonPartecipa = 'Non Partecipa' } + cast nel modello.
  Attenzione allo spazio in "Non Partecipa" (valore persistito e usato nei filtri URL).
- `eventi_partecipanti` non ha `updated_at`: o aggiungerlo in migration (consigliato)
  o `const UPDATED_AT = null`.

### Tenancy e autorizzazione
- Global scope / scope esplicito `forAssociazione($id)` su Evento ed EventoPartecipante;
  tutte le write devono includere il vincolo tenant (mai fidarsi degli id dal form).
- Policy: `EventoPolicy` (viewAny/create/update/delete) per i tre ruoli; nel legacy non
  c'è differenziazione, quindi default: tutti i ruoli autenticati del tenant possono tutto.
- Route admin: `eventi.index/store/update/destroy`,
  `eventi.partecipanti.index/store/storeAll/update/destroy` (nested resource).

### Validazione (FormRequest)
- `EventoRequest`: titolo required|string|max:255; data_evento required|date;
  luogo nullable|string|max:255; descrizione nullable|string.
- `PartecipanteRequest`: socio_id required|uuid + exists scoped al tenant + socio Attivo;
  stato_partecipazione required + `Rule::enum(StatoPartecipazione::class)`;
  note nullable|string.
- Upsert add-socio: `EventoPartecipante::upsert(...)` o `updateOrCreate(['evento_id','socio_id'], [...])`
  per replicare l'ON DUPLICATE KEY UPDATE.
- Aggiunta massiva: `insertOrIgnore` in batch con conteggio righe inserite.

### API check-in
- Route API: `GET /api/v1/eventi`, `GET /api/v1/eventi/{evento}`,
  `POST /api/v1/eventi/{evento}/checkin`.
- Auth: middleware API key Bearer (tabella api_keys esistente) con abilità
  `eventi:read` / `eventi:write`; supportare chiavi scoped e globali
  (per le globali derivare il tenant dall'evento, NON dalla chiave).
- Replicare esattamente: ordine di risoluzione socio (socio_id → tessera_id →
  numero_tessera con `ORDER BY anno_validita DESC` → numero_socio), check socio Attivo,
  idempotenza su Confermato, formato risposta JSON
  (`success`, `already_checked_in`, `message`, `socio{nome,cognome,numero_socio}`, `evento`)
  e i codici errore (`invalid_id`, `not_found`, `missing_socio`, `socio_not_found`,
  `socio_not_active`, `db_error`) — la pagina mobile li consuma.
- Avvolgere update/insert check-in in transazione; valutare lock ottimistico o
  `firstOrCreate` per gestire race tra due dispositivi sullo stesso socio
  (l'UNIQUE composito protegge comunque da duplicati).

### Pagina check-in
- Route pubblica (es. `/checkin`) senza middleware di sessione; vista Blade che riusa
  il JS legacy (è già self-contained e XSS-safe) o porting a componente.
- Mantenere: Permissions-Policy camera, CSP, persistenza localStorage/sessionStorage,
  cooldown 2s, parsing QR (URL con `?t=` → tessera_id, altrimenti numero_tessera),
  overlay esiti verde/giallo/rosso, lista ultimi 10.
- Servire `html5-qrcode` come asset locale (vendor) invece che da CDN se si vuole
  irrigidire la CSP.

### UI admin
- PRG + flash messages (il legacy non lo fa); conferme su delete/remove/add-all;
  filtro stato come query param con stessi valori; ricerca su titolo/descrizione/luogo;
  formati data italiani `d/m/Y H:i`; badge stato con gli stessi colori
  (success/warning/danger); modale edit auto-aperta quando si arriva con parametro edit
  (o pattern equivalente).
- Correggere il bug legacy delle statistiche filtrate (calcolarle sull'intero evento).

### Test consigliati
- Feature: CRUD eventi con isolamento tenant (404/redirect su id altrui);
  upsert add-socio; add-all idempotente con conteggio; filtro stato.
- API: matrice di risoluzione socio (4 input × trovato/non trovato), socio non attivo,
  doppio check-in idempotente, chiave globale su evento di tenant X,
  permessi mancanti (403), UUID malformato (400).
