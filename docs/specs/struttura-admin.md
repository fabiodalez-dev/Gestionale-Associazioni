# Specifica Funzionale — Modulo "Struttura Admin"

Gestione associazioni (tenant), sedi, utenti amministratori, configurazioni tenant, dashboard, pre-iscrizione pubblica e privacy policy pubblica.

Fonte: app PHP legacy — `pages/associazioni.php`, `pages/sezioni.php`, `pages/amministratori.php`, `pages/configurazioni.php`, `pages/dashboard.php`, `preiscrizione.php`, `privacy-policy.php`.

---

## 0. Contesto generale e modello multi-tenant

- L'applicazione è un SaaS multi-tenant: il tenant è l'**associazione** (`associazioni`). Quasi tutte le tabelle di dominio hanno una colonna `associazione_id` (CHAR(36) UUID) che realizza l'isolamento dei dati.
- Tutte le primary key di dominio sono **UUID v4 in formato CHAR(36)** generati applicativamente (`generateUuid()`), **eccezione**: la tabella `users` legacy usa `id INT AUTO_INCREMENT`.
- Sessione utente admin: `$_SESSION['user_role']`, `$_SESSION['associazione_id']`, `$_SESSION['associazione_nome']`.
- Ruoli esistenti nel codice legacy: **`super_admin`** e **`admin_associazione`** (ENUM su `users.role`). Il ruolo "operatore" NON esiste nel codice legacy: se richiesto in Laravel, va introdotto ex novo (vedi §9).
- Convenzioni di sicurezza legacy da replicare in Laravel con i meccanismi nativi:
  - CSRF token su ogni POST (`validateCSRFToken`).
  - Input ripulito con `cleanInput()` (trim + rimozione caratteri di controllo); output sempre con `htmlspecialchars()`.
  - Gli errori PDO non vengono mai mostrati all'utente: log + messaggio generico.

### 0.1 Tabelle DB coinvolte nel modulo (colonne effettivamente usate dal codice)

**`associazioni`** (tenant)
- `id` CHAR(36) PK
- `nome` (obbligatorio), `email` (obbligatorio, UNIQUE — vincolo dedotto dalla gestione "Duplicate entry"), `partita_iva`, `codice_fiscale`, `indirizzo`, `citta`, `provincia` (2 char), `cap` (5 char), `telefono`
- `tipo_scadenza_default` ENUM-like: `'solare'` | `'annuale'` (default `'solare'`)
- `giorni_notifica_scadenza` INT (default 30)
- `costo_tessera` DECIMAL nullable
- `attiva` TINYINT(1) (default 1)
- `logo_url` VARCHAR — path relativo `uploads/logos/<file>`
- `template_email_scadenza` TEXT
- `privacy_policy_html` LONGTEXT NULL (aggiunta da auto-migrazione runtime)
- `campi_obbligatori_config` JSON NULL (aggiunta da auto-migrazione runtime)

**`users`** (utenti admin del backend — NB nel progetto esiste anche la tabella `utenti` in altre parti; questo modulo usa `users`)
- `id` INT AUTO_INCREMENT PK
- `username` VARCHAR(50) UNIQUE NOT NULL
- `email` VARCHAR(255) UNIQUE NOT NULL
- `password_hash` VARCHAR(255) (bcrypt via `password_hash(..., PASSWORD_DEFAULT)`)
- `role` ENUM('admin_associazione','super_admin') DEFAULT 'admin_associazione'
- `associazione_id` CHAR(36) NULL, FK → `associazioni(id)` ON DELETE SET NULL
- `created_at`, `updated_at`, `last_login` TIMESTAMP

**`sedi`**
- `id` CHAR(36) PK, `associazione_id` FK
- `nome`, `indirizzo`, `citta`, `provincia`, `cap`, `email`, `telefono`, `responsabile`

**`soci`** (usata da dashboard e preiscrizione)
- `id` CHAR(36), `associazione_id`, `numero_socio`, `nome`, `cognome`, `email`, `telefono`, `data_nascita`, `codice_fiscale`, `indirizzo`, `citta`, `provincia`, `cap`, `data_iscrizione`, `stato` ENUM('Attivo','Sospeso','Radiato','Deceduto','Trasferito','In Attesa','In Attesa di Pagamento'), `privacy_consenso` TINYINT, `tipo_socio_id`, `categoria_socio_id`
- `rinnovo_token` CHAR(64) NULL + indice, `rinnovo_token_expires` DATETIME NULL (auto-migrate runtime)

**`tipi_socio`**: `id`, `associazione_id`, `nome`, `descrizione`, `costo_tessera`
**`categorie_socio`**: `id`, `associazione_id`, `nome`, `descrizione`
**`campi_personalizzati`**: `id`, `associazione_id`, `nome_campo`, `tipo_campo` (text/textarea/select/checkbox/date/number/...), `descrizione`, `opzioni` (testo, una opzione per riga), `obbligatorio` BOOLEAN, `obbligatorio_preiscrizione` BOOLEAN (auto-migrate), `ordine`
**`valori_campi_personalizzati`**: `id`, `socio_id`, `campo_id`, `valore`
**`tags`**: `id`, `associazione_id`, `nome_tag`, `colore`
**`gruppi_dinamici`**: `id`, `associazione_id`, `nome_gruppo`, `descrizione`, `filtri_json`
**`tessera_templates`** (può non esistere pre-migrazione → il codice controlla `tableExists`): `id`, `associazione_id`, `tipo_socio_id` NULL (NULL = template default per tutti i tipi), `titolo`, `contenuto`, `attivo`, `updated_at`
**`payment_gateway_settings`** (creata runtime se assente): `id` CHAR(36) PK, `associazione_id` UNIQUE FK CASCADE, `stripe_enabled` BOOL, `stripe_publishable_key` VARCHAR(255), `stripe_secret_key_encrypted` TEXT, `stripe_webhook_secret_encrypted` TEXT, `paypal_enabled` BOOL, `paypal_client_id` VARCHAR(255), `paypal_client_secret_encrypted` TEXT, `paypal_mode` ENUM('sandbox','live') DEFAULT 'sandbox', `paypal_webhook_id` VARCHAR(255), `auto_attivazione_pagamento` BOOL DEFAULT TRUE, `valuta` VARCHAR(3) DEFAULT 'EUR', timestamps
**`quote`** (colonne aggiunte runtime, usate altrove ma create da configurazioni.php): `metodo_pagamento` VARCHAR(50), `gateway` VARCHAR(20), `gateway_transaction_id` VARCHAR(255), `payment_token` CHAR(64) + indice, `payment_token_expires` DATETIME
**`eventi`**: `id`, `associazione_id`, `titolo`, `data_evento` (dashboard)
**`tessere`**: `associazione_id`, `stato` ('Attiva', ...), `data_scadenza` (dashboard)
**`attivita_soci`** (implicita via `logSocioActivity`): log attività per socio con `associazione_id`, `socio_id`, tipo azione, descrizione.

---

## 1. Gestione Associazioni (`pages/associazioni.php`) — SOLO super_admin

### 1.1 Accesso
- Guard: utente loggato con ruolo `super_admin`, altrimenti redirect al login. Nessun'altra autorizzazione granulare.

### 1.2 Elenco associazioni
- Query: tutte le associazioni ordinate per `nome`, con due sub-count per riga:
  - `soci_count` = COUNT soci con `associazione_id = a.id`
  - `sedi_count` = COUNT sedi con `associazione_id = a.id`
- Colonne tabella UI: logo (se `logo_url` presente) + nome, email, stato (badge Attiva/Disattiva da `attiva`), n. sedi, n. soci, `tipo_scadenza_default`, azioni (modifica, elimina).
- Footer: conteggio totale associazioni.

### 1.3 Creazione associazione
Form modale (multipart per upload logo). Campi e regole:
- `nome` (required), `email` (required, formato email lato HTML; unicità garantita da vincolo DB — su violazione "Duplicate entry" si mostra "esiste già un'associazione con questa email")
- `partita_iva`, `codice_fiscale`, `telefono`, `indirizzo`, `citta`, `provincia` (maxlength 2), `cap` (maxlength 5) — tutti facoltativi, solo `cleanInput`
- `tipo_scadenza_default`: whitelist `['solare','annuale']`, fallback `'solare'`
- `giorni_notifica_scadenza`: cast a int, default 30
- `costo_tessera`: float oppure NULL se stringa vuota
- `attiva`: checkbox → 1/0 (default checked alla creazione)
- L'`id` viene generato come UUID applicativo prima dell'INSERT.

L'intera operazione POST (insert + tutte le sotto-azioni sotto) avviene in **un'unica transazione DB** con rollback su eccezione.

#### 1.3.1 Upload logo (sia in create che in edit)
- Validazione: estensioni `jpg|jpeg|png`, max **2 MB** (`validateFileUpload`).
- Nome file rigenerato in modo sicuro con prefisso `logo_` (`generateSecureFileName`).
- Salvataggio in `UPLOADS_PATH/logos/`; in DB si salva il path relativo `uploads/logos/<nome>`.
- Se l'upload fallisce o non è valido, l'operazione principale NON viene annullata: si appende al messaggio "(Logo non salvato)" o "(Logo non valido: …)" e il tipo messaggio diventa `warning`. **Pattern generale: le sotto-azioni falliscono "soft" con warning appeso, l'azione principale resta valida.**

#### 1.3.2 Collegamento admin esistente (create: campo `link_admin_user_id_create`)
- Si seleziona via autocomplete (vedi §1.7). Verifica server-side: l'utente deve esistere e avere `associazione_id` NULL/vuoto (non già collegato).
- Se `role === 'super_admin'`: si imposta solo `associazione_id` (il ruolo super_admin viene mantenuto).
- Altrimenti: `associazione_id` impostato E ruolo forzato a `admin_associazione`.
- Se non trovato o già collegato → warning "(Admin esistente non collegato: non trovato o già collegato)".
- Il collegamento di un admin esistente ha **priorità** sulla creazione di un nuovo admin: se `link_admin_user_id_create` è valorizzato, il blocco `create_admin` viene saltato.

#### 1.3.3 Creazione nuovo admin contestuale (flag `create_admin`)
- Campi: `admin_username`, `admin_email`, `admin_password`. Eseguito solo se tutti e tre non vuoti.
- Regole: password ≥ 8 caratteri; `username` o `email` non devono già esistere in `users` (check `COUNT(*) WHERE username=? OR email=?`).
- INSERT in `users` con `role='admin_associazione'`, `associazione_id` = nuova associazione, hash bcrypt.
- Errori → warning appesi: "(Admin non creato: la password deve avere almeno 8 caratteri)" / "(Admin non creato: username/email già esistenti)".
- UI: i due switch "crea admin" e "collega admin esistente" sono mutuamente esclusivi (JS deseleziona l'altro).

#### 1.3.4 Creazione sede iniziale (flag `create_sede`, checked di default)
- Campi: `sede_nome` (default "Sede Principale"), `sede_indirizzo`, `sede_citta`, `sede_responsabile`.
- INSERT in `sedi` con UUID nuovo e `associazione_id` = nuova associazione. Solo le 4 colonne sopra + id/associazione (provincia/cap/email/telefono non richiesti qui).

#### 1.3.5 Clonazione configurazioni da altra associazione (flag `clone_enable` + `clone_from_assoc_id`)
Eseguita solo se il flag è attivo E la sorgente è selezionata. Checkbox indipendenti per ogni dataset; per ognuno: SELECT * dalla sorgente, INSERT riga per riga con **nuovo UUID** e `associazione_id` di destinazione. Colonne copiate:
- `clone_tipi` → `tipi_socio` (nome, descrizione, costo_tessera). Si costruisce una **mappa `vecchio_id → nuovo_id`** (`$tipo_map`) usata dai template tessera.
- `clone_categorie` → `categorie_socio` (nome, descrizione)
- `clone_sedi` → `sedi` (nome, indirizzo, citta, provincia, cap, email, telefono, responsabile)
- `clone_campi` → `campi_personalizzati` (nome_campo, tipo_campo, descrizione, opzioni, obbligatorio, ordine) — NB: `obbligatorio_preiscrizione` NON viene clonato (resta default)
- `clone_tags` → `tags` (nome_tag, colore)
- `clone_gruppi` → `gruppi_dinamici` (nome_gruppo, descrizione, filtri_json)
- `clone_templates` → `tessera_templates` (titolo, contenuto, attivo) — solo se la tabella esiste. `tipo_socio_id` viene rimappato tramite `$tipo_map`: se il tipo sorgente non è stato clonato (o il template è default), il template clonato avrà `tipo_socio_id = NULL`.
- **La clonazione non copia mai utenti/admin né soci.** (Nota UI esplicita.)

### 1.4 Modifica associazione
- Si entra in edit via GET `?edit=<id>`: la riga viene caricata e la modale si apre automaticamente (JS) precompilata.
- UPDATE di tutti i campi anagrafici/configurazione di §1.3 (stesse validazioni). Gestione "Duplicate entry" su email come in creazione.
- In edit sono disponibili le stesse sotto-azioni: upload logo, collega admin esistente (`link_admin_user_id`, qui senza ricontrollo server-side che sia libero — il filtro avviene nell'API di ricerca e nel JS; il check è solo che l'utente esista), creazione nuovo admin (`create_admin`, saltata se `link_admin_user_id` valorizzato), clonazione su associazione esistente (flag `clone_enable_edit`, identica a §1.3.5; nel select sorgente l'associazione corrente è esclusa).
- **Lista "Amministratori correnti"** dentro la modale: utenti con `associazione_id = <id>` AND `role='admin_associazione'` ordinati per username, con pulsante di rimozione.

### 1.5 Rimozione admin dall'associazione
- POST con `remove_admin_user_id` + `id` (associazione): `UPDATE users SET associazione_id = NULL WHERE id = ? AND associazione_id = ?` (scoping difensivo sull'associazione). Il ruolo NON viene cambiato. Azione esclusiva: se presente, nessun'altra azione del form viene processata.

### 1.6 Eliminazione associazione
- POST `delete_id`: `DELETE FROM associazioni WHERE id = ?`. Confirm JS "Tutti i dati collegati saranno rimossi". L'eliminazione dei dati figli avviene **per cascade DB** (FK ON DELETE CASCADE sulle tabelle tenant; `users.associazione_id` va a NULL per ON DELETE SET NULL).

### 1.7 API ricerca admin (dipendenza esterna citata)
- Endpoint `api/users_search.php?only_admin_assoc=1&available=1&q=<query>` → JSON `{success, items:[{id, username, email, role, associazione_id}]}`.
- Il client la invoca con debounce 300ms, query minima 3 caratteri; filtra ulteriormente client-side gli utenti con `associazione_id` non nullo. In Laravel: endpoint che cerca per username/email, esclude (o segnala) utenti già collegati; parametri di filtro per soli admin disponibili.

### 1.8 Edge case
- Email associazione duplicata → errore bloccante con messaggio dedicato (sia INSERT che UPDATE); l'implementazione legacy usa `goto end_post` per saltare il commit — in Laravel basta abortire la transazione e restituire l'errore.
- Warning soft non annullano l'operazione principale (logo, admin, clone falliti parzialmente NON fanno rollback... attenzione: nel legacy sono dentro la stessa transazione, quindi un'ECCEZIONE in un sotto-blocco farebbe rollback di tutto; i casi "warning" sono gestiti senza eccezione).
- `tipo_scadenza_default` fuori whitelist → forzato a `solare`.

---

## 2. Sedi/Sezioni (`pages/sezioni.php` + sezione Sedi di `configurazioni.php`)

### 2.1 Pagina `sezioni`
È solo un **redirect**:
- Utente loggato con `associazione_id` in sessione → `index.php?page=configurazioni&section=sedi` (ancora `#sedi`).
- Super admin senza associazione selezionata → `index.php?page=configurazioni`.
- Non loggato → login.
In Laravel: route alias/redirect verso la pagina impostazioni, sezione sedi.

### 2.2 CRUD Sedi (dentro configurazioni, scoped al tenant corrente)
- **Lista**: `SELECT * FROM sedi WHERE associazione_id = ? ORDER BY nome`. Colonne UI: nome, indirizzo, città, provincia, cap, responsabile.
- **Crea/Modifica** (POST `sedi_action=save`): campi `nome`, `indirizzo`, `citta`, `provincia`, `cap`, `responsabile` (tutti `cleanInput`, nessuna validazione aggiuntiva, nemmeno su provincia/cap). Se presente `id` → UPDATE con doppia condizione `id=? AND associazione_id=?`; altrimenti INSERT con nuovo UUID. NB: in questo form NON si gestiscono `email` e `telefono` della sede (esistono in tabella, usate solo dalla clonazione).
- **Elimina** (POST `sedi_action=delete` + `delete_id`): DELETE con scoping `associazione_id`.
- Tutte le scritture richiedono CSRF valido e `associazione_id` in sessione.

---

## 3. Utenti amministratori (`pages/amministratori.php`)

### 3.1 Accesso
- **ATTENZIONE (bug/lacuna legacy)**: la pagina non ha guard di ruolo esplicito (è inclusa dal router `index.php` che richiede il login, ma chiunque sia loggato può raggiungerla, anche un admin_associazione, e vede/gestisce TUTTI gli utenti di TUTTE le associazioni). In Laravel: **restringere a super_admin** (la pagina elenca utenti cross-tenant); eventualmente versione scoped per admin_associazione che gestisce solo gli utenti della propria associazione.

### 3.2 Elenco
- Query: tutti gli `users` con LEFT JOIN su `associazioni` per il nome, ordinati per `role DESC, username` (i super_admin prima).
- UI: username, email, badge ruolo (Super Admin rosso / Admin Associazione blu), associazione (badge col nome, oppure "Tutte le associazioni" se NULL), azioni modifica/elimina.

### 3.3 Creazione (POST `add_admin`)
Campi: `username`, `email`, `password`, `role`, `associazione_id`.
Validazioni in ordine:
1. username, email, password tutti obbligatori
2. email valida (`FILTER_VALIDATE_EMAIL`)
3. password ≥ 8 caratteri
4. se `role === 'admin_associazione'` → `associazione_id` obbligatorio ("Gli admin di associazione devono essere collegati a un'associazione!")
5. unicità: nessun utente con stesso username OR email
Regola di business: se `role === 'super_admin'` → `associazione_id` forzato a NULL ("Super admin non sono legati a specifiche associazioni"). Password salvata con bcrypt.
- Il select associazione mostra solo associazioni **attive** (`attiva=1`, ordinate per nome). UI: il campo associazione è visibile/required solo quando il ruolo selezionato è admin_associazione (JS).

### 3.4 Modifica (POST `edit_admin` + `id`)
- Apertura modale via GET `?edit=<id>` (auto-show, precompilata).
- Unicità username/email **escludendo l'utente stesso** (`AND id <> ?`).
- `role='super_admin'` → `associazione_id=NULL`.
- Password: opzionale; se vuota NON viene toccata (UPDATE senza password_hash); se valorizzata, min 8 caratteri e re-hash.
- **Lacuna legacy**: in edit non viene rivalidato che un admin_associazione abbia un'associazione → in Laravel applicare la stessa regola della creazione.

### 3.5 Eliminazione (POST `delete_id`)
- DELETE diretto; se `rowCount()===0` → "Amministratore non trovato" (warning). Confirm JS.
- **Lacune legacy da correggere in Laravel**: nessuna protezione contro l'auto-eliminazione né contro l'eliminazione dell'ultimo super_admin.

### 3.6 Auto-migrazione legacy (da NON replicare)
Il file crea la tabella `users` se assente (CREATE TABLE IF NOT EXISTS), aggiunge `associazione_id` + FK e modifica l'ENUM del ruolo a runtime. In Laravel: tutto in migration.

---

## 4. Configurazioni associazione (`pages/configurazioni.php`)

### 4.1 Accesso e contesto
- Richiede login E `associazione_id` in sessione (altrimenti redirect a dashboard — è così che un super_admin "entra" prima in un'associazione, vedi §5.2).
- Tutte le letture/scritture sono scoped su `$_SESSION['associazione_id']`.
- Auto-migrazioni runtime eseguite all'apertura (in Laravel → migrations): colonna `associazioni.privacy_policy_html`, `associazioni.campi_obbligatori_config` (JSON), `campi_personalizzati.obbligatorio_preiscrizione`, tabella `payment_gateway_settings`, colonne pagamento su `quote`, colonne costo tessera (`ensureTesseraCostColumns`).

### 4.2 Sezioni e azioni POST (tutte con CSRF; errori PDO → log + messaggio generico)

1. **Sedi** — vedi §2.2.

2. **Upload logo** (POST `upload_logo` + file `logo_file`): identico a §1.3.1 ma per il tenant corrente; qui un errore di upload è bloccante (messaggio danger, nessun salvataggio). Aggiorna `associazioni.logo_url` e la variabile in pagina.

3. **Dati anagrafici** (POST `update_info`): UPDATE `nome, partita_iva, codice_fiscale, indirizzo, citta, provincia, cap, email, telefono` — tutti `cleanInput`, nessuna validazione formale (nemmeno email). NB: qui non si possono cambiare `attiva` né `costo_tessera` (riservati al super_admin in §1).

4. **Tesseramento** (POST `update_tesseramento`): UPDATE `tipo_scadenza_default` (select solare/annuale; etichette: "Anno Solare (31 Dicembre)" / "Annuale (365 giorni da iscrizione)") e `giorni_notifica_scadenza` (`FILTER_VALIDATE_INT`). Significato: regole default di scadenza tessere e preavviso notifiche.

5. **Template email scadenza** (POST `update_template`): UPDATE `template_email_scadenza` (textarea, `cleanInput`). Placeholder documentati in UI: `{NOME_SOCIO}, {COGNOME_SOCIO}, {DATA_SCADENZA}, {IMPORTO_QUOTA}`. Default suggerito: "Ciao {NOME_SOCIO}, ti ricordiamo che la tua quota scade il {DATA_SCADENZA}.".

6. **Privacy Policy** (POST `update_privacy_policy`): UPDATE `privacy_policy_html` con l'HTML **raw** dell'editor TinyMCE (NESSUNA sanitizzazione — il campo è poi stampato senza escape sulla pagina pubblica, vedi §7; in Laravel sanitizzare l'HTML con purifier mantenendo i tag dell'editor: liste, link, tabelle, grassetti). Editor: TinyMCE self-hosted, plugins `lists link table code`, lingua it.

7. **Campi obbligatori** (POST `update_required_fields`): configurazione di quali campi sono required nel backend soci e nel form pubblico di pre-iscrizione.
   - Campi sempre obbligatori (non configurabili, mostrati con badge "Sempre"): **nome, cognome, email**.
   - Campi built-in configurabili per il **backend**: `telefono, codice_fiscale, data_nascita, indirizzo, citta, provincia, cap, tipo_socio_id, categoria_socio_id, sede_id, privacy_consenso, note`.
   - Campi built-in configurabili per la **preiscrizione**: come sopra MENO `sede_id` e `note` (backend-only, mostrati "N/A").
   - **`privacy_consenso` in preiscrizione è SEMPRE forzato a true server-side (GDPR)**, indipendentemente dall'input (in UI badge "GDPR" + hidden input).
   - Persistenza: JSON in `associazioni.campi_obbligatori_config` con struttura `{"backend": {campo: bool, ...}, "preiscrizione": {campo: bool, ...}}` (encode `JSON_UNESCAPED_UNICODE`).
   - **Campi personalizzati**: per ogni campo del tenant due checkbox (`required_custom_backend_<id>`, `required_custom_preiscrizione_<id>`) che aggiornano direttamente `campi_personalizzati.obbligatorio` e `.obbligatorio_preiscrizione` (UPDATE scoped per id+associazione).
   - Lettura: helper `loadRequiredFieldsConfig($pdo, $assocId)` che decodifica il JSON (con default sensati se NULL) e helper `isFieldRequired($config, 'backend'|'preiscrizione', $campo)`.

8. **Template tessera per tipo socio** (POST `save_tessera_template`): disponibile solo se la tabella `tessera_templates` esiste (altrimenti alert "esegui migrazione").
   - Selettore GET `tipo_id`: vuoto = template **default** (`tipo_socio_id IS NULL`), altrimenti template specifico per tipo.
   - Logica **upsert**: cerca template esistente per (associazione, tipo|NULL); se c'è → UPDATE `contenuto` + `updated_at=NOW()`; altrimenti INSERT (UUID, titolo fisso 'Template Tessera', `attivo=1`).
   - Placeholder documentati: `{ASSOCIAZIONE_NOME}, {ASSOCIAZIONE_CODICE_FISCALE}, {ASSOCIAZIONE_INDIRIZZO}, {NOME}, {COGNOME}, {NOME_COMPLETO}, {NUMERO_SOCIO}, {TIPO_SOCIO}, {CATEGORIA_SOCIO}, {NUMERO_TESSERA}, {ANNO_VALIDITA}, {DATA_EMISSIONE}, {DATA_SCADENZA}`.
   - Contenuto default proposto in UI: "Il/La sottoscritto/a {NOME_COMPLETO}, tessera n. {NUMERO_TESSERA}, è iscritto/a all'associazione {ASSOCIAZIONE_NOME} per l'anno {ANNO_VALIDITA}.".

9. **Pagamenti online** (POST `update_payment_gateway`): impostazioni Stripe + PayPal per tenant (riga unica per associazione — UNIQUE su `associazione_id`, pattern upsert manuale).
   - Stripe: `stripe_enabled` (checkbox), `stripe_publishable_key` (testo in chiaro), `stripe_secret_key`, `stripe_webhook_secret` (segreti).
   - PayPal: `paypal_enabled`, `paypal_client_id` (chiaro), `paypal_client_secret` (segreto), `paypal_mode` whitelist `sandbox|live` default `sandbox`, `paypal_webhook_id`.
   - Generali: `auto_attivazione_pagamento` (default true: il socio viene attivato automaticamente dopo pagamento online), `valuta` (3 char, default EUR).
   - **Regola segreti**: i campi segreto sono input password sempre vuoti in UI (placeholder "••••••••" se già configurati); se inviati vuoti **mantengono il valore cifrato esistente**; se valorizzati vengono cifrati con `encryptValue()` (cifratura applicativa simmetrica) e sovrascritti. Mai mostrare i segreti decifrati.

### 4.3 Dati caricati per la vista
- Riga associazione corrente, tipi socio del tenant (per select template tessera), template corrente per il tipo selezionato, config campi obbligatori, riga payment gateway (tollerare tabella assente), campi personalizzati con i due flag obbligatorietà.
- Sidebar "Altre Configurazioni": link a Gestione Sedi (`sezioni`), Tipi Socio, Categorie Socio, Amministratori.

---

## 5. Dashboard (`pages/dashboard.php`)

### 5.1 Accesso
- Solo login richiesto. Comportamento dipende da ruolo e presenza di `associazione_id` in sessione.

### 5.2 Context switching per super_admin
- GET `?switch_assoc` → rimuove `associazione_id` e `associazione_nome` dalla sessione e redirect alla dashboard (torna in vista globale).
- GET `?assoc_id=<uuid>` → verifica che l'associazione esista (SELECT nome), poi imposta `$_SESSION['associazione_id']` e `$_SESSION['associazione_nome']` e redirect. Se non esiste, nessun cambiamento (resta sulla pagina).
- GET `?assoc_id=all` (o vuoto) → equivale a switch_assoc (vista globale).
- Card "Associazioni" (solo super_admin): elenco associazioni **attive** ordinate per nome con bottone "Entra" (`?assoc_id=<id>`) + card "Tutte le associazioni" → "Vista Globale" (`?assoc_id=all`). È QUESTO il meccanismo con cui il super_admin acquisisce il contesto tenant per usare configurazioni e le altre pagine scoped.

### 5.3 Statistiche
Due modalità:
- **Vista globale** (super_admin senza associazione): query NON scoped su tutte le associazioni.
- **Vista tenant**: stesse query con `WHERE associazione_id = ?`.

Metriche calcolate (entrambe le modalità):
1. `total_soci`: COUNT soci
2. `soci_attivi`: COUNT soci con `stato='Attivo'`
3. `eventi_mese`: COUNT eventi con `MONTH(data_evento)=MONTH(CURDATE()) AND YEAR(data_evento)=YEAR(CURDATE())` (mese corrente)
4. `quote_pagate`: COUNT quote con `stato='Pagata' AND anno=YEAR(CURDATE())` (anno corrente)
5. `scadenze_imminenti`: COUNT tessere con `stato='Attiva' AND data_scadenza BETWEEN CURDATE() AND CURDATE()+30 giorni`
6. `prossimi_eventi`: titolo+data dei prossimi 5 eventi con `data_evento >= CURDATE()` ordinati ascendenti.

### 5.4 Widget UI
- Header: "Panoramica generale di <nome associazione>" oppure "Benvenuto Super Admin. Panoramica globale…"; timestamp "Ultimo aggiornamento" (ora corrente del render).
- 3 stat card visualizzate: Totale Soci, Soci Attivi, Scadenze Imminenti (NB: `eventi_mese` e `quote_pagate` sono calcolate ma non hanno card dedicata nel layout attuale — mantenerle disponibili).
- Card "Prossimi Eventi" (lista 5, badge data dd/mm/YYYY, fallback "Nessun evento in programma").
- Card "Statistiche Quote": placeholder canvas Chart.js non implementato (in Laravel: implementare o omettere).
- Errori DB → alert generico in pagina + log.

---

## 6. Pre-iscrizione pubblica (`preiscrizione.php`)

Pagina **pubblica** (nessun login), standalone (layout brand dedicato, colore #FF7B11, logo associazione).
URL: `/preiscrizione.php?assoc=<UUID>` (nuova pre-iscrizione) oppure `/preiscrizione.php?assoc=<UUID>&token=<TOKEN64>` (rinnovo dati).

### 6.1 Validazione accesso
- `assoc` deve matchare il regex UUID `^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$` (case-insensitive) → altrimenti HTTP 400 "Link non valido".
- L'associazione deve esistere ed essere **attiva** (`attiva=1`) → altrimenti HTTP 404 "Associazione non trovata".

### 6.2 Dati caricati
- Associazione: `id, nome, email, logo_url, privacy_policy_html`.
- `tipi_socio` e `categorie_socio` del tenant (ordinati per nome) — le rispettive select compaiono solo se ci sono record.
- Config campi obbligatori (`loadRequiredFieldsConfig`) e campi personalizzati (`ORDER BY ordine, nome_campo`).

### 6.3 Modalità rinnovo (token GET)
- `token` deve essere hex di 64 char; si cerca un socio con `rinnovo_token = ? AND associazione_id = ?`.
- Token non trovato, formato errato o `rinnovo_token_expires < now` → stato "token scaduto": si mostra SOLO un alert "link di rinnovo scaduto o non valido, contatta l'associazione a <email assoc>" (niente form).
- Token valido → modalità rinnovo: titolo "Rinnovo iscrizione a <nome>", form **precompilato** con i dati del socio (inclusi i valori dei campi personalizzati da `valori_campi_personalizzati`), bottone "Aggiorna dati", hidden `renewal_token`.

### 6.4 Anti-abuso
- CSRF token (anche sul form pubblico).
- **Honeypot**: campo `website_url` nascosto via CSS off-screen; se valorizzato → il bot riceve un **finto successo** ("Richiesta inviata con successo.") senza scrivere nulla.

### 6.5 Validazione POST (in ordine, primo errore vince)
1. nome, cognome, email obbligatori sempre
2. email formalmente valida
3. `privacy_consenso` checkbox obbligatorio sempre ("Devi accettare l'informativa sulla privacy")
4. data di nascita: obbligatoria solo se configurata required; se presente deve essere `YYYY-MM-DD` (altrimenti errore; se non matcha il formato viene comunque salvata NULL)
5. provincia: se presente, esattamente 2 lettere
6. cap: se presente, esattamente 5 cifre
7. Campi configurabili required (da `campi_obbligatori_config.preiscrizione`): `telefono, codice_fiscale, data_nascita, indirizzo, citta, provincia, cap` (valore non vuoto) + `tipo_socio_id`, `categoria_socio_id` (select valorizzate). Messaggio aggregato: "Campi obbligatori mancanti: <lista label>".
8. Campi personalizzati con `obbligatorio_preiscrizione=1`: valore non vuoto, **eccetto tipo `checkbox`** che non è mai forzato.
- Normalizzazioni: `codice_fiscale` → uppercase, NULL se vuoto; tipo/categoria → NULL se vuoti.
- **Validazione FK anti-tamper**: `tipo_socio_id` e `categoria_socio_id` inviati devono appartenere al tenant; altrimenti vengono azzerati a NULL silenziosamente (non errore).

### 6.6 Percorso RINNOVO (token POST valido e non scaduto)
- Il token viene riletto da `$_POST['renewal_token']` e riverificato (formato + lookup + scadenza).
- UPDATE del socio esistente (scoped `id + associazione_id`): nome, cognome, email, telefono, data_nascita, codice_fiscale, indirizzo, citta, provincia, cap, tipo_socio_id, categoria_socio_id, privacy_consenso, **e azzeramento token** (`rinnovo_token=NULL, rinnovo_token_expires=NULL` → il link è one-shot).
- Upsert dei valori campi personalizzati: per ogni `custom_field[<campo_id>]` postato, se esiste già un valore per (socio, campo) → UPDATE (anche a stringa vuota); altrimenti INSERT solo se non vuoto.
- Log attività: `logSocioActivity(assoc, socio, 'Rinnovo dati', 'Dati anagrafici aggiornati tramite form di rinnovo.')`.
- Messaggio: "I tuoi dati sono stati aggiornati con successo. Grazie!". Lo stato del socio NON viene modificato dal rinnovo.

### 6.7 Percorso NUOVA PRE-ISCRIZIONE
1. **Controllo duplicati**: cerca socio del tenant con stessa `email`; se non trovato e CF presente, cerca per `UPPER(codice_fiscale)`. Se esiste → errore bloccante: "Esiste già un socio registrato con questo email|codice fiscale (<Nome Cognome>). Se hai bisogno di assistenza, contatta l'associazione."
2. **Numero socio auto-generato**: `MAX(CAST(numero_socio AS UNSIGNED)) + 1` sul tenant, formattato con padding a 3 cifre (`001`, `002`, …; oltre 999 il numero cresce senza padding extra). NB: potenziale race condition nel legacy — in Laravel gestire con lock/transazione.
3. INSERT in `soci` con: UUID, associazione_id, numero_socio, dati anagrafici, `data_iscrizione = CURDATE()`, **`stato = 'In Attesa'`** (stato che richiede approvazione dell'admin altrove), privacy_consenso, tipo/categoria.
4. INSERT dei valori campi personalizzati non vuoti.
5. Log attività: tipo 'Pre-iscrizione', descrizione 'Richiesta di pre-iscrizione inviata dal form pubblico.'.
6. **Notifica email all'associazione** (best-effort, errori solo loggati): se `EmailService` del tenant è configurato → `seedDefaultTemplates()`, render del template `nuova_preiscrizione` con placeholder costruiti da `buildPlaceholderValues` (+ extra `TELEFONO`, `CODICE_FISCALE`), invio in coda (`queueEmail`) all'email dell'associazione, priorità 2.
7. Messaggio: "La tua richiesta di pre-iscrizione è stata inviata con successo! Riceverai una comunicazione quando sarà approvata."

### 6.8 Rendering form
- Asterisco rosso e attributo `required` HTML applicati dinamicamente in base alla config required.
- Repopolamento campi da `$_POST` in caso di errore; in rinnovo, prevale il prefill dal socio.
- Campi personalizzati renderizzati per `tipo_campo`: `textarea` (3 righe, full width), `select` (opzioni = righe non vuote di `opzioni`), `checkbox` (value 1), default → `<input type="<tipo_campo>">` (text/date/number/email...).
- Checkbox privacy: se `privacy_policy_html` non vuoto, il label include link alla pagina pubblica `privacy-policy.php?assoc=<id>` (target _blank).
- Bottone submit con spinner anti doppio-invio (JS).
- Auto-migrazioni runtime presenti nel file (ENUM stato soci esteso con 'In Attesa'/'In Attesa di Pagamento', colonne rinnovo_token, privacy_policy_html, obbligatorio_preiscrizione) → in Laravel: migrations.

---

## 7. Privacy policy pubblica (`privacy-policy.php`)

- Pagina pubblica read-only: `/privacy-policy.php?assoc=<UUID>`.
- Stessa validazione di accesso di §6.1 (regex UUID → 400; associazione esistente e `attiva=1` → 404).
- Carica `nome, logo_url, privacy_policy_html`.
- Se la policy è vuota/blank → alert informativo "Privacy policy non ancora disponibile per questa associazione."
- Altrimenti stampa l'HTML della policy **senza escape** (HTML fidato proveniente dall'editor admin). In Laravel: sanitizzare in scrittura (vedi §4.2.6) e stampare con `{!! !!}` su contenuto purificato.
- Layout brand identico alla preiscrizione (header arancione, logo, footer col nome associazione).

---

## 8. Matrice comportamenti per ruolo

| Funzionalità | super_admin | admin_associazione | operatore* | pubblico |
|---|---|---|---|---|
| CRUD associazioni, clone, collega/rimuovi admin | ✔ (esclusiva) | ✘ | ✘ | ✘ |
| Switch contesto associazione (dashboard) | ✔ | ✘ (ha sempre il proprio tenant in sessione dal login) | ✘ | ✘ |
| Dashboard vista globale | ✔ (senza tenant selezionato) | ✘ | ✘ | ✘ |
| Dashboard vista tenant | ✔ (dopo "Entra") | ✔ | ✔ (sola lettura, proposta) | ✘ |
| Configurazioni tenant (sedi, anagrafica, tesseramento, template, privacy, campi obbligatori, pagamenti) | ✔ sul tenant selezionato | ✔ sul proprio tenant | ✘ (proposta) | ✘ |
| Gestione utenti admin | ✔ (nel legacy: chiunque loggato — da correggere) | da decidere: solo utenti del proprio tenant | ✘ | ✘ |
| Pre-iscrizione / rinnovo | — | — | — | ✔ |
| Privacy policy | — | — | — | ✔ |

\* "operatore" non esiste nel legacy: i permessi indicati sono la proposta per Laravel (accesso operativo ai soci senza configurazioni).

Regole di ruolo hard-coded da preservare:
- super_admin non è mai legato a un'associazione in modo permanente (`users.associazione_id` usato solo come "contesto", e in `amministratori.php` forzato a NULL).
- Collegare un utente a un'associazione da `associazioni.php` declassa/forza il ruolo a `admin_associazione`, tranne se è super_admin (mantiene il ruolo).
- admin_associazione DEVE avere un'associazione.

---

## 9. Note per l'implementazione Laravel

1. **Migrations al posto delle auto-migrazioni runtime.** Il legacy fa ALTER TABLE/CREATE TABLE "lazy" a ogni request (privacy_policy_html, campi_obbligatori_config, obbligatorio_preiscrizione, payment_gateway_settings, colonne quote, ENUM stato soci, colonne rinnovo_token). Consolidare tutto in migrations Laravel; eliminare ogni `tableExists/columnExists` runtime (il check `tessera_templates` sparisce: la tabella esiste sempre).
2. **Modelli/Relazioni suggeriti**: `Associazione` (hasMany Sede, Socio, TipoSocio, CategoriaSocio, CampoPersonalizzato, Tag, GruppoDinamico, TesseraTemplate; hasOne PaymentGatewaySetting; hasMany User), `User` (belongsTo Associazione nullable), `Sede`, `TesseraTemplate`, `PaymentGatewaySetting`, `Socio`. UUID come PK (`HasUuids`) per coerenza col legacy; valutare la migrazione di `users` da INT a UUID o mantenere INT.
3. **Tenancy**: middleware che risolve il tenant da sessione (o da utente per admin_associazione) + global scope `associazione_id` sui modelli tenant. Il context-switch del super_admin (§5.2) diventa un controller dedicato che setta il tenant in sessione, con vista globale = nessun tenant; proteggere il parametro `assoc_id` verificando esistenza.
4. **Autorizzazione**: Policy/Gate — `manage-associazioni` e `manage-users` solo super_admin (correggendo la falla legacy di `amministratori.php`); `manage-settings` per super_admin (con tenant selezionato) e admin_associazione (proprio tenant). Introdurre eventualmente `operatore` come terzo valore enum con accesso operativo ma non alle configurazioni.
5. **Form Request** per ogni azione: regole esplicite al posto delle validazioni implicite (email `unique:associazioni,email`, provincia `size:2|alpha`, cap `digits:5`, password `min:8`, whitelist enum, ecc.). Replicare le regole implicite documentate: privacy_consenso sempre required in preiscrizione, segreto gateway vuoto = mantieni, super_admin → associazione NULL, ecc.
6. **Transazioni**: la creazione associazione con sotto-azioni (admin, sede, clone) va in `DB::transaction`; decidere consapevolmente la semantica "warning soft" del legacy (sotto-azione fallita non blocca la principale) — consiglio: validare PRIMA tutto ciò che può fallire (password corta, username duplicato) e poi eseguire atomicamente.
7. **Clonazione**: estrarre un service `CloneAssociazioneConfigService` con la mappa tipi→template; ricordare che utenti e soci non si clonano mai e che `obbligatorio_preiscrizione` non viene copiato nel legacy (decidere se copiarlo).
8. **Upload logo**: `Storage::disk('public')` con validazione `mimes:jpg,jpeg,png|max:2048`; conservare URL/path nel campo `logo_url`; rigenerare il nome file.
9. **Segreti gateway**: usare `encrypted` cast di Eloquent al posto di `encryptValue()`; non rinviare mai i valori al client; pattern "vuoto = non modificare" nel Form Request/controller.
10. **Privacy policy HTML**: sanitizzare in input (es. mearie/purifier) con whitelist coerente con la toolbar TinyMCE (p, h1-h3, b/strong, i/em, u, ul/ol/li, a[href], table/tr/td/th, br); output con `{!! !!}`. Nel legacy l'HTML è salvato e stampato raw: è un punto da irrobustire.
11. **Numero socio**: generare dentro la transazione con `lockForUpdate()` (o sequenza per tenant) per eliminare la race del `MAX()+1`; mantenere il padding a 3 cifre per compatibilità.
12. **Token di rinnovo e pre-iscrizione**: rotte pubbliche firmate o con token random 64 hex come nel legacy; token one-shot (azzerato dopo l'uso) e con scadenza; rate limiting + honeypot (o captcha) sul form pubblico; mantenere il "finto successo" per i bot.
13. **Email**: la notifica `nuova_preiscrizione` diventa una Notification/Mailable accodata (best-effort: fallimento non blocca l'iscrizione); il sistema legacy usa template DB per tenant (`EmailService.renderTemplate`) con coda prioritaria — replicare con tabella template + queue.
14. **Dashboard**: spostare le statistiche in un service/query object con scope tenant opzionale; le 5 metriche e i prossimi 5 eventi come da §5.3; aggiungere (opzionale) il grafico quote mai implementato nel legacy.
15. **API ricerca utenti** (`users_search`): endpoint JSON autenticato solo super_admin, parametri `q` (min 3 char), filtro "disponibili" (associazione_id NULL) ed esclusione super_admin a livello server (nel legacy parte del filtro era solo client-side: irrobustire).
16. **Protezioni mancanti nel legacy da aggiungere**: divieto di auto-eliminazione utente; divieto di eliminare l'ultimo super_admin; conferma forte per il DELETE associazione (cascade massivo) — es. digitare il nome; audit log delle azioni di super_admin.
17. **Redirect `sezioni`**: route legacy `/sezioni` → redirect 302 alla pagina impostazioni ancorata alla sezione sedi, per compatibilità con vecchi link.
