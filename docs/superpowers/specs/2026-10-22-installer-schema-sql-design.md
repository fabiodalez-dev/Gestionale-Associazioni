# Installer web: schema SQL iniettato nel database (design)

Data: 2026-10-22. Branch: `feature/installer-schema-sql` (da `feature/piattaforma-4`).

## Intento

L'installer web (`/install`) deve funzionare come quello di Pinakes: un dump del database **vuoto** (`database/installer/schema.sql`) viene iniettato nel database che l'utente configura nel wizard, invece di eseguire le migration Laravel a runtime. Motivi: nessuna dipendenza da `exec()` o dal client `mysql` sugli hosting condivisi, tempi brevi e risultato identico su ogni installazione, schema verificabile prima del rilascio.

## Obiettivi

- Il wizard scrive il `.env`, inietta lo schema nel database indicato, verifica che ogni tabella dichiarata esista, allinea ruoli e permessi e passa alla creazione del super admin.
- Il dump registra le migration già applicate: `php artisan migrate` (anche nell'aggiornamento in-app) esegue solo quelle nuove.
- Il dump è generato da un comando (`installer:genera-schema`), è deterministico e un test fallisce se è indietro rispetto a `database/migrations`.
- Reinstallare su un database che contiene già le tabelle dell'app le ricrea vuote solo con conferma esplicita; le tabelle estranee non vengono toccate.

## Non obiettivi

- Dati dimostrativi o email template nel dump (restano nel `DemoSeeder`, opzionale nel wizard).
- Supporto a database diversi da MySQL/MariaDB.
- Import via client `mysql` con fallback PDO come in Pinakes: qui il formato del dump è controllato, quindi basta PDO.

## Come funziona

1. `php artisan installer:genera-schema` crea `<nome>_schema_tmp` con le credenziali della connessione MySQL configurata, punta temporaneamente la connessione su quel database, esegue `migrate`, legge ogni tabella con `SHOW CREATE TABLE` (senza `AUTO_INCREMENT=`) e le righe di `migrations`, scrive il file e cancella il database temporaneo. La cache va in memoria durante la generazione perché il seeder dei permessi la svuota.
2. Formato del file: commenti `--`, ogni istruzione termina con `;` a fine riga, `SET FOREIGN_KEY_CHECKS = 0` all'inizio e `= 1` alla fine, `DROP TABLE IF EXISTS` + `CREATE TABLE` per ogni tabella in ordine alfabetico, un solo `INSERT INTO migrations`.
3. `App\Services\Installer\SchemaSql` (puro, senza database): divide il dump in istruzioni, estrae i nomi delle tabelle (rifiutando parsing parziali e duplicati) e delle migration registrate.
4. `InstallerService::installaSchema()` sostituisce `runMigrations()`: riconfigura la connessione `mysql` a runtime, esegue ogni istruzione con `unprepared`, riapre la connessione e confronta le tabelle presenti con quelle attese, poi lancia `RolesSeeder`. Ogni errore diventa un `RuntimeException` leggibile mostrato nel form del database.

## Verifica

- `tests/Feature/Installer/SchemaSqlTest.php`: parsing e allineamento dump/migration (gira su SQLite in memoria, sempre).
- `tests/Feature/Installer/InstallaSchemaMysqlTest.php`: iniezione reale su MySQL, `migrate` senza nulla da fare, creazione admin, reinstallazione con tabella estranea. Si attiva con `INSTALLER_TEST_MYSQL_DSN`.
- Prova manuale del wizard nel browser su un database inesistente creato dal wizard.
