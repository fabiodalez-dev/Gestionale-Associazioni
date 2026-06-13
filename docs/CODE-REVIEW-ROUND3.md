# Code Review — Round 3 (Funzionalità organizzative)

Revisione adversariale del diff round 3 (`33f7ef9..c10683d`) sulle aree: volontari/turni, spazi/risorse, progetti, riunioni/decisioni, relazioni, trasversale. Tutti i findings sotto sono stati **confermati** dopo tentativo di refutazione contro il codice reale.

## Riepilogo

| Severità | Conteggio |
|---|---|
| Critical | 0 |
| High | 0 |
| Medium | 5 |
| Low | 9 |
| **Totale** | **14** |

**Giudizio complessivo:** nessun problema bloccante (critical/high). Il round 3 è funzionalmente solido sul piano di sicurezza e isolamento multi-tenant: nessun IDOR, nessun leak cross-tenant, nessuna SQL injection, nessuna escalation di privilegi in produzione. Il tema ricorrente è una classe di **race condition check-then-act (TOCTOU)** su risorse a capacità limitata (posti turno, prenotazioni spazi, disponibilità attrezzature): il codebase usa già `DB::transaction` + `lockForUpdate` altrove (PrenotazioneService, EventoApiController, NumeroProgressivoService) ma le nuove feature organizzative non lo applicano. Gli altri findings sono difetti di qualità/coerenza a impatto contenuto. Tre punti meritano priorità: la persistenza pubblica delle foto galleria anche dopo la revoca (privacy/GDPR), il mancato backfill del codice referral (feature silenziosamente non operativa sui soci esistenti), e la regressione N+1 nella lista progetti.

---

## MEDIUM

### 1. Race condition sui posti del turno in auto-candidatura
- **File:** `app/Services/VolontariTurni/CandidaturaService.php:46-67`
- **Categoria:** data-integrity · **Area:** volontari-turni
- **Rischio:** `candidati()` controlla `$turno->postiDisponibili() < 1` (COUNT separata) e poi crea l'assegnazione senza transazione né lock. Due soci distinti che si auto-candidano in parallelo all'ultimo posto leggono entrambi `postiDisponibili()==1`, superano il check e creano entrambi l'assegnazione → turno sovra-riempito oltre `posti`. L'unique `(turno_id, socio_id)` non protegge perché i soci sono distinti. Endpoint self-service in area-soci, attivabile da utenti concorrenti reali.
- **Fix:** avvolgere il check + create in `DB::transaction(fn () => ...)` con `Turno::whereKey($turno->id)->lockForUpdate()->first()` PRIMA di ricontare i posti; centralizzare in un helper condiviso con gli endpoint admin.

### 2. Race condition sull'overlap delle prenotazioni spazi
- **File:** `app/Http/Controllers/SpaziRisorse/PrenotazioneSpazioController.php:89-105` (validazione in `PrenotazioneSpazioRequest.php:61-95`)
- **Categoria:** data-integrity · **Area:** spazi-risorse
- **Rischio:** il conflitto di overlap è rilevato solo nel Form Request (`validaConflitti` → `scopeInConflittoCon`); il controller fa `create()` senza transazione né lock. Due richieste concorrenti per lo stesso spazio/intervallo passano entrambe la validazione e creano due prenotazioni sovrapposte. Nessun vincolo DB (solo indice non-unique `idx_prenotazioni_spazi_periodo`) lo impedisce → garanzia funzionale f.8 aggirabile sotto concorrenza. Stessa lacuna in `update()`.
- **Fix:** eseguire ricontrollo conflitti + `create()` dentro `DB::transaction` con `Spazio::lockForUpdate()` sulla riga spazio (lock applicativo sull'aggregato), oppure introdurre un vincolo di esclusione/serializzazione a livello DB.

### 3. Race condition sulla disponibilità attrezzature: doppio prestito oltre la quantità
- **File:** `app/Http/Controllers/SpaziRisorse/PrestitoAttrezzaturaController.php:60-69` (validazione in `PrestitoAttrezzaturaRequest.php:72-95`)
- **Categoria:** data-integrity · **Area:** spazi-risorse
- **Rischio:** `validaDisponibilita()` calcola `disponibile = quantita - SUM(prestiti attivi)` e blocca se `richiesta > disponibile`; poi il controller fa `create()` senza transazione né lock. Due prestiti concorrenti sulla stessa attrezzatura leggono la stessa disponibilità e vengono entrambi creati → pezzi in prestito oltre la quantità totale. Nessun vincolo DB protegge l'invariante `quantita_in_prestito <= quantita`.
- **Fix:** racchiudere ricontrollo disponibilità + `create()` in `DB::transaction` con `Attrezzatura::whereKey(...)->lockForUpdate()` prima di risommare i prestiti attivi.

### 4. N+1 query nella lista progetti
- **File:** `resources/views/progetti/index.blade.php:53`
- **Categoria:** performance · **Area:** progetti
- **Rischio:** il controller carica `withCount(['milestones','eventi','turni'])` ma la vista chiama `$progetto->percentualeAvanzamento()` per ogni riga (fino a 20). Quel metodo (`Progetto.php:72-83`) esegue DUE COUNT lazy su `milestones()` ignorando il `milestones_count` già eager-loaded → ~40 query extra per pagina, regressione introdotta dal round 3 che peggiora linearmente.
- **Fix:** aggiungere al `withCount` un conteggio vincolato `milestones_completate_count` (`withCount(['milestones', 'milestones as milestones_completate_count' => fn ($q) => $q->whereNotNull('completata_at')])`) e calcolare la percentuale in memoria dai due contatori, senza query nel metodo.

### 5. Foto galleria evento accessibili pubblicamente anche con `galleria_pubblica=false`
- **File:** `app/Models/Evento.php:~300` (`registerMediaCollections`, `useDisk('public')`) · toggle in `GalleriaEventoController.php:244-255`
- **Categoria:** security · **Area:** trasversale / relazioni
- **Rischio:** la collection `galleria` usa il disco `public` di Spatie → ogni foto è servita staticamente via URL diretto `/storage/{media_id}/file.jpg`, fuori da route/middleware/policy. Il flag `galleria_pubblica` controlla solo il rendering della griglia (`RelazioniPublicComposer`), NON l'accesso al file. Disattivare la galleria non rende le foto private: chi ha l'URL (o un crawler/CDN che l'ha cachato) continua a scaricarle. Il PK media è un intero sequenziale (`$table->id()`), quindi l'id è enumerabile (serve però anche il nome file originale). Esposizione privacy/GDPR per foto di soci/minori.
- **Fix:** spostare la collection su un disco privato (`useDisk('media_private')`) e servire le immagini via route autorizzata con `Storage::download`/URL firmati temporanei che rispettino `galleria_pubblica`; in alternativa, alla revoca, ruotare i path/rigenerare i media. Valutare cancellazione fisica all'unpublish se la revoca deve essere effettiva.

### 6. Nessun backfill di `codice_referral` per i soci esistenti (feature "porta un amico" non operativa)
- **File:** `database/migrations/2026_06_13_100000_add_organizzazione_foundation.php:45`
- **Categoria:** bug · **Area:** trasversale
- **Rischio:** il codice referral è generato solo in `static::creating()` di `Socio`; la migration aggiunge `codice_referral` come nullable senza popolare i soci preesistenti. In upgrade in-place su tabella `soci` già popolata, tutti i soci esistenti restano con `codice_referral = NULL`: la card "Porta un amico" non viene renderizzata per loro (il partial richiede `$slug && $codice`) e non possono mai entrare nella classifica ambasciatori (`AmbasciatoriController` filtra `whereIn('codice_referral', ...)`). Feature f.23 silenziosamente non operativa per l'intera base utenti esistente. Nota: risalvare un socio NON popola il codice (scatta solo `creating`, mai `updating`).
- **Fix:** aggiungere alla migration (o migration dedicata) un backfill che genera il codice per i soci con `codice_referral IS NULL`, riusando la stessa logica di generazione del modello e garantendo l'unicità.

---

## LOW

### 7. Stesso check-then-act sui posti negli endpoint admin `assegna()`/`conferma()`
- **File:** `app/Http/Controllers/VolontariTurni/TurnoController.php:146-149,182-185`
- **Categoria:** data-integrity · **Area:** volontari-turni
- **Rischio:** `assegna()` e `conferma()` ripetono `if ($turno->postiDisponibili() < 1)` seguito da create/update senza lock. Sovra-riempimento se due operatori agiscono in parallelo, o se un operatore conferma mentre un socio si auto-candida (auto_conferma) sullo stesso ultimo posto: i percorsi non si sincronizzano. Admin-only, tipicamente single-actor → severità minore, ma stessa classe di difetto del #1.
- **Fix:** estrarre la logica posti in un helper transazionale con `lockForUpdate` condiviso tra `CandidaturaService` e `TurnoController`, così tutti i percorsi (self-service + admin) si serializzano sullo stesso lock di turno.

### 8. `registro-ore.destroy`: autorizzazione derivata indirettamente dalla presenza della relazione `socio`
- **File:** `app/Http/Controllers/VolontariTurni/RegistroOreController.php:84-105`
- **Categoria:** quality · **Area:** volontari-turni
- **Rischio:** `destroy()` deriva il livello di permesso da `$assegnazione->socio`: presente → `authorize('update', $socio)` (richiede `soci.update`); null → fallback `authorize('viewAny', Socio::class)` (richiede solo `soci.view`). Il path null è raggiungibile: `Socio` usa SoftDeletes e la relazione `socio()` non usa `withTrashed()`, quindi un'assegnazione legata a un socio soft-deleted risolve `socio === null` (il cascade DB non scatta sul soft delete). Effetto: tale assegnazione è cancellabile col solo permesso di lettura. Nessun IDOR cross-tenant (binding tenant-scoped), ma autorizzazione incoerente.
- **Fix:** introdurre una policy dedicata su `TurnoAssegnazione`/registro e chiamare sempre `authorize('delete', $assegnazione)` con permesso esplicito coerente (es. `soci.update`/`volontari.update`), indipendente dalla presenza della relazione.

### 9. Riduzione quantità attrezzatura non atomica (variante della race sui prestiti)
- **File:** `app/Http/Controllers/SpaziRisorse/AttrezzaturaController.php:74-97` (`update`)
- **Categoria:** data-integrity · **Area:** spazi-risorse
- **Rischio:** `update()` verifica che `quantita` non scenda sotto `quantitaInPrestito()` e poi salva, senza lock né transazione. Un prestito concorrente può aumentare i pezzi in prestito tra check e save, lasciando `quantita < pezzi in prestito`. Variante/sottoinsieme della race #3, auto-risanante.
- **Fix:** eseguire check + `save()` in `DB::transaction` con `lockForUpdate` sulla riga attrezzatura, coerentemente col fix di #3.

### 10. Convocazione email inviata anche ai soci eliminati (soft-deleted)
- **File:** `app/Http/Controllers/Struttura/RiunioneController.php:160-174`
- **Categoria:** bug · **Area:** riunioni-decisioni
- **Rischio:** in `convoca()` i partecipanti sono caricati con `partecipanti.socio => withTrashed()` e poi filtrati solo su `socio !== null && filled(email)`. Un socio soft-deleted con carica ancora attiva (la cancellazione NON chiude le cariche: nessun observer; `scopeAttive` filtra solo per data) mantiene email valorizzata → riceve comunque la convocazione del direttivo. `withTrashed()` qui rimuove l'unica barriera contro l'invio ad anagrafiche cancellate. Impatto: email indesiderata a un ex-membro (privacy minore).
- **Fix:** filtrare i trashed prima di `Notification::send` (es. `->reject(fn ($s) => $s->trashed())`), mantenendo `withTrashed()` solo dove serve mostrare i nomi storici (`show()`/`generaVerbale`).

### 11. Le foto della galleria restano scaricabili dopo aver disattivato `galleria_pubblica`
- **File:** `app/Http/Controllers/Relazioni/GalleriaEventoController.php:244-255` (`toggleVisibilita`) / `app/Models/Evento.php` `useDisk('public')`
- **Categoria:** security · **Area:** relazioni
- **Rischio:** stesso difetto strutturale del #5, visto dal lato della revoca: `toggleVisibilita()` cambia solo il flag di rendering, non sposta/elimina i file fisici. Chi ha già l'URL (o un crawler) continua a scaricare le foto dopo la "occultazione" della galleria → la revoca della visibilità non è realmente efficace. Severità low perché contenuto deliberatamente pubblicato e URL non banalmente enumerabile.
- **Fix:** vedi #5 (disco privato + download autorizzato/URL firmati); in più, alla revoca, invalidare i path serviti. Il fix di #5 risolve anche questo punto.

### 12. Policy Sponsor/ContattoEsterno: `operatore` nel fallback ruoli, incoerente col seeder
- **File:** `app/Policies/SponsorPolicy.php:21` e `app/Policies/ContattoEsternoPolicy.php:21`
- **Categoria:** quality · **Area:** relazioni
- **Rischio:** entrambe dichiarano `FALLBACK = ['admin_associazione','operatore']` ma le abilities mappano sulla famiglia `struttura.*`, che il `RolesSeeder` NON assegna a `operatore`. In produzione (permessi seedati) `hasPermissionTo('struttura.view')` ritorna false e l'operatore è correttamente negato. Ma in ambienti senza permessi seedati (test, o tenant dove RolesSeeder non è girato) il `catch (\Throwable)` usa il fallback e concede a `operatore` viewAny/create/update/delete su Sponsor e ContattoEsterno — contraddicendo la matrice ruoli e le policy sorelle (`AdempimentoPolicy`/`RiunionePolicy`/`ProgettoPolicy` usano fallback admin-only).
- **Fix:** cambiare `FALLBACK` in entrambe le policy a `['admin_associazione']` (o `[Utente::RUOLO_ADMIN_ASSOCIAZIONE]`), allineandole alle altre policy `struttura.*` e all'intento del seeder.

### 13. Metodo `categorieTenant()` morto nel `ProgettoReportService`
- **File:** `app/Services/Progetti/ProgettoReportService.php:121`
- **Categoria:** quality · **Area:** progetti
- **Rischio:** il metodo pubblico introdotto nel round 3 non è referenziato da nessun controller/vista/route. Duplica la query già implementata inline in `PrimaNotaController::index` (`CategoriaMovimento::query()->orderBy('tipo')->orderBy('nome')->get()`). Nessun rischio sicurezza (tenant-scoped), ma codice morto/duplicato.
- **Fix:** rimuovere `categorieTenant()`; se la query serve in più punti, estrarla in un unico metodo riusato anche da `PrimaNotaController` invece di duplicarla.

### 14. `adempimenti:promemoria` privo di idempotenza: rischio invio duplicato in caso di re-run
- **File:** `app/Console/Commands/AdempimentiPromemoria.php:39-58`
- **Categoria:** data-integrity · **Area:** trasversale
- **Rischio:** il comando (schedulato `monthlyOn(1,'08:00')`) invia `PromemoriaAdempimentoNotification` agli admin senza tracking in `promemoria_inviati` (a differenza di `TurniPromemoria`, che deduplica). Una seconda esecuzione nello stesso mese (re-run manuale `--mese=`, retry dopo fallimento parziale di `Notification::send`, doppio cron) reinvia l'email per ogni adempimento già notificato. Il filtro `reject(isCompletatoPerAnno)` controlla lo stato di completamento, NON l'avvenuto invio: il docblock che dichiara "idempotente" è fuorviante. `withoutOverlapping` previene solo i fire concorrenti, non i re-run sequenziali.
- **Fix:** registrare l'invio in `promemoria_inviati` (chiave per tenant + adempimento + anno/mese) e saltare gli adempimenti già notificati, come fa `TurniPromemoria`.

### 15. Dedup promemoria turni: chiave di idempotenza fragile (md5 troncato a 28 bit, senza turno_id/data)
- **File:** `app/Console/Commands/TurniPromemoria.php:83-97`
- **Categoria:** bug · **Area:** trasversale
- **Rischio:** la dedup usa `step = hexdec(substr(md5(turno->id),0,7))` (~28 bit) e interroga `promemoria_inviati` per `(associazione_id, socio_id, tipo='turno', step, tessera_id NULL)`. Il `turno_id` reale e la data NON entrano nella chiave: in caso di collisione dello step tra due turni diversi dello stesso socio nello stesso tenant, il secondo promemoria viene soppresso erroneamente. Il docblock afferma "il controllo include la data del turno" ma `giaInviato()` non filtra per data. Probabilità bassa (spazio 268M, pochi turni/socio). Nota aggiuntiva: la colonna `step` è `unsignedSmallInteger` (max 65535) mentre `stepDaTurno` produce valori fino a ~268M → su MySQL strict l'INSERT può fallire DOPO l'invio email (notify precede create), rischio re-invio ai retry; su SQLite (test) il problema non emerge.
- **Fix:** includere `turno_id` (e/o la data del turno) direttamente nella chiave di dedup di `giaInviato()`/`create()`, abbandonando l'hash troncato; allineare il tipo della colonna `step` al range effettivo o salvare direttamente `turno_id`.

---

## Note di scope

- **Nessun problema critical/high confermato.** Isolamento multi-tenant (global scope `BelongsToAssociazione`) intatto su tutti i percorsi esaminati; nessun IDOR cross-tenant; nessuna SQL injection (i valori LIKE sono bound parametrici).
- **Falso allarme escluso (escape LIKE):** la ricerca in `ContattoEsternoController.php:107-112` escapa `%`/`_` con backslash ma senza clausola `ESCAPE`: su MySQL (produzione) funziona perché il backslash è l'escape di default; su SQLite (test) l'escaping è inerte. È una discrepanza di comportamento cross-DB su query con wildcard letterali, **non** una SQL injection — classificata come quality minore (vedi nota), non come vulnerabilità.

### Vettori controllati (esito ok)
- Isolamento multi-tenant / IDOR cross-tenant sui nuovi binding (turni, assegnazioni, prenotazioni, prestiti, attrezzature, riunioni, progetti, sponsor, contatti) → **ok**, tutti tenant-scoped.
- SQL injection nelle ricerche/filtri round 3 → **ok** (binding parametrici).
- Escalation privilegi in produzione (permessi seedati) → **ok** (l'incoerenza #12 si manifesta solo in ambienti non seedati/test).
- Autorizzazione CRUD admin (Policy `authorize`) sugli endpoint nuovi → **ok** sui percorsi normali (eccezione di coerenza in #8).
