# Specifica Funzionale — Modulo Soci

Fonte: app PHP legacy `gestione-associazioni` — file analizzati:
`pages/soci.php`, `pages/socio_dettaglio.php`, `pages/tipi-socio.php`, `pages/categorie-socio.php`,
`pages/config_campi.php`, `pages/config_tags.php`, `pages/config_gruppi.php`, `api/soci_search.php`.

Questa specifica è autosufficiente: permette di reimplementare il modulo in Laravel senza leggere il codice vecchio.

---

## 0. Contesto architetturale

- **Multi-tenant**: ogni record è isolato per `associazione_id` (CHAR(36) UUID). Ogni query di lettura/scrittura include sempre `WHERE associazione_id = ?` (sessione: `$_SESSION['associazione_id']`).
- **Chiavi primarie**: tutte UUID CHAR(36) generate applicativamente (`generateUuid()`), mai auto-increment.
- **Ruoli**: `super_admin`, `admin_associazione`, `operatore` (in `$_SESSION['user_role']`).
- **Pattern POST**: tutte le mutazioni avvengono via POST con token CSRF obbligatorio; dopo successo si fa redirect PRG con `message`/`messageType` in query string.
- **Sanitizzazione input**: `cleanInput()` (trim + strip control chars, preferito) o `sanitizeInput()` (legacy, HTML-encode). Output sempre con `htmlspecialchars()`.

---

## 1. Anagrafica Soci — Lista e CRUD (`pages/soci.php`)

### 1.1 Accesso
- Richiede utente loggato (qualsiasi ruolo, incluso `operatore`) **e** `associazione_id` in sessione; altrimenti redirect a login / dashboard.
- `super_admin`: stessa pagina, ma la lista mostra la colonna extra "Associazione" (nome associazione del socio). Il super_admin opera comunque sull'associazione correntemente selezionata in sessione — non vede i soci di tutte le associazioni contemporaneamente.

### 1.2 Tabella `soci` — colonne usate
| Colonna | Note |
|---|---|
| `id` | CHAR(36) UUID, PK |
| `associazione_id` | tenant |
| `nome`, `cognome` | obbligatori (required HTML) |
| `email` | obbligatoria (required HTML) |
| `numero_socio` | stringa; progressivo auto se vuoto (vedi 1.5) |
| `data_nascita` | DATE nullable (vuoto → NULL) |
| `data_iscrizione` | DATE; default odierna nel form |
| `stato` | enum applicativo, vedi §2 |
| `codice_fiscale` | normalizzato UPPERCASE; vuoto → NULL (per non violare unique); unicità per associazione |
| `telefono`, `indirizzo`, `citta`, `provincia` (max 2 char), `cap` (max 5 char), `note` | testo |
| `privacy_consenso` | TINYINT 0/1 (checkbox) |
| `tipo_socio_id` | FK nullable → `tipi_socio` |
| `categoria_socio_id` | FK nullable → `categorie_socio` |
| `sede_id` | FK nullable → `sedi` |

### 1.3 Creazione socio (POST senza `id`)
Flusso in **transazione DB**:
1. Validazione CSRF.
2. Validazione `stato` contro whitelist (vedi §2); stato non valido → rollback + errore.
3. **Validazione campi obbligatori configurabili** (contesto `backend`, vedi §8) — saltata se lo stato è `In Attesa` o `In Attesa di Pagamento`. Campi mancanti → rollback + messaggio "Campi obbligatori mancanti: <elenco label>".
4. **Validazione FK cross-tenant**: se valorizzati, `tipo_socio_id`, `categoria_socio_id`, `sede_id` devono esistere E appartenere all'associazione corrente (COUNT su tabella con `associazione_id`). Fallimento → rollback + errore specifico.
5. **Unicità codice fiscale** per associazione: `COUNT(*) FROM soci WHERE associazione_id=? AND UPPER(codice_fiscale)=?` (in update si esclude l'id corrente). Duplicato → rollback + "Esiste già un socio con lo stesso Codice Fiscale in questa associazione."
6. Generazione UUID per il socio; se `numero_socio` vuoto → assegnazione progressiva (§1.5).
7. INSERT in `soci`.
8. Log attività: tipo `Iscrizione`, descrizione "Nuovo socio iscritto con n. {numero_socio}" (§9).
9. **Creazione automatica tessera per l'anno corrente** se non già esistente (§1.6).
10. Salvataggio campi personalizzati (§5) e tag (§6).
11. Commit; **fuori transazione** invio (best-effort, errori solo loggati) **email di benvenuto** se: SMTP configurato + flag `auto_benvenuto` attivo + template `benvenuto` esistente e attivo. L'email è accodata in coda email (queueEmail) con placeholder valorizzati dal socio.
12. Redirect PRG con messaggio di successo.

Nota UI: nel form di creazione il campo numero socio è disabilitato con placeholder "Verrà assegnato automaticamente"; esiste anche un checkbox `genera_tessera` (checked di default) ma **nel codice il valore non viene mai letto**: la tessera+PDF viene generata sempre alla creazione (bug/incoerenza nota — in Laravel decidere se onorare il flag).

### 1.4 Modifica socio (POST con `id`)
- Stesse validazioni di cui sopra (stato, campi obbligatori, FK cross-tenant, CF unico escludendo se stesso).
- UPDATE su tutti i campi anagrafici con `WHERE id=? AND associazione_id=?`.
- Log attività: tipo `Modifica Anagrafica`, "L'anagrafica del socio è stata aggiornata." (Nota: il log viene scritto **prima** dell'UPDATE; viene registrato anche se i dati non cambiano).
- Campi personalizzati: upsert (`ON DUPLICATE KEY UPDATE valore`).
- Tag: delete-all + reinsert (vedi §6).
- In modifica il campo `numero_socio` è editabile e obbligatorio.
- Apertura form: `GET ?edit=<id>` carica il socio (scoped per associazione), i suoi valori campi personalizzati e gli id dei tag; il modal si apre automaticamente via JS.

### 1.5 Numero socio progressivo
- Se non fornito alla creazione: `SELECT MAX(CAST(numero_socio AS UNSIGNED)) FROM soci WHERE associazione_id = ?` → max+1, zero-padded a **3 cifre** (`str_pad(..., 3, '0', STR_PAD_LEFT)`), es. `001`, `002`, ... `1000` (oltre 999 il padding non aggiunge zeri).
- Il progressivo è **per associazione**.
- Nessun lock: in Laravel valutare lock pessimistico o sequence per evitare race condition su creazioni concorrenti.
- Numeri socio non numerici vengono trattati come 0 dal CAST (edge case: se tutti i numeri sono alfanumerici il prossimo sarà `001`).

### 1.6 Creazione automatica tessera (alla creazione socio e alla conferma pagamento)
Eseguita solo se non esiste già una tessera per (`associazione_id`, `socio_id`, `anno_validita` = anno corrente):
1. Legge `tipo_scadenza_default` da `associazioni` (`solare` o `annuale`; fallback `solare`).
2. **Numero tessera progressivo per associazione**: `MAX(CAST(numero_tessera AS UNSIGNED))+1`, zero-padded a **4 cifre** (es. `0001`).
3. `data_emissione` = oggi; `data_scadenza` = `data_emissione + 1 anno` se `annuale`, altrimenti `31/12` dell'anno corrente (`solare`).
4. INSERT in `tessere` (`id` UUID, `associazione_id`, `socio_id`, `numero_tessera`, `anno_validita`, `data_emissione`, `data_scadenza`, `stato='Attiva'`, `tipo_scadenza`).
5. Genera URL di verifica QR (`buildTesseraVerificationUrl(tessera_id)`) e lo salva in `tessere.qr_code_url`.
6. (Solo alla creazione socio) **Genera PDF tessera** via Dompdf (A4, dati: nome completo, numero socio, categoria/tipo, numero tessera, "valida fino al", logo associazione embedded base64 se locale, `testo_tessera` della categoria in un box); salva in `uploads/documents/tessera_{numero_socio}_{anno}.pdf`; registra il file nella tabella `documenti` (id, associazione_id, nome_file, percorso_file, descrizione "Tessera di Cognome Nome - anno", categoria `Tessere`, caricato_da = user_id). Errori PDF non bloccano la creazione del socio (try/catch + error_log).
7. Log attività `Generazione Tessera` con `dettagli_json` = {tessera_id, numero_tessera, documento_id, file}.

### 1.7 Eliminazione socio (POST `delete_id`)
- `DELETE FROM soci WHERE id=? AND associazione_id=?` — hard delete, con conferma JS.
- Le righe correlate (tags, valori campi, tessere, quote, storico) dipendono dai vincoli FK del DB (cascade); il codice non le elimina esplicitamente.
- Disponibile a tutti i ruoli che accedono alla pagina (anche `operatore`).

### 1.8 Lista soci — query principale
Query unica con JOIN:
- `soci s` + `associazioni a` (nome, per colonna super_admin)
- `categorie_socio cs` e `tipi_socio ts` con doppia condizione `id` **e** `associazione_id = s.associazione_id` (difesa cross-tenant); espone `ts.costo_tessera`
- Subquery aggregata tag: `GROUP_CONCAT(nome_tag,'|',colore SEPARATOR ';')` da `socio_tags`+`tags` → stringa parsata in UI (`Nome|#colore;Nome2|#colore2`)
- **Ultima tessera**: JOIN su `tessere tt` dove `tt.data_emissione = (SELECT MAX(data_emissione) ...)` per quel socio/associazione → espone `numero_tessera`, `data_scadenza`, `tipo_scadenza`, `template_tessera`.
- Ordinamento fisso: `ORDER BY s.cognome, s.nome ASC`. Nessuna paginazione (tutta la lista).

Colonne tabella UI: Socio (cognome nome + numero socio, link al dettaglio), [Associazione se super_admin], Contatti (email+telefono), Stato (badge colorato), Categoria/Tipo, Tessera (numero, template, data scadenza con badge "Scaduta"/"In scadenza"), Tags (badge colorati), Azioni. Vista doppia desktop (tabella) / mobile (card) con identico contenuto.

Badge scadenza tessera: "Scaduta" (rosso) se `data_scadenza < oggi`; "In scadenza" (giallo) se entro `giorni_notifica_scadenza` dell'associazione (default 30).

Badge stato socio: Attivo→success, In Attesa→warning, In Attesa di Pagamento→info, Sospeso→secondary, tutti gli altri (Radiato/Deceduto/Trasferito)→danger.

### 1.9 Filtri di ricerca
Filtri via GET (form `filtersForm`):

| Parametro | Valori | Comportamento SQL |
|---|---|---|
| `search` | testo libero | `LIKE %term%` su `nome`, `cognome`, `email`, `codice_fiscale`, `numero_socio` (OR) |
| `status` | `all` (default) o uno degli stati §2 | `s.stato = ?` |
| `sede_id` | `all` o UUID sede | `s.sede_id = ?` |
| `categoria_ids[]` | multi-select di UUID | `s.categoria_socio_id IN (...)` |
| `has_tessera` | `all`/`yes`/`no` | tessera (ultima) presente / assente |
| `tessera_stato` | **default `attive`** / `scadute` / `all` | attive: `tt.stato='Attiva' AND (data_scadenza IS NULL OR >= oggi)`; scadute: `tt.stato='Scaduta' OR data_scadenza < oggi` (in entrambi i casi richiede tessera presente) |
| `tessera_template` | `all` o un valore distinct di `tessere.template_tessera` dell'associazione | `tt.template_tessera = ?` |
| `tessera_scadenza` | `all`/`in_scadenza`/`scadute` | `in_scadenza`: `data_scadenza BETWEEN oggi AND oggi+giorni_notifica_scadenza`; `scadute`: `< oggi`; entrambi richiedono `data_scadenza NOT NULL` |
| `gruppo_id` | `all` o UUID gruppo dinamico | vedi sotto |

**Regole implicite importanti:**
- Il default `tessera_stato=attive` significa che, senza toccare i filtri, la lista mostra **solo soci con tessera attiva non scaduta**.
- Se `status` è `In Attesa` o `In Attesa di Pagamento`, il filtro stato-tessera viene **saltato** (i pending non hanno tessera e sparirebbero).
- Se `gruppo_id != all`, **tutti gli altri filtri sono ignorati** (la UI disabilita search e status): si applicano esclusivamente i filtri del gruppo dinamico — il JSON `filtri_json` del gruppo viene decodificato e ogni coppia chiave/valore non vuota genera `AND s.<chiave> = ?`, con whitelist colonne: `nome, cognome, email, stato, tipo_socio_id, categoria_socio_id, sede_id, citta, provincia`.

### 1.10 Filtraggio AJAX
- Param `ajax=1` sulla stessa pagina: risponde JSON `{count, html}` dove `html` è il rendering server-side della sola tabella/card (stessi template). Il JS:
  - submit del form intercettato; input `search` con debounce 300ms; ogni `<select>` triggera fetch al change;
  - aggiorna `#sociResults`, badge contatore, link export CSV, e la URL del browser (`history.replaceState`) senza il param ajax;
  - quando `gruppo_id != all` disabilita lato client search e status.

### 1.11 Export CSV
- Link `api/export.php?type=soci&...` costruito con gli stessi parametri di filtro correnti + `assoc_id` (l'endpoint non è in scope di questa spec, ma il contratto dei parametri è: `search, status, sede_id, categoria_ids[], tessera_template, tessera_scadenza, has_tessera, tessera_stato, assoc_id`).

### 1.12 Contatore e banner pre-iscrizioni
- Badge contatore soci risultanti dal filtro corrente ("N socio/soci").
- Banner warning se `COUNT(soci WHERE stato IN ('In Attesa','In Attesa di Pagamento'))` > 0, con link rapido `?status=In Attesa&tessera_stato=all`.

### 1.13 Link pubblico pre-iscrizione
- Modal che mostra l'URL `preiscrizione.php?assoc=<associazione_id>` (costruito da scheme+host correnti) con bottone copia negli appunti. Il form pubblico (fuori scope) crea soci con stato `In Attesa`.

---

## 2. Stati socio e transizioni

### 2.1 Stati validi (whitelist applicativa, enum)
`Attivo`, `Sospeso`, `Radiato`, `Deceduto`, `Trasferito`, `In Attesa`, `In Attesa di Pagamento`

### 2.2 Macchina a stati del flusso pre-iscrizione
```
(form pubblico) → In Attesa
In Attesa ──[Approva]──→ In Attesa di Pagamento
In Attesa ──[Rifiuta]──→ DELETE record
In Attesa di Pagamento ──[Conferma pagamento manuale]──→ Attivo (+ quota pagata + tessera + email benvenuto)
In Attesa di Pagamento ──[Conferma con metodo online]──→ resta In Attesa di Pagamento (+ quota da pagare + link pagamento)
In Attesa di Pagamento ──[Rifiuta]──→ DELETE record
```
Gli altri stati (`Sospeso`, `Radiato`, `Deceduto`, `Trasferito`) si impostano solo manualmente dal form di modifica, senza side effect.

### 2.3 Approvazione pre-iscrizione (POST `approve_id`)
- Verifica che il socio esista nell'associazione con stato esattamente `In Attesa`; altrimenti errore "Socio non trovato o stato non valido."
- `UPDATE soci SET stato='In Attesa di Pagamento'`.
- Log attività `Approvazione pre-iscrizione` ("Pre-iscrizione approvata, in attesa di pagamento.").

### 2.4 Conferma pagamento (POST `confirm_payment_id`)
Pre-condizione: socio in stato `In Attesa di Pagamento` (scoped per associazione), altrimenti errore.
Input dal modal: `confirm_payment_importo` (number ≥ 0, precompilato da `tipi_socio.costo_tessera` del socio oppure `associazioni.costo_tessera`), `confirm_payment_metodo` (`contanti|bonifico|carta|altro|stripe|paypal` — i metodi online compaiono solo se il gateway è abilitato e configurato in `payment_gateway_settings`), `confirm_payment_data` (default oggi), `confirm_payment_tipo` (default "Quota Associativa").

**Metodo online (stripe/paypal):**
1. Crea quota **non pagata** via `PaymentService::createQuotaForSocio(pdo, assoc, socio, importo, tipo, metodo, data_pagamento=null)`.
2. Genera token di pagamento (`generatePaymentToken(quotaId)`) e costruisce link `{baseUrl}/pagamento.php?token=...`.
3. Mostra il link nel messaggio di successo. Il socio **NON viene attivato** (lo farà il webhook/ritorno pagamento, fuori scope).

**Metodo manuale:**
1. Se importo > 0: crea quota **pagata** con data pagamento indicata.
2. `UPDATE soci SET stato='Attivo', data_iscrizione=CURDATE()` (la data iscrizione viene **sovrascritta** con oggi).
3. Log `Pagamento confermato` ("Pagamento confermato (€X, metodo) e socio attivato.").
4. Crea tessera anno corrente se assente (stessa logica §1.6, senza PDF ma con QR), log `Generazione Tessera`.
5. Dopo commit: email di benvenuto best-effort (stesse condizioni di §1.3 punto 11).

### 2.5 Rifiuto pre-iscrizione (POST `reject_id`)
- `DELETE FROM soci WHERE id=? AND associazione_id=? AND stato IN ('In Attesa','In Attesa di Pagamento')` — il rifiuto è una **cancellazione definitiva**, ammessa solo nei due stati pending. rowCount=0 → errore.

### 2.6 Azioni contestuali in lista
- Stato `In Attesa`: bottoni Approva / Rifiuta (niente modifica/elimina standard).
- Stato `In Attesa di Pagamento`: bottoni Conferma Pagamento (apre modal) / Rifiuta.
- Altri stati: Modifica / Genera Tessera PDF (link a pagina `genera-tessera-pdf&socio_id=`) / Elimina.
- Le stesse azioni rapide compaiono dentro il modal di modifica se il socio in editing è in stato pending.

---

## 3. Tipi di Socio (`pages/tipi-socio.php`)

### 3.1 Accesso
Solo `admin_associazione` e `super_admin` (`isUserLoggedIn(['admin_associazione','super_admin'])`). **L'operatore non accede.**

### 3.2 Tabella `tipi_socio`
| Colonna | Note |
|---|---|
| `id` | UUID |
| `associazione_id` | tenant |
| `nome` | obbligatorio |
| `descrizione` | testo |
| `costo_tessera` | DECIMAL nullable — **override per tipo** del costo tessera; se NULL si usa `associazioni.costo_tessera` |

All'avvio pagina viene chiamato `ensureTesseraCostColumns($pdo)` (migrazione lazy che garantisce l'esistenza della colonna `costo_tessera` — in Laravel: normale migration).

### 3.3 CRUD
- Create/Update: `nome`, `descrizione` (cleanInput), `costo_tessera` normalizzato a 2 decimali con punto (`number_format(...,2,'.','')`), stringa vuota → NULL.
- Delete: hard delete scoped per associazione, conferma JS. Nessun controllo se il tipo è in uso da soci (la FK `soci.tipo_socio_id` decide: in Laravel impostare `nullOnDelete` o bloccare).
- PRG fatto via JS (`window.location.href`) perché l'output è già iniziato — in Laravel usare redirect standard.
- Legacy: la descrizione viene decodificata 3 volte con `html_entity_decode` prima del re-encode in output (fix per dati storici doppio/triplo-encoded; in Laravel basta sanificare in ingresso una volta).

### 3.4 Uso del costo tessera
- Precompila l'importo nel modal "Conferma Pagamento" (`ts.costo_tessera ?? associazioni.costo_tessera`).
- In `socio_dettaglio` il costo mostrato per ogni tessera è `tipi_socio.costo_tessera` del socio, fallback `associazioni.costo_tessera`.

---

## 4. Categorie di Socio (`pages/categorie-socio.php`)

### 4.1 Accesso
Solo `admin_associazione` e `super_admin`.

### 4.2 Tabella `categorie_socio`
| Colonna | Note |
|---|---|
| `id` | UUID |
| `associazione_id` | tenant |
| `nome` | obbligatorio |
| `descrizione` | testo |
| `testo_tessera` | testo libero stampato sul PDF tessera (box dedicato, con nl2br) |

### 4.3 CRUD
- Create/Update/Delete standard scoped per associazione; redirect PRG via JS, preservando `assoc_id` in query per super_admin.
- Nessuna validazione di unicità nome; nessun blocco eliminazione se la categoria è usata da soci.
- Stesso workaround triple-decode legacy sulla descrizione/testo tessera.

---

## 5. Campi Personalizzati (`pages/config_campi.php` + uso in soci.php)

### 5.1 Accesso
Configurazione: solo `admin_associazione` e `super_admin`. La compilazione dei valori avviene nel form socio (accessibile anche a `operatore`).

### 5.2 Tabelle
**`campi_personalizzati`** (definizione, per associazione):
| Colonna | Note |
|---|---|
| `id` | UUID |
| `associazione_id` | tenant |
| `nome_campo` | label |
| `tipo_campo` | uno di: `text`, `email`, `tel`, `number`, `date`, `url`, `textarea`, `select`, `checkbox` |
| `descrizione` | help text |
| `opzioni` | per `select`: opzioni separate da newline (una per riga, trimmed) |
| `obbligatorio` | 0/1 — required HTML lato client nel form socio (NON validato server-side!) |

**`valori_campi_personalizzati`** (valori per socio):
| Colonna | Note |
|---|---|
| `id` | UUID |
| `socio_id` | FK |
| `campo_id` | FK → campi_personalizzati |
| `valore` | testo (checkbox salvata come "1") |
| — | unique implicita su (socio_id, campo_id): l'INSERT usa `ON DUPLICATE KEY UPDATE valore` |

### 5.3 Comportamenti
- **Preset**: modal con 6 campi predefiniti attivabili a checkbox: Professione (text), Titolo di Studio (select con 6 opzioni: Licenza Media…Dottorato), Data Diploma/Laurea (date), Contatto di Emergenza (text), Specializzazione (text), Sito Web (url). Inserimento batch, tutti con `obbligatorio=0`; duplicati ignorati silenziosamente (catch su PDOException); messaggio "N campi aggiunti".
- Create/Update manuale: tutti i campi via `sanitizeInput`; l'area "Opzioni" è visibile solo per tipo `select` (toggle JS).
- Delete: hard delete con conferma "Verranno persi tutti i dati associati" (i valori orfani dipendono dalla FK cascade).
- **Rendering nel form socio** (sezione "Dati Personalizzati"): textarea → `<textarea>`; select → `<select>` con opzioni da `opzioni` (split su newline); checkbox → checkbox value=1; altri tipi → `<input type="{tipo_campo}">`. Asterisco e attributo required se `obbligatorio`.
- **Salvataggio nel form socio**: per ogni campo definito, se `custom_field[<campo_id>]` è presente nel POST → upsert del valore (cleanInput). Nota edge case: una **checkbox deselezionata non invia il campo** e quindi il valore precedente NON viene azzerato (bug legacy: in Laravel gestire l'assenza come falso/null).
- Visualizzazione nel dettaglio socio: JOIN valori+definizioni, mostra `nome_campo: valore`.

---

## 6. Tag (`pages/config_tags.php` + uso in soci.php)

### 6.1 Accesso
Configurazione: solo `admin_associazione` e `super_admin`. Assegnazione ai soci: chiunque acceda al form socio.

### 6.2 Tabelle
**`tags`**: `id` UUID, `associazione_id`, `nome_tag`, `colore` (hex, default `#888888`, input type=color).
**`socio_tags`** (pivot): `socio_id`, `tag_id` — nessun id proprio, nessun campo extra.

### 6.3 Comportamenti
- CRUD tag standard scoped per associazione; delete hard con conferma.
- Nel form socio: lista checkbox di tutti i tag dell'associazione (badge colorato come label).
- Salvataggio: **delete totale** delle righe `socio_tags` del socio + reinsert dei selezionati (sync completo). Attenzione: il tag_id inviato NON viene validato come appartenente all'associazione (in Laravel: validare).
- Visualizzazione: badge colorati in lista soci (stringa aggregata `nome|colore;...`), in testata dettaglio socio.

---

## 7. Gruppi Dinamici (`pages/config_gruppi.php` + applicazione in soci.php)

### 7.1 Accesso
Configurazione: solo `admin_associazione` e `super_admin`. Applicazione come filtro in lista soci: tutti.

### 7.2 Tabella `gruppi_dinamici`
| Colonna | Note |
|---|---|
| `id` | UUID |
| `associazione_id` | tenant |
| `nome_gruppo` | obbligatorio |
| `descrizione` | testo |
| `filtri_json` | JSON oggetto `{colonna: valore}` |

### 7.3 Comportamenti
- Un gruppo dinamico è un **filtro salvato sul DB lato definizione**: non materializza membership; la lista soci lo applica live.
- Il form di configurazione attuale permette solo 2 filtri: `stato` (select: Qualsiasi/Attivo/Sospeso) e `citta` (testo). Solo chiavi non vuote finiscono nel JSON.
- L'applicazione in lista soci usa una **whitelist più ampia** (`nome, cognome, email, stato, tipo_socio_id, categoria_socio_id, sede_id, citta, provincia`), tutti con match di uguaglianza esatta (`= ?`) — quindi il modello dati supporta più chiavi di quante la UI permetta di impostare.
- Quando un gruppo è selezionato, gli altri filtri della lista vengono ignorati (vedi §1.9).
- La tabella di config mostra il JSON raw dei filtri.

---

## 8. Campi obbligatori configurabili (helper `loadRequiredFieldsConfig` / `isFieldRequired`)

- Config per associazione in `associazioni.campi_obbligatori_config` (JSON), con due contesti: `backend` (form admin) e `preiscrizione` (form pubblico).
- Campi configurabili contesto `backend`: `telefono, codice_fiscale, data_nascita, indirizzo, citta, provincia, cap, tipo_socio_id, categoria_socio_id, sede_id, privacy_consenso, note`.
- Default: tutto `false` tranne `data_nascita=true` (e `privacy_consenso=true` nel solo contesto preiscrizione).
- Il JSON salvato fa merge sui default solo per chiavi note (chiavi sconosciute ignorate).
- `isFieldRequired(config, contesto, campo)` → bool.
- Enforcement: lato client (attributo required + asterisco) E lato server in soci.php (lista label per messaggio di errore: "Data di Nascita", "Telefono", "Codice Fiscale", "Indirizzo", "Città", "Provincia", "CAP", "Tipo Socio", "Categoria Socio", "Sede", "Consenso Privacy", "Note").
- `nome`, `cognome`, `email`, `data_iscrizione` sono sempre obbligatori (required hard-coded). `numero_socio` obbligatorio solo in modifica.

---

## 9. Storico attività socio (`logSocioActivity` + tab Storico in socio_dettaglio)

### 9.1 Tabella `storico_attivita_socio`
| Colonna | Note |
|---|---|
| `id` | UUID |
| `associazione_id` | tenant |
| `socio_id` | FK |
| `utente_id` | FK nullable → `utenti` (user di sessione; NULL per azioni anonime/di sistema) |
| `tipo_attivita` | stringa libera |
| `descrizione` | testo |
| `dettagli_json` | JSON nullable |
| `data_attivita` | timestamp (default DB) |

### 9.2 Eventi loggati dal modulo soci
| tipo_attivita | quando |
|---|---|
| `Iscrizione` | creazione socio ("Nuovo socio iscritto con n. X") |
| `Modifica Anagrafica` | update socio |
| `Approvazione pre-iscrizione` | In Attesa → In Attesa di Pagamento |
| `Pagamento confermato` | conferma pagamento manuale (importo+metodo nel testo) |
| `Generazione Tessera` | creazione tessera (con dettagli_json in alcuni percorsi) |
| `Caricamento Documento` | upload documento dal dettaglio socio |

- La funzione di log è **fail-safe**: PDOException catturata e loggata, non interrompe mai il flusso.
- Visualizzazione: tab "Storico" del dettaglio socio, lista ordinata per `data_attivita DESC`, con tipo, data/ora (d/m/Y H:i), descrizione e "Eseguito da: <email utente>" (JOIN `utenti` su `utente_id`).

---

## 10. Dettaglio socio (`pages/socio_dettaglio.php`)

### 10.1 Accesso
Qualsiasi ruolo loggato con associazione in sessione. `GET ?id=<socio_id>`; senza id → redirect a lista; socio non trovato nella propria associazione → redirect a 404.

### 10.2 Struttura — 4 tab
**Testata**: nome+cognome, numero socio, badge dei tag.

**Tab Anagrafica**: email, telefono (fallback "N/D"), data nascita e data iscrizione (d/m/Y o N/D), stato (badge — sempre verde, indipendente dallo stato: incoerenza nota), e tutti i campi personalizzati valorizzati (nome_campo: valore).

**Tab Quote e Tessere**:
- Quote: `SELECT * FROM quote WHERE socio_id=? ORDER BY anno DESC` — colonne anno, importo (€), stato, data pagamento.
- Tessere: `SELECT * FROM tessere WHERE socio_id=? ORDER BY anno_validita DESC` — numero, anno, scadenza, stato, **costo** (per tutte le tessere lo stesso valore: `tipi_socio.costo_tessera` del socio, fallback `associazioni.costo_tessera`, formattato it `€ 25,00`), **QR code** (thumbnail 36px generata come data-URI dal link di verifica tessera; click apre overlay modale con QR 200px e numero tessera).
- ATTENZIONE: queste due query NON sono scoped per associazione (solo per socio_id; il socio però è già stato verificato come appartenente all'associazione — in Laravel scoping comunque consigliato).

**Tab Documenti**:
- Form upload (POST `action=upload_documento`, multipart): file (required), descrizione (sanitizeInput), data scadenza opzionale.
- Validazione file via `validateFileUpload()` (whitelist tipi/dimensione, helper condiviso); nome file rigenerato sicuro con prefisso `doc_` (`generateSecureFileName`); destinazione `UPLOADS_PATH/documents/{associazione_id}/{socio_id}/`.
- INSERT in **`documenti_socio`**: `id` UUID, `socio_id`, `associazione_id`, `nome_file` (nome sicuro), `percorso_file` (path assoluto), `descrizione`, `data_scadenza`, `caricato_da` (user id). Log attività `Caricamento Documento`.
- Lista documenti: `ORDER BY created_at DESC`, colonne file, descrizione, scadenza, caricato il. **Download e cancellazione non implementati** (link `href="#"` placeholder) — in Laravel implementarli (download protetto con check tenant, delete con rimozione file).

### 10.3 Tabelle aggiuntive coinvolte
- `quote`: almeno `socio_id, anno, importo, stato, data_pagamento` (creazione gestita da PaymentService, fuori scope di questi file).
- `tessere`: `id, associazione_id, socio_id, numero_tessera, anno_validita, data_emissione, data_scadenza, stato (Attiva/Scaduta), tipo_scadenza (solare/annuale), template_tessera, qr_code_url`.
- `documenti_socio` (per-socio) distinta da `documenti` (repository generale dell'associazione, usata per i PDF tessera).
- `sedi`: `id, associazione_id, nome` (lookup per filtro e form).
- `associazioni`: usate le colonne `nome, logo_url, tipo_scadenza_default, giorni_notifica_scadenza, costo_tessera, campi_obbligatori_config`.

---

## 11. Filtri salvati / Preset (`saved_filters`)

### 11.1 Tabella `saved_filters`
| Colonna | Note |
|---|---|
| `id` | UUID |
| `associazione_id` | tenant |
| `user_id` | nullable — NULL = preset condiviso, valorizzato = preset personale |
| `scope` | qui sempre `'soci'` (la tabella è generica per altri moduli) |
| `name` | nome preset |
| `params_json` | JSON dei parametri filtro |
| `updated_at` | aggiornato su upsert |

### 11.2 Comportamenti
- **Salvataggio** (POST `save_filter_preset` + `preset_name` non vuoto): serializza i filtri correnti (`search, status, sede_id, categoria_ids[], tessera_template, tessera_scadenza, has_tessera, tessera_stato, gruppo_id`) in JSON; INSERT con `ON DUPLICATE KEY UPDATE params_json` (implica unique key tipo (associazione_id, user_id, scope, name): salvare con lo stesso nome aggiorna il preset). `user_id` = utente corrente.
- **Caricamento** (GET `preset_id`): legge `params_json` (scoped per associazione — nota: non verifica user_id in lettura) e sovrascrive i filtri di default; i valori mancanti nel JSON mantengono il default.
- **Eliminazione** (POST `delete_filter_preset` + `preset_id_delete`): `DELETE ... WHERE id=? AND associazione_id=? AND (user_id IS NULL OR user_id=?)` — si possono eliminare i propri preset e quelli condivisi.
- **Lista nel dropdown**: preset dell'associazione con `user_id IS NULL OR user_id = <utente corrente>`, ordinati per nome.
- Tutta la gestione è racchiusa in try/catch tollerante (tabella eventualmente assente → feature silenziosamente disattivata).

---

## 12. Ricerca autocomplete (`api/soci_search.php`)

- Endpoint JSON standalone (carica `config.php` direttamente, fuori dal router `index.php`).
- Auth: utente loggato + associazione in sessione, altrimenti **403** `{"error":"Accesso negato"}`. Nessuna restrizione di ruolo.
- Input: `GET q` (trim). `q` vuota → `[]` (200).
- Query: soci dell'associazione **con stato `Attivo` soltanto**, match `LIKE %q%` su `nome`, `cognome`, `CONCAT(cognome,' ',nome)`, `CONCAT(nome,' ',cognome)` (quindi funziona sia "Rossi Mario" sia "Mario Rossi").
- Output: array di `{id, nome_completo}` ("Cognome Nome"), `ORDER BY cognome, nome`, **LIMIT 20**.
- Errore DB → 500 `{"error":"Errore interno"}` (dettagli solo in error_log).

---

## 13. Comportamenti per ruolo — riepilogo

| Funzionalità | super_admin | admin_associazione | operatore |
|---|---|---|---|
| Lista/filtri/preset soci | ✔ (+colonna Associazione) | ✔ | ✔ |
| Crea/modifica/elimina socio | ✔ | ✔ | ✔ |
| Approva/rifiuta/conferma pagamento | ✔ | ✔ | ✔ |
| Dettaglio socio + upload documenti | ✔ | ✔ | ✔ |
| Autocomplete `soci_search` | ✔ | ✔ | ✔ |
| Tipi socio (config) | ✔ | ✔ | ✘ |
| Categorie socio (config) | ✔ | ✔ | ✘ |
| Campi personalizzati (config) | ✔ | ✔ | ✘ |
| Tag (config) | ✔ | ✔ | ✘ |
| Gruppi dinamici (config) | ✔ | ✔ | ✘ |

Il super_admin non ha query speciali in questi file: lavora sempre sull'associazione attiva in sessione (la selezione dell'associazione avviene altrove). Unica differenza UI: colonna "Associazione" in lista e suffisso "(nome associazione)" nelle option dei lookup quando presente `associazione_nome` (residuo di una vecchia vista cross-tenant; con le query attuali per-tenant non si attiva mai).

---

## 14. Edge case e regole implicite (checklist)

1. **CF vuoto → NULL** (mai stringa vuota) per non violare l'unique; CF sempre UPPERCASE; unicità case-insensitive per associazione.
2. **Validazioni saltate per stati pending**: i campi obbligatori configurabili non si applicano a soci `In Attesa` / `In Attesa di Pagamento` (provenienti da pre-iscrizione incompleta).
3. **Default lista = solo tessere attive**: senza filtri espliciti, soci senza tessera o con tessera scaduta non compaiono (motivo del link banner con `tessera_stato=all`).
4. **Filtro stato-tessera saltato per stati pending** nel filtro `status`.
5. **Gruppo dinamico esclusivo**: annulla tutti gli altri filtri.
6. **Conferma pagamento sovrascrive `data_iscrizione` con la data odierna.**
7. **Importo 0 ammesso** nella conferma manuale: attiva il socio e genera tessera senza creare quota.
8. **Numero socio**: padding 3 cifre; numero tessera: padding 4 cifre; entrambi progressivi per associazione via MAX(CAST AS UNSIGNED) — race condition possibile, nessun lock.
9. **Tessera non duplicata**: la creazione automatica controlla l'esistenza per (socio, anno) prima di inserire.
10. **PDF tessera best-effort**: errori Dompdf non bloccano la creazione socio.
11. **Email benvenuto best-effort e condizionale** (SMTP configurato + auto_benvenuto + template attivo); inviata sia alla creazione manuale sia alla conferma pagamento; sempre fuori transazione.
12. **Checkbox campi personalizzati**: deselezionarla non cancella il valore salvato (assenza dal POST = nessun upsert). Da correggere in Laravel.
13. **Tag**: sync con delete-all+insert; manca la validazione tenant sui tag_id ricevuti. Da aggiungere in Laravel.
14. **Rifiuto = hard delete**, nessun soft delete / archivio dei rifiutati.
15. **Eliminazione socio/tipo/categoria/campo/tag/gruppo**: nessun controllo d'uso, ci si affida ai vincoli FK.
16. **log "Modifica Anagrafica" scritto prima dell'UPDATE** e senza diff dei campi.
17. **Preset omonimi = upsert** (richiede unique key su nome+scope+utente+associazione).
18. **Doppio/triplo encode legacy** su descrizioni di tipi/categorie: solo bonifica dati, non replicare.
19. **`genera_tessera` checkbox ignorata**: la tessera viene sempre creata. Decidere il comportamento desiderato.
20. **Tab quote/tessere del dettaglio non scoped per associazione** (mitigato dal check preliminare sul socio).
21. **Stato badge nel dettaglio sempre verde** (bug cosmetico).
22. **Documenti socio: download/delete non implementati** nel legacy.
23. Ricerca testo copre anche `numero_socio` (la label UI cita solo nome/email/CF).
24. L'endpoint autocomplete filtra **solo soci Attivi**.

---

## 15. Note per l'implementazione Laravel

### Modelli e relazioni suggerite
- `Socio` (belongsTo `Associazione`, `TipoSocio`, `CategoriaSocio`, `Sede`; hasMany `Tessera`, `Quota`, `DocumentoSocio`, `AttivitaSocio`, `ValoreCampoPersonalizzato`; belongsToMany `Tag` via `socio_tags`).
- `TipoSocio`, `CategoriaSocio`, `Tag`, `CampoPersonalizzato`, `GruppoDinamico`, `SavedFilter` — tutti con scope tenant.
- UUID: `HasUuids` su tutti i modelli (PK string CHAR(36)).
- **Multi-tenancy**: global scope `BelongsToAssociazione` (o package tipo stancl/tenancy) che applica `associazione_id` dalla sessione/contesto su tutte le query; mai fidarsi degli id in input senza scope.

### Stati e transizioni
- Implementare `stato` come enum PHP backed (`SocioStato`) con i 7 valori; transizioni del flusso pre-iscrizione in azioni dedicate (`ApprovaPreiscrizione`, `ConfermaPagamento`, `RifiutaPreiscrizione`) o state machine (es. spatie/laravel-model-states). Le azioni devono verificare lo stato di partenza atomico (`where stato=...` nell'update o lockForUpdate).

### Eventi/observer
- Eventi domain: `SocioCreato`, `SocioAttivato`, `TesseraGenerata` → listener per: generazione tessera, PDF (queued job, Dompdf/laravel-dompdf), email benvenuto (Mailable in coda, condizioni SMTP/auto_benvenuto/template), log storico.
- Lo storico attività come trait/servizio `LogsSocioActivity` con scrittura fail-safe (try/catch, report()).

### Progressivi
- Numero socio (3 cifre) e numero tessera (4 cifre): generare dentro `DB::transaction` con `lockForUpdate` su una riga contatore per associazione (tabella `counters`) invece di MAX(CAST) — elimina le race condition e i problemi con valori alfanumerici.

### Validazione
- FormRequest per il socio con: whitelist stato, regole condizionali dai campi obbligatori configurabili (config in `associazioni.campi_obbligatori_config` → servizio `RequiredFieldsConfig`), skip per stati pending, `Rule::exists(...)->where('associazione_id', $assocId)` per le FK, `Rule::unique('soci')->where(associazione)->ignore($id)` su CF (normalizzato uppercase in `prepareForValidation`, vuoto→null).
- Validare i campi personalizzati server-side in base a `tipo_campo` e `obbligatorio` (il legacy non lo fa); checkbox assente → cancellare/azzerare il valore.
- Validare appartenenza tenant dei `tag_ids`; usare `sync()` per la pivot.

### Lista/filtri
- Query builder dedicato (`SociFilter` / spatie query-builder) che replica esattamente: ricerca multi-colonna LIKE, default `tessera_stato=attive`, skip filtro tessera per stati pending, esclusività del gruppo dinamico con whitelist colonne, join "ultima tessera" (consigliato: subquery `whereIn` su max data_emissione o relazione `latestOfMany('data_emissione')`).
- AJAX: sostituire con endpoint JSON + partial Blade (o Livewire/Inertia); mantenere debounce e aggiornamento URL.
- Aggiungere paginazione (il legacy carica tutto).
- Export CSV con gli stessi filtri (streamed response).

### Filtri salvati
- Unique composito su (`associazione_id`,`user_id`,`scope`,`name`) + `updateOrCreate`. Casting `params_json` ad array. Policy: delete consentito su propri preset e su quelli condivisi (user_id null).

### Autorizzazione
- Policy/middleware: pagine di configurazione (tipi, categorie, campi, tag, gruppi) riservate a `admin_associazione`+`super_admin`; gestione soci aperta anche a `operatore`. Valutare se restringere l'eliminazione socio ai soli admin (il legacy la concede a tutti).

### Documenti
- Upload con validazione `mimes`/`max`, storage su disco `local` privato in `documents/{associazione_id}/{socio_id}/`, nome random; salvare path relativo (il legacy salva path assoluto: non replicare). Implementare download (route firmata o controller con policy) e delete mancanti nel legacy.

### Tessere/QR/PDF
- `qr_code_url` = URL pubblico di verifica tessera; QR generati on-the-fly come data-URI nel dettaglio (lib es. bacon/qr-code).
- PDF tessera come job in coda con dompdf; registrare il PDF in repository documenti dell'associazione (categoria "Tessere"); usare `testo_tessera` della categoria e `costo_tessera` con fallback tipo→associazione.

### Migrazioni / integrità
- FK con `cascadeOnDelete` per `socio_tags`, `valori_campi_personalizzati`, `documenti_socio`, `storico_attivita_socio`, `tessere`, `quote` rispetto a `soci`; `nullOnDelete` per `soci.tipo_socio_id/categoria_socio_id/sede_id`.
- Unique: `soci(associazione_id, codice_fiscale)` (CF nullable), `valori_campi_personalizzati(socio_id, campo_id)`, `saved_filters(associazione_id, user_id, scope, name)`; valutare unique su `soci(associazione_id, numero_socio)` e `tessere(associazione_id, numero_tessera)` (il legacy non le impone ma la logica le presuppone).
- Bonificare in migrazione i dati legacy con descrizioni multi-encoded (decode iterativo una tantum).

### API
- Endpoint autocomplete: route API autenticata, `q` required|min:1, scope tenant, solo stato Attivo, limit 20, risposta `[{id, nome_completo}]`.
