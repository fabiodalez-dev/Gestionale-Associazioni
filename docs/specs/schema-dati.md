# Specifica funzionale — Schema dati (dizionario dati completo)

**Area:** `schema-dati`
**Fonti analizzate (sola lettura):**
- `gestione-associazioni-laravel/docs/schema-reale-mysql.sql` — **dump reale del DB MySQL di produzione (FONTE DI VERITÀ)**
- `gestione-associazioni/database/schema.sql` — schema "pulito" v2.0 dell'installer (usato per la tabella `payment_gateway_settings`, assente dal dump, e per le colonne più recenti non ancora migrate sul DB reale)
- `gestione-associazioni/migrations/*.php` — migrazioni runtime PHP (regole di business implicite)

**Scopo:** consentire a uno sviluppatore di reimplementare l'intero schema dati e le sue regole in Laravel **senza leggere il codice della vecchia app**.

---

## 1. Convenzioni generali (regole trasversali)

| Regola | Dettaglio |
|---|---|
| **Chiavi primarie** | Tutte le tabelle di dominio usano `id CHAR(36)` (UUID v4 generato applicativamente, mai `AUTO_INCREMENT`). Eccezioni: `users` (legacy, `INT AUTO_INCREMENT`) e `migrations` (`INT AUTO_INCREMENT`). |
| **Multi-tenancy** | Quasi tutte le tabelle hanno `associazione_id CHAR(36)` che riferisce `associazioni.id`. **Ogni query applicativa DEVE filtrare per `associazione_id`** (tenant isolation). Le tabelle figlie di `soci` (es. `valori_campi_personalizzati`, `socio_tags`) ereditano il tenant tramite il socio. |
| **Charset/Collation** | `utf8mb4` / `utf8mb4_unicode_ci` su tutte le tabelle, engine `InnoDB`. |
| **Timestamp** | Pattern standard: `created_at DATETIME DEFAULT CURRENT_TIMESTAMP`, `updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. Alcune tabelle legacy usano `TIMESTAMP NULL` (`eventi_partecipanti`, `scadenze`, `verbali`, `users`). `documenti`, `socio_tags`, `email_queue`, `email_log`, `storico_attivita_socio` **non hanno** `updated_at` (sono append-only). |
| **Boolean** | `tinyint(1)` con default `0` o `1`. |
| **Cancellazioni a cascata** | FK verso `associazioni` quasi sempre `ON DELETE CASCADE` (cancellare un'associazione elimina TUTTO il suo tenant). FK verso `utenti` quasi sempre `ON DELETE SET NULL` (la cancellazione di un operatore non distrugge lo storico). FK verso entità di lookup (`sedi`, `tipi_socio`, `categorie_socio`) `ON DELETE SET NULL`. |
| **Tabelle senza FK** | `verbali` e `scadenze` non hanno alcun vincolo FK (sono pagine legacy migrate al multi-tenant solo a livello applicativo): l'isolamento per tenant è garantito SOLO dalle query. |

---

## 2. Dizionario dati — tabella per tabella

> Legenda: **PK** = primary key, **UQ** = unique, **IDX** = indice non-unico, **FK** = foreign key.
> Salvo diversa indicazione, i tipi sono quelli del **dump reale**.

### 2.1 `associazioni` — entità radice (tenant)

| Colonna | Tipo | Null | Default | Note |
|---|---|---|---|---|
| id | CHAR(36) | NO | — | PK, UUID |
| nome | VARCHAR(255) | NO | — | Ragione sociale |
| partita_iva | VARCHAR(20) | SÌ | NULL | **UQ globale** |
| codice_fiscale | VARCHAR(16) | SÌ | NULL | **UQ globale** |
| indirizzo | TEXT | SÌ | NULL | |
| citta | VARCHAR(100) | SÌ | NULL | |
| provincia | VARCHAR(2) | SÌ | NULL | Sigla provincia |
| cap | VARCHAR(5) | SÌ | NULL | |
| email | VARCHAR(255) | NO | — | **UQ globale** |
| telefono | VARCHAR(20) | SÌ | NULL | |
| costo_tessera | DECIMAL(10,2) | SÌ | NULL | Costo tessera di default dell'associazione (fallback se il tipo socio non ne ha uno) |
| logo_url | TEXT | SÌ | NULL | |
| attiva | TINYINT(1) | SÌ | 1 | Disattivazione soft del tenant |
| tipo_scadenza_default | ENUM('solare','annuale') | NO | 'solare' | Modalità default di scadenza tessere: 'solare' = 31/12 anno corrente; 'annuale' = 365 gg dall'emissione |
| giorni_notifica_scadenza | INT | SÌ | 30 | Anticipo notifiche scadenza |
| template_email_scadenza | TEXT | SÌ | NULL | Template email legacy (pre sistema email_templates) |
| privacy_policy_html | LONGTEXT | SÌ | NULL | HTML privacy policy mostrata in preiscrizione/area soci |
| created_at / updated_at | DATETIME | SÌ | CURRENT_TIMESTAMP | standard |

**Indici/vincoli:** PK(id); UQ(email); UQ(partita_iva); UQ(codice_fiscale).

> ⚠️ **Differenza dump vs schema file:** `schema.sql` aggiunge `campi_obbligatori_config JSON DEFAULT NULL` (configurazione per-associazione di quali campi anagrafici sono obbligatori, usata da preiscrizione). **Assente dal dump reale** → in Laravel va prevista come migration aggiuntiva.

### 2.2 `utenti` — account amministrativi (auth backend)

| Colonna | Tipo | Null | Default | Note |
|---|---|---|---|---|
| id | CHAR(36) | NO | — | PK, UUID |
| associazione_id | CHAR(36) | SÌ | NULL | **NULL = super_admin** (non legato a un tenant) |
| nome | VARCHAR(100) | NO | — | |
| cognome | VARCHAR(100) | NO | — | |
| email | VARCHAR(255) | NO | — | **UQ globale** (login key) |
| password_hash | VARCHAR(255) | NO | — | `password_hash()` PHP (bcrypt) |
| ruolo | ENUM('super_admin','admin_associazione','operatore') | NO | — | Nessun default: va sempre specificato |
| attivo | TINYINT(1) | SÌ | 1 | Account disattivabile |
| last_login | DATETIME | SÌ | NULL | Aggiornato al login |
| created_at / updated_at | DATETIME | SÌ | CURRENT_TIMESTAMP | |

**Indici/vincoli:** PK(id); UQ(email); IDX idx_utenti_associazione_id(associazione_id); FK associazione_id → associazioni(id) **ON DELETE CASCADE**.

> Nota: la FK CASCADE implica che cancellando l'associazione spariscono anche i suoi admin/operatori; i super_admin (associazione_id NULL) sopravvivono.

### 2.3 `users` — account legacy (retrocompatibilità)

| Colonna | Tipo | Null | Default | Note |
|---|---|---|---|---|
| id | INT AUTO_INCREMENT | NO | — | PK |
| username | VARCHAR(50) | NO | — | **UQ** |
| email | VARCHAR(255) | NO | — | **UQ** |
| password_hash | VARCHAR(255) | NO | — | |
| role | ENUM('admin_associazione','super_admin') | SÌ | 'admin_associazione' | **Non esiste 'operatore'** in questa tabella |
| associazione_id | CHAR(36) | SÌ | NULL | |
| created_at / updated_at | TIMESTAMP | SÌ | CURRENT_TIMESTAMP | |
| last_login | TIMESTAMP | SÌ | NULL | |

**Indici/vincoli:** PK(id); UQ(username); UQ(email); IDX idx_users_association(associazione_id); FK associazione_id → associazioni(id) **ON DELETE SET NULL**.

> Tabella pre-SaaS mantenuta per retrocompatibilità. La migration `update_users_table.php` documenta la sua evoluzione (vedi §4.5). In Laravel **non va reimplementata**: i suoi dati vanno migrati una tantum in `utenti`/`users` Laravel.

### 2.4 `sedi` — sedi/filiali dell'associazione

| Colonna | Tipo | Null | Default |
|---|---|---|---|
| id | CHAR(36) | NO | — (PK) |
| associazione_id | CHAR(36) | NO | — |
| nome | VARCHAR(255) | NO | — |
| indirizzo | TEXT | SÌ | NULL |
| citta | VARCHAR(100) | SÌ | NULL |
| provincia | VARCHAR(2) | SÌ | NULL |
| cap | VARCHAR(5) | SÌ | NULL |
| email | VARCHAR(255) | SÌ | NULL |
| telefono | VARCHAR(20) | SÌ | NULL |
| responsabile | VARCHAR(255) | SÌ | NULL |
| created_at / updated_at | DATETIME | SÌ | CURRENT_TIMESTAMP |

**Indici/vincoli:** PK(id); IDX idx_sedi_associazione_id(associazione_id); FK associazione_id → associazioni **CASCADE**. **Nessun vincolo unique sul nome** (nomi sede duplicati ammessi).

### 2.5 `tipi_socio` — tipologie di socio (con costo tessera)

| Colonna | Tipo | Null | Default |
|---|---|---|---|
| id | CHAR(36) | NO | — (PK) |
| associazione_id | CHAR(36) | NO | — |
| nome | VARCHAR(255) | NO | — |
| descrizione | TEXT | SÌ | NULL |
| costo_tessera | DECIMAL(10,2) | SÌ | NULL |
| created_at / updated_at | DATETIME | SÌ | CURRENT_TIMESTAMP |

**Indici/vincoli:** PK(id); **UQ(associazione_id, nome)**; FK associazione_id → associazioni **CASCADE**.

**Regola di business (cascata costo tessera):** il costo applicato a un socio è `tipi_socio.costo_tessera` se valorizzato, altrimenti `associazioni.costo_tessera` (fallback), altrimenti nessun costo. (Origine: migration `update_tessera_cost.php` che ha aggiunto entrambe le colonne.)

### 2.6 `categorie_socio` — categorie di socio

Identica a `tipi_socio` ma **senza** `costo_tessera`:
id (PK), associazione_id (NOT NULL), nome (NOT NULL), descrizione, created_at, updated_at.
**Indici/vincoli:** **UQ(associazione_id, nome)**; FK associazione_id → associazioni **CASCADE**.

### 2.7 `soci` — anagrafica soci (tabella centrale)

| Colonna | Tipo | Null (dump) | Default | Note |
|---|---|---|---|---|
| id | CHAR(36) | NO | — | PK |
| associazione_id | CHAR(36) | NO | — | tenant |
| sede_id | CHAR(36) | SÌ | NULL | FK sedi, SET NULL |
| numero_socio | VARCHAR(50) | NO | — | Numero progressivo/manuale, **UQ per associazione** |
| nome | VARCHAR(100) | NO | — | |
| cognome | VARCHAR(100) | NO | — | |
| data_nascita | DATE | **NO (dump)** | — | ⚠️ vedi differenze §3 |
| codice_fiscale | VARCHAR(16) | SÌ | NULL | **UQ per associazione** (uniq_assoc_cf) |
| email | VARCHAR(255) | NO | — | **UQ per associazione** |
| telefono | VARCHAR(20) | SÌ | NULL | |
| indirizzo | VARCHAR(255) | SÌ | NULL | |
| citta | VARCHAR(100) | SÌ | NULL | |
| provincia | VARCHAR(2) | SÌ | NULL | |
| cap | VARCHAR(5) | SÌ | NULL | |
| data_iscrizione | DATE | NO | — | |
| stato | ENUM('Attivo','Sospeso','Radiato','Deceduto','Trasferito','In Attesa','In Attesa di Pagamento') | SÌ | 'Attivo' | 'In Attesa' = preiscrizione da approvare; 'In Attesa di Pagamento' = preiscrizione approvata con pagamento online pendente |
| note | TEXT | SÌ | NULL | |
| privacy_consenso | TINYINT(1) | SÌ | 0 | Consenso GDPR |
| email_opt_out | TINYINT(1) | SÌ | 0 | Esclusione da invii massivi |
| email_opt_out_token | CHAR(64) | SÌ | NULL | Token per link "disiscriviti" nelle email |
| tipo_socio_id | CHAR(36) | SÌ | NULL | FK tipi_socio, SET NULL |
| categoria_socio_id | CHAR(36) | SÌ | NULL | FK categorie_socio, SET NULL |
| created_at / updated_at | DATETIME | SÌ | CURRENT_TIMESTAMP | |
| password_hash | VARCHAR(255) | SÌ | NULL | Login area soci (NULL = nessun accesso self-service) |
| password_reset_token | VARCHAR(255) | SÌ | NULL | Reset password area soci |
| password_reset_expires | DATETIME | SÌ | NULL | Scadenza token reset |
| rinnovo_token | CHAR(64) | SÌ | NULL | Token per link diretto rinnovo tessera/quota via email |
| rinnovo_token_expires | DATETIME | SÌ | NULL | Scadenza token rinnovo |

**Indici/vincoli (dump):**
- PK(id)
- **UQ(associazione_id, numero_socio)**
- **UQ(associazione_id, email)** → la stessa email può esistere in associazioni diverse, mai due volte nello stesso tenant
- **UQ uniq_assoc_cf(associazione_id, codice_fiscale)** → CF unico per tenant; più righe con CF NULL sono ammesse (semantica MySQL)
- IDX: idx_soci_assoc(associazione_id), idx_soci_tipo(tipo_socio_id), idx_soci_cat(categoria_socio_id), idx_soci_sede(sede_id), idx_soci_associazione_id(associazione_id) *(duplicato storico di idx_soci_assoc — in Laravel tenerne uno solo)*, idx_soci_cognome_nome(cognome, nome), idx_soci_rinnovo_token(rinnovo_token)
- FK: associazione_id → associazioni **CASCADE**; sede_id → sedi **SET NULL**; tipo_socio_id → tipi_socio **SET NULL**; categoria_socio_id → categorie_socio **SET NULL**

### 2.8 `tessere` — tessere associative

| Colonna | Tipo | Null | Default | Note |
|---|---|---|---|---|
| id | CHAR(36) | NO | — | PK |
| socio_id | CHAR(36) | NO | — | |
| associazione_id | CHAR(36) | NO | — | denormalizzato per query dirette per tenant |
| numero_tessera | VARCHAR(50) | NO | — | **UQ per associazione** |
| anno_validita | INT | NO | — | Anno di riferimento |
| data_emissione | DATE | NO | — | |
| data_scadenza | DATE | NO | — | Calcolata: 'solare' → 31/12 di anno_validita; 'annuale' → emissione + 1 anno |
| tipo_scadenza | ENUM('solare','annuale') | NO | — | Snapshot del tipo al momento dell'emissione (default da `associazioni.tipo_scadenza_default`) |
| stato | ENUM('Attiva','Scaduta','Sospesa','Annullata') | SÌ | 'Attiva' | |
| qr_code_url | TEXT | SÌ | NULL | URL/dataURI QR per check-in |
| template_tessera | VARCHAR(100) | SÌ | NULL | Riferimento (per nome/id stringa) al template grafico usato |
| created_at / updated_at | DATETIME | SÌ | CURRENT_TIMESTAMP | |

**Indici/vincoli (dump):** PK(id); **UQ(associazione_id, numero_tessera)**; IDX idx_tessere_assoc_anno(associazione_id, anno_validita), idx_tessere_socio(socio_id), idx_tessere_emissione(socio_id, associazione_id, data_emissione), idx_tessere_template(template_tessera), idx_tessere_scadenza(data_scadenza), idx_tessere_socio_id(socio_id) *(duplicato storico)*, idx_tessere_associazione_id(associazione_id); FK socio_id → soci **CASCADE**; associazione_id → associazioni **CASCADE**.

> ⚠️ **Differenza:** `schema.sql` aggiunge `evento_creazione_id CHAR(36) NULL` + IDX idx_tessere_evento_creazione (tessera emessa contestualmente a un evento, senza FK). **Assente dal dump.**

**Regola implicita:** nessun vincolo DB impedisce più tessere attive per lo stesso socio/anno — l'unicità "una tessera attiva per anno" è applicativa.

### 2.9 `tessera_templates` — template grafici tessera

| Colonna | Tipo | Null | Default |
|---|---|---|---|
| id | CHAR(36) | NO | — (PK) |
| associazione_id | CHAR(36) | NO | — |
| tipo_socio_id | CHAR(36) | SÌ | NULL |
| titolo | VARCHAR(255) | SÌ | 'Template Tessera' |
| contenuto | TEXT | NO | — (HTML/JSON del layout con placeholder) |
| attivo | TINYINT(1) | SÌ | 1 |
| created_at / updated_at | DATETIME | SÌ | CURRENT_TIMESTAMP |

**Indici/vincoli:** PK(id); **UQ(associazione_id, tipo_socio_id)** → un solo template per coppia associazione+tipo; con `tipo_socio_id = NULL` (template generico) MySQL ammette più righe NULL: l'unicità del template generico è applicativa. FK associazione_id → associazioni **CASCADE**; tipo_socio_id → tipi_socio **SET NULL**.

**Regola implicita:** alla stampa tessera si cerca prima il template del tipo socio, fallback sul template generico (tipo_socio_id NULL).

### 2.10 `quote` — quote associative / pagamenti

| Colonna | Tipo (dump) | Null | Default | Note |
|---|---|---|---|---|
| id | CHAR(36) | NO | — | PK |
| socio_id | CHAR(36) | NO | — | |
| associazione_id | CHAR(36) | NO | — | |
| anno | INT | NO | — | Anno di competenza |
| importo | DECIMAL(10,2) | NO | — | |
| data_scadenza | DATE | NO | — | |
| data_pagamento | DATE | SÌ | NULL | Valorizzata quando stato → 'Pagata' |
| stato | ENUM('Pagata','Da Pagare','Scaduta','In Scadenza') | SÌ | 'Da Pagare' | 'Scaduta'/'In Scadenza' sono stati derivati ricalcolati da data_scadenza vs oggi e giorni_notifica_scadenza |
| tipo | VARCHAR(100) | NO | — | Es. 'Tesseramento', 'Quota annuale' — testo libero |
| note | TEXT | SÌ | NULL | |
| created_at / updated_at | DATETIME | SÌ | CURRENT_TIMESTAMP | |

**Indici/vincoli (dump):** PK(id); IDX idx_quote_socio_id(socio_id), idx_quote_associazione_id(associazione_id); FK socio_id → soci **CASCADE**; associazione_id → associazioni **CASCADE**. **Nessun vincolo unique** su (socio_id, anno, tipo): quote duplicate per anno sono permesse dal DB.

> ⚠️ **Differenza:** `schema.sql` aggiunge le colonne dell'integrazione pagamenti online (assenti dal dump):
> - `metodo_pagamento VARCHAR(50) NULL` (es. 'contanti', 'bonifico', 'stripe', 'paypal')
> - `gateway VARCHAR(20) NULL` ('stripe' | 'paypal')
> - `gateway_transaction_id VARCHAR(255) NULL` (id transazione del gateway)
> - `payment_token CHAR(64) NULL` + IDX idx_quote_payment_token (token del link pubblico di pagamento)
> - `payment_token_expires DATETIME NULL`

### 2.11 `eventi` — eventi dell'associazione

id (PK), associazione_id (NOT NULL), titolo VARCHAR(255) NOT NULL, descrizione TEXT, data_evento DATETIME NOT NULL, luogo VARCHAR(255), created_at/updated_at DATETIME.
**Indici/vincoli:** IDX idx_eventi_associazione_id; FK associazione_id → associazioni **CASCADE**.

### 2.12 `eventi_partecipanti` — partecipazioni a eventi

| Colonna | Tipo | Null | Default |
|---|---|---|---|
| id | CHAR(36) | NO | — (PK) |
| associazione_id | CHAR(36) | NO | — |
| evento_id | CHAR(36) | NO | — |
| socio_id | CHAR(36) | NO | — |
| stato_partecipazione | ENUM('Confermato','Forse','Non Partecipa') | SÌ | 'Non Partecipa' |
| data_conferma | TIMESTAMP | SÌ | NULL |
| note | TEXT | SÌ | NULL |
| created_at | TIMESTAMP | SÌ | CURRENT_TIMESTAMP (no updated_at) |

**Indici/vincoli:** PK(id); **UQ unique_partecipation(evento_id, socio_id)** → un socio è iscritto una sola volta per evento; IDX su associazione_id, evento_id, socio_id; FK tutte e tre **CASCADE**.

**Regola implicita:** quando lo stato passa a 'Confermato' va valorizzata `data_conferma` (usata per check-in QR).

### 2.13 `documenti` — documenti dell'associazione

id (PK), associazione_id (NOT NULL), nome_file VARCHAR(255) NOT NULL, percorso_file TEXT NOT NULL, descrizione TEXT, categoria VARCHAR(100), dimensione_kb INT, caricato_da CHAR(36) NULL, created_at DATETIME (**no updated_at**: append-only).
**Indici/vincoli:** IDX caricato_da, idx_documenti_associazione_id; FK associazione_id → associazioni **CASCADE**; caricato_da → utenti **SET NULL**.

### 2.14 `documenti_socio` — documenti del singolo socio

id (PK), socio_id (NOT NULL), associazione_id (NOT NULL), nome_file VARCHAR(255) NOT NULL, percorso_file TEXT NOT NULL, descrizione TEXT, **data_scadenza DATE NULL** (es. certificato medico — alimenta notifiche scadenza), caricato_da CHAR(36) NULL, created_at/updated_at DATETIME.
**Indici/vincoli:** IDX associazione_id, caricato_da, idx_documenti_socio_socio_id; FK socio_id → soci **CASCADE**; associazione_id → associazioni **CASCADE**; caricato_da → utenti **SET NULL**.

### 2.15 `campi_personalizzati` — definizione campi custom

| Colonna | Tipo | Null | Default |
|---|---|---|---|
| id | CHAR(36) | NO | — (PK) |
| associazione_id | CHAR(36) | NO | — |
| nome_campo | VARCHAR(100) | NO | — |
| tipo_campo | ENUM('text','email','tel','number','date','url','textarea','select','checkbox') | NO | — |
| descrizione | TEXT | SÌ | NULL |
| opzioni | TEXT | SÌ | NULL (lista opzioni per select, formato testo/JSON) |
| obbligatorio | TINYINT(1) | SÌ | 0 |
| ordine | INT | SÌ | 0 (ordinamento UI) |
| created_at / updated_at | DATETIME | SÌ | CURRENT_TIMESTAMP |

**Indici/vincoli:** **UQ(associazione_id, nome_campo)**; FK associazione_id → associazioni **CASCADE**.

> ⚠️ **Differenza:** `schema.sql` aggiunge `obbligatorio_preiscrizione BOOLEAN DEFAULT FALSE` (campo richiesto nel form pubblico di preiscrizione). **Assente dal dump.**

### 2.16 `valori_campi_personalizzati` — valori campi custom per socio

id (PK), socio_id (NOT NULL), campo_id (NOT NULL), valore TEXT NULL, created_at/updated_at.
**Indici/vincoli:** **UQ(socio_id, campo_id)** → un valore per campo per socio (upsert); IDX campo_id, idx_valori_campi_personalizzati_socio_id; FK socio_id → soci **CASCADE**; campo_id → campi_personalizzati **CASCADE**.
Tutti i valori sono memorizzati come TEXT a prescindere dal tipo_campo (cast applicativo).

### 2.17 `tags` — etichette soci

id (PK), associazione_id (NOT NULL), nome_tag VARCHAR(50) NOT NULL, colore VARCHAR(7) DEFAULT '#888888' (hex), created_at/updated_at.
**Indici/vincoli:** **UQ(associazione_id, nome_tag)**; FK associazione_id → associazioni **CASCADE**.

### 2.18 `socio_tags` — pivot socio↔tag

socio_id CHAR(36) NOT NULL, tag_id CHAR(36) NOT NULL, created_at DATETIME.
**Indici/vincoli:** **PK composita (socio_id, tag_id)**; IDX tag_id, idx_socio_tags_socio_id; FK socio_id → soci **CASCADE**; tag_id → tags **CASCADE**. Nessuna colonna `id` né `associazione_id` (tenant via socio).

### 2.19 `storico_attivita_socio` — audit log per socio

| Colonna | Tipo | Null | Default |
|---|---|---|---|
| id | CHAR(36) | NO | — (PK) |
| socio_id | CHAR(36) | NO | — |
| associazione_id | CHAR(36) | NO | — |
| utente_id | CHAR(36) | SÌ | NULL (chi ha compiuto l'azione; NULL per azioni di sistema o self-service) |
| tipo_attivita | VARCHAR(50) | NO | — (codice azione, es. 'creazione', 'modifica', 'tessera_emessa', 'quota_pagata') |
| descrizione | TEXT | NO | — (testo leggibile) |
| dettagli_json | JSON | SÌ | NULL (diff/payload strutturato) |
| data_attivita | DATETIME | SÌ | CURRENT_TIMESTAMP |

**Indici/vincoli:** IDX associazione_id, utente_id, idx_storico_attivita_socio_socio_id; FK socio_id → soci **CASCADE**; associazione_id → associazioni **CASCADE**; utente_id → utenti **SET NULL**. Append-only (no updated_at).

### 2.20 `gruppi_dinamici` — gruppi a filtro salvato

id (PK), associazione_id (NOT NULL), nome_gruppo VARCHAR(100) NOT NULL, descrizione TEXT, **filtri_json JSON NOT NULL** (criteri di selezione soci: stato, tipo, categoria, tag, sede, ecc.), created_at/updated_at.
**Indici/vincoli:** **UQ(associazione_id, nome_gruppo)**; FK associazione_id → associazioni **CASCADE**.
**Regola implicita:** l'appartenenza al gruppo NON è materializzata — viene ricalcolata a runtime applicando `filtri_json` alla tabella soci (usata p.es. per invii email massivi).

### 2.21 `saved_filters` — preset filtri liste

| Colonna | Tipo | Null | Default |
|---|---|---|---|
| id | CHAR(36) | NO | — (PK) |
| associazione_id | CHAR(36) | NO | — |
| user_id | CHAR(36) | SÌ | NULL (NULL = filtro condiviso a livello associazione) |
| scope | VARCHAR(50) | NO | 'soci' (pagina/lista a cui si applica) |
| name | VARCHAR(255) | NO | — |
| params_json | LONGTEXT | NO | — (querystring/JSON dei parametri filtro) |
| created_at / updated_at | DATETIME | SÌ | CURRENT_TIMESTAMP |

**Indici/vincoli:** **UQ uniq_filter(associazione_id, user_id, scope, name)**; IDX user_id; FK associazione_id → associazioni **CASCADE**; user_id → utenti **SET NULL**.
Edge case: con user_id NULL il vincolo unique MySQL non blocca nomi duplicati (NULL ≠ NULL) — l'unicità dei filtri condivisi è applicativa.

### 2.22 `verbali` — verbali riunioni (legacy, senza FK)

| Colonna | Tipo | Null | Default |
|---|---|---|---|
| id | CHAR(36) | NO | — (PK) |
| associazione_id | CHAR(36) | NO | — (**solo indice, NESSUNA FK**) |
| titolo | VARCHAR(255) | NO | — |
| data_riunione | DATE | NO | — |
| tipo_riunione | ENUM('Assemblea Ordinaria','Assemblea Straordinaria','Consiglio Direttivo','Commissione') | NO | — |
| presenti | TEXT | SÌ | NULL |
| ordine_del_giorno | TEXT | SÌ | NULL |
| deliberazioni | TEXT | SÌ | NULL |
| allegati | VARCHAR(500) | SÌ | NULL (percorso/i file) |
| approvato | TINYINT(1) | SÌ | 0 |
| approvato_da | CHAR(36) | SÌ | NULL (riferisce utenti.id, **senza FK**) |
| data_approvazione | DATE | SÌ | NULL |
| created_at / updated_at | TIMESTAMP | SÌ | CURRENT_TIMESTAMP |

**Indici:** idx_verbali_associazione(associazione_id). **Regola implicita:** quando `approvato` → 1 vanno valorizzati `approvato_da` e `data_approvazione`.

### 2.23 `scadenze` — scadenzario/promemoria (legacy, senza FK)

| Colonna | Tipo | Null | Default |
|---|---|---|---|
| id | CHAR(36) | NO | — (PK) |
| associazione_id | CHAR(36) | NO | — (**solo indice, NESSUNA FK**) |
| titolo | VARCHAR(255) | NO | — |
| descrizione | TEXT | SÌ | NULL |
| data_scadenza | DATE | NO | — |
| tipo_scadenza | ENUM('Quote','Documenti','Eventi','Generale') | NO | — |
| priorita | ENUM('Bassa','Media','Alta','Critica') | SÌ | 'Media' |
| stato | ENUM('Attiva','Completata','Annullata') | SÌ | 'Attiva' |
| assegnato_a | CHAR(36) | SÌ | NULL (riferisce utenti.id, **senza FK**) |
| promemoria_giorni | INT | SÌ | 7 |
| created_at / updated_at | TIMESTAMP | SÌ | CURRENT_TIMESTAMP |

**Indici:** idx_scadenze_associazione(associazione_id).

### 2.24 `smtp_settings` — configurazione SMTP per associazione

| Colonna | Tipo | Null | Default | Note |
|---|---|---|---|---|
| id | CHAR(36) | NO | — | PK |
| associazione_id | CHAR(36) | NO | — | **UQ uniq_smtp_assoc** → 1 sola config per tenant |
| smtp_host | VARCHAR(255) | NO | '' | |
| smtp_port | INT | NO | 587 | |
| smtp_user | VARCHAR(255) | NO | '' | |
| smtp_pass_encrypted | TEXT | NO | — | **Password cifrata applicativamente** (mai in chiaro) |
| smtp_encryption | ENUM('tls','ssl','none') | NO | 'tls' | |
| from_email | VARCHAR(255) | NO | '' | |
| from_name | VARCHAR(255) | NO | '' | |
| reply_to_email | VARCHAR(255) | SÌ | NULL | |
| reply_to_name | VARCHAR(255) | SÌ | NULL | |
| is_verified | TINYINT(1) | SÌ | 0 | Diventa 1 dopo test invio riuscito |
| max_per_hour | INT | SÌ | 100 | Rate limit invii |
| auto_benvenuto | TINYINT(1) | SÌ | 1 | Email automatica benvenuto nuovo socio |
| auto_scadenza_tessera | TINYINT(1) | SÌ | 1 | Notifica automatica scadenza tessera |
| auto_scadenza_quota | TINYINT(1) | SÌ | 1 | Notifica automatica scadenza quota |
| auto_rinnovo_tessera | TINYINT(1) | SÌ | 0 | Email automatica a rinnovo tessera |
| auto_pagamento_quota | TINYINT(1) | SÌ | 0 | Email automatica a pagamento quota |
| last_test_at | DATETIME | SÌ | NULL | |
| created_at / updated_at | DATETIME | SÌ | CURRENT_TIMESTAMP | |

**FK:** associazione_id → associazioni **CASCADE**.

### 2.25 `email_templates` — template email per associazione

id (PK), associazione_id (NOT NULL), codice VARCHAR(50) NOT NULL (chiave logica, es. 'benvenuto', 'scadenza_tessera'), nome VARCHAR(255) NOT NULL, oggetto VARCHAR(500) NOT NULL DEFAULT '', corpo_html TEXT NOT NULL, corpo_json TEXT NULL (sorgente editor visuale GrapesJS), attivo TINYINT(1) DEFAULT 1, created_at/updated_at.
**Indici/vincoli:** **UQ uniq_template(associazione_id, codice)**; IDX idx_et_assoc; FK associazione_id → associazioni **CASCADE**.

### 2.26 `email_queue` — coda invio email

| Colonna | Tipo | Null | Default | Note |
|---|---|---|---|---|
| id | CHAR(36) | NO | — | PK |
| associazione_id | CHAR(36) | NO | — | |
| batch_id | CHAR(36) | NO | — | Raggruppa un invio massivo |
| socio_id | CHAR(36) | SÌ | NULL | FK soci **SET NULL** |
| to_email | VARCHAR(255) | NO | — | |
| to_name | VARCHAR(255) | SÌ | '' | |
| subject | VARCHAR(500) | NO | — | |
| body_html | TEXT | NO | — | HTML già renderizzato (placeholder risolti all'accodamento) |
| template_codice | VARCHAR(50) | SÌ | NULL | |
| priority | TINYINT | SÌ | 5 | Più basso = più prioritario |
| status | ENUM('pending','sending','sent','failed','cancelled') | NO | 'pending' | |
| attempts | INT | SÌ | 0 | |
| max_attempts | INT | SÌ | 3 | |
| last_attempt_at | DATETIME | SÌ | NULL | |
| error_message | TEXT | SÌ | NULL | |
| sent_at | DATETIME | SÌ | NULL | |
| created_at | DATETIME | SÌ | CURRENT_TIMESTAMP | no updated_at |

**Indici/vincoli:** IDX idx_eq_status(status, created_at) *(scansione worker)*, idx_eq_batch(batch_id), idx_eq_assoc(associazione_id), socio_id; FK associazione_id → associazioni **CASCADE**; socio_id → soci **SET NULL**.

**Regole di business del worker (implicite):** preleva i `pending` ordinati per priority/created_at, marca `sending`, incrementa `attempts`, rispetta `max_per_hour` di smtp_settings; su errore: se `attempts < max_attempts` torna `pending`, altrimenti `failed` con `error_message`; su successo: `sent` + `sent_at` + riga in `email_log`. Stato `cancelled` per annullamento batch da UI. I soci con `email_opt_out = 1` non vengono accodati negli invii massivi.

### 2.27 `email_log` — log permanente invii

id (PK), associazione_id (NOT NULL), batch_id CHAR(36) NULL, socio_id CHAR(36) NULL, to_email NOT NULL, to_name DEFAULT '', subject VARCHAR(500) NOT NULL, template_codice VARCHAR(50) NULL, status ENUM('sent','failed') NOT NULL, error_message TEXT NULL, sent_by CHAR(36) NULL (utente che ha lanciato l'invio; NULL = automatico), sent_at DATETIME DEFAULT CURRENT_TIMESTAMP.
**Indici/vincoli:** IDX idx_el_assoc, idx_el_sent_at, idx_el_batch, socio_id, sent_by; FK associazione_id → associazioni **CASCADE**; socio_id → soci **SET NULL**; sent_by → utenti **SET NULL**. Append-only: **non si registra il body** (privacy/spazio).

### 2.28 `api_keys` — chiavi REST API

| Colonna | Tipo | Null | Default | Note |
|---|---|---|---|---|
| id | CHAR(36) | NO | — | PK |
| associazione_id | CHAR(36) | SÌ | NULL | **NULL = chiave GLOBALE** (accesso cross-associazione, solo super_admin può crearle) |
| api_key | VARCHAR(64) | NO | — | **UQ globale** (token random) |
| nome | VARCHAR(255) | NO | — | Etichetta |
| permessi | JSON | NO | — | Lista permessi/scope (es. ["soci.read","tessere.write"]) |
| attiva | TINYINT(1) | SÌ | 1 | |
| ultimo_utilizzo | DATETIME | SÌ | NULL | Aggiornata a ogni richiesta autenticata |
| scadenza | DATE | SÌ | NULL | NULL = senza scadenza |
| created_at / updated_at | DATETIME | SÌ | CURRENT_TIMESTAMP | |

**Indici/vincoli:** UQ(api_key); IDX idx_api_key(api_key) *(ridondante rispetto all'unique — non replicare)*, idx_api_assoc(associazione_id); FK associazione_id → associazioni **ON DELETE SET NULL** (⚠️ cancellando l'associazione la chiave diventa globale: in Laravel valutare CASCADE o revoca esplicita).

**Validazione richiesta API (implicita):** chiave esistente AND `attiva = 1` AND (`scadenza` NULL OR ≥ oggi); il permesso richiesto deve essere in `permessi`; se `associazione_id` non NULL, accesso limitato a quel tenant.

### 2.29 `payment_gateway_settings` — config Stripe/PayPal (SOLO schema.sql, assente dal dump)

| Colonna | Tipo | Null | Default | Note |
|---|---|---|---|---|
| id | CHAR(36) | NO | — | PK |
| associazione_id | CHAR(36) | NO | — | **UQ uniq_pg_assoc** → 1 config per tenant |
| stripe_enabled | BOOLEAN | SÌ | FALSE | |
| stripe_publishable_key | VARCHAR(255) | SÌ | '' | Chiave pubblica (in chiaro) |
| stripe_secret_key_encrypted | TEXT | SÌ | '' | **Cifrata applicativamente** |
| stripe_webhook_secret_encrypted | TEXT | SÌ | '' | **Cifrata** |
| paypal_enabled | BOOLEAN | SÌ | FALSE | |
| paypal_client_id | VARCHAR(255) | SÌ | '' | |
| paypal_client_secret_encrypted | TEXT | SÌ | '' | **Cifrata** |
| paypal_mode | ENUM('sandbox','live') | SÌ | 'sandbox' | |
| paypal_webhook_id | VARCHAR(255) | SÌ | '' | |
| auto_attivazione_pagamento | BOOLEAN | SÌ | TRUE | Al pagamento riuscito: quota → 'Pagata' e socio 'In Attesa di Pagamento' → 'Attivo' automaticamente |
| valuta | VARCHAR(3) | SÌ | 'EUR' | ISO 4217 |
| created_at / updated_at | DATETIME | SÌ | CURRENT_TIMESTAMP | |

**FK:** associazione_id → associazioni **CASCADE**.
Lavora insieme alle colonne payment di `quote` (§2.10): il flusso è quota con `payment_token` → pagina pubblica di pagamento → webhook gateway → set `gateway`, `gateway_transaction_id`, `metodo_pagamento`, `data_pagamento`, stato 'Pagata'.

### 2.30 `migrations` — tracking migrazioni (sistema legacy)

id INT AUTO_INCREMENT (PK), migration_name VARCHAR(255) **UQ** NOT NULL, executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP.
Nel dump `AUTO_INCREMENT=3` → 2 migrazioni registrate in produzione. In Laravel viene sostituita dalla tabella `migrations` nativa.

---

## 3. Differenze dump reale vs schema file (riepilogo)

| # | Tabella | Differenza | Dove |
|---|---|---|---|
| 1 | `associazioni` | `campi_obbligatori_config JSON NULL` presente solo in schema.sql | manca nel dump |
| 2 | `soci.data_nascita` | dump: `DATE NOT NULL`; schema.sql: `DATE DEFAULT NULL` (resa opzionale per preiscrizione) | **conflitto di nullabilità** — in Laravel scegliere nullable (direzione evolutiva) ma gestire i dati esistenti tutti valorizzati |
| 3 | `soci` | UQ `uniq_assoc_cf(associazione_id, codice_fiscale)` presente nel dump (creato dalla migration `update_cf_unique.php`) ma **assente da schema.sql** | va reintrodotto in Laravel |
| 4 | `tessere` | `evento_creazione_id CHAR(36) NULL` + idx solo in schema.sql | manca nel dump |
| 5 | `quote` | colonne pagamento (`metodo_pagamento`, `gateway`, `gateway_transaction_id`, `payment_token`, `payment_token_expires` + idx) solo in schema.sql | mancano nel dump |
| 6 | `campi_personalizzati` | `obbligatorio_preiscrizione BOOLEAN DEFAULT FALSE` solo in schema.sql | manca nel dump |
| 7 | `payment_gateway_settings` | intera tabella solo in schema.sql | manca nel dump |
| 8 | Indici duplicati | il dump contiene coppie ridondanti: `idx_soci_assoc`+`idx_soci_associazione_id`, `idx_tessere_socio`+`idx_tessere_socio_id`, `idx_api_key`+UQ `api_key` | in Laravel tenere un solo indice per colonna |
| 9 | `email_queue`/`email_log` | il dump ha indici extra auto-generati dalle FK (`socio_id`, `sent_by`) | irrilevanti: Laravel li crea con le FK |
| 10 | `saved_filters` (migration) | la migration la crea con `DEFAULT CHARSET=utf8mb4` senza collation esplicita e FK aggiunte best-effort | usare lo standard unicode_ci |

**Interpretazione:** schema.sql ⊇ dump: rappresenta lo stato "target" con le feature più recenti (preiscrizione, pagamenti online). Il dump è lo stato effettivo dei dati da migrare. La reimplementazione Laravel deve produrre lo schema di schema.sql **più** il vincolo `uniq_assoc_cf` del dump.

---

## 4. Funzionalità e regole di business per modulo

### 4.1 Multi-tenancy e ruoli

Tre ruoli in `utenti.ruolo`:

| Ruolo | associazione_id | Capacità |
|---|---|---|
| **super_admin** | NULL | Gestisce le associazioni (CRUD su `associazioni`), crea admin, può creare **api_keys globali** (associazione_id NULL) con accesso cross-tenant; può impersonare/selezionare il tenant. Non ha tenant proprio. |
| **admin_associazione** | valorizzato | Pieno controllo del proprio tenant: soci, tessere, quote, eventi, documenti, configurazioni (smtp, gateway, template, campi custom), utenti operatore del proprio tenant. Mai accesso ad altri tenant. |
| **operatore** | valorizzato | Operatività quotidiana sul tenant (gestione soci/tessere/quote/eventi); escluse le configurazioni sensibili (SMTP, gateway pagamenti, api_keys, gestione utenti) — enforcement applicativo, non a DB. |

La sessione legacy memorizzava `associazione_id` e `user_role`: ogni pagina verificava login + presenza tenant. In Laravel: global scope/middleware sul tenant.

### 4.2 Soci — anagrafica

- **Identità per tenant:** tripla unicità per associazione: `numero_socio`, `email`, `codice_fiscale` (CF nullable: più NULL ammessi).
- **Normalizzazione CF (da `update_cf_unique.php`):** CF stringa vuota → `NULL` prima del salvataggio (per non violare l'unique su ''). In caso di deduplica storica, si conserva il record con `data_iscrizione` più vecchia.
- **Stati del ciclo di vita:** `In Attesa` (preiscrizione pubblica da approvare) → `In Attesa di Pagamento` (approvato, pagamento online pendente) → `Attivo`; stati amministrativi: `Sospeso`, `Radiato`, `Deceduto`, `Trasferito`.
- **Area soci self-service:** `password_hash` NULL = nessun accesso. Reset password: `password_reset_token` + `password_reset_expires` (verificare scadenza). Rinnovo via link email: `rinnovo_token` (indicizzato) + `rinnovo_token_expires`.
- **Email marketing:** `email_opt_out` esclude da invii massivi; `email_opt_out_token` (64 char) autentica il link di disiscrizione senza login.
- **Privacy:** `privacy_consenso` boolean, raccolto in preiscrizione mostrando `associazioni.privacy_policy_html`.
- **Audit:** ogni mutazione rilevante scrive su `storico_attivita_socio` (tipo_attivita codificato + descrizione + dettagli_json).

### 4.3 Tessere e template

- Numerazione: `numero_tessera` unico per associazione (formato libero VARCHAR(50)).
- `tipo_scadenza` snapshot per tessera (default da `associazioni.tipo_scadenza_default`): 'solare' → `data_scadenza` = 31/12 di `anno_validita`; 'annuale' → emissione + 1 anno.
- `stato` derivabile: una tessera con `data_scadenza < oggi` va considerata/aggiornata 'Scaduta' (job o calcolo a runtime). `Sospesa`/`Annullata` manuali.
- QR code (`qr_code_url`) per check-in eventi (`checkin.php` legacy).
- Template di stampa: lookup `tessera_templates` per (associazione, tipo_socio) con fallback al generico (tipo_socio_id NULL); `contenuto` con placeholder ({{nome}}, {{numero_tessera}}, ...).
- Costo tessera: `tipi_socio.costo_tessera` ?? `associazioni.costo_tessera`.

### 4.4 Quote e pagamenti online

- Quota = (socio, anno, tipo, importo, data_scadenza). Nessun vincolo DB anti-duplicato: la verifica "esiste già quota per anno/tipo" è applicativa.
- Stati: `Da Pagare` (default) → `Pagata` (con `data_pagamento`); `In Scadenza` e `Scaduta` sono **stati derivati** ricalcolati confrontando `data_scadenza` con oggi e con `associazioni.giorni_notifica_scadenza` (in Laravel: meglio accessor/scope che colonna mutata).
- Pagamento online (schema.sql): si genera `payment_token` (64 char, indicizzato) con `payment_token_expires`; la pagina pubblica del token mostra il checkout Stripe/PayPal secondo `payment_gateway_settings`; il webhook valida (`stripe_webhook_secret_encrypted` / `paypal_webhook_id`), poi setta `gateway`, `gateway_transaction_id`, `metodo_pagamento`, `data_pagamento = oggi`, `stato = 'Pagata'`; se `auto_attivazione_pagamento = 1` e il socio è 'In Attesa di Pagamento' → socio 'Attivo'. Eventuale email automatica se `smtp_settings.auto_pagamento_quota = 1`.
- Edge case: token scaduto → rifiutare pagamento; webhook duplicato → idempotenza su `gateway_transaction_id`.

### 4.5 Utenti e migrazione legacy `users`

Dalla migration `update_users_table.php` (regole storiche da NON riprodurre, ma utili per migrare i dati):
- enum `role` legacy 'admin' rinominato in 'admin_associazione';
- garanzia che esista almeno un super_admin (in assenza veniva creato `admin`/`admin@test.com` con password 'admin123' — **non riprodurre questo default in Laravel**, usare seeding sicuro/installer);
- tracking in tabella `migrations` con `migration_name` unico (idempotenza).

### 4.6 Campi personalizzati

- Definizione per tenant (`campi_personalizzati`), 9 tipi campo; `opzioni` per i select; `ordine` per il sort UI; `obbligatorio` (form admin) e — schema target — `obbligatorio_preiscrizione` (form pubblico).
- Valori in `valori_campi_personalizzati` con upsert su UQ(socio_id, campo_id); tutto TEXT, cast/validazione per tipo a livello applicativo (email valida, number numerico, date parsabile, url valido, checkbox 0/1, select ∈ opzioni).
- Cancellare un campo cancella i valori (CASCADE).

### 4.7 Tag, gruppi dinamici e filtri salvati

- Tag: nome unico per tenant + colore hex; pivot `socio_tags` con PK composita (niente duplicati by design).
- Gruppi dinamici: `filtri_json` (criteri su soci) valutato a runtime → destinatari email massive/esport. Nome unico per tenant.
- Saved filters: preset per (tenant, utente, scope, nome); user_id NULL = condiviso. Scope default 'soci' (estensibile ad altre liste).

### 4.8 Eventi e partecipazioni

- Evento per tenant con `data_evento` DATETIME.
- Partecipazione unica per (evento, socio); stati Confermato/Forse/Non Partecipa (default 'Non Partecipa'); `data_conferma` settata alla conferma — usata anche dal check-in QR (scan tessera → set Confermato + data_conferma).
- (Schema target) `tessere.evento_creazione_id` collega una tessera emessa durante un evento.

### 4.9 Documenti

- `documenti`: archivio per tenant con categoria e dimensione; append-only.
- `documenti_socio`: allegati per socio con `data_scadenza` opzionale → alimenta notifiche/scadenzario ('Documenti').
- In entrambe `caricato_da` SET NULL preserva il file alla cancellazione dell'utente. I file fisici stanno su filesystem (`percorso_file`), fuori dal DB.

### 4.10 Sistema email (smtp_settings, email_templates, email_queue, email_log)

- Una config SMTP per tenant (UQ), password cifrata, flag `is_verified` post-test, `max_per_hour` come rate limit, 5 flag di automazione (benvenuto, scadenza tessera, scadenza quota, rinnovo tessera, pagamento quota).
- Template per (tenant, codice) con HTML + JSON sorgente editor; `attivo` per disabilitare un automatismo senza cancellare.
- Coda con batch_id, priorità, retry (attempts/max_attempts), stati pending→sending→sent/failed (+cancelled); log permanente separato senza body.
- Regole: niente invio a soci `email_opt_out`; ogni invio (anche automatico) finisce in `email_log` con esito.

### 4.11 Verbali e scadenzario

- Moduli legacy senza FK: isolamento tenant solo via query (in Laravel: aggiungere FK reali su associazione_id e su assegnato_a/approvato_da → utenti SET NULL).
- Verbali: 4 tipi riunione, flusso approvazione (approvato + approvato_da + data_approvazione).
- Scadenze: 4 tipi, 4 priorità, 3 stati, `promemoria_giorni` (default 7) per l'anticipo notifica, assegnazione opzionale a un utente.

### 4.12 API keys (REST API)

- Chiavi per tenant o globali (associazione_id NULL, riservate al super_admin); `permessi` JSON come scope list; validazione: attiva + non scaduta + permesso presente + tenant coerente; `ultimo_utilizzo` aggiornato a ogni chiamata.

### 4.13 Manutenzione dati (da `update_schema_optimizations.php`)

Regola implicita storica: le descrizioni di `tipi_socio` e `categorie_socio` erano state salvate con HTML entity over-encoding (encode multiplo); la migration applica `html_entity_decode` ripetuto (max 3 passate) fino a stabilizzazione. **Lezione per Laravel:** salvare sempre testo raw nel DB ed eseguire l'escape SOLO in output (Blade lo fa di default).

---

## 5. Validazioni applicative richieste

| Campo | Validazione |
|---|---|
| Email (soci, utenti, associazioni, smtp) | formato email valido; unicità secondo i vincoli §2 |
| codice_fiscale socio | 16 char max, uppercase, '' → NULL, unico per tenant |
| partita_iva associazione | max 20 char, unica globale |
| provincia | 2 char (sigla) |
| cap | 5 char |
| colore tag | hex `#RRGGBB` (7 char) |
| importi (costo_tessera, importo quota) | decimal ≥ 0, 2 decimali |
| anno (quote, tessere) | intero plausibile (es. 2000–2100) |
| enum | accettare solo i valori elencati nel dizionario (in Laravel: backed Enum PHP + cast) |
| token (opt_out, rinnovo, payment, reset) | random sicuro 64 char; verificare sempre la relativa `*_expires` dove esiste |
| valuta | ISO 4217 3 char |
| campi custom | cast per tipo_campo; obbligatorietà secondo `obbligatorio`/`obbligatorio_preiscrizione` |
| upload documenti | salvare nome originale + percorso; calcolare `dimensione_kb` |

---

## 6. Note per l'implementazione Laravel

1. **Migrations:** ricreare lo schema partendo da schema.sql (stato target) **aggiungendo** `uniq_assoc_cf` su soci (presente solo nel dump). Risolvere il conflitto `soci.data_nascita`: dichiararla `nullable()` (direzione evolutiva del prodotto) e validarla required solo dove il flusso lo richiede.
2. **UUID:** usare `HasUuids` (`$keyType = 'string'`, `$incrementing = false`) e colonne `char(36)` per compatibilità con i dati esistenti (sono UUID v4 testuali, NON binary16).
3. **Tenancy:** trait `BelongsToAssociazione` con global scope su `associazione_id` + middleware che risolve il tenant dalla sessione/utente; super_admin bypassa lo scope esplicitamente.
4. **Auth:** due guard — `web` su `utenti` (ruoli via enum + Policies/Gates per super_admin/admin_associazione/operatore) e `soci` per l'area membri (password nullable = non registrato). Le password legacy sono bcrypt PHP `password_hash()` → compatibili con `Hash::check`.
5. **Tabella `users` legacy:** NON ricrearla; scrivere un comando di import una tantum che la riversi in `utenti` (mappando role→ruolo, username→nome).
6. **Segreti cifrati:** `smtp_pass_encrypted`, `stripe_secret_key_encrypted`, `stripe_webhook_secret_encrypted`, `paypal_client_secret_encrypted` → cast `encrypted` di Laravel; per importare i dati esistenti serve decifrare col meccanismo legacy e ricifrare (la cifratura legacy è applicativa, chiave nel config PHP).
7. **Coda email:** sostituire `email_queue` + worker custom con queue native Laravel (`database`/`redis`) + Mailable; mantenere però `email_log` come tabella di dominio (audit per tenant) e i flag/rate-limit da `smtp_settings` (Mailer dinamico per tenant via `Mail::build` o config runtime). `batch_id` → Bus::batch.
8. **Stati derivati:** non persistere 'Scaduta'/'In Scadenza' su quote e 'Scaduta' su tessere: usare accessor + scope (`scopeInScadenza($giorni)`) o scheduled command notturno se serve persistenza per ricerche.
9. **Enum:** mappare tutti gli ENUM MySQL in PHP backed enums (`StatoSocio`, `StatoTessera`, `StatoQuota`, `TipoScadenzaTessera`, `RuoloUtente`, `StatoPartecipazione`, `TipoRiunione`, `TipoScadenza`, `Priorita`, `StatoScadenza`, `SmtpEncryption`, `PaypalMode`, `EmailStatus`, `QueueStatus`, `TipoCampo`).
10. **FK mancanti:** aggiungere le FK assenti nel legacy: `verbali.associazione_id`/`scadenze.associazione_id` → associazioni CASCADE; `verbali.approvato_da`/`scadenze.assegnato_a` → utenti SET NULL (pre-pulizia dei riferimenti orfani in fase di import dati).
11. **Indici:** eliminare i duplicati storici (un solo indice per colonna); lasciare che le FK Laravel creino i propri indici.
12. **api_keys:** valutare `ON DELETE CASCADE` invece di SET NULL per evitare l'escalation involontaria a chiave globale; permessi JSON → cast `array` + Policy.
13. **MySQL unique con NULL:** ricordare che (associazione_id, codice_fiscale) e uniq_filter con user_id NULL non bloccano i duplicati con NULL — replicare i controlli a livello di validazione (Rule::unique con where) dove serve unicità logica.
14. **Webhook pagamenti:** idempotenza su `gateway_transaction_id` (unique applicativo o indice), verifica firma, transazione DB per quota+socio+log attività.
15. **Storico attività:** implementare come Observer sui model (Socio, Tessera, Quota, …) che scrive `storico_attivita_socio` (o adottare un package di audit mantenendo le colonne legacy per l'import).
16. **Import dati:** ordine di caricamento rispettando le dipendenze: associazioni → utenti/sedi/tipi_socio/categorie_socio → soci → tessere/quote/documenti_socio/valori_campi/socio_tags/storico → eventi → eventi_partecipanti → resto. Normalizzare in import: CF '' → NULL, deduplicati secondo §4.2.

---

*Documento generato dall'analisi statica di dump reale, schema installer e migrazioni runtime. Le regole marcate "implicite" derivano dal comportamento codificato nelle migrazioni e dalle convenzioni rilevate nello schema; tutto il resto è verificabile riga per riga nei file sorgente citati in testa.*
