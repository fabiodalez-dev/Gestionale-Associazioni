# Aggiornamenti: come si pubblica una versione e come la riceve un'installazione

Il sistema di aggiornamento è modellato su quello di [Pinakes](https://github.com/fabiodalez-dev/Pinakes) (`app/Support/Updater.php`, `docs/CREATING_UPDATES.md`), adattato a Laravel. Questo documento è l'equivalente di `CREATING_UPDATES.md`.

## Le parti

| Pezzo | Dove | Cosa fa |
|---|---|---|
| Versione installata | `VERSION` | Un numero semver per riga. Lo legge `config('update.version')` e lo sovrascrive il pacchetto. |
| Pacchetto | `bin/build-release.sh` | Costruisce `releases/gestionale-associazioni-vX.Y.Z.zip`: una cartella `gestionale-associazioni/` con l'app completa (`vendor/` di produzione, `public/build`, cartelle di runtime, `.htaccess` di protezione, nessun `.env`). |
| Pubblicazione | `.github/workflows/release.yml`, `bin/create-release.sh` | Al tag `v*` la Action costruisce il pacchetto con PHP 8.3, controlla che `VERSION` coincida con il tag e carica zip e `.sha256` nella GitHub Release. Lo script locale fa i controlli, crea il tag, attende la Action e verifica gli asset. |
| Updater | `app/Services/Update/UpdateService.php` | Controllo release, preflight, backup, download verificato, copia, migration, rollback. |
| Interfaccia | `/aggiornamenti` (super admin), `php artisan app:aggiorna` | Pagina con stato, requisiti, pulsante di aggiornamento, upload del pacchetto, backup, storico; comando CLI con lo stesso flusso. |
| Schema per nuove installazioni | `database/installer/schema.sql`, `php artisan installer:genera-schema` | Dump del database vuoto che l'installer web inietta. |

## Cosa fa l'updater, passo per passo

1. **Lock** esclusivo su `storage/framework/aggiornamento.lock` (flock): mai due aggiornamenti insieme. `ignore_user_abort`, nessun limite di tempo, memoria almeno 256 MB.
2. **Preflight**: PHP ≥ 8.3, estensione zip, radice/`app`/`vendor`/`public`/`bootstrap/cache`/`storage` scrivibili, spazio libero ≥ 200 MB, prova di scrittura reale (1 MB in `storage/app/updates`, perché `disk_free_space` non vede la quota dell'account su cPanel).
3. **Backup del database**: `php artisan backup:run --only-db` (spatie/laravel-backup, usa `mysqldump`); se non è disponibile, dump in PHP puro (`DumpDatabasePhp`, struttura + dati, gzip) in `storage/app/private/aggiornamenti/`. Senza backup non si aggiorna.
4. **Pacchetto**: dalla release GitHub del tag richiesto si prende l'asset `gestionale-associazioni-vX.Y.Z.zip` e il suo **digest sha256 dall'API GitHub** (campo `digest`, servito via TLS da `api.github.com`). Il download va direttamente su file, lo sha256 del file deve coincidere, altrimenti rifiuto. Niente digest = niente aggiornamento (il `.sha256` pubblicato a fianco serve a chi scarica a mano: arriva dallo stesso CDN e non proverebbe nulla). Nessun ripiego sullo zipball dei sorgenti, che non contiene `vendor/`. In alternativa, un pacchetto caricato dall'utente (pagina) o indicato da CLI: stesso flusso, senza verifica del digest.
5. **Estrazione** in `storage/app/updates/extract_*` con controllo anti zip-slip; la radice del pacchetto deve contenere `artisan`, `VERSION`, `vendor/autoload.php`, `public/index.php`, `bootstrap/app.php`; la versione nel pacchetto deve essere quella richiesta.
6. **Prova a secco**: ogni destinazione che il processo PHP non potrebbe scrivere viene elencata (con tentativo di `chmod u+w` dove il proprietario coincide) e l'aggiornamento si ferma prima di toccare un byte, suggerendo `php artisan app:aggiorna` da terminale (gira come proprietario dei file). Spazio richiesto: due volte il pacchetto estratto più 50 MB.
7. **Manutenzione** (`artisan down`), **backup dei file** che verranno sovrascritti in `storage/app/updates/filebackup_*`, **copia** del pacchetto sulla radice saltando i percorsi preservati (`config('update.preserve_paths')`: `.env`, `storage`, `public/storage`, `public/uploads`…: un file nuovo del pacchetto dentro un percorso preservato viene aggiunto, uno esistente non viene toccato), **pulizia degli orfani** nelle cartelle di codice (`config('update.orphan_dirs')`: `app`, `config`, `database`, `resources`, `routes`, `vendor`, `public/assets`, `public/build`, `bin`, `lang`).
8. **Post-installazione**: `migrate --force`, `app:aggiorna-permessi`, pulizia di config/cache/viste, `queue:restart`, `storage:link` se manca. Poi `artisan up`.
9. **Esito** in `update_logs` (`completato`, `fallito`, `rollback`). Se qualcosa fallisce dopo l'inizio della copia, i file tornano com'erano dal backup; la manutenzione viene tolta in ogni caso, anche su errore fatale (funzione di shutdown). Una manutenzione rimasta accesa da più di 30 minuti senza aggiornamento in corso si sblocca dalla pagina.

La pagina lancia l'aggiornamento via fetch e nel frattempo interroga `/aggiornamenti/stato` ogni 3 secondi: se un proxy chiude la connessione prima della fine, l'esito arriva comunque dal polling.

## Pubblicare una versione

1. Codice pronto sul branch, test verdi.
2. Se ci sono migration nuove: `php artisan installer:genera-schema` (il test `SchemaSqlTest` fallisce altrimenti).
3. Aggiorna `VERSION` (semver; con `-rc.N` è una prerelease, visibile solo alle installazioni con `UPDATE_CHANNEL=rc`).
4. Commit e push.
5. `bash bin/create-release.sh X.Y.Z`: controlla tree pulito, `VERSION`, schema allineato; crea e pusha il tag; aspetta la Action; verifica che la release abbia zip, `.sha256` e digest API coerenti.

Le note di rilascio le genera GitHub dai commit (`--generate-notes`); si possono riscrivere dalla pagina della release, la pagina `/aggiornamenti` le mostra.

## Migration e dati

Le migration sono quelle di Laravel (`database/migrations`), idempotenti per costruzione (ogni file gira una volta, registrato in `migrations`). Il pacchetto non porta file SQL per versione come Pinakes: lo stesso risultato lo dà `migrate --force` in post-installazione. Lo schema SQL serve solo alle installazioni nuove e deve restare allineato (`installer:genera-schema`).

Regole:
- mai modificare una migration già rilasciata: se ne aggiunge una nuova;
- una migration che tocca molti dati va provata su una copia del database di produzione;
- se una migration fallisce, l'updater fa il rollback dei file ma **non** del database: si ripristina il backup del passo 3 (`storage/app/private/<nome app>/*.zip` di spatie o il `.sql.gz` dell'updater), si corregge e si riprova.

## Dove guardare quando qualcosa va storto

- Storico nella pagina `/aggiornamenti` (`update_logs`: versione, esito, messaggio d'errore).
- `storage/logs/laravel.log` (voci `UpdateService:`).
- File lasciati in `storage/app/updates/` dopo un rollback fallito: `filebackup_*` contiene i file precedenti, `.voci.json` dice cosa ripristinare.
- Manutenzione accesa senza aggiornamento in corso: pulsante "Togli la manutenzione" nella pagina, oppure `php artisan up`.
- Hosting dove PHP del web server non può scrivere i file: `php artisan app:aggiorna --versione=X.Y.Z` da terminale (o `--pacchetto=/percorso/zip`), che gira come proprietario dei file. `UPDATE_SELF_ENABLED=false` nasconde il pulsante dalla pagina.
- API GitHub oltre il limite (60 richieste/ora senza token): salva un token dalla pagina (finisce in `.env` come `UPDATE_GITHUB_TOKEN`).

## Differenze rispetto a Pinakes

- Migration Laravel al posto dei file `migrate_X.Y.Z.sql`; nessun patch pre/post-update scaricato dalla release.
- Manutenzione con `artisan down`/`up` invece del file `storage/.maintenance`.
- Backup del database con spatie/laravel-backup quando c'è `mysqldump`, altrimenti dump PHP come Pinakes.
- Token GitHub nel `.env` invece che cifrato nel database.
