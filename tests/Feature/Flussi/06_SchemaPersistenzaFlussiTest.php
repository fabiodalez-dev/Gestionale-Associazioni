<?php

namespace Tests\Feature\Flussi;

use App\Models\Account;
use App\Models\Adempimento;
use App\Models\Annuncio;
use App\Models\ApiKey;
use App\Models\Associazione;
use App\Models\Attrezzatura;
use App\Models\CampoPersonalizzato;
use App\Models\Carica;
use App\Models\CategoriaSocio;
use App\Models\Cessazione;
use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Consenso;
use App\Models\ContattoEsterno;
use App\Models\Documento;
use App\Models\EmailLog;
use App\Models\EmailQueue;
use App\Models\EmailTemplate;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Mansione;
use App\Models\MilestoneProgetto;
use App\Models\Movimento;
use App\Models\NotaSocio;
use App\Models\Nucleo;
use App\Models\OpportunitaCrm;
use App\Models\PaymentGatewaySetting;
use App\Models\Prenotazione;
use App\Models\PrenotazioneSpazio;
use App\Models\PrestitoAttrezzatura;
use App\Models\Progetto;
use App\Models\Quota;
use App\Models\Ricevuta;
use App\Models\RichiestaIscrizione;
use App\Models\RichiestaModificaDati;
use App\Models\RichiestaRegistrazione;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Scadenza;
use App\Models\Sede;
use App\Models\SmtpSetting;
use App\Models\Socio;
use App\Models\Sondaggio;
use App\Models\Spazio;
use App\Models\Sponsor;
use App\Models\StoricoAttivitaSocio;
use App\Models\Tag;
use App\Models\Tessera;
use App\Models\TesseraCounter;
use App\Models\TesseraTemplate;
use App\Models\TipoSocio;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use App\Models\Utente;
use App\Models\ValoreCampoPersonalizzato;
use App\Models\Verbale;
use App\Models\VerbaleFirmatario;
use App\Services\Installer\InstallerService;
use App\Services\Installer\SchemaSql;
use App\Services\Update\DumpDatabasePhp;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Health\ResultStores\EloquentHealthResultStore;
use Tests\TestCase;

/**
 * Flussi reali — SCHEMA E PERSISTENZA.
 *
 * Parità tra le migration e il dump dell'installer (database/installer/
 * schema.sql) su un vero MariaDB, wizard di installazione completo, round
 * trip dei form amministrativi (crea → rileggi → modifica → rileggi),
 * coerenza fillable/casts dei modelli con le colonne, cascade e set null
 * delle foreign key, vincoli unique rispettati con errori di validazione
 * (mai 500), import/export e backup reimportabile.
 *
 * I test che richiedono MariaDB/MySQL si attivano solo con la variabile
 * INSTALLER_TEST_MYSQL_DSN (es. mysql://gest:gest@127.0.0.1:3306/flussi_schema_test)
 * e usano database dedicati con "flussi_schema_test" nel nome, creati e
 * cancellati dal test. Gli altri girano sul SQLite in memoria della suite.
 */
class Flussi06_SchemaPersistenzaFlussiTest extends TestCase
{
    use RefreshDatabase;

    /** Database di prova: migration, schema.sql e reimport del backup. */
    private const DB_SCHEMA = 'flussi_schema_test';

    private const DB_MIGRATION = 'flussi_schema_test_mig';

    private const DB_RESTORE = 'flussi_schema_test_restore';

    private const DB_WIZARD = 'flussi_schema_test_wizard';

    /** Codici fiscali con checksum valido. */
    private const CF_MARIO = 'RSSMRA85T10A562S';

    private const CF_LUIGI = 'VRDLGI90A01H501G';

    private const CF_GIOVANNI = 'BNCGVN80A01F205M';

    private const CF_GIULIA = 'NREGLI95M41L219U';

    private Associazione $associazione;

    private Associazione $altraAssociazione;

    private Utente $admin;

    /** @var list<string> database MariaDB da cancellare nel tearDown */
    private array $databaseCreati = [];

    private bool $suMariaDb = false;

    /** @var list<string> file temporanei da cancellare nel tearDown */
    private array $fileTemporanei = [];

    /**
     * Confronto migration/schema.sql condiviso dai test 1-3: i due database
     * vengono costruiti una volta sola per processo (migrate su MariaDB
     * impiega diversi secondi) e cancellati a fine classe.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $confrontoSchema = null;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();

        Carbon::setTestNow('2026-03-10 10:00:00');

        // Persone vere, non bot: honeypot spento sui form pubblici
        config(['honeypot.enabled' => false]);

        $this->seed(RolesSeeder::class);

        $this->associazione = Associazione::factory()->create([
            'nome' => 'Circolo Le Radici',
            'slug' => 'circolo-radici',
            'email' => 'info@radici.test',
            'partita_iva' => '01234567890',
            'codice_fiscale' => '91234567890',
            'costo_tessera' => 20.00,
            'frontend_pubblico_attivo' => true,
        ]);

        $this->altraAssociazione = Associazione::factory()->create([
            'nome' => 'Associazione Vicina',
            'slug' => 'associazione-vicina',
            'email' => 'segreteria@vicina.test',
            'partita_iva' => '09876543210',
            'codice_fiscale' => '97654321098',
            'costo_tessera' => 15.00,
            'frontend_pubblico_attivo' => true,
        ]);

        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Marta',
            'cognome' => 'Segretaria',
        ]);
        $this->admin->assignRole('admin_associazione');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if ($this->suMariaDb) {
            // La transazione di RefreshDatabase è sulla connessione SQLite:
            // la connessione di default torna quella prima del rollback
            DB::purge('mysql');
            config(['database.default' => 'sqlite']);
            DB::setDefaultConnection('sqlite');
        }

        foreach ($this->databaseCreati as $nome) {
            $this->eliminaDatabase($nome);
        }

        foreach ($this->fileTemporanei as $file) {
            File::delete($file);
        }

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$confrontoSchema !== null) {
            $cfg = self::$confrontoSchema['cfg'];
            try {
                $pdo = new PDO(sprintf('mysql:host=%s;port=%d', $cfg['host'], $cfg['port']), $cfg['username'], $cfg['password']);
                $pdo->exec('DROP DATABASE IF EXISTS `'.self::DB_MIGRATION.'`');
                $pdo->exec('DROP DATABASE IF EXISTS `'.self::DB_SCHEMA.'`');
            } catch (\Throwable) {
                // server non più raggiungibile: niente da pulire
            }
            self::$confrontoSchema = null;
        }

        parent::tearDownAfterClass();
    }

    // ------------------------------------------------------------------
    // Attori
    // ------------------------------------------------------------------

    /** L'admin nel pannello (guard web esplicita). */
    private function comeAdmin(?Utente $admin = null): static
    {
        return $this->actingAs($admin ?? $this->admin, 'web');
    }

    /** Nessun utente autenticato (visitatore del mini-sito). */
    private function comeOspite(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function nuovoAdmin(Associazione $associazione): Utente
    {
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $associazione->id]);
        $admin->assignRole('admin_associazione');

        return $admin;
    }

    private function superAdmin(): Utente
    {
        $super = Utente::factory()->superAdmin()->create();
        $super->assignRole('super_admin');

        return $super;
    }

    /**
     * Nessuna eccezione non gestita: una violazione di vincolo o un dato
     * troppo lungo per la colonna diventano 500, mai accettabili.
     */
    private function assertNon500(TestResponse $risposta, string $contesto): void
    {
        $this->assertNotSame(
            500,
            $risposta->getStatusCode(),
            $contesto.': errore 500 '.($risposta->exception ? get_class($risposta->exception).' - '.mb_substr($risposta->exception->getMessage(), 0, 300) : '')
        );
    }

    // ------------------------------------------------------------------
    // MariaDB / MySQL
    // ------------------------------------------------------------------

    /**
     * Credenziali del server di prova da INSTALLER_TEST_MYSQL_DSN (test
     * saltato se assente) con il nome database indicato.
     *
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    private function mysqlCfg(string $database): array
    {
        $dsn = (string) env('INSTALLER_TEST_MYSQL_DSN', '');
        if ($dsn === '') {
            $this->markTestSkipped('Imposta INSTALLER_TEST_MYSQL_DSN per eseguire i flussi su MariaDB/MySQL.');
        }

        $parti = parse_url($dsn);
        if ($parti === false || ($parti['scheme'] ?? '') !== 'mysql') {
            $this->fail('INSTALLER_TEST_MYSQL_DSN non valido: atteso mysql://utente:password@host:porta/database');
        }

        $this->assertStringContainsString('test', $database, 'Per sicurezza i database di prova contengono "test" nel nome');

        return [
            'host' => $parti['host'] ?? '127.0.0.1',
            'port' => (int) ($parti['port'] ?? 3306),
            'database' => $database,
            'username' => urldecode($parti['user'] ?? 'root'),
            'password' => urldecode($parti['pass'] ?? ''),
        ];
    }

    /**
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $cfg
     */
    private function server(array $cfg, ?string $database = null): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%d%s', $cfg['host'], $cfg['port'], $database ? ';dbname='.$database : ''),
            $cfg['username'],
            $cfg['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * Crea (vuoto) un database di prova e lo registra per la pulizia.
     *
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    private function creaDatabase(string $nome, bool $registra = true): array
    {
        $cfg = $this->mysqlCfg($nome);
        $server = $this->server($cfg);
        $server->exec("DROP DATABASE IF EXISTS `{$nome}`");
        $server->exec("CREATE DATABASE `{$nome}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        if ($registra) {
            $this->databaseCreati[] = $nome;
        }

        return $cfg;
    }

    private function eliminaDatabase(string $nome): void
    {
        try {
            $this->server($this->mysqlCfg($nome))->exec("DROP DATABASE IF EXISTS `{$nome}`");
        } catch (\Throwable) {
            // pulizia best-effort
        }
    }

    /**
     * Configura la connessione mysql dell'app sul database indicato e la
     * rende quella di default (come InstallerService::configuraConnessione).
     *
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $cfg
     */
    private function puntaConnessioneMysql(array $cfg): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.url' => null,
            'database.connections.mysql.unix_socket' => '',
            'database.connections.mysql.host' => $cfg['host'],
            'database.connections.mysql.port' => $cfg['port'],
            'database.connections.mysql.database' => $cfg['database'],
            'database.connections.mysql.username' => $cfg['username'],
            'database.connections.mysql.password' => $cfg['password'],
            // La migration di spatie/health usa la connessione del suo result
            // store (DB_CONNECTION al boot, qui sqlite): segue l'app su MariaDB
            'health.result_stores.'.EloquentHealthResultStore::class.'.connection' => 'mysql',
        ]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
        $this->suMariaDb = true;
    }

    /**
     * L'intera app gira su MariaDB: database di prova migrato da zero,
     * ruoli seedati e fixture (associazione + admin) ricreate lì.
     */
    private function usaMariaDb(): void
    {
        $cfg = $this->creaDatabase(self::DB_SCHEMA);
        $this->puntaConnessioneMysql($cfg);

        Artisan::call('migrate:fresh', ['--database' => 'mysql', '--force' => true]);
        $this->seed(RolesSeeder::class);

        $this->associazione = Associazione::factory()->create([
            'nome' => 'Circolo Le Radici',
            'slug' => 'circolo-radici',
            'email' => 'info@radici.test',
            'costo_tessera' => 20.00,
            'frontend_pubblico_attivo' => true,
        ]);
        $this->altraAssociazione = Associazione::factory()->create([
            'nome' => 'Associazione Vicina',
            'slug' => 'associazione-vicina',
            'frontend_pubblico_attivo' => true,
        ]);
        $this->admin = $this->nuovoAdmin($this->associazione);
    }

    /**
     * I due database del confronto: uno costruito con `migrate`, l'altro
     * con l'import di schema.sql dell'installer. Ritorna le credenziali.
     *
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    private function preparaConfrontoSchema(): array
    {
        $cfgMig = $this->mysqlCfg(self::DB_MIGRATION);

        if (self::$confrontoSchema !== null) {
            return self::$confrontoSchema['cfg'];
        }

        $this->creaDatabase(self::DB_MIGRATION, registra: false);
        $this->puntaConnessioneMysql($cfgMig);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]), Artisan::output());

        $cfgSql = $this->creaDatabase(self::DB_SCHEMA, registra: false);
        app(InstallerService::class)->installaSchema($cfgSql);

        self::$confrontoSchema = ['cfg' => $cfgMig];

        return $cfgMig;
    }

    /**
     * Righe di information_schema per i due database del confronto.
     *
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $cfg
     * @return array{0: list<string>, 1: list<string>}
     */
    private function righeInformationSchema(array $cfg, string $sql): array
    {
        $server = $this->server($cfg);
        $risultato = [];

        foreach ([self::DB_MIGRATION, self::DB_SCHEMA] as $db) {
            $stmt = $server->prepare($sql);
            $stmt->execute([$db]);
            $righe = array_map(
                fn (array $r) => implode(' | ', array_map(fn ($v) => $v === null ? '<NULL>' : (string) $v, $r)),
                $stmt->fetchAll(PDO::FETCH_NUM)
            );
            sort($righe);
            $risultato[] = $righe;
        }

        return $risultato;
    }

    // ------------------------------------------------------------------
    // Fixture applicative
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $override
     */
    private function creaSocio(array $override = [], ?Associazione $associazione = null): Socio
    {
        return Socio::factory()->create(array_merge([
            'associazione_id' => ($associazione ?? $this->associazione)->id,
        ], $override));
    }

    private function campo(string $nome, string $tipo, ?string $opzioni = null, ?Associazione $associazione = null): CampoPersonalizzato
    {
        return CampoPersonalizzato::create([
            'associazione_id' => ($associazione ?? $this->associazione)->id,
            'nome_campo' => $nome,
            'tipo_campo' => $tipo,
            'opzioni' => $opzioni,
            'obbligatorio' => false,
            'obbligatorio_preiscrizione' => false,
            'ordine' => 1,
        ]);
    }

    /**
     * Configura il modulo pubblico /a/{slug}/iscriviti: solo i campi
     * standard indicati sono visibili (con la loro obbligatorietà).
     *
     * @param  array<string, bool>  $visibiliObbligatori  campo => obbligatorio
     */
    private function configuraModuloPubblico(Associazione $associazione, array $visibiliObbligatori): void
    {
        $campi = [];
        foreach (['telefono', 'codice_fiscale', 'data_nascita', 'indirizzo', 'citta', 'provincia', 'cap'] as $campo) {
            $campi[] = [
                'campo' => $campo,
                'visibile' => array_key_exists($campo, $visibiliObbligatori),
                'obbligatorio' => $visibiliObbligatori[$campo] ?? false,
            ];
        }

        $associazione->iscrizioni()->aggiorna([
            'iscrizioni_socio_aperte' => true,
            'modalita_iscrizione' => Associazione::MODALITA_PREISCRIZIONE,
            'iscrizione_form_config' => ['intro_html' => null, 'campi_standard' => $campi, 'campi_personalizzati' => []],
        ]);
    }

    /**
     * Dati completi e validi del form socio amministrativo.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function datiFormSocio(array $override = []): array
    {
        return array_merge([
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'email' => 'mario.rossi@radici.test',
            'data_nascita' => '1985-12-10',
            'data_iscrizione' => '2026-03-01',
            'stato' => Socio::STATO_ATTIVO,
            'telefono' => '+39 333 1234567',
            'indirizzo' => 'Via Garibaldi 12',
            'citta' => 'Pisa',
            'provincia' => 'PI',
            'cap' => '56100',
            'privacy_consenso' => '1',
            'genera_tessera' => '0',
        ], $override);
    }

    /** CSV temporaneo come file caricato (prima riga = intestazioni). */
    private function csv(array $righe, string $nome = 'soci.csv'): UploadedFile
    {
        $percorso = tempnam(sys_get_temp_dir(), 'csv_flussi06_');
        $this->fileTemporanei[] = $percorso;
        $fh = fopen($percorso, 'w');
        foreach ($righe as $riga) {
            fputcsv($fh, $riga);
        }
        fclose($fh);

        return new UploadedFile($percorso, $nome, 'text/csv', null, true);
    }

    // ==================================================================
    // 1-5. Schema su MariaDB e installer
    // ==================================================================

    public function test_su_mariadb_migration_e_schema_sql_producono_le_stesse_colonne_tipi_null_e_default(): void
    {
        $cfg = $this->preparaConfrontoSchema();

        [$tabelleMig, $tabelleSql] = $this->righeInformationSchema($cfg,
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'"
        );
        $this->assertNotEmpty($tabelleMig);
        $this->assertSame([], array_values(array_diff($tabelleMig, $tabelleSql)), 'Tabelle create dalle migration ma assenti in schema.sql');
        $this->assertSame([], array_values(array_diff($tabelleSql, $tabelleMig)), 'Tabelle di schema.sql che nessuna migration crea');
        $this->assertSame($tabelleMig, $tabelleSql);

        // L'elenco dichiarato nel dump coincide con quello reale
        $dichiarate = SchemaSql::tabelle((string) file_get_contents(config('installer.schema_file')));
        $this->assertSame($dichiarate, array_map('strtolower', $tabelleSql));

        [$colonneMig, $colonneSql] = $this->righeInformationSchema($cfg,
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?'
        );
        $this->assertSame([], array_values(array_diff($colonneMig, $colonneSql)), 'Colonne diverse (migration → schema.sql)');
        $this->assertSame([], array_values(array_diff($colonneSql, $colonneMig)), 'Colonne diverse (schema.sql → migration)');

        // Le colonne su cui i form fanno affidamento hanno la lunghezza attesa
        $this->assertContains('soci | indirizzo | varchar(255) | YES | NULL |  | utf8mb4_unicode_ci', $colonneSql);
        $this->assertContains('accounts | telefono | varchar(20) | YES | NULL |  | utf8mb4_unicode_ci', $colonneSql);
        $this->assertContains('associazioni | email | varchar(255) | NO | <NULL> |  | utf8mb4_unicode_ci', $colonneSql);
    }

    public function test_su_mariadb_indici_e_vincoli_unique_coincidono_tra_migration_e_schema_sql(): void
    {
        $cfg = $this->preparaConfrontoSchema();

        [$indiciMig, $indiciSql] = $this->righeInformationSchema($cfg,
            'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
             FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ?
             GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE'
        );
        $this->assertSame([], array_values(array_diff($indiciMig, $indiciSql)), 'Indici delle migration mancanti in schema.sql');
        $this->assertSame([], array_values(array_diff($indiciSql, $indiciMig)), 'Indici di schema.sql non creati dalle migration');

        $unique = array_values(array_filter($indiciSql, fn (string $r) => str_contains($r, ' | 0 | ')));
        $attesi = [
            'soci | soci_associazione_id_email_unique | 0 | associazione_id,email',
            'soci | soci_associazione_id_numero_socio_unique | 0 | associazione_id,numero_socio',
            'soci | uniq_assoc_cf | 0 | associazione_id,codice_fiscale',
            'tessere | tessere_associazione_id_numero_tessera_unique | 0 | associazione_id,numero_tessera',
            // Issue #21: una sola tessera non annullata per socio e anno
            'tessere | uq_tessere_socio_anno | 0 | socio_id,anno_non_annullata',
            'accounts | accounts_associazione_id_email_unique | 0 | associazione_id,email',
            'accounts | accounts_socio_id_unique | 0 | socio_id',
            'prenotazioni | prenotazioni_associazione_id_codice_unique | 0 | associazione_id,codice',
            'prenotazioni | prenotazioni_qr_token_unique | 0 | qr_token',
            'ricevute | ricevute_quota_id_unique | 0 | quota_id',
            'associazioni | associazioni_email_unique | 0 | email',
            'associazioni | associazioni_partita_iva_unique | 0 | partita_iva',
            'tessera_templates | tessera_templates_associazione_id_tipo_socio_id_unique | 0 | associazione_id,tipo_socio_id',
        ];
        foreach ($attesi as $atteso) {
            $this->assertContains($atteso, $unique, 'Vincolo unique atteso: '.$atteso);
        }
    }

    public function test_su_mariadb_le_foreign_key_e_le_regole_on_delete_coincidono_e_sono_quelle_attese_per_soci(): void
    {
        $cfg = $this->preparaConfrontoSchema();

        $sql = 'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION),
                       k.REFERENCED_TABLE_NAME, GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION),
                       r.DELETE_RULE, r.UPDATE_RULE
                FROM information_schema.KEY_COLUMN_USAGE k
                JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                  ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
                WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
                GROUP BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE, r.UPDATE_RULE';

        [$fkMig, $fkSql] = $this->righeInformationSchema($cfg, $sql);
        $this->assertNotEmpty($fkMig);
        $this->assertSame([], array_values(array_diff($fkMig, $fkSql)), 'FK delle migration mancanti o diverse in schema.sql');
        $this->assertSame([], array_values(array_diff($fkSql, $fkMig)), 'FK di schema.sql diverse dalle migration');

        // Regole on delete verso soci: [tabella.colonna => regola]
        $regole = [];
        foreach ($fkSql as $riga) {
            [$tabella, , $colonna, $riferita, , $delete] = explode(' | ', $riga);
            $regole[$tabella.'.'.$colonna.'→'.$riferita] = $delete;
        }

        foreach (['tessere', 'quote', 'consensi', 'storico_attivita_socio', 'valori_campi_personalizzati', 'eventi_partecipanti', 'cariche', 'cessazioni', 'turni_assegnazioni', 'prestiti_attrezzature', 'note_socio', 'richieste_modifica_dati'] as $tabella) {
            $this->assertSame('CASCADE', $regole[$tabella.'.socio_id→soci'] ?? null, "{$tabella}.socio_id deve seguire il socio (CASCADE)");
        }
        foreach (['accounts', 'richieste_iscrizione', 'email_log', 'email_queue', 'verbale_firmatari', 'crm_opportunita'] as $tabella) {
            $this->assertSame('SET NULL', $regole[$tabella.'.socio_id→soci'] ?? null, "{$tabella}.socio_id deve sopravvivere al socio (SET NULL)");
        }
        $this->assertSame('CASCADE', $regole['richieste_iscrizione.account_id→accounts'] ?? null);
        $this->assertSame('CASCADE', $regole['soci.associazione_id→associazioni'] ?? null);
        $this->assertSame('SET NULL', $regole['soci.tipo_socio_id→tipi_socio'] ?? null);
    }

    public function test_dopo_import_dello_schema_migrate_non_ha_nulla_da_fare_e_il_registro_migration_e_completo(): void
    {
        $cfg = $this->creaDatabase(self::DB_SCHEMA.'_import');

        $installer = app(InstallerService::class);
        $esito = $installer->testDatabase($cfg);
        $this->assertTrue($esito['ok'], (string) $esito['error']);
        $this->assertSame(0, $esito['tables']);
        $this->assertFalse($esito['installata']);

        $risultato = $installer->installaSchema($cfg);
        $this->suMariaDb = true; // installaSchema ha spostato la connessione di default

        $registrate = DB::connection('mysql')->table('migrations')->orderBy('migration')->pluck('migration')->all();
        $this->assertSame(SchemaSql::migrazioniDelProgetto(database_path('migrations')), $registrate);
        $this->assertSame(count($registrate), $risultato['migrazioni']);
        $this->assertSame(1, (int) DB::connection('mysql')->table('migrations')->max('batch'));

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertStringContainsString('Nothing to migrate', Artisan::output());
        $this->assertSame(count($registrate), DB::connection('mysql')->table('migrations')->count(), 'migrate non deve registrare nulla di nuovo');

        // Ruoli e permessi allineati dal seeder lanciato dall'installer
        $ruoli = DB::connection('mysql')->table('roles')->pluck('name')->all();
        foreach (['super_admin', 'admin_associazione', 'operatore'] as $ruolo) {
            $this->assertContains($ruolo, $ruoli);
        }
        $this->assertGreaterThan(0, DB::connection('mysql')->table('permissions')->count());
        $this->assertTrue($installer->databaseReady());
        $this->assertFalse($installer->adminExists());
    }

    public function test_wizard_installer_completo_su_mariadb_dal_test_connessione_al_lock(): void
    {
        $cfg = $this->mysqlCfg(self::DB_WIZARD);
        $this->eliminaDatabase(self::DB_WIZARD);
        $this->databaseCreati[] = self::DB_WIZARD;

        // App NON installata: lock, marcatore ed .env dedicati a questo test
        $cartella = sys_get_temp_dir().'/flussi06_wizard_'.uniqid();
        File::ensureDirectoryExists($cartella);
        $env = $cartella.'/.env';
        File::copy(base_path('.env.example'), $env);
        config([
            'installer.enforce_in_tests' => true,
            'installer.lock_file' => $cartella.'/installed.lock',
            'installer.in_progress_file' => $cartella.'/in-corso',
            'installer.env_file' => $env,
        ]);

        try {
            $this->get('/login')->assertRedirect('/install');
            $this->get('/install')->assertOk()->assertSee('Requisiti di sistema');

            $datiDb = [
                'host' => $cfg['host'], 'port' => $cfg['port'], 'database' => self::DB_WIZARD,
                'username' => $cfg['username'], 'password' => $cfg['password'], 'crea_database' => '1',
            ];

            // Test connessione AJAX: crea il database vuoto
            $this->postJson('/install/database/test', $datiDb)
                ->assertOk()
                ->assertJson(['ok' => true, 'tables' => 0, 'installata' => false]);

            // Salvataggio: schema iniettato, .env scritto, token del wizard in cookie
            $risposta = $this->post('/install/database', $datiDb);
            $risposta->assertRedirect(route('installer.admin'))->assertSessionHasNoErrors();
            $this->suMariaDb = true;
            $cookie = $risposta->getCookie('installer_token', false);
            $this->assertNotNull($cookie, 'Il wizard deve consegnare il token al browser che installa');
            $this->withUnencryptedCookie('installer_token', (string) $cookie->getValue());

            $contenutoEnv = (string) file_get_contents($env);
            $this->assertMatchesRegularExpression('/^DB_CONNECTION=mysql$/m', $contenutoEnv);
            $this->assertMatchesRegularExpression('/^DB_DATABASE='.self::DB_WIZARD.'$/m', $contenutoEnv);
            $this->assertMatchesRegularExpression('/^DB_HOST='.preg_quote($cfg['host'], '/').'$/m', $contenutoEnv);
            $this->assertMatchesRegularExpression('/^DB_USERNAME='.preg_quote($cfg['username'], '/').'$/m', $contenutoEnv);

            // Super admin + prima associazione + dati demo
            $this->get('/install/admin')->assertOk();
            $this->post('/install/admin', [
                'associazione_nome' => 'APS Prima Installazione',
                'associazione_email' => 'info@prima.test',
                'nome' => 'Ada',
                'cognome' => 'Lovelace',
                'email' => 'ada@prima.test',
                'password' => 'cavallo batteria graffetta corretta',
                'password_confirmation' => 'cavallo batteria graffetta corretta',
                'dati_demo' => '1',
            ])->assertRedirect(route('installer.email'))->assertSessionHasNoErrors();

            $db = DB::connection('mysql');
            $this->assertSame(self::DB_WIZARD, $db->getDatabaseName());
            $admin = Utente::query()->where('email', 'ada@prima.test')->first();
            $this->assertNotNull($admin);
            $this->assertSame('super_admin', $admin->ruolo);
            $this->assertTrue($admin->hasRole('super_admin'));
            $this->assertTrue($db->table('associazioni')->where('nome', 'APS Prima Installazione')->where('email', 'info@prima.test')->exists());
            $this->assertGreaterThan(0, $db->table('soci')->count(), 'I dati dimostrativi creano dei soci');
            $this->assertGreaterThan(1, $db->table('associazioni')->count(), 'I dati dimostrativi creano le associazioni demo');

            // Email SMTP di piattaforma
            $this->post('/install/email', [
                'driver' => 'smtp',
                'from_address' => 'noreply@prima.test',
                'from_name' => 'APS Prima',
                'smtp_host' => 'smtp.prima.test',
                'smtp_port' => 587,
                'smtp_username' => 'utente-smtp',
                'smtp_password' => 'segreta-smtp',
                'smtp_encryption' => 'tls',
            ])->assertRedirect(route('installer.done'));

            $contenutoEnv = (string) file_get_contents($env);
            $this->assertMatchesRegularExpression('/^MAIL_MAILER=smtp$/m', $contenutoEnv);
            $this->assertMatchesRegularExpression('/^MAIL_HOST=smtp\.prima\.test$/m', $contenutoEnv);
            $this->assertMatchesRegularExpression('/^MAIL_PORT=587$/m', $contenutoEnv);
            $this->assertMatchesRegularExpression('/^MAIL_SCHEME=smtp$/m', $contenutoEnv);
            $this->assertMatchesRegularExpression('/^MAIL_FROM_ADDRESS=noreply@prima\.test$/m', $contenutoEnv);

            // Fine: lock scritto, marcatore rimosso
            $this->get('/install/done')->assertOk();
            $this->assertFileExists($cartella.'/installed.lock');
            $this->assertFileDoesNotExist($cartella.'/in-corso');

            // Secondo passaggio del wizard: chiuso, anche via POST
            $this->get('/install')->assertRedirect('/login');
            $this->get('/install/admin')->assertRedirect('/login');
            $this->post('/install/database', $datiDb)->assertRedirect('/login');
            $this->assertSame(1, Utente::query()->where('ruolo', 'super_admin')->count());
        } finally {
            File::deleteDirectory($cartella);
        }
    }

    // ==================================================================
    // 6-8. Lunghezze delle colonne e vincoli sui form
    // ==================================================================

    public function test_su_mariadb_iscrizione_pubblica_con_indirizzo_di_300_caratteri_non_va_in_errore_di_database(): void
    {
        $this->usaMariaDb();
        $this->configuraModuloPubblico($this->associazione, ['indirizzo' => false, 'citta' => false]);

        $indirizzo = 'Via '.str_repeat('Lunghissima ', 24).'n. 1'; // 300 caratteri
        $indirizzo = mb_substr(str_pad($indirizzo, 300, 'x'), 0, 300);
        $this->assertSame(300, mb_strlen($indirizzo));

        // Il form pubblico mostra il campo indirizzo
        $this->comeOspite();
        $this->get('/a/circolo-radici/iscriviti')->assertOk()->assertSee('name="indirizzo"', false);

        // Ospite: iscrizione dal mini-sito
        $risposta = $this->post('/a/circolo-radici/iscriviti', [
            'nome' => 'Lucia',
            'cognome' => 'Lunga',
            'email' => 'lucia.lunga@ospite.test',
            'privacy' => '1',
            'indirizzo' => $indirizzo,
            'citta' => 'Lucca',
        ]);

        $this->assertNon500($risposta, 'POST /a/{slug}/iscriviti con indirizzo di 300 caratteri');
        $socio = Socio::allTenants()->where('email', 'lucia.lunga@ospite.test')->first();
        if ($socio === null) {
            $risposta->assertSessionHasErrors('indirizzo');
        } else {
            $this->assertLessThanOrEqual(255, mb_strlen((string) $socio->indirizzo));
        }

        // Stesso dato dal form pubblico di preiscrizione (/preiscrizione/{id})
        $risposta = $this->post('/preiscrizione/'.$this->associazione->id, [
            'nome' => 'Pietro',
            'cognome' => 'Lungo',
            'email' => 'pietro.lungo@ospite.test',
            'privacy_consenso' => '1',
            'indirizzo' => $indirizzo,
        ]);
        $this->assertNon500($risposta, 'POST /preiscrizione con indirizzo di 300 caratteri');
        $socio = Socio::allTenants()->where('email', 'pietro.lungo@ospite.test')->first();
        if ($socio === null) {
            // Il dato troppo lungo deve essere segnalato sul campo, non con un errore generico
            $risposta->assertSessionHasErrors('indirizzo');
        } else {
            $this->assertLessThanOrEqual(255, mb_strlen((string) $socio->indirizzo));
        }
    }

    public function test_su_mariadb_registrazione_account_con_telefono_di_25_caratteri_non_va_in_errore_di_database(): void
    {
        $this->usaMariaDb();
        $telefono = '+39 0123 456789 int. 12'; // 23 caratteri: oltre accounts.telefono varchar(20)
        $this->assertGreaterThan(20, mb_strlen($telefono));

        $this->comeOspite();
        $risposta = $this->post('/a/circolo-radici/registrati', [
            'nome' => 'Gino',
            'cognome' => 'Telefonista',
            'email' => 'gino.tel@ospite.test',
            'telefono' => $telefono,
            'password' => 'cavallo batteria graffetta corretta',
            'password_confirmation' => 'cavallo batteria graffetta corretta',
            'privacy' => '1',
        ]);

        $this->assertNon500($risposta, 'POST /a/{slug}/registrati con telefono di 23 caratteri');
        $account = Account::allTenants()->where('email', 'gino.tel@ospite.test')->first();
        if ($account === null) {
            $risposta->assertSessionHasErrors('telefono');
        } else {
            $this->assertSame($telefono, $account->telefono, 'Se accettato, il telefono va salvato per intero');
        }

        // Profilo dell'area account: stesso campo, stessa regola
        $this->comeOspite();
        $verificato = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'verificato@ospite.test',
            'telefono' => '3331234567',
        ]);
        $risposta = $this->actingAs($verificato, 'account')->patch('/a/circolo-radici/account/profilo', [
            'nome' => 'Vera',
            'cognome' => 'Verificata',
            'telefono' => $telefono,
        ]);
        $this->assertNon500($risposta, 'PATCH profilo account con telefono di 23 caratteri');
        $verificato->refresh();
        if ($verificato->telefono === '3331234567') {
            $risposta->assertSessionHasErrors('telefono');
        } else {
            $this->assertSame($telefono, $verificato->telefono);
        }
    }

    public function test_dati_anagrafici_associazione_con_email_vuota_o_duplicata_restituiscono_errore_di_validazione_e_non_500(): void
    {
        $originale = $this->associazione->fresh()->only(['nome', 'email', 'partita_iva', 'codice_fiscale']);

        $base = [
            'nome' => 'Circolo Le Radici APS',
            'partita_iva' => '01234567890',
            'codice_fiscale' => '91234567890',
        ];

        // 1. Email svuotata: la colonna è NOT NULL
        $risposta = $this->comeAdmin()->post('/configurazioni/info', $base + ['email' => '']);
        $this->assertNon500($risposta, 'email vuota');
        $risposta->assertSessionHasErrors('email');
        $this->assertSame($originale, $this->associazione->fresh()->only(['nome', 'email', 'partita_iva', 'codice_fiscale']));

        // 2. Email già usata da un'altra associazione: unique su associazioni.email
        $risposta = $this->comeAdmin()->post('/configurazioni/info', $base + ['email' => 'segreteria@vicina.test']);
        $this->assertNon500($risposta, 'email di un\'altra associazione');
        $risposta->assertSessionHasErrors('email');
        $this->assertSame($originale, $this->associazione->fresh()->only(['nome', 'email', 'partita_iva', 'codice_fiscale']));

        // 3. Partita IVA già usata da un'altra associazione
        $risposta = $this->comeAdmin()->post('/configurazioni/info', ['email' => 'info@radici.test', 'partita_iva' => '09876543210'] + $base);
        $this->assertNon500($risposta, 'partita IVA di un\'altra associazione');
        $risposta->assertSessionHasErrors('partita_iva');
        $this->assertSame($originale, $this->associazione->fresh()->only(['nome', 'email', 'partita_iva', 'codice_fiscale']));

        // 4. Dati validi completi
        $this->comeAdmin()->post('/configurazioni/info', [
            'nome' => 'Circolo Le Radici APS',
            'email' => 'presidenza@radici.test',
            'partita_iva' => '11122233344',
            'codice_fiscale' => '91234567899',
            'telefono' => '050 123456',
            'indirizzo' => 'Piazza dei Cavalieri 7',
            'citta' => 'Pisa',
            'provincia' => 'PI',
            'cap' => '56126',
        ])->assertRedirect(route('configurazioni.index', ['section' => 'info']))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('associazioni', [
            'id' => $this->associazione->id,
            'nome' => 'Circolo Le Radici APS',
            'email' => 'presidenza@radici.test',
            'partita_iva' => '11122233344',
            'codice_fiscale' => '91234567899',
            'telefono' => '050 123456',
            'indirizzo' => 'Piazza dei Cavalieri 7',
            'citta' => 'Pisa',
            'provincia' => 'PI',
            'cap' => '56126',
        ]);
        // L'altra associazione non è toccata
        $this->assertDatabaseHas('associazioni', ['id' => $this->altraAssociazione->id, 'email' => 'segreteria@vicina.test', 'partita_iva' => '09876543210']);
    }

    // ==================================================================
    // 9-16. Round trip dei form amministrativi
    // ==================================================================

    public function test_round_trip_socio_admin_con_tutti_i_campi_crea_rileggi_modifica_rileggi(): void
    {
        $tipoOrdinario = TipoSocio::create(['associazione_id' => $this->associazione->id, 'nome' => 'Ordinario', 'costo_tessera' => 20]);
        $tipoSostenitore = TipoSocio::create(['associazione_id' => $this->associazione->id, 'nome' => 'Sostenitore', 'costo_tessera' => 50]);
        $catAdulti = CategoriaSocio::create(['associazione_id' => $this->associazione->id, 'nome' => 'Adulti']);
        $catSenior = CategoriaSocio::create(['associazione_id' => $this->associazione->id, 'nome' => 'Senior']);
        $sedePisa = Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Sede Pisa']);
        $sedeLucca = Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Sede Lucca']);
        $tagCoro = Tag::create(['associazione_id' => $this->associazione->id, 'nome_tag' => 'Coro', 'colore' => '#ff0000']);
        $tagTeatro = Tag::create(['associazione_id' => $this->associazione->id, 'nome_tag' => 'Teatro', 'colore' => '#00ff00']);
        $tagGite = Tag::create(['associazione_id' => $this->associazione->id, 'nome_tag' => 'Gite', 'colore' => '#0000ff']);
        $professione = $this->campo('Professione', 'text');
        $taglia = $this->campo('Taglia maglietta', 'select', "S\nM\nL");
        $cartaceo = $this->campo('Notiziario cartaceo', 'checkbox');
        $visita = $this->campo('Visita medica', 'date');

        // 1. Creazione con ogni campo del form
        $this->comeAdmin()->post('/soci', $this->datiFormSocio([
            'numero_socio' => '0042',
            'codice_fiscale' => strtolower(self::CF_MARIO),
            'provincia' => 'pi',
            'note' => "Socio fondatore.\nRiceve le convocazioni per posta.",
            'tipo_socio_id' => $tipoOrdinario->id,
            'categoria_socio_id' => $catAdulti->id,
            'sede_id' => $sedePisa->id,
            'tag_ids' => [$tagCoro->id, $tagTeatro->id],
            'custom_field' => [
                $professione->id => '  Insegnante  ',
                $taglia->id => 'M',
                $cartaceo->id => '1',
                $visita->id => '2026-02-15',
            ],
            'genera_tessera' => '1',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $socio = Socio::query()->where('email', 'mario.rossi@radici.test')->firstOrFail();
        $this->assertDatabaseHas('soci', [
            'id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'numero_socio' => '0042',
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'codice_fiscale' => self::CF_MARIO,
            'telefono' => '+39 333 1234567',
            'indirizzo' => 'Via Garibaldi 12',
            'citta' => 'Pisa',
            'provincia' => 'PI',
            'cap' => '56100',
            'stato' => Socio::STATO_ATTIVO,
            'note' => "Socio fondatore.\nRiceve le convocazioni per posta.",
            'privacy_consenso' => true,
            'tipo_socio_id' => $tipoOrdinario->id,
            'categoria_socio_id' => $catAdulti->id,
            'sede_id' => $sedePisa->id,
        ]);
        $this->assertSame('1985-12-10', $socio->data_nascita->toDateString());
        $this->assertSame('2026-03-01', $socio->data_iscrizione->toDateString());
        $this->assertEqualsCanonicalizing([$tagCoro->id, $tagTeatro->id], $socio->tags()->pluck('tags.id')->all());
        $valori = ValoreCampoPersonalizzato::query()->where('socio_id', $socio->id)->pluck('valore', 'campo_id')->all();
        $this->assertSame(['Insegnante', 'M', '1', '2026-02-15'], [$valori[$professione->id], $valori[$taglia->id], $valori[$cartaceo->id], $valori[$visita->id]]);
        $tessera = Tessera::query()->where('socio_id', $socio->id)->firstOrFail();
        $this->assertSame(2026, (int) $tessera->anno_validita);
        $this->assertSame('2026-12-31', $tessera->data_scadenza->toDateString());
        $this->assertNotEmpty($socio->codice_referral);

        // 2. Rilettura: scheda e form di modifica mostrano i valori salvati
        $this->comeAdmin()->get('/soci/'.$socio->id)->assertOk()
            ->assertSee('Rossi')->assertSee(self::CF_MARIO)->assertSee('Insegnante')->assertSee('Coro');
        $this->comeAdmin()->get('/soci/'.$socio->id.'/modifica')->assertOk()
            ->assertSee('value="0042"', false)->assertSee('Via Garibaldi 12')->assertSee('Insegnante');

        // Dati che il form non gestisce: non devono andare persi
        $socio->forceFill(['password_hash' => 'AreaSoci-Segreta-2026', 'email_opt_out' => true])->save();
        $referral = $socio->fresh()->codice_referral;
        $hashPassword = $socio->fresh()->password_hash;

        // 3. Modifica di tutti i campi (checkbox personalizzata deselezionata)
        $this->comeAdmin()->put('/soci/'.$socio->id, [
            'numero_socio' => '0043',
            'nome' => 'Mariano',
            'cognome' => 'Rossetti',
            'email' => 'mariano.rossetti@radici.test',
            'data_nascita' => '1986-01-15',
            'data_iscrizione' => '2026-02-01',
            'stato' => Socio::STATO_SOSPESO,
            'telefono' => '+39 347 7654321',
            'indirizzo' => 'Via Fillungo 3',
            'citta' => 'Lucca',
            'provincia' => 'lu',
            'cap' => '55100',
            'codice_fiscale' => self::CF_LUIGI,
            'note' => 'Trasferito a Lucca.',
            'tipo_socio_id' => $tipoSostenitore->id,
            'categoria_socio_id' => $catSenior->id,
            'sede_id' => $sedeLucca->id,
            'tag_ids' => [$tagGite->id],
            'custom_field' => [
                $professione->id => 'Pensionato',
                $taglia->id => 'L',
                $visita->id => '2026-03-01',
            ],
        ])->assertRedirect('/soci/'.$socio->id)->assertSessionHasNoErrors();

        $socio->refresh();
        $this->assertSame(
            ['0043', 'Mariano', 'Rossetti', 'mariano.rossetti@radici.test', Socio::STATO_SOSPESO, '+39 347 7654321', 'Via Fillungo 3', 'Lucca', 'LU', '55100', self::CF_LUIGI, 'Trasferito a Lucca.', $tipoSostenitore->id, $catSenior->id, $sedeLucca->id],
            [$socio->numero_socio, $socio->nome, $socio->cognome, $socio->email, $socio->stato, $socio->telefono, $socio->indirizzo, $socio->citta, $socio->provincia, $socio->cap, $socio->codice_fiscale, $socio->note, $socio->tipo_socio_id, $socio->categoria_socio_id, $socio->sede_id]
        );
        $this->assertSame('1986-01-15', $socio->data_nascita->toDateString());
        $this->assertSame('2026-02-01', $socio->data_iscrizione->toDateString());
        $this->assertFalse($socio->privacy_consenso, 'Checkbox privacy non inviata = consenso revocato');
        $this->assertSame([$tagGite->id], $socio->tags()->pluck('tags.id')->all());
        $valori = ValoreCampoPersonalizzato::query()->where('socio_id', $socio->id)->pluck('valore', 'campo_id')->all();
        $this->assertSame(['Pensionato', 'L', '0', '2026-03-01'], [$valori[$professione->id], $valori[$taglia->id], $valori[$cartaceo->id], $valori[$visita->id]]);
        $this->assertSame(4, ValoreCampoPersonalizzato::query()->where('socio_id', $socio->id)->count(), 'Upsert: nessun valore duplicato');

        // Campi fuori dal form intatti
        $this->assertSame($referral, $socio->codice_referral);
        $this->assertSame($hashPassword, $socio->password_hash);
        $this->assertTrue($socio->email_opt_out);
        $this->assertSame(1, Tessera::query()->where('socio_id', $socio->id)->count(), 'La modifica non emette altre tessere');
        $this->assertTrue(StoricoAttivitaSocio::query()->where('socio_id', $socio->id)->where('tipo_attivita', 'Modifica Anagrafica')->exists()
            || StoricoAttivitaSocio::query()->where('socio_id', $socio->id)->count() >= 2);

        $this->comeAdmin()->get('/soci/'.$socio->id)->assertOk()->assertSee('Rossetti')->assertSee('Pensionato')->assertSee('Gite');
    }

    public function test_round_trip_evento_e_prenotazione_spazio_con_tutti_i_campi(): void
    {
        $sede = Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Sede centrale']);
        $progetto = Progetto::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Estate insieme']);

        // Spazio: creazione completa, poi modifica con campi svuotati
        $this->comeAdmin()->post('/spazi', [
            'nome' => 'Sala grande',
            'descrizione' => 'Sala al piano terra con palco.',
            'capienza' => '120',
            'sede_id' => $sede->id,
            'attivo' => '1',
        ])->assertRedirect(route('spazi.index'))->assertSessionHasNoErrors();
        $spazio = Spazio::query()->where('nome', 'Sala grande')->firstOrFail();
        $this->assertDatabaseHas('spazi', ['id' => $spazio->id, 'associazione_id' => $this->associazione->id, 'descrizione' => 'Sala al piano terra con palco.', 'capienza' => 120, 'sede_id' => $sede->id, 'attivo' => true]);

        $this->comeAdmin()->put('/spazi/'.$spazio->id, [
            'nome' => 'Sala grande (ristrutturata)', 'descrizione' => '', 'capienza' => '', 'sede_id' => '', 'attivo' => '0',
        ])->assertRedirect(route('spazi.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('spazi', ['id' => $spazio->id, 'nome' => 'Sala grande (ristrutturata)', 'descrizione' => null, 'capienza' => null, 'sede_id' => null, 'attivo' => false]);
        $spazio->update(['attivo' => true]);

        // Evento: ogni campo del form
        $this->comeAdmin()->post('/eventi', [
            'titolo' => 'Festa di primavera',
            'data_evento' => '2026-05-20 18:30',
            'data_fine' => '2026-05-20 23:15',
            'luogo' => 'Giardino della sede',
            'sede_id' => $sede->id,
            'descrizione' => 'Musica, cena e lotteria.',
            'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
            'capienza' => '80',
            'prezzo' => '12,50',
            'iscrizioni_dal' => '2026-04-01 08:00',
            'iscrizioni_al' => '2026-05-18 20:00',
            'max_posti_per_prenotazione' => '6',
            'lista_attesa_attiva' => '0',
            'iscrizioni_aperte' => '1',
            'richiede_approvazione' => '1',
            'annullamento_entro_ore' => '48',
            'solo_soci' => '1',
            'immagine_url' => 'https://radici.test/img/primavera.jpg',
            'spazio_id' => $spazio->id,
            'progetto_id' => $progetto->id,
        ])->assertRedirect(route('eventi.index'))->assertSessionHasNoErrors();

        $evento = Evento::query()->where('titolo', 'Festa di primavera')->firstOrFail();
        $this->assertSame(
            [$this->associazione->id, '2026-05-20 18:30', '2026-05-20 23:15', 'Giardino della sede', $sede->id, 'Musica, cena e lotteria.', Evento::STATO_PUBBLICATO, 80, '12.50', '2026-04-01 08:00', '2026-05-18 20:00', 6, false, true, true, 48, true, 'https://radici.test/img/primavera.jpg', $spazio->id, $progetto->id],
            [$evento->associazione_id, $evento->data_evento->format('Y-m-d H:i'), $evento->data_fine->format('Y-m-d H:i'), $evento->luogo, $evento->sede_id, $evento->descrizione, $evento->stato_pubblicazione, $evento->capienza, $evento->prezzo, $evento->iscrizioni_dal->format('Y-m-d H:i'), $evento->iscrizioni_al->format('Y-m-d H:i'), $evento->max_posti_per_prenotazione, $evento->lista_attesa_attiva, $evento->iscrizioni_aperte, $evento->richiede_approvazione, $evento->annullamento_entro_ore, $evento->solo_soci, $evento->immagine_url, $evento->spazio_id, $evento->progetto_id]
        );

        // Modifica: FK opzionali svuotate → NULL, flag invertiti, prezzo con punto
        $this->comeAdmin()->put('/eventi/'.$evento->id, [
            'titolo' => 'Festa di primavera (rinviata)',
            'data_evento' => '2026-05-27 19:00',
            'data_fine' => '',
            'luogo' => '',
            'sede_id' => '',
            'descrizione' => '',
            'stato_pubblicazione' => Evento::STATO_BOZZA,
            'capienza' => '',
            'prezzo' => '0.00',
            'iscrizioni_dal' => '',
            'iscrizioni_al' => '',
            'max_posti_per_prenotazione' => '2',
            'lista_attesa_attiva' => '1',
            'iscrizioni_aperte' => '0',
            'richiede_approvazione' => '0',
            'annullamento_entro_ore' => '0',
            'solo_soci' => '0',
            'immagine_url' => '',
            'spazio_id' => '',
            'progetto_id' => '',
        ])->assertRedirect(route('eventi.index'))->assertSessionHasNoErrors();

        $evento->refresh();
        $this->assertSame('2026-05-27 19:00', $evento->data_evento->format('Y-m-d H:i'));
        $this->assertSame(
            [null, null, null, null, null, '0.00', null, null, 2, true, false, false, 0, false, null, null, null, Evento::STATO_BOZZA],
            [$evento->data_fine, $evento->luogo, $evento->sede_id, $evento->descrizione, $evento->capienza, $evento->prezzo, $evento->iscrizioni_dal, $evento->iscrizioni_al, $evento->max_posti_per_prenotazione, $evento->lista_attesa_attiva, $evento->iscrizioni_aperte, $evento->richiede_approvazione, $evento->annullamento_entro_ore, $evento->solo_soci, $evento->immagine_url, $evento->spazio_id, $evento->progetto_id, $evento->stato_pubblicazione]
        );

        // Prenotazione interna dello spazio: crea e modifica
        $this->comeAdmin()->post('/spazi/prenotazioni', [
            'spazio_id' => $spazio->id,
            'titolo' => 'Prove del coro',
            'dal' => '2026-04-01 20:30',
            'al' => '2026-04-01 22:45',
            'note' => 'Servono le sedie impilate.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $prenotazione = PrenotazioneSpazio::query()->where('titolo', 'Prove del coro')->firstOrFail();
        $this->assertSame(
            [$this->associazione->id, $spazio->id, '2026-04-01 20:30', '2026-04-01 22:45', 'Servono le sedie impilate.', $this->admin->id],
            [$prenotazione->associazione_id, $prenotazione->spazio_id, $prenotazione->dal->format('Y-m-d H:i'), $prenotazione->al->format('Y-m-d H:i'), $prenotazione->note, $prenotazione->richiedente_id]
        );

        $this->comeAdmin()->put('/spazi/prenotazioni/'.$prenotazione->id, [
            'spazio_id' => $spazio->id,
            'titolo' => 'Prove del coro (spostate)',
            'dal' => '2026-04-02 21:00',
            'al' => '2026-04-02 23:00',
            'note' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $prenotazione->refresh();
        $this->assertSame(
            ['Prove del coro (spostate)', '2026-04-02 21:00', '2026-04-02 23:00', null, $this->admin->id],
            [$prenotazione->titolo, $prenotazione->dal->format('Y-m-d H:i'), $prenotazione->al->format('Y-m-d H:i'), $prenotazione->note, $prenotazione->richiedente_id]
        );

        $this->comeAdmin()->get('/spazi/prenotazioni?spazio='.$spazio->id)->assertOk()->assertSee('Prove del coro (spostate)');
    }

    public function test_round_trip_quota_tessera_e_template_tessera(): void
    {
        $socio = $this->creaSocio(['nome' => 'Anna', 'cognome' => 'Quotista']);
        $secondo = $this->creaSocio(['nome' => 'Bruno', 'cognome' => 'Tesserato']);
        $terzo = $this->creaSocio(['nome' => 'Carla', 'cognome' => 'Riassegnata']);
        $quarto = $this->creaSocio(['nome' => 'Dario', 'cognome' => 'Senzatessera']);
        $tipo = TipoSocio::create(['associazione_id' => $this->associazione->id, 'nome' => 'Ordinario', 'costo_tessera' => 20]);

        // Quota
        $this->comeAdmin()->post('/quote', [
            'socio_id' => $socio->id, 'anno' => '2026', 'importo' => '35.50', 'data_scadenza' => '2026-12-31', 'tipo' => 'Quota Associativa',
        ])->assertRedirect(route('quote.index'))->assertSessionHasNoErrors();
        $quota = Quota::query()->where('socio_id', $socio->id)->firstOrFail();
        $this->assertSame([$this->associazione->id, 2026, '35.50', '2026-12-31', 'Quota Associativa', Quota::STATO_DA_PAGARE],
            [$quota->associazione_id, $quota->anno, $quota->importo, $quota->data_scadenza->toDateString(), $quota->tipo, $quota->stato]);

        $this->comeAdmin()->put('/quote/'.$quota->id, [
            'socio_id' => $socio->id, 'anno' => '2027', 'importo' => '40', 'data_scadenza' => '2027-01-31', 'tipo' => 'Quota Sostenitore',
        ])->assertRedirect(route('quote.index'))->assertSessionHasNoErrors();
        $quota->refresh();
        $this->assertSame([2027, '40.00', '2027-01-31', 'Quota Sostenitore', Quota::STATO_DA_PAGARE],
            [$quota->anno, $quota->importo, $quota->data_scadenza->toDateString(), $quota->tipo, $quota->stato]);

        // Tessera con scadenza vuota: calcolata (solare → 31/12)
        $this->comeAdmin()->post('/tessere', [
            'socio_id' => $socio->id, 'numero_tessera' => '20260101', 'anno_validita' => '2026',
            'data_emissione' => '2026-03-10', 'data_scadenza' => '', 'stato' => Tessera::STATO_ATTIVA,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $prima = Tessera::query()->where('numero_tessera', '20260101')->firstOrFail();
        $this->assertSame([$socio->id, 2026, '2026-03-10', '2026-12-31', Tessera::TIPO_SCADENZA_SOLARE, Tessera::STATO_ATTIVA],
            [$prima->socio_id, (int) $prima->anno_validita, $prima->data_emissione->toDateString(), $prima->data_scadenza->toDateString(), $prima->tipo_scadenza, $prima->stato]);
        $this->assertNotEmpty($prima->qr_code_url);

        // L'associazione passa alla scadenza annuale
        $this->comeAdmin()->post('/configurazioni/tesseramento', ['tipo_scadenza_default' => 'annuale', 'giorni_notifica_scadenza' => '45'])
            ->assertSessionHasNoErrors();

        $this->comeAdmin()->post('/tessere', [
            'socio_id' => $secondo->id, 'numero_tessera' => '20260102', 'anno_validita' => '2026',
            'data_emissione' => '2026-03-10', 'data_scadenza' => '', 'stato' => Tessera::STATO_ATTIVA,
        ])->assertSessionHasNoErrors();
        $annuale = Tessera::query()->where('numero_tessera', '20260102')->firstOrFail();
        $this->assertSame(['2027-03-10', Tessera::TIPO_SCADENZA_ANNUALE], [$annuale->data_scadenza->toDateString(), $annuale->tipo_scadenza]);

        // Scadenza indicata a mano: rispettata
        $this->comeAdmin()->post('/tessere', [
            'socio_id' => $terzo->id, 'numero_tessera' => '20260103', 'anno_validita' => '2026',
            'data_emissione' => '2026-03-10', 'data_scadenza' => '2026-09-30', 'stato' => Tessera::STATO_SOSPESA,
        ])->assertSessionHasNoErrors();
        $this->assertSame('2026-09-30', Tessera::query()->where('numero_tessera', '20260103')->firstOrFail()->data_scadenza->toDateString());

        // Riassegnazione a un socio che ha già la tessera 2026: errore di
        // validazione, non un 500 sul vincolo unique socio + anno (issue #21)
        $risposta = $this->comeAdmin()->put('/tessere/'.$prima->id, [
            'socio_id' => $terzo->id, 'numero_tessera' => '20260111', 'anno_validita' => '2026',
            'data_emissione' => '2026-04-01', 'data_scadenza' => '', 'stato' => Tessera::STATO_SOSPESA,
        ]);
        $this->assertNon500($risposta, 'riassegnazione a un socio con la tessera dello stesso anno');
        $risposta->assertSessionHasErrors('anno_validita');
        $this->assertSame($socio->id, $prima->fresh()->socio_id);

        // Modifica con riassegnazione del socio e scadenza ricalcolata
        $this->comeAdmin()->put('/tessere/'.$prima->id, [
            'socio_id' => $quarto->id, 'numero_tessera' => '20260111', 'anno_validita' => '2026',
            'data_emissione' => '2026-04-01', 'data_scadenza' => '', 'stato' => Tessera::STATO_SOSPESA,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $prima->refresh();
        $this->assertSame([$quarto->id, '20260111', '2026-04-01', '2027-04-01', Tessera::TIPO_SCADENZA_ANNUALE, Tessera::STATO_SOSPESA],
            [$prima->socio_id, $prima->numero_tessera, $prima->data_emissione->toDateString(), $prima->data_scadenza->toDateString(), $prima->tipo_scadenza, $prima->stato]);

        // Numero di un'altra tessera dello stesso tenant: errore di validazione
        $this->comeAdmin()->put('/tessere/'.$prima->id, [
            'socio_id' => $quarto->id, 'numero_tessera' => '20260102', 'anno_validita' => '2026',
            'data_emissione' => '2026-04-01', 'stato' => Tessera::STATO_ATTIVA,
        ])->assertSessionHasErrors('numero_tessera');

        // Template testo tessera per tipo socio: unico per tipo
        $this->comeAdmin()->post('/tessere/templates', [
            'tipo_socio_id' => $tipo->id, 'titolo' => 'Tessera ordinaria', 'contenuto' => 'Socio {{nome}} {{cognome}}', 'attivo' => '1',
        ])->assertRedirect(route('tessere.templates.index'))->assertSessionHasNoErrors();
        $template = TesseraTemplate::query()->where('tipo_socio_id', $tipo->id)->firstOrFail();
        $this->assertSame([$this->associazione->id, 'Tessera ordinaria', 'Socio {{nome}} {{cognome}}', true],
            [$template->associazione_id, $template->titolo, $template->contenuto, (bool) $template->attivo]);

        $this->comeAdmin()->put('/tessere/templates/'.$template->id, [
            'tipo_socio_id' => $tipo->id, 'titolo' => '', 'contenuto' => 'Tessera n. {{numero_tessera}}',
        ])->assertRedirect(route('tessere.templates.index'))->assertSessionHasNoErrors();
        $template->refresh();
        $this->assertSame(['Template Tessera', 'Tessera n. {{numero_tessera}}', false], [$template->titolo, $template->contenuto, (bool) $template->attivo]);

        $risposta = $this->comeAdmin()->post('/tessere/templates', [
            'tipo_socio_id' => $tipo->id, 'titolo' => 'Doppione', 'contenuto' => 'X', 'attivo' => '1',
        ]);
        $this->assertNon500($risposta, 'template duplicato per lo stesso tipo');
        $risposta->assertSessionHasErrors('tipo_socio_id');
        $this->assertSame(1, TesseraTemplate::query()->where('tipo_socio_id', $tipo->id)->count());

        $this->comeAdmin()->get('/tessere/templates')->assertOk()->assertSee('Tessera n.');
    }

    public function test_round_trip_riunione_verbale_scadenza_adempimento_documento(): void
    {
        Storage::fake('local');
        $spazio = Spazio::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Sala consiglio']);
        $evento = Evento::factory()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Assemblea annuale']);
        $tesoriere = $this->nuovoAdmin($this->associazione);
        $consigliere = $this->creaSocio(['nome' => 'Elena', 'cognome' => 'Consigliera']);
        Carica::factory()->create(['associazione_id' => $this->associazione->id, 'socio_id' => $consigliere->id, 'ruolo' => 'Consigliere', 'inizio' => '2025-01-01', 'fine' => null]);

        // Riunione con OdG (righe vuote scartate)
        $this->comeAdmin()->post('/riunioni', [
            'titolo' => 'Consiglio di aprile',
            'data_ora' => '2026-04-15 21:00',
            'luogo' => 'Sede',
            'spazio_id' => $spazio->id,
            'stato' => Riunione::STATO_PIANIFICATA,
            'odg' => [
                ['punto' => 'Bilancio consuntivo', 'descrizione' => 'Approvazione del rendiconto'],
                ['punto' => '   ', 'descrizione' => 'riga vuota'],
                ['punto' => 'Varie ed eventuali', 'descrizione' => ''],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $riunione = Riunione::query()->where('titolo', 'Consiglio di aprile')->firstOrFail();
        $this->assertSame(
            [$this->associazione->id, '2026-04-15 21:00', 'Sede', $spazio->id, Riunione::STATO_PIANIFICATA, [['punto' => 'Bilancio consuntivo', 'descrizione' => 'Approvazione del rendiconto'], ['punto' => 'Varie ed eventuali', 'descrizione' => null]], null],
            [$riunione->associazione_id, $riunione->data_ora->format('Y-m-d H:i'), $riunione->luogo, $riunione->spazio_id, $riunione->stato, $riunione->odg, $riunione->convocazione_inviata_at]
        );

        $this->comeAdmin()->put('/riunioni/'.$riunione->id, [
            'titolo' => 'Consiglio di aprile (bis)', 'data_ora' => '2026-04-16 20:45', 'luogo' => '', 'spazio_id' => '',
            'stato' => Riunione::STATO_SVOLTA, 'odg' => [['punto' => 'Unico punto', 'descrizione' => 'Rinnovo cariche']],
        ])->assertRedirect('/riunioni/'.$riunione->id)->assertSessionHasNoErrors();
        $riunione->refresh();
        $this->assertSame(['Consiglio di aprile (bis)', '2026-04-16 20:45', null, null, Riunione::STATO_SVOLTA, [['punto' => 'Unico punto', 'descrizione' => 'Rinnovo cariche']]],
            [$riunione->titolo, $riunione->data_ora->format('Y-m-d H:i'), $riunione->luogo, $riunione->spazio_id, $riunione->stato, $riunione->odg]);

        $this->comeAdmin()->post('/riunioni/'.$riunione->id.'/convoca')->assertRedirect('/riunioni/'.$riunione->id);
        $this->assertSame('2026-03-10 10:00:00', $riunione->fresh()->convocazione_inviata_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('riunioni_partecipanti', ['riunione_id' => $riunione->id, 'socio_id' => $consigliere->id, 'carica_ruolo' => 'Consigliere']);
        $this->comeAdmin()->get('/riunioni/'.$riunione->id)->assertOk()->assertSee('Unico punto')->assertSee('Rinnovo cariche');

        // Verbali: numerazione per anno, approvazione, modifica che azzera
        foreach (['Verbale consiglio aprile', 'Verbale consiglio maggio'] as $i => $titolo) {
            $this->comeAdmin()->post('/verbali', [
                'titolo' => $titolo, 'data_riunione' => '2026-0'.(4 + $i).'-15', 'tipo_riunione' => 'Consiglio Direttivo',
                'presenti' => 'Presidente, Tesoriere', 'ordine_del_giorno' => '1. Bilancio', 'deliberazioni' => 'Approvato all\'unanimità',
                'evento_id' => $evento->id,
            ])->assertRedirect(route('verbali.index'))->assertSessionHasNoErrors();
        }
        $verbale = Verbale::query()->where('titolo', 'Verbale consiglio aprile')->firstOrFail();
        $this->assertSame([1, 2026, 'Consiglio Direttivo', 'Presidente, Tesoriere', '1. Bilancio', 'Approvato all\'unanimità', $evento->id, false],
            [$verbale->numero, $verbale->anno, $verbale->tipo_riunione, $verbale->presenti, $verbale->ordine_del_giorno, $verbale->deliberazioni, $verbale->evento_id, (bool) $verbale->approvato]);
        $this->assertSame(2, Verbale::query()->where('titolo', 'Verbale consiglio maggio')->firstOrFail()->numero);

        $this->comeAdmin()->post('/verbali/'.$verbale->id.'/approva')->assertRedirect(route('verbali.index'));
        $verbale->refresh();
        $this->assertTrue((bool) $verbale->approvato);
        $this->assertSame([$this->admin->id, '2026-03-10'], [$verbale->approvato_da, $verbale->data_approvazione->toDateString()]);

        $this->comeAdmin()->put('/verbali/'.$verbale->id, [
            'titolo' => 'Verbale consiglio aprile (rettificato)', 'data_riunione' => '2027-01-10', 'tipo_riunione' => 'Assemblea Ordinaria',
            'presenti' => '', 'ordine_del_giorno' => '', 'deliberazioni' => 'Rettifica', 'evento_id' => '',
        ])->assertRedirect(route('verbali.index'))->assertSessionHasNoErrors();
        $verbale->refresh();
        $this->assertSame([1, 2027, 'Assemblea Ordinaria', null, null, 'Rettifica', null, false, null],
            [$verbale->numero, $verbale->anno, $verbale->tipo_riunione, $verbale->presenti, $verbale->ordine_del_giorno, $verbale->deliberazioni, $verbale->evento_id, (bool) $verbale->approvato, $verbale->approvato_da]);

        // Scadenza
        $this->comeAdmin()->post('/scadenze', [
            'titolo' => 'Rinnovo assicurazione', 'descrizione' => 'Polizza RC volontari', 'data_scadenza' => '2026-06-30',
            'tipo_scadenza' => 'Documenti', 'priorita' => 'Alta', 'stato' => 'Attiva', 'assegnato_a' => $tesoriere->id, 'promemoria_giorni' => '10',
        ])->assertRedirect(route('scadenze.index'))->assertSessionHasNoErrors();
        $scadenza = Scadenza::query()->where('titolo', 'Rinnovo assicurazione')->firstOrFail();
        $this->assertSame(['Polizza RC volontari', '2026-06-30', 'Documenti', 'Alta', 'Attiva', $tesoriere->id, 10],
            [$scadenza->descrizione, $scadenza->data_scadenza->toDateString(), $scadenza->tipo_scadenza, $scadenza->priorita, $scadenza->stato, $scadenza->assegnato_a, $scadenza->promemoria_giorni]);

        $this->comeAdmin()->put('/scadenze/'.$scadenza->id, [
            'titolo' => 'Rinnovo assicurazione RC', 'descrizione' => '', 'data_scadenza' => '2026-07-15',
            'tipo_scadenza' => 'Generale', 'priorita' => 'Critica', 'stato' => 'Attiva', 'assegnato_a' => '', 'promemoria_giorni' => '3',
        ])->assertRedirect(route('scadenze.index'))->assertSessionHasNoErrors();
        $scadenza->refresh();
        $this->assertSame(['Rinnovo assicurazione RC', null, '2026-07-15', 'Generale', 'Critica', null, 3],
            [$scadenza->titolo, $scadenza->descrizione, $scadenza->data_scadenza->toDateString(), $scadenza->tipo_scadenza, $scadenza->priorita, $scadenza->assegnato_a, $scadenza->promemoria_giorni]);

        // Adempimento ricorrente
        $this->comeAdmin()->post('/adempimenti', [
            'titolo' => 'Rendiconto RUNTS', 'descrizione' => 'Deposito entro il 30 giugno', 'mese_scadenza' => '6', 'attivo' => '1',
        ])->assertRedirect(route('adempimenti.index'))->assertSessionHasNoErrors();
        $adempimento = Adempimento::query()->where('titolo', 'Rendiconto RUNTS')->firstOrFail();
        $this->assertSame([$this->associazione->id, 'Deposito entro il 30 giugno', 6, true],
            [$adempimento->associazione_id, $adempimento->descrizione, $adempimento->mese_scadenza, $adempimento->attivo]);

        $this->comeAdmin()->put('/adempimenti/'.$adempimento->id, [
            'titolo' => 'Rendiconto RUNTS annuale', 'descrizione' => '', 'mese_scadenza' => '7',
        ])->assertRedirect(route('adempimenti.index'))->assertSessionHasNoErrors();
        $adempimento->refresh();
        $this->assertSame(['Rendiconto RUNTS annuale', null, 7, false], [$adempimento->titolo, $adempimento->descrizione, $adempimento->mese_scadenza, $adempimento->attivo]);

        // Documento caricato
        $this->comeAdmin()->post('/documenti', [
            'document_file' => UploadedFile::fake()->create('statuto-2026.pdf', 120, 'application/pdf'),
            'descrizione' => 'Statuto approvato in assemblea',
            'categoria' => 'Statuto',
        ])->assertRedirect(route('documenti.index'))->assertSessionHasNoErrors();
        $documento = Documento::query()->where('nome_file', 'statuto-2026.pdf')->firstOrFail();
        $this->assertSame([$this->associazione->id, 'Statuto approvato in assemblea', 'Statuto', $this->admin->id],
            [$documento->associazione_id, $documento->descrizione, $documento->categoria, $documento->caricato_da]);
        $this->assertNotSame('', (string) $documento->percorso_file);
        $this->assertGreaterThan(0, (int) $documento->dimensione_kb);

        $this->comeAdmin()->get('/documenti')->assertOk()->assertSee('statuto-2026.pdf')->assertSee('Statuto approvato in assemblea');
        $this->comeAdmin()->get('/scadenze')->assertOk()->assertSee('Rinnovo assicurazione RC');
        $this->comeAdmin()->get('/adempimenti')->assertOk()->assertSee('Rendiconto RUNTS annuale');
    }

    public function test_round_trip_annuncio_sondaggio_template_email_smtp(): void
    {
        $evento = Evento::factory()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Assemblea dei soci']);

        // Annuncio di convocazione: HTML sanitizzato ma contenuto conservato
        $this->comeAdmin()->post('/annunci', [
            'titolo' => 'Convocazione assemblea ordinaria',
            'tipo' => Annuncio::TIPO_CONVOCAZIONE,
            'corpo_html' => '<p>Cari soci, siete <strong>convocati</strong>.</p><script>alert("x")</script>',
            'data_assemblea' => '2026-04-28 18:00',
            'luogo_assemblea' => 'Sala grande',
            'ordine_del_giorno' => "1. Bilancio\n2. Nomine",
            'evento_id' => $evento->id,
            'pubblicato_dal' => '2026-03-10 00:00',
            'pubblicato_al' => '2026-04-28 23:59',
            'in_evidenza' => '1',
            'solo_soci' => '1',
        ])->assertRedirect(route('annunci.index'))->assertSessionHasNoErrors();
        $annuncio = Annuncio::query()->where('titolo', 'Convocazione assemblea ordinaria')->firstOrFail();
        $this->assertStringContainsString('<strong>convocati</strong>', $annuncio->corpo_html);
        $this->assertStringContainsString('Cari soci', $annuncio->corpo_html);
        $this->assertStringNotContainsString('<script', $annuncio->corpo_html);
        $this->assertSame(
            [$this->associazione->id, Annuncio::TIPO_CONVOCAZIONE, '2026-04-28 18:00', 'Sala grande', "1. Bilancio\n2. Nomine", $evento->id, '2026-03-10 00:00', '2026-04-28 23:59', true, true, $this->admin->id],
            [$annuncio->associazione_id, $annuncio->tipo, $annuncio->data_assemblea->format('Y-m-d H:i'), $annuncio->luogo_assemblea, $annuncio->ordine_del_giorno, $annuncio->evento_id, $annuncio->pubblicato_dal->format('Y-m-d H:i'), $annuncio->pubblicato_al->format('Y-m-d H:i'), $annuncio->in_evidenza, $annuncio->solo_soci, $annuncio->creato_da]
        );

        // Torna comunicazione: i dettagli assemblea vengono azzerati
        $this->comeAdmin()->put('/annunci/'.$annuncio->id, [
            'titolo' => 'Comunicazione ai soci',
            'tipo' => Annuncio::TIPO_COMUNICAZIONE,
            'corpo_html' => '<p>Assemblea rinviata.</p>',
            'data_assemblea' => '2026-05-01 18:00',
            'luogo_assemblea' => 'Residuo',
            'evento_id' => $evento->id,
            'pubblicato_dal' => '',
            'pubblicato_al' => '',
        ])->assertRedirect(route('annunci.index'))->assertSessionHasNoErrors();
        $annuncio->refresh();
        $this->assertSame(['Comunicazione ai soci', Annuncio::TIPO_COMUNICAZIONE, '<p>Assemblea rinviata.</p>', null, null, null, null, null, null, false, false, $this->admin->id],
            [$annuncio->titolo, $annuncio->tipo, trim($annuncio->corpo_html), $annuncio->data_assemblea, $annuncio->luogo_assemblea, $annuncio->ordine_del_giorno, $annuncio->evento_id, $annuncio->pubblicato_dal, $annuncio->pubblicato_al, $annuncio->in_evidenza, $annuncio->solo_soci, $annuncio->creato_da]);

        // Sondaggio: opzioni da textarea → JSON pulito
        $this->comeAdmin()->post('/sondaggi', [
            'domanda' => 'Dove facciamo la gita?',
            'opzioni_testo' => "Mare\r\n\r\n  Montagna  \nLago",
            'aperto_dal' => '2026-03-10 09:00',
            'aperto_al' => '2026-03-31 23:00',
            'solo_soci' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $sondaggio = Sondaggio::query()->where('domanda', 'Dove facciamo la gita?')->firstOrFail();
        $this->assertSame([['Mare', 'Montagna', 'Lago'], '2026-03-10 09:00', '2026-03-31 23:00', true],
            [$sondaggio->opzioni, $sondaggio->aperto_dal->format('Y-m-d H:i'), $sondaggio->aperto_al->format('Y-m-d H:i'), $sondaggio->solo_soci]);
        $this->assertSame('["Mare","Montagna","Lago"]', DB::table('sondaggi')->where('id', $sondaggio->id)->value('opzioni'));

        $this->comeAdmin()->put('/sondaggi/'.$sondaggio->id, [
            'domanda' => 'Dove facciamo la gita di maggio?', 'opzioni_testo' => "Città d'arte\nCollina", 'aperto_dal' => '', 'aperto_al' => '',
        ])->assertRedirect('/sondaggi/'.$sondaggio->id)->assertSessionHasNoErrors();
        $sondaggio->refresh();
        $this->assertSame(['Dove facciamo la gita di maggio?', ["Città d'arte", 'Collina'], null, null, false],
            [$sondaggio->domanda, $sondaggio->opzioni, $sondaggio->aperto_dal, $sondaggio->aperto_al, $sondaggio->solo_soci]);

        // SMTP del tenant: primo salvataggio (password obbligatoria, cifrata)
        $this->comeAdmin()->post('/email/impostazioni', [
            'smtp_host' => 'smtp.radici.test', 'smtp_port' => '587', 'smtp_user' => 'mailer@radici.test', 'smtp_pass' => 'segreta-smtp',
            'smtp_encryption' => 'tls', 'from_email' => 'noreply@radici.test', 'from_name' => 'Circolo Le Radici',
            'reply_to_email' => 'segreteria@radici.test', 'reply_to_name' => 'Segreteria', 'max_per_hour' => '120',
            'auto_benvenuto' => '1', 'auto_scadenza_tessera' => '1', 'auto_scadenza_quota' => '0', 'auto_rinnovo_tessera' => '1',
        ])->assertRedirect(route('email-impostazioni.index'))->assertSessionHasNoErrors();
        $smtp = SmtpSetting::query()->where('associazione_id', $this->associazione->id)->firstOrFail();
        $this->assertSame(['smtp.radici.test', 587, 'mailer@radici.test', 'segreta-smtp', 'tls', 'noreply@radici.test', 'Circolo Le Radici', 'segreteria@radici.test', 'Segreteria', 120, true, true, false, true, false],
            [$smtp->smtp_host, (int) $smtp->smtp_port, $smtp->smtp_user, $smtp->smtp_pass_encrypted, $smtp->smtp_encryption, $smtp->from_email, $smtp->from_name, $smtp->reply_to_email, $smtp->reply_to_name, (int) $smtp->max_per_hour, (bool) $smtp->auto_benvenuto, (bool) $smtp->auto_scadenza_tessera, (bool) $smtp->auto_scadenza_quota, (bool) $smtp->auto_rinnovo_tessera, (bool) $smtp->auto_pagamento_quota]);
        $this->assertNotSame('segreta-smtp', DB::table('smtp_settings')->where('id', $smtp->id)->value('smtp_pass_encrypted'), 'Password SMTP cifrata a riposo');

        // Secondo salvataggio con password vuota: resta la precedente
        $this->comeAdmin()->post('/email/impostazioni', [
            'smtp_host' => 'smtp2.radici.test', 'smtp_port' => '465', 'smtp_user' => '', 'smtp_pass' => '',
            'smtp_encryption' => 'ssl', 'from_email' => 'noreply@radici.test', 'from_name' => '', 'max_per_hour' => '50',
            'auto_pagamento_quota' => '1',
        ])->assertSessionHasNoErrors();
        $smtp->refresh();
        $this->assertSame(['smtp2.radici.test', 465, '', 'segreta-smtp', 'ssl', '', null, 50, false, true],
            [$smtp->smtp_host, (int) $smtp->smtp_port, (string) $smtp->smtp_user, $smtp->smtp_pass_encrypted, $smtp->smtp_encryption, (string) $smtp->from_name, $smtp->reply_to_email, (int) $smtp->max_per_hour, (bool) $smtp->auto_benvenuto, (bool) $smtp->auto_pagamento_quota]);

        // Template email (seedati al primo salvataggio SMTP)
        $this->assertTrue(EmailTemplate::query()->where('codice', 'benvenuto')->exists());
        $this->comeAdmin()->put('/email/templates/benvenuto', [
            'oggetto' => "Benvenuto in {{associazione}}\x07",
            'corpo_html' => '<h2>Ciao {{nome}}</h2><p>Ti diamo il <em>benvenuto</em>.</p><img src="x" onerror="alert(1)">',
            'corpo_json' => '{"blocchi":[{"tipo":"testo"}]}',
            'attivo' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $template = EmailTemplate::query()->where('codice', 'benvenuto')->firstOrFail();
        $this->assertSame('Benvenuto in {{associazione}}', $template->oggetto);
        $this->assertStringContainsString('Ciao {{nome}}', $template->corpo_html);
        $this->assertStringContainsString('<em>benvenuto</em>', $template->corpo_html);
        $this->assertStringNotContainsString('onerror', $template->corpo_html);
        $this->assertSame('{"blocchi":[{"tipo":"testo"}]}', is_string($template->corpo_json) ? $template->corpo_json : json_encode($template->corpo_json));
        $this->assertTrue((bool) $template->attivo);

        $this->comeAdmin()->get('/email/templates?t=benvenuto')->assertOk()->assertSee('Benvenuto in');
    }

    public function test_round_trip_turno_assegnazione_attrezzatura_prestito_progetto_milestone(): void
    {
        $mansione = Mansione::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Cucina']);
        $evento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);
        $spazio = Spazio::factory()->create(['associazione_id' => $this->associazione->id]);
        $volontario = $this->creaSocio(['nome' => 'Vito', 'cognome' => 'Volontario']);
        $responsabile = $this->nuovoAdmin($this->associazione);

        // Progetto e milestone
        $this->comeAdmin()->post('/progetti', [
            'nome' => 'Orto sociale', 'descrizione' => 'Coltiviamo insieme.', 'dal' => '2026-03-01', 'al' => '2026-10-31',
            'responsabile_id' => $responsabile->id, 'stato' => Progetto::STATO_ATTIVO, 'budget' => '1500.5',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $progetto = Progetto::query()->where('nome', 'Orto sociale')->firstOrFail();
        $this->assertSame([$this->associazione->id, 'Coltiviamo insieme.', '2026-03-01', '2026-10-31', $responsabile->id, Progetto::STATO_ATTIVO, '1500.50'],
            [$progetto->associazione_id, $progetto->descrizione, $progetto->dal->toDateString(), $progetto->al->toDateString(), $progetto->responsabile_id, $progetto->stato, $progetto->budget]);

        $this->comeAdmin()->put('/progetti/'.$progetto->id, [
            'nome' => 'Orto sociale 2026', 'descrizione' => '', 'dal' => '', 'al' => '', 'responsabile_id' => '', 'stato' => Progetto::STATO_CONCLUSO, 'budget' => '',
        ])->assertRedirect('/progetti/'.$progetto->id)->assertSessionHasNoErrors();
        $progetto->refresh();
        $this->assertSame(['Orto sociale 2026', null, null, null, null, Progetto::STATO_CONCLUSO, null],
            [$progetto->nome, $progetto->descrizione, $progetto->dal, $progetto->al, $progetto->responsabile_id, $progetto->stato, $progetto->budget]);

        $this->comeAdmin()->post('/progetti/'.$progetto->id.'/milestone', ['titolo' => 'Semina', 'scadenza' => '2026-04-10', 'ordine' => '2'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $milestone = MilestoneProgetto::query()->where('progetto_id', $progetto->id)->firstOrFail();
        $this->assertSame([$this->associazione->id, 'Semina', '2026-04-10', 2], [$milestone->associazione_id, $milestone->titolo, $milestone->scadenza->toDateString(), (int) $milestone->ordine]);
        $this->comeAdmin()->put('/progetti/'.$progetto->id.'/milestone/'.$milestone->id, ['titolo' => 'Semina e trapianto', 'scadenza' => ''])
            ->assertRedirect()->assertSessionHasNoErrors();
        $milestone->refresh();
        $this->assertSame(['Semina e trapianto', null, 2], [$milestone->titolo, $milestone->scadenza, (int) $milestone->ordine]);

        // Turno con tutti i riferimenti, poi modifica
        $this->comeAdmin()->post('/turni', [
            'titolo' => 'Cucina della festa', 'data' => '2026-05-20', 'ora_inizio' => '17:00', 'ora_fine' => '23:30', 'posti' => '3',
            'mansione_id' => $mansione->id, 'evento_id' => $evento->id, 'spazio_id' => $spazio->id, 'progetto_id' => $progetto->id,
            'note' => 'Portare il grembiule.', 'stato' => Turno::STATO_APERTO, 'auto_conferma' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $turno = Turno::query()->where('titolo', 'Cucina della festa')->firstOrFail();
        $this->assertSame([$this->associazione->id, '2026-05-20', '17:00', '23:30', 3, $mansione->id, $evento->id, $spazio->id, $progetto->id, 'Portare il grembiule.', Turno::STATO_APERTO, true],
            [$turno->associazione_id, $turno->data->toDateString(), substr((string) $turno->ora_inizio, 0, 5), substr((string) $turno->ora_fine, 0, 5), (int) $turno->posti, $turno->mansione_id, $turno->evento_id, $turno->spazio_id, $turno->progetto_id, $turno->note, $turno->stato, (bool) $turno->auto_conferma]);

        $this->comeAdmin()->put('/turni/'.$turno->id, [
            'titolo' => 'Cucina della festa (pomeriggio)', 'data' => '2026-05-21', 'ora_inizio' => '15:00', 'ora_fine' => '19:00', 'posti' => '2',
            'mansione_id' => '', 'evento_id' => '', 'spazio_id' => '', 'progetto_id' => '', 'note' => '', 'stato' => Turno::STATO_APERTO,
        ])->assertRedirect('/turni/'.$turno->id)->assertSessionHasNoErrors();
        $turno->refresh();
        $this->assertSame(['2026-05-21', '15:00', '19:00', 2, null, null, null, null, null, false],
            [$turno->data->toDateString(), substr((string) $turno->ora_inizio, 0, 5), substr((string) $turno->ora_fine, 0, 5), (int) $turno->posti, $turno->mansione_id, $turno->evento_id, $turno->spazio_id, $turno->progetto_id, $turno->note, (bool) $turno->auto_conferma]);

        // Assegnazione confermata e ore effettive (decimal a 2 cifre)
        $this->comeAdmin()->post('/turni/'.$turno->id.'/assegna', ['socio_id' => $volontario->id, 'conferma' => '1'])
            ->assertRedirect('/turni/'.$turno->id)->assertSessionHasNoErrors();
        $assegnazione = TurnoAssegnazione::query()->where('turno_id', $turno->id)->where('socio_id', $volontario->id)->firstOrFail();
        $this->assertSame([$this->associazione->id, TurnoAssegnazione::STATO_CONFERMATO, null], [$assegnazione->associazione_id, $assegnazione->stato, $assegnazione->ore_effettive]);

        $this->comeAdmin()->post('/turni/'.$turno->id.'/presenze', ['ore' => [$assegnazione->id => '3.456']])
            ->assertRedirect('/turni/'.$turno->id)->assertSessionHasNoErrors();
        $this->assertSame('3.46', $assegnazione->fresh()->ore_effettive);
        $this->assertSame(Turno::STATO_CHIUSO, $turno->fresh()->stato);

        // Attrezzatura e prestito
        $this->comeAdmin()->post('/attrezzature', [
            'nome' => 'Gazebo 3x3', 'descrizione' => 'Gazebo pieghevole bianco', 'quantita' => '5', 'valore_unitario' => '149,90',
            'stato' => Attrezzatura::STATO_BUONO, 'spazio_id' => $spazio->id, 'ubicazione' => 'Magazzino, scaffale B',
        ])->assertRedirect(route('attrezzature.index'))->assertSessionHasNoErrors();
        $attrezzatura = Attrezzatura::query()->where('nome', 'Gazebo 3x3')->firstOrFail();
        $this->assertSame([$this->associazione->id, 'Gazebo pieghevole bianco', 5, '149.90', Attrezzatura::STATO_BUONO, $spazio->id, 'Magazzino, scaffale B'],
            [$attrezzatura->associazione_id, $attrezzatura->descrizione, (int) $attrezzatura->quantita, $attrezzatura->valore_unitario, $attrezzatura->stato, $attrezzatura->spazio_id, $attrezzatura->ubicazione]);

        $this->comeAdmin()->post('/prestiti', [
            'attrezzatura_id' => $attrezzatura->id, 'socio_id' => $volontario->id, 'quantita' => '2',
            'dal' => '2026-03-12', 'restituzione_prevista' => '2026-03-20', 'note' => 'Per la sagra di quartiere',
        ])->assertRedirect(route('prestiti.index'))->assertSessionHasNoErrors();
        $prestito = PrestitoAttrezzatura::query()->where('attrezzatura_id', $attrezzatura->id)->firstOrFail();
        $this->assertSame([$this->associazione->id, $volontario->id, 2, '2026-03-12', '2026-03-20', 'Per la sagra di quartiere', null],
            [$prestito->associazione_id, $prestito->socio_id, (int) $prestito->quantita, $prestito->dal->toDateString(), $prestito->restituzione_prevista->toDateString(), $prestito->note, $prestito->restituito_at]);

        // Non si può scendere sotto i pezzi in prestito
        $this->comeAdmin()->put('/attrezzature/'.$attrezzatura->id, [
            'nome' => 'Gazebo 3x3', 'quantita' => '1', 'stato' => Attrezzatura::STATO_BUONO,
        ])->assertSessionHasErrors('quantita');
        $this->assertSame(5, (int) $attrezzatura->fresh()->quantita);

        $this->comeAdmin()->put('/attrezzature/'.$attrezzatura->id, [
            'nome' => 'Gazebo 3x3 (bianco)', 'descrizione' => '', 'quantita' => '4', 'valore_unitario' => '', 'stato' => Attrezzatura::STATO_DA_RIPARARE, 'spazio_id' => '', 'ubicazione' => '',
        ])->assertRedirect(route('attrezzature.index'))->assertSessionHasNoErrors();
        $attrezzatura->refresh();
        $this->assertSame(['Gazebo 3x3 (bianco)', null, 4, null, Attrezzatura::STATO_DA_RIPARARE, null, null],
            [$attrezzatura->nome, $attrezzatura->descrizione, (int) $attrezzatura->quantita, $attrezzatura->valore_unitario, $attrezzatura->stato, $attrezzatura->spazio_id, $attrezzatura->ubicazione]);

        Carbon::setTestNow('2026-03-19 17:45:00');
        $this->comeAdmin()->post('/prestiti/'.$prestito->id.'/restituisci')->assertRedirect();
        $this->assertSame('2026-03-19 17:45', $prestito->fresh()->restituito_at->format('Y-m-d H:i'));
    }

    public function test_round_trip_contatto_sponsor_opportunita_crm_nucleo_carica_cessazione(): void
    {
        $responsabile = $this->nuovoAdmin($this->associazione);
        $capo = $this->creaSocio(['nome' => 'Franco', 'cognome' => 'Famiglia']);
        $figlia = $this->creaSocio(['nome' => 'Fiamma', 'cognome' => 'Famiglia']);
        $dimissionario = $this->creaSocio(['nome' => 'Dario', 'cognome' => 'Dimesso']);

        // Contatto esterno: tag da elenco separato da virgole → JSON deduplicato
        $this->comeAdmin()->post('/contatti-esterni', [
            'tipo' => ContattoEsterno::TIPO_FORNITORE, 'nome' => 'Service Audio Srl', 'referente' => 'Paolo Fonico',
            'email' => 'paolo@audio.test', 'telefono' => '+39 050 9876543 int 22', 'note' => 'Sconto soci 10%', 'tag' => 'audio, luci , audio,palco',
        ])->assertRedirect(route('contatti-esterni.index'))->assertSessionHasNoErrors();
        $contatto = ContattoEsterno::query()->where('nome', 'Service Audio Srl')->firstOrFail();
        $this->assertSame([$this->associazione->id, ContattoEsterno::TIPO_FORNITORE, 'Paolo Fonico', 'paolo@audio.test', '+39 050 9876543 int 22', 'Sconto soci 10%', ['audio', 'luci', 'palco']],
            [$contatto->associazione_id, $contatto->tipo, $contatto->referente, $contatto->email, $contatto->telefono, $contatto->note, array_values((array) $contatto->tag)]);

        $this->comeAdmin()->put('/contatti-esterni/'.$contatto->id, [
            'tipo' => ContattoEsterno::TIPO_SPONSOR, 'nome' => 'Service Audio & Luci Srl', 'referente' => '', 'email' => '', 'telefono' => '', 'note' => '', 'tag' => '',
        ])->assertRedirect(route('contatti-esterni.index'))->assertSessionHasNoErrors();
        $contatto->refresh();
        $this->assertSame([ContattoEsterno::TIPO_SPONSOR, 'Service Audio & Luci Srl', null, null, null, null, []],
            [$contatto->tipo, $contatto->nome, $contatto->referente, $contatto->email, $contatto->telefono, $contatto->note, array_values((array) $contatto->tag)]);

        // Sponsor collegato al contatto
        $this->comeAdmin()->post('/sponsor', [
            'nome' => 'Audio & Luci', 'livello' => Sponsor::LIVELLO_ORO, 'logo_url' => 'https://audio.test/logo.png', 'sito_url' => 'https://audio.test',
            'dal' => '2026-01-01', 'al' => '2026-12-31', 'visibile_sul_sito' => '1', 'contatto_esterno_id' => $contatto->id,
        ])->assertRedirect(route('sponsor.index'))->assertSessionHasNoErrors();
        $sponsor = Sponsor::query()->where('nome', 'Audio & Luci')->firstOrFail();
        $this->assertSame([$this->associazione->id, Sponsor::LIVELLO_ORO, 'https://audio.test/logo.png', 'https://audio.test', '2026-01-01', '2026-12-31', true, $contatto->id],
            [$sponsor->associazione_id, $sponsor->livello, $sponsor->logo_url, $sponsor->sito_url, $sponsor->dal->toDateString(), $sponsor->al->toDateString(), (bool) $sponsor->visibile_sul_sito, $sponsor->contatto_esterno_id]);

        $this->comeAdmin()->put('/sponsor/'.$sponsor->id, [
            'nome' => 'Audio & Luci', 'livello' => Sponsor::LIVELLO_ARGENTO, 'logo_url' => '', 'sito_url' => '', 'dal' => '', 'al' => '', 'contatto_esterno_id' => '',
        ])->assertRedirect(route('sponsor.index'))->assertSessionHasNoErrors();
        $sponsor->refresh();
        $this->assertSame([Sponsor::LIVELLO_ARGENTO, null, null, null, null, false, null],
            [$sponsor->livello, $sponsor->logo_url, $sponsor->sito_url, $sponsor->dal, $sponsor->al, (bool) $sponsor->visibile_sul_sito, $sponsor->contatto_esterno_id]);

        // Opportunità CRM: posizione progressiva, esito alla fase finale
        foreach (['Sponsor maglie', 'Sponsor palco'] as $titolo) {
            $this->comeAdmin()->post('/crm', [
                'pipeline' => 'sponsor', 'fase' => 'contatto', 'titolo' => $titolo, 'descrizione' => 'Proposta per la stagione',
                'origine' => 'evento', 'valore' => '2500.75', 'prossima_azione_il' => '2026-03-20', 'email' => 'comm@audio.test',
                'telefono' => '+39 050 1111111', 'responsabile_id' => $responsabile->id,
            ])->assertRedirect()->assertSessionHasNoErrors();
        }
        $opp = OpportunitaCrm::query()->where('titolo', 'Sponsor maglie')->firstOrFail();
        $this->assertSame([$this->associazione->id, 'sponsor', 'contatto', 'Proposta per la stagione', 'evento', '2500.75', '2026-03-20', 'comm@audio.test', '+39 050 1111111', $responsabile->id, 1, null],
            [$opp->associazione_id, $opp->pipeline, $opp->fase, $opp->descrizione, $opp->origine, (string) $opp->valore, $opp->prossima_azione_il->toDateString(), $opp->email, $opp->telefono, $opp->responsabile_id, (int) $opp->posizione, $opp->esito]);
        $this->assertSame(2, (int) OpportunitaCrm::query()->where('titolo', 'Sponsor palco')->value('posizione'));

        $this->comeAdmin()->put('/crm/'.$opp->id, [
            'fase' => 'firmato', 'titolo' => 'Sponsor maglie 2026', 'descrizione' => '', 'origine' => '', 'valore' => '', 'prossima_azione_il' => '',
            'email' => '', 'telefono' => '', 'responsabile_id' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $opp->refresh();
        $this->assertSame(['sponsor', 'firmato', 'Sponsor maglie 2026', null, null, null, null, null, null, null, 'vinta', '2026-03-10 10:00'],
            [$opp->pipeline, $opp->fase, $opp->titolo, $opp->descrizione, $opp->origine, $opp->valore, $opp->prossima_azione_il, $opp->email, $opp->telefono, $opp->responsabile_id, $opp->esito, $opp->chiusa_at?->format('Y-m-d H:i')]);

        // Nucleo familiare
        $this->comeAdmin()->post('/nuclei', ['nome' => 'Famiglia Famiglia', 'capofamiglia_id' => $capo->id])->assertRedirect()->assertSessionHasNoErrors();
        $nucleo = Nucleo::query()->where('nome', 'Famiglia Famiglia')->firstOrFail();
        $this->assertSame([$this->associazione->id, $capo->id, $nucleo->id], [$nucleo->associazione_id, $nucleo->capofamiglia_id, $capo->fresh()->nucleo_id]);
        $this->comeAdmin()->put('/nuclei/'.$nucleo->id, ['nome' => 'Nucleo Famiglia', 'capofamiglia_id' => $figlia->id])->assertRedirect()->assertSessionHasNoErrors();
        $nucleo->refresh();
        $this->assertSame(['Nucleo Famiglia', $figlia->id, $nucleo->id], [$nucleo->nome, $nucleo->capofamiglia_id, $figlia->fresh()->nucleo_id]);

        // Carica sociale
        $this->comeAdmin()->post('/organi-sociali', [
            'socio_id' => $capo->id, 'ruolo' => 'Presidente', 'inizio' => '2026-01-01', 'fine' => '2028-12-31', 'note' => 'Eletto in assemblea',
        ])->assertRedirect(route('organi-sociali.index'))->assertSessionHasNoErrors();
        $carica = Carica::query()->where('socio_id', $capo->id)->firstOrFail();
        $this->assertSame([$this->associazione->id, 'Presidente', '2026-01-01', '2028-12-31', 'Eletto in assemblea'],
            [$carica->associazione_id, $carica->ruolo, $carica->inizio->toDateString(), $carica->fine->toDateString(), $carica->note]);
        $this->comeAdmin()->put('/organi-sociali/'.$carica->id, [
            'socio_id' => $figlia->id, 'ruolo' => 'Vicepresidente', 'inizio' => '2026-02-01', 'fine' => '', 'note' => '',
        ])->assertRedirect(route('organi-sociali.index'))->assertSessionHasNoErrors();
        $carica->refresh();
        $this->assertSame([$figlia->id, 'Vicepresidente', '2026-02-01', null, null],
            [$carica->socio_id, $carica->ruolo, $carica->inizio->toDateString(), $carica->fine, $carica->note]);

        // Cessazione con delibera
        $this->comeAdmin()->post('/cessazioni', [
            'socio_id' => $dimissionario->id, 'tipo' => Cessazione::TIPO_DIMISSIONI, 'data' => '2026-03-05',
            'motivo' => 'Trasferimento all\'estero', 'delibera_numero' => '12/2026', 'delibera_data' => '2026-03-08',
        ])->assertRedirect(route('cessazioni.index'))->assertSessionHasNoErrors();
        $cessazione = Cessazione::query()->where('socio_id', $dimissionario->id)->firstOrFail();
        $this->assertSame([$this->associazione->id, Cessazione::TIPO_DIMISSIONI, '2026-03-05', 'Trasferimento all\'estero', '12/2026', '2026-03-08', $this->admin->id],
            [$cessazione->associazione_id, $cessazione->tipo, $cessazione->data->toDateString(), $cessazione->motivo, $cessazione->delibera_numero, $cessazione->delibera_data->toDateString(), $cessazione->registrata_da]);
        $this->assertNotSame(Socio::STATO_ATTIVO, $dimissionario->fresh()->stato);

        $this->comeAdmin()->get('/cessazioni')->assertOk()->assertSee('Dimesso');
        $this->comeAdmin()->get('/organi-sociali')->assertOk()->assertSee('Vicepresidente');
        $this->comeAdmin()->get('/crm?pipeline=sponsor')->assertOk()->assertSee('Sponsor maglie 2026');
    }

    public function test_round_trip_configurazioni_associazione_tesseramento_privacy_campi_obbligatori_e_modulo_iscrizione(): void
    {
        $campoTaglia = $this->campo('Taglia maglietta', 'select', "S\nM\nL");
        $campoAltro = $this->campo('Allergie', 'text');
        $campoAltrui = $this->campo('Campo di B', 'text', null, $this->altraAssociazione);

        // Tesseramento
        $this->comeAdmin()->post('/configurazioni/tesseramento', ['tipo_scadenza_default' => 'annuale', 'giorni_notifica_scadenza' => '45'])
            ->assertRedirect(route('configurazioni.index', ['section' => 'tesseramento']))->assertSessionHasNoErrors();

        // Template email scadenza
        $this->comeAdmin()->post('/configurazioni/template-email', ['template_email_scadenza' => "Caro {{nome}},\nla tua tessera scade il {{data_scadenza}}."])
            ->assertSessionHasNoErrors();

        // Privacy policy: HTML purificato
        $this->comeAdmin()->post('/configurazioni/privacy-policy', [
            'privacy_policy_html' => '<h2>Informativa</h2><p>Trattiamo i dati <strong>con cura</strong>.</p><script>alert(1)</script><iframe src="https://x.test"></iframe>',
        ])->assertSessionHasNoErrors();

        // Campi obbligatori backend/preiscrizione + campi personalizzati
        $this->comeAdmin()->post('/configurazioni/campi-obbligatori', [
            'required_backend' => ['telefono' => '1', 'codice_fiscale' => '1'],
            'required_preiscrizione' => ['cap' => '1', 'privacy_consenso' => '0'],
            'required_custom_backend' => [$campoTaglia->id],
            'required_custom_preiscrizione' => [$campoAltro->id],
        ])->assertSessionHasNoErrors();

        // Modulo di iscrizione pubblico: ordine, visibilità, obbligatorietà
        $this->comeAdmin()->post('/configurazioni/modulo-iscrizione', [
            'iscrizioni_socio_aperte' => '1',
            'modalita_iscrizione' => Associazione::MODALITA_DIRETTA,
            'intro_html' => '<p>Benvenuto nel <b>circolo</b>!</p><script>alert(2)</script>',
            'campi_standard' => [
                'telefono' => ['visibile' => '1', 'obbligatorio' => '1', 'ordine' => '3'],
                'codice_fiscale' => ['visibile' => '0', 'obbligatorio' => '1', 'ordine' => '4'],
                'data_nascita' => ['visibile' => '0', 'ordine' => '5'],
                'indirizzo' => ['visibile' => '1', 'obbligatorio' => '0', 'ordine' => '2'],
                'citta' => ['visibile' => '1', 'obbligatorio' => '1', 'ordine' => '1'],
                'provincia' => ['visibile' => '0', 'ordine' => '6'],
                'cap' => ['visibile' => '1', 'obbligatorio' => '0', 'ordine' => '7'],
            ],
            'campi_personalizzati' => [$campoTaglia->id],
        ])->assertRedirect(route('configurazioni.modulo-iscrizione'))->assertSessionHasNoErrors();

        // Un campo personalizzato di un altro tenant è rifiutato
        $this->comeAdmin()->post('/configurazioni/modulo-iscrizione', [
            'modalita_iscrizione' => Associazione::MODALITA_DIRETTA,
            'campi_standard' => ['telefono' => ['visibile' => '1', 'ordine' => '1']],
            'campi_personalizzati' => [$campoAltrui->id],
        ])->assertSessionHasErrors('campi_personalizzati.0');

        // Pagamenti e comunicazione
        $this->comeAdmin()->post('/configurazioni/pagamenti', [
            'stripe_enabled' => '1', 'stripe_publishable_key' => 'pk_test_123', 'stripe_secret_key' => 'sk_test_456', 'stripe_webhook_secret' => 'whsec_789',
            'paypal_enabled' => '0', 'paypal_client_id' => '', 'paypal_mode' => 'qualcosa', 'auto_attivazione_pagamento' => '1', 'valuta' => 'eur',
        ])->assertSessionHasNoErrors();
        $this->comeAdmin()->post('/configurazioni/comunicazione', ['auguri_automatici' => '1'])->assertSessionHasNoErrors();

        // --- Rilettura: settings e colonne speculari allineati ---
        $associazione = Associazione::query()->findOrFail($this->associazione->id);
        $this->assertSame('annuale', $associazione->tesseramento()->tipo_scadenza_default);
        $this->assertSame(45, $associazione->tesseramento()->giorni_notifica_scadenza);
        $this->assertSame("Caro {{nome}},\nla tua tessera scade il {{data_scadenza}}.", $associazione->tesseramento()->template_email_scadenza);
        $this->assertSame(['annuale', 45, "Caro {{nome}},\nla tua tessera scade il {{data_scadenza}}."],
            [$associazione->tipo_scadenza_default, $associazione->giorni_notifica_scadenza, $associazione->template_email_scadenza], 'Colonne speculari aggiornate insieme ai settings');
        $this->assertDatabaseHas('settings', ['associazione_id' => $associazione->id, 'group' => 'tesseramento', 'name' => 'tipo_scadenza_default']);

        $this->assertStringContainsString('<strong>con cura</strong>', (string) $associazione->privacy_policy_html);
        $this->assertStringNotContainsString('<script', (string) $associazione->privacy_policy_html);
        $this->assertStringNotContainsString('<iframe', (string) $associazione->privacy_policy_html);

        $campiObbligatori = $associazione->iscrizioni()->campi_obbligatori_config;
        $this->assertTrue($campiObbligatori['backend']['telefono']);
        $this->assertTrue($campiObbligatori['backend']['codice_fiscale']);
        $this->assertFalse($campiObbligatori['backend']['note']);
        $this->assertTrue($campiObbligatori['preiscrizione']['cap']);
        $this->assertTrue($campiObbligatori['preiscrizione']['privacy_consenso'], 'Consenso privacy sempre obbligatorio (GDPR)');
        $this->assertSame($campiObbligatori, $associazione->campi_obbligatori_config, 'Colonna campi_obbligatori_config speculare');
        $this->assertSame([true, false], [(bool) $campoTaglia->fresh()->obbligatorio, (bool) $campoTaglia->fresh()->obbligatorio_preiscrizione]);
        $this->assertSame([false, true], [(bool) $campoAltro->fresh()->obbligatorio, (bool) $campoAltro->fresh()->obbligatorio_preiscrizione]);

        $config = $associazione->iscrizioni()->iscrizione_form_config;
        $this->assertSame(Associazione::MODALITA_DIRETTA, $associazione->iscrizioni()->modalita_iscrizione);
        $this->assertTrue($associazione->iscrizioni()->iscrizioni_socio_aperte);
        $this->assertSame(['citta', 'indirizzo', 'telefono', 'codice_fiscale', 'data_nascita', 'provincia', 'cap'], array_column($config['campi_standard'], 'campo'), 'Ordine del builder');
        $this->assertSame(
            ['citta' => [true, true], 'indirizzo' => [true, false], 'telefono' => [true, true], 'codice_fiscale' => [false, false], 'data_nascita' => [false, false], 'provincia' => [false, false], 'cap' => [true, false]],
            collect($config['campi_standard'])->mapWithKeys(fn ($r) => [$r['campo'] => [$r['visibile'], $r['obbligatorio']]])->all(),
            'Un campo nascosto non può essere obbligatorio'
        );
        $this->assertSame([$campoTaglia->id], $config['campi_personalizzati']);
        $this->assertStringContainsString('<b>circolo</b>', (string) $config['intro_html']);
        $this->assertStringNotContainsString('<script', (string) $config['intro_html']);
        $this->assertSame($config, $associazione->iscrizione_form_config, 'Colonna iscrizione_form_config speculare');
        $this->assertSame([Associazione::MODALITA_DIRETTA, true, true], [$associazione->modalita_iscrizione, $associazione->iscrizioni_socio_aperte, $associazione->auguri_automatici]);

        $pg = PaymentGatewaySetting::query()->where('associazione_id', $associazione->id)->firstOrFail();
        $this->assertSame([true, 'pk_test_123', 'sk_test_456', 'whsec_789', false, 'sandbox', true, 'EUR'],
            [(bool) $pg->stripe_enabled, $pg->stripe_publishable_key, $pg->stripe_secret_key_encrypted, $pg->stripe_webhook_secret_encrypted, (bool) $pg->paypal_enabled, $pg->paypal_mode, (bool) $pg->auto_attivazione_pagamento, $pg->valuta]);

        foreach (['info', 'tesseramento', 'template-email', 'privacy', 'campi-obbligatori', 'pagamenti', 'comunicazione'] as $sezione) {
            $this->comeAdmin()->get('/configurazioni?section='.$sezione)->assertOk();
        }
        $this->comeAdmin()->get('/configurazioni/modulo-iscrizione')->assertOk()->assertSee('circolo');

        // --- Il form pubblico legge la configurazione appena salvata ---
        $this->comeOspite();
        $form = $this->get('/a/circolo-radici/iscriviti')->assertOk()
            ->assertSee('Benvenuto nel', false)
            ->assertDontSee('alert(2)', false)
            ->assertSee('name="citta"', false)
            ->assertSee('name="indirizzo"', false)
            ->assertSee('name="telefono"', false)
            ->assertSee('name="cap"', false)
            ->assertDontSee('name="codice_fiscale"', false)
            ->assertDontSee('name="provincia"', false)
            ->assertSee('Taglia maglietta');
        $html = $form->getContent();
        $this->assertLessThan(strpos($html, 'name="indirizzo"'), strpos($html, 'name="citta"'), 'Città prima di indirizzo (ordine 1, 2)');
        $this->assertLessThan(strpos($html, 'name="telefono"'), strpos($html, 'name="indirizzo"'), 'Indirizzo prima di telefono (ordine 2, 3)');
        $this->assertMatchesRegularExpression('/name="citta"[^>]*required/s', $html);

        // Obbligatorietà applicata davvero lato server; modalità diretta → socio attivo
        $this->post('/a/circolo-radici/iscriviti', ['nome' => 'Ivo', 'cognome' => 'Iscritto', 'email' => 'ivo@ospite.test', 'privacy' => '1'])
            ->assertSessionHasErrors(['citta', 'telefono']);
        $this->post('/a/circolo-radici/iscriviti', [
            'nome' => 'Ivo', 'cognome' => 'Iscritto', 'email' => 'ivo@ospite.test', 'privacy' => '1',
            'citta' => 'Pisa', 'telefono' => '+39 333 7654321', 'codice_fiscale' => self::CF_GIULIA,
            'custom_field' => [$campoTaglia->id => 'M'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $iscritto = Socio::allTenants()->where('email', 'ivo@ospite.test')->firstOrFail();
        $this->assertSame([Socio::STATO_ATTIVO, 'Pisa', '+39 333 7654321', null],
            [$iscritto->stato, $iscritto->citta, $iscritto->telefono, $iscritto->codice_fiscale], 'Il campo nascosto (codice fiscale) non viene salvato');
        $this->assertSame('M', ValoreCampoPersonalizzato::query()->where('socio_id', $iscritto->id)->where('campo_id', $campoTaglia->id)->value('valore'));

        // L'altra associazione resta con la configurazione di default
        $this->assertNull($this->altraAssociazione->fresh()->iscrizioni()->iscrizione_form_config);
        $this->assertSame('solare', $this->altraAssociazione->fresh()->tesseramento()->tipo_scadenza_default);
    }

    // ==================================================================
    // 17-18. Modelli coerenti con lo schema
    // ==================================================================

    public function test_fillable_e_casts_di_tutti_i_modelli_corrispondono_a_colonne_esistenti_e_le_colonne_json_hanno_cast_array(): void
    {
        // Tipi reali delle colonne dal dump MySQL dell'installer (su SQLite
        // json e decimal diventano text/numeric e non si distinguerebbero)
        $sql = (string) file_get_contents(config('installer.schema_file'));
        preg_match_all('/CREATE TABLE `([^`]+)` \((.*?)\n\) ENGINE/s', $sql, $blocchi, PREG_SET_ORDER);
        $tipi = [];
        foreach ($blocchi as $blocco) {
            foreach (explode("\n", $blocco[2]) as $riga) {
                if (preg_match('/^\s*`([^`]+)` (\w+)/', $riga, $c)) {
                    $tipi[$blocco[1]][$c[1]] = strtolower($c[2]);
                }
            }
        }
        $this->assertNotEmpty($tipi);

        $problemi = [];
        $modelli = 0;
        $colonneJsonViste = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $classe = 'App\\Models\\'.basename($file, '.php');
            $riflessione = new \ReflectionClass($classe);
            if ($riflessione->isAbstract() || ! $riflessione->isSubclassOf(Model::class)) {
                continue;
            }

            /** @var Model $modello */
            $modello = new $classe;
            $tabella = $modello->getTable();
            $modelli++;

            // Le colonne esistono davvero nello schema migrato (SQLite) e nel dump
            $colonneSqlite = Schema::getColumnListing($tabella);
            if ($colonneSqlite === []) {
                $problemi[] = "{$classe}: tabella {$tabella} inesistente";

                continue;
            }
            $colonne = $tipi[$tabella] ?? [];
            $this->assertEqualsCanonicalizing(array_keys($colonne), $colonneSqlite, "Colonne di {$tabella}: migration e schema.sql divergono");

            foreach ($modello->getFillable() as $attributo) {
                if (! in_array($attributo, $colonneSqlite, true)) {
                    $problemi[] = "{$classe}: fillable '{$attributo}' senza colonna in {$tabella}";
                }
            }

            $casts = $modello->getCasts();
            foreach ($casts as $attributo => $cast) {
                if (! in_array($attributo, $colonneSqlite, true)) {
                    $problemi[] = "{$classe}: cast '{$attributo}' ({$cast}) senza colonna in {$tabella}";
                }
            }

            foreach ($colonne as $colonna => $tipo) {
                $cast = (string) ($casts[$colonna] ?? '');
                $base = strtolower(explode(':', $cast)[0]);

                if ($tipo === 'json') {
                    $colonneJsonViste[] = $colonna;
                    if (! in_array($base, ['array', 'json', 'collection', 'object', 'encrypted'], true)
                        && ! str_contains($cast, 'AsArrayObject') && ! str_contains($cast, 'AsCollection')) {
                        $problemi[] = "{$classe}: colonna json {$tabella}.{$colonna} senza cast array (cast: '{$cast}')";
                    }
                }
                if ($tipo === 'decimal' && $base !== 'decimal') {
                    $problemi[] = "{$classe}: colonna decimal {$tabella}.{$colonna} senza cast decimal (cast: '{$cast}')";
                }
                if (in_array($tipo, ['timestamp', 'datetime'], true) && str_ends_with($colonna, '_at')
                    && ! in_array($colonna, ['created_at', 'updated_at', 'deleted_at'], true)
                    && ! str_contains($base, 'datetime') && ! str_contains($base, 'timestamp')) {
                    $problemi[] = "{$classe}: colonna {$tabella}.{$colonna} ({$tipo}) senza cast datetime (cast: '{$cast}')";
                }
                if ($tipo === 'date' && ! str_contains($base, 'date')) {
                    $problemi[] = "{$classe}: colonna date {$tabella}.{$colonna} senza cast date (cast: '{$cast}')";
                }
            }
        }

        $this->assertGreaterThan(60, $modelli, 'Tutti i modelli di app/Models sono stati verificati');
        foreach (['disponibilita', 'preferenze', 'dati_form', 'proposte', 'odg', 'opzioni', 'tag', 'iscrizione_form_config', 'permessi', 'annunci_ids'] as $json) {
            $this->assertContains($json, $colonneJsonViste, "Colonna json {$json} coperta da un modello");
        }
        $this->assertSame([], $problemi, "Incoerenze modelli/schema:\n".implode("\n", $problemi));

        // Prova di persistenza: i cast array rileggono la stessa struttura
        $socio = $this->creaSocio(['disponibilita' => ['giorni' => ['lun', 'gio'], 'sera' => true]]);
        $this->assertSame(['giorni' => ['lun', 'gio'], 'sera' => true], $socio->fresh()->disponibilita);
        $this->assertSame('{"giorni":["lun","gio"],"sera":true}', DB::table('soci')->where('id', $socio->id)->value('disponibilita'));
    }

    public function test_ogni_tabella_con_associazione_id_ha_modello_con_belongs_to_associazione_e_fk_cascade(): void
    {
        // Tabelle dello schema migrato con la colonna di tenant
        $tabelle = array_values(array_filter(
            Schema::getTableListing(schemaQualified: false),
            fn (string $t) => Schema::hasColumn($t, 'associazione_id')
        ));
        $this->assertContains('soci', $tabelle);
        $this->assertGreaterThan(50, count($tabelle));

        // Tabella → modello
        $modelliPerTabella = [];
        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $classe = 'App\\Models\\'.basename($file, '.php');
            if (is_subclass_of($classe, Model::class) && ! (new \ReflectionClass($classe))->isAbstract()) {
                $modelliPerTabella[(new $classe)->getTable()] = $classe;
            }
        }

        // Dichiaratamente globali: la riga può esistere senza tenant (o lo
        // sceglie il super admin) e non hanno il global scope
        $globali = [
            'utenti' => Utente::class,
            'api_keys' => ApiKey::class,
            'richieste_registrazione' => RichiestaRegistrazione::class,
        ];
        // Tabelle tecniche senza modello Eloquent dedicato
        $senzaModello = ['settings'];

        $problemi = [];
        foreach ($tabelle as $tabella) {
            $classe = $modelliPerTabella[$tabella] ?? null;

            if ($classe === null) {
                if (! in_array($tabella, $senzaModello, true)) {
                    $problemi[] = "{$tabella}: nessun modello in app/Models";
                }
            } elseif (! isset($globali[$tabella]) && ! in_array(BelongsToAssociazione::class, class_uses_recursive($classe), true)) {
                $problemi[] = "{$classe} ({$tabella}): manca il trait BelongsToAssociazione (nessun isolamento tenant)";
            }

            $fk = collect(Schema::getForeignKeys($tabella))
                ->first(fn (array $f) => $f['columns'] === ['associazione_id'] && $f['foreign_table'] === 'associazioni');
            if ($fk === null) {
                $problemi[] = "{$tabella}.associazione_id senza foreign key verso associazioni";
            } elseif (! in_array(strtolower((string) $fk['on_delete']), ['cascade', 'set null'], true)) {
                $problemi[] = "{$tabella}.associazione_id con on delete {$fk['on_delete']} (atteso cascade o set null)";
            }
        }
        $this->assertSame([], $problemi, "Tabelle di tenant incoerenti:\n".implode("\n", $problemi));

        // Il global scope isola davvero: l'admin di A non vede le righe di B
        // (la lista mostra di default i soci con tessera attiva)
        foreach ([[$this->associazione, 'Visibilissimo'], [$this->altraAssociazione, 'Nascostissimo']] as [$assoc, $cognome]) {
            $s = $this->creaSocio(['cognome' => $cognome], $assoc);
            Tessera::factory()->create(['associazione_id' => $assoc->id, 'socio_id' => $s->id, 'numero_tessera' => '2026'.$cognome, 'anno_validita' => 2026, 'data_scadenza' => '2026-12-31', 'stato' => Tessera::STATO_ATTIVA]);
        }
        $this->comeAdmin()->get('/soci')->assertOk()->assertSee('Visibilissimo')->assertDontSee('Nascostissimo');
    }

    // ==================================================================
    // 19-20. Cancellazioni a cascata
    // ==================================================================

    public function test_eliminazione_definitiva_del_socio_propaga_in_cascata_e_mette_a_null_dove_previsto(): void
    {
        $a = $this->associazione->id;
        $socio = $this->creaSocio(['nome' => 'Ettore', 'cognome' => 'Eliminato', 'email' => 'ettore@radici.test']);
        $testimone = $this->creaSocio(['nome' => 'Tina', 'cognome' => 'Testimone']);

        $tessera = Tessera::factory()->create(['associazione_id' => $a, 'socio_id' => $socio->id, 'numero_tessera' => '20269001', 'anno_validita' => 2026]);
        $quota = Quota::factory()->create(['associazione_id' => $a, 'socio_id' => $socio->id, 'anno' => 2026, 'importo' => 20, 'stato' => Quota::STATO_PAGATA]);
        $ricevuta = Ricevuta::create(['associazione_id' => $a, 'quota_id' => $quota->id, 'numero' => 1, 'anno' => 2026, 'importo' => 20, 'emessa_at' => now()]);
        $movimento = Movimento::create(['associazione_id' => $a, 'tipo' => 'entrata', 'importo' => 20, 'data' => '2026-03-10', 'descrizione' => 'Quota 2026 Ettore', 'origine_type' => $quota->getMorphClass(), 'origine_id' => $quota->id]);
        $consenso = Consenso::create(['associazione_id' => $a, 'socio_id' => $socio->id, 'chiave' => 'foto', 'testo' => 'Uso delle foto', 'accettato' => true, 'origine' => 'admin']);
        $campo = $this->campo('Professione', 'text');
        $valore = ValoreCampoPersonalizzato::create(['socio_id' => $socio->id, 'campo_id' => $campo->id, 'valore' => 'Fabbro']);
        $storico = StoricoAttivitaSocio::create(['associazione_id' => $a, 'socio_id' => $socio->id, 'tipo_attivita' => 'Iscrizione', 'descrizione' => 'Iscritto', 'data_attivita' => now()]);
        $evento = Evento::factory()->create(['associazione_id' => $a]);
        $partecipazione = EventoPartecipante::create(['associazione_id' => $a, 'evento_id' => $evento->id, 'socio_id' => $socio->id, 'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO]);
        $carica = Carica::factory()->create(['associazione_id' => $a, 'socio_id' => $socio->id]);
        $cessazione = Cessazione::factory()->create(['associazione_id' => $a, 'socio_id' => $socio->id]);
        $nota = NotaSocio::factory()->create(['associazione_id' => $a, 'socio_id' => $socio->id, 'autore_id' => $this->admin->id]);
        $turno = Turno::factory()->create(['associazione_id' => $a]);
        $assegnazione = TurnoAssegnazione::factory()->create(['associazione_id' => $a, 'turno_id' => $turno->id, 'socio_id' => $socio->id]);
        $attrezzatura = Attrezzatura::factory()->create(['associazione_id' => $a]);
        $prestito = PrestitoAttrezzatura::factory()->create(['associazione_id' => $a, 'attrezzatura_id' => $attrezzatura->id, 'socio_id' => $socio->id]);
        $richiestaModifica = RichiestaModificaDati::factory()->create(['associazione_id' => $a, 'socio_id' => $socio->id]);
        $account = Account::factory()->create(['associazione_id' => $a, 'socio_id' => $socio->id, 'email' => 'ettore@radici.test']);
        $richiestaIscrizione = RichiestaIscrizione::factory()->create(['associazione_id' => $a, 'account_id' => $account->id, 'socio_id' => $socio->id, 'stato' => RichiestaIscrizione::STATO_APPROVATA]);
        $emailLog = EmailLog::create(['associazione_id' => $a, 'socio_id' => $socio->id, 'to_email' => 'ettore@radici.test', 'subject' => 'Benvenuto', 'status' => 'sent']);
        $emailCoda = EmailQueue::create(['associazione_id' => $a, 'batch_id' => (string) Str::uuid(), 'socio_id' => $socio->id, 'to_email' => 'ettore@radici.test', 'subject' => 'Notiziario', 'body_html' => '<p>Ciao</p>']);
        $verbale = Verbale::create(['associazione_id' => $a, 'titolo' => 'Assemblea', 'numero' => 1, 'anno' => 2026, 'data_riunione' => '2026-02-01', 'tipo_riunione' => 'Assemblea Ordinaria']);
        $firmatario = VerbaleFirmatario::create(['associazione_id' => $a, 'verbale_id' => $verbale->id, 'socio_id' => $socio->id, 'nome' => 'Ettore Eliminato', 'ruolo' => 'Segretario']);
        $opportunita = OpportunitaCrm::create(['associazione_id' => $a, 'pipeline' => 'adesioni', 'fase' => 'contatto', 'posizione' => 1, 'titolo' => 'Rinnovo Ettore', 'socio_id' => $socio->id]);
        $partecipanteRiunione = RiunionePartecipante::factory()->create(['associazione_id' => $a, 'riunione_id' => Riunione::factory()->create(['associazione_id' => $a])->id, 'socio_id' => $socio->id]);
        Tessera::factory()->create(['associazione_id' => $a, 'socio_id' => $testimone->id, 'numero_tessera' => '20269002', 'anno_validita' => 2026]);

        // Prima nel cestino, poi eliminazione definitiva dal cestino
        $this->comeAdmin()->delete('/soci/'.$socio->id)->assertRedirect(route('soci.index'));
        $this->assertSoftDeleted('soci', ['id' => $socio->id]);
        $this->assertDatabaseHas('tessere', ['id' => $tessera->id]);

        $risposta = $this->comeAdmin()->delete('/soci/'.$socio->id.'/definitivo');
        $this->assertNon500($risposta, 'eliminazione definitiva del socio');
        $risposta->assertRedirect(route('soci.cestino'));
        $this->assertDatabaseMissing('soci', ['id' => $socio->id]);

        // CASCADE: i dati del socio spariscono con lui
        foreach ([
            'tessere' => $tessera, 'quote' => $quota, 'ricevute' => $ricevuta, 'consensi' => $consenso,
            'valori_campi_personalizzati' => $valore, 'storico_attivita_socio' => $storico, 'eventi_partecipanti' => $partecipazione,
            'cariche' => $carica, 'cessazioni' => $cessazione, 'note_socio' => $nota, 'turni_assegnazioni' => $assegnazione,
            'prestiti_attrezzature' => $prestito, 'richieste_modifica_dati' => $richiestaModifica, 'riunioni_partecipanti' => $partecipanteRiunione,
        ] as $tabella => $riga) {
            $this->assertDatabaseMissing($tabella, ['id' => $riga->id]);
        }

        // SET NULL: lo storico che appartiene ad altri resta, senza il socio
        foreach ([
            'accounts' => $account, 'richieste_iscrizione' => $richiestaIscrizione, 'email_log' => $emailLog,
            'email_queue' => $emailCoda, 'verbale_firmatari' => $firmatario, 'crm_opportunita' => $opportunita,
        ] as $tabella => $riga) {
            $this->assertDatabaseHas($tabella, ['id' => $riga->id, 'socio_id' => null]);
        }
        $this->assertDatabaseHas('verbale_firmatari', ['id' => $firmatario->id, 'nome' => 'Ettore Eliminato']);

        // La prima nota non perde la scrittura contabile (l'origine resta come
        // riferimento storico a una quota che non esiste più)
        $this->assertDatabaseHas('movimenti', ['id' => $movimento->id, 'importo' => 20, 'origine_id' => $quota->id]);
        $this->assertNull(Quota::query()->find($movimento->fresh()->origine_id));

        // Le altre righe non sono toccate
        $this->assertDatabaseHas('soci', ['id' => $testimone->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('tessere', ['numero_tessera' => '20269002', 'socio_id' => $testimone->id]);
        $this->assertDatabaseHas('eventi', ['id' => $evento->id]);
        $this->assertDatabaseHas('turni', ['id' => $turno->id]);
        $this->assertDatabaseHas('verbali', ['id' => $verbale->id]);
        $this->assertDatabaseHas('campi_personalizzati', ['id' => $campo->id]);
        $this->comeAdmin()->get('/soci/cestino')->assertOk()->assertDontSee('Eliminato');
    }

    public function test_eliminazione_associazione_dal_super_admin_elimina_in_cascata_tutti_i_dati_del_tenant_senza_toccare_altri_tenant(): void
    {
        $this->verificaEliminazioneAssociazione('SQLite');

        // Stesso flusso su MariaDB, se disponibile: l'ordine delle cascade
        // InnoDB è diverso da SQLite (prenotazioni.account_id è RESTRICT)
        if ((string) env('INSTALLER_TEST_MYSQL_DSN', '') !== '') {
            $this->usaMariaDb();
            $this->verificaEliminazioneAssociazione('MariaDB');
        }
    }

    private function verificaEliminazioneAssociazione(string $motore): void
    {
        $popola = function (Associazione $assoc, string $prefisso): void {
            $sede = Sede::create(['associazione_id' => $assoc->id, 'nome' => $prefisso.' sede']);
            $tipo = TipoSocio::create(['associazione_id' => $assoc->id, 'nome' => $prefisso.' ordinario']);
            $socio = $this->creaSocio(['sede_id' => $sede->id, 'tipo_socio_id' => $tipo->id, 'email' => $prefisso.'@socio.test'], $assoc);
            Tessera::factory()->create(['associazione_id' => $assoc->id, 'socio_id' => $socio->id, 'numero_tessera' => $prefisso.'0001']);
            $quota = Quota::factory()->create(['associazione_id' => $assoc->id, 'socio_id' => $socio->id, 'anno' => 2026, 'importo' => 20]);
            Ricevuta::create(['associazione_id' => $assoc->id, 'quota_id' => $quota->id, 'numero' => 1, 'anno' => 2026, 'importo' => 20, 'emessa_at' => now()]);
            $evento = Evento::factory()->create(['associazione_id' => $assoc->id]);
            $account = Account::factory()->create(['associazione_id' => $assoc->id, 'socio_id' => $socio->id]);
            Prenotazione::factory()->create(['associazione_id' => $assoc->id, 'evento_id' => $evento->id, 'account_id' => $account->id]);
            Prenotazione::factory()->create(['associazione_id' => $assoc->id, 'evento_id' => $evento->id]);
            EventoPartecipante::create(['associazione_id' => $assoc->id, 'evento_id' => $evento->id, 'socio_id' => $socio->id, 'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO]);
            $this->campo($prefisso.' campo', 'text', null, $assoc);
            Tag::create(['associazione_id' => $assoc->id, 'nome_tag' => $prefisso.' tag']);
            Movimento::create(['associazione_id' => $assoc->id, 'sede_id' => $sede->id, 'tipo' => 'entrata', 'importo' => 20, 'data' => '2026-03-10', 'origine_type' => $quota->getMorphClass(), 'origine_id' => $quota->id]);
            $turno = Turno::factory()->create(['associazione_id' => $assoc->id]);
            TurnoAssegnazione::factory()->create(['associazione_id' => $assoc->id, 'turno_id' => $turno->id, 'socio_id' => $socio->id]);
            RichiestaIscrizione::factory()->create(['associazione_id' => $assoc->id, 'account_id' => $account->id, 'socio_id' => $socio->id]);
            $assoc->tesseramento()->aggiorna(['tipo_scadenza_default' => 'annuale', 'giorni_notifica_scadenza' => 40]);
            $assoc->iscrizioni()->aggiorna(['modalita_iscrizione' => Associazione::MODALITA_DIRETTA]);
            SmtpSetting::create(['associazione_id' => $assoc->id, 'smtp_host' => 'smtp.'.$prefisso.'.test', 'smtp_port' => 587, 'smtp_user' => '', 'smtp_pass_encrypted' => 'x', 'smtp_encryption' => 'tls', 'from_email' => 'no@'.$prefisso.'.test', 'from_name' => '', 'max_per_hour' => 10]);
        };
        $popola($this->associazione, 'aaa');
        $popola($this->altraAssociazione, 'bbb');
        $adminB = $this->nuovoAdmin($this->altraAssociazione);

        $tabelleTenant = array_values(array_filter(
            Schema::getTableListing(schemaQualified: false),
            fn (string $t) => Schema::hasColumn($t, 'associazione_id')
        ));
        $conta = fn (string $assocId) => collect($tabelleTenant)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->where('associazione_id', $assocId)->count()])->all();
        $primaB = $conta($this->altraAssociazione->id);
        $this->assertGreaterThan(0, $conta($this->associazione->id)['prenotazioni']);
        $this->assertGreaterThan(0, $conta($this->associazione->id)['settings']);

        // Conferma forte: senza il nome giusto non si elimina nulla
        $super = $this->superAdmin();
        $this->actingAs($super, 'web')->delete('/associazioni/'.$this->associazione->id, ['conferma_nome' => 'Circolo sbagliato'])
            ->assertSessionHasErrors('conferma_nome');
        $this->assertDatabaseHas('associazioni', ['id' => $this->associazione->id]);

        $risposta = $this->actingAs($super, 'web')->delete('/associazioni/'.$this->associazione->id, ['conferma_nome' => 'Circolo Le Radici']);
        $this->assertNon500($risposta, "{$motore}: eliminazione associazione con prenotazioni di account");
        $risposta->assertRedirect(route('associazioni.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('associazioni', ['id' => $this->associazione->id]);
        $rimasteA = array_filter($conta($this->associazione->id));
        $this->assertSame([], $rimasteA, "{$motore}: righe del tenant eliminato rimaste nel database");
        $this->assertSame(0, DB::table('soci')->where('email', 'aaa@socio.test')->count());
        $this->assertSame(0, DB::table('valori_campi_personalizzati')->whereNotIn('socio_id', DB::table('soci')->pluck('id'))->count(), 'Nessun valore orfano');

        // Gli utenti di A restano, scollegati (non cancellati)
        $this->assertDatabaseHas('utenti', ['id' => $this->admin->id, 'associazione_id' => null]);

        // B intatto tabella per tabella
        $this->assertSame($primaB, $conta($this->altraAssociazione->id), "{$motore}: il tenant B deve restare intatto");
        $this->assertSame('annuale', $this->altraAssociazione->fresh()->tesseramento()->tipo_scadenza_default);
        $this->comeAdmin($adminB)->get('/soci')->assertOk()->assertSee('bbb@socio.test');
    }

    // ==================================================================
    // 21-25. Vincoli unique, import/export, backup e numerazioni
    // ==================================================================

    public function test_socio_nel_cestino_e_vincoli_unique_su_email_e_codice_fiscale_su_sqlite_e_mariadb(): void
    {
        $this->verificaUniqueSociConCestino('SQLite');

        // Stesso flusso su MariaDB, se disponibile (il NULL nell'unique
        // composito si comporta come in SQLite: più soci senza CF ammessi)
        if ((string) env('INSTALLER_TEST_MYSQL_DSN', '') !== '') {
            $this->usaMariaDb();
            $this->verificaUniqueSociConCestino('MariaDB');
        }
    }

    private function verificaUniqueSociConCestino(string $motore): void
    {
        $cestinato = $this->creaSocio(['nome' => 'Cesare', 'cognome' => 'Cestinato', 'email' => 'cesare@radici.test', 'codice_fiscale' => self::CF_MARIO, 'numero_socio' => '500']);
        $this->comeAdmin()->delete('/soci/'.$cestinato->id)->assertRedirect();
        $this->assertSoftDeleted('soci', ['id' => $cestinato->id]);

        // Stessa email di un socio nel cestino: errore chiaro, non 500
        $risposta = $this->comeAdmin()->post('/soci', $this->datiFormSocio(['email' => 'CESARE@radici.test', 'codice_fiscale' => '']));
        $this->assertNon500($risposta, "{$motore}: email di un socio nel cestino (maiuscole)");
        $risposta = $this->comeAdmin()->post('/soci', $this->datiFormSocio(['email' => 'cesare@radici.test', 'codice_fiscale' => '']));
        $this->assertNon500($risposta, "{$motore}: email di un socio nel cestino");
        $risposta->assertSessionHasErrors('email');
        $this->assertStringContainsString('già stato utilizzato', session('errors')->first('email'));

        // Stesso codice fiscale (anche minuscolo)
        $risposta = $this->comeAdmin()->post('/soci', $this->datiFormSocio(['email' => 'altro@radici.test', 'codice_fiscale' => strtolower(self::CF_MARIO)]));
        $this->assertNon500($risposta, "{$motore}: CF di un socio nel cestino");
        $risposta->assertSessionHasErrors(['codice_fiscale' => 'Esiste già un socio con lo stesso Codice Fiscale in questa associazione.']);

        // CF vuoto due volte: NULL, nessuna violazione dell'unique composito
        foreach (['senza.cf.1@radici.test', 'senza.cf.2@radici.test'] as $email) {
            $risposta = $this->comeAdmin()->post('/soci', $this->datiFormSocio(['email' => $email, 'codice_fiscale' => '   ']));
            $this->assertNon500($risposta, "{$motore}: socio senza CF");
            $risposta->assertSessionHasNoErrors();
        }
        $this->assertSame(2, Socio::query()->whereIn('email', ['senza.cf.1@radici.test', 'senza.cf.2@radici.test'])->whereNull('codice_fiscale')->count());

        // Lo stesso CF in un'altra associazione è ammesso
        $adminB = $this->nuovoAdmin($this->altraAssociazione);
        $this->comeAdmin($adminB)->post('/soci', $this->datiFormSocio(['email' => 'cesare@radici.test', 'codice_fiscale' => self::CF_MARIO]))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Socio::allTenants()->where('associazione_id', $this->altraAssociazione->id)->where('codice_fiscale', self::CF_MARIO)->count());

        // Ripristino dal cestino: il socio torna con i suoi dati unici
        $this->comeAdmin()->post('/soci/'.$cestinato->id.'/ripristina')->assertRedirect(route('soci.cestino'));
        $this->assertDatabaseHas('soci', ['id' => $cestinato->id, 'deleted_at' => null, 'codice_fiscale' => self::CF_MARIO]);
    }

    public function test_import_csv_soci_con_campi_al_limite_di_lunghezza_e_valori_non_validi_scarta_le_righe_giuste(): void
    {
        $this->creaSocio(['numero_socio' => '007', 'email' => 'gia.socio@radici.test']);
        $this->creaSocio(['numero_socio' => '900', 'email' => 'b@vicina.test'], $this->altraAssociazione);

        $intestazioni = ['nome', 'cognome', 'email', 'codice_fiscale', 'telefono', 'data_nascita', 'data_iscrizione', 'stato', 'numero_socio', 'indirizzo', 'citta', 'provincia', 'cap', 'note'];
        $indirizzoLungo = str_repeat('Via molto lunga ', 19).'1234'; // 308 caratteri
        $file = $this->csv([
            $intestazioni,
            ['Giulia', 'Neri', 'Giulia.Neri@Radici.test', strtolower(self::CF_GIULIA), '3331112222', '01/08/1995', '2026-01-15', 'Attivo', '', 'Via Roma 1', 'Torino', 'to', '10100', 'Importata dal vecchio gestionale'],
            ['Doppio', 'Socio', 'gia.socio@radici.test', '', '', '', '', '', '', '', '', '', '', ''],
            ['Cap', 'Lungo', 'cap@radici.test', '', '', '', '', '', '', '', '', '', '123456', ''],
            ['Indirizzo', 'Lungo', 'indirizzo@radici.test', '', '', '', '', '', '', $indirizzoLungo, '', '', '', ''],
            ['Codice', 'Errato', 'cf@radici.test', 'RSSMRA85T10A562X', '', '', '', '', '', '', '', '', '', ''],
            ['Minimo', 'Indispensabile', 'minimo@radici.test', '', '', '', '', '', '', '', '', '', '', ''],
            ['Email', 'DiB', 'b@vicina.test', '', '', '', '', '', '', '', '', '', '', ''],
        ]);

        $risposta = $this->comeAdmin()->post('/soci/import', ['file' => $file]);
        $this->assertNon500($risposta, 'import CSV soci');
        $risposta->assertRedirect(route('soci.import'));

        $report = session('import_report');
        $this->assertSame(3, $report['importati'], 'Righe valide: completa, minima e email usata solo da un altro tenant');
        $righeScartate = array_column($report['errori'], 'riga');
        sort($righeScartate);
        $this->assertSame([3, 4, 5, 6], $righeScartate, 'Scartate: email duplicata, CAP di 6 cifre, indirizzo oltre 255, CF non valido');
        $erroriPerRiga = collect($report['errori'])->mapWithKeys(fn ($e) => [$e['riga'] => implode(' ', $e['errori'])]);
        $this->assertStringContainsStringIgnoringCase('email', $erroriPerRiga[3]);
        $this->assertStringContainsStringIgnoringCase('cap', $erroriPerRiga[4]);
        $this->assertStringContainsStringIgnoringCase('indirizzo', $erroriPerRiga[5]);
        $this->assertStringContainsStringIgnoringCase('codice fiscale', $erroriPerRiga[6]);

        // Riga completa normalizzata e salvata con i valori giusti
        $giulia = Socio::query()->where('email', 'giulia.neri@radici.test')->firstOrFail();
        $this->assertSame(['008', self::CF_GIULIA, '3331112222', '1995-08-01', '2026-01-15', Socio::STATO_ATTIVO, 'Via Roma 1', 'Torino', 'TO', '10100', 'Importata dal vecchio gestionale', false],
            [$giulia->numero_socio, $giulia->codice_fiscale, $giulia->telefono, $giulia->data_nascita->toDateString(), $giulia->data_iscrizione->toDateString(), $giulia->stato, $giulia->indirizzo, $giulia->citta, $giulia->provincia, $giulia->cap, $giulia->note, $giulia->privacy_consenso]);

        // Numeri socio progressivi senza buchi, per tenant
        $this->assertSame(['007', '008', '009', '010'], Socio::query()->orderBy('numero_socio')->pluck('numero_socio')->all());
        $this->assertSame('2026-03-10', Socio::query()->where('email', 'minimo@radici.test')->firstOrFail()->data_iscrizione->toDateString());
        $this->assertSame(1, Socio::allTenants()->where('associazione_id', $this->altraAssociazione->id)->count(), 'Il tenant B non riceve righe');
        $this->assertSame(0, Socio::allTenants()->whereIn('email', ['cap@radici.test', 'indirizzo@radici.test', 'cf@radici.test'])->count());
        $this->assertTrue(StoricoAttivitaSocio::query()->where('socio_id', $giulia->id)->exists());
    }

    public function test_export_import_aggiornamento_con_diff_applica_solo_i_campi_cambiati_e_non_tocca_altri_tenant(): void
    {
        Storage::fake('local');
        $anna = $this->creaSocio(['numero_socio' => '001', 'nome' => 'Anna', 'cognome' => 'Aggiornata', 'email' => 'anna@radici.test', 'telefono' => '3330000001', 'citta' => 'Pisa', 'cap' => '00184', 'provincia' => 'PI', 'indirizzo' => 'Via Vecchia 1', 'data_nascita' => '1990-05-04', 'note' => 'Nota invariata']);
        $bea = $this->creaSocio(['numero_socio' => '002', 'nome' => 'Bea', 'cognome' => 'Invariata', 'email' => 'bea@radici.test', 'cap' => '56100']);
        $diB = $this->creaSocio(['numero_socio' => '777', 'nome' => 'Bruno', 'cognome' => 'DiB', 'email' => 'bruno@vicina.test', 'telefono' => '3339999999', 'citta' => 'Lucca'], $this->altraAssociazione);
        $primaAnna = Socio::query()->findOrFail($anna->id)->getAttributes();
        $primaBea = Socio::query()->findOrFail($bea->id)->getAttributes();
        $primaB = Socio::allTenants()->findOrFail($diB->id)->getAttributes();

        // 1. Export Excel multi-foglio
        $export = $this->comeAdmin()->get('/export-import/export');
        $export->assertOk();
        $percorsoExport = $export->baseResponse->getFile()->getPathname();
        $foglio = IOFactory::load($percorsoExport)->getSheet(0);
        $intestazioni = $foglio->rangeToArray('A1:'.$foglio->getHighestColumn().'1')[0];
        $colonna = fn (string $nome) => array_search($nome, $intestazioni, true) + 1;
        $righe = $foglio->toArray();
        $rigaAnna = null;
        foreach ($righe as $i => $r) {
            if (($r[$colonna('Email') - 1] ?? null) === 'anna@radici.test') {
                $rigaAnna = $i + 1;
            }
        }
        $this->assertNotNull($rigaAnna, 'Anna è nel foglio Soci');
        $this->assertCount(3, $righe, 'Solo i soci del tenant (intestazione + 2)');
        $this->assertNotContains('bruno@vicina.test', array_column($righe, $colonna('Email') - 1));

        // 2. Modifica del file: telefono e città di Anna + una riga di un socio di B
        $foglio->setCellValue([$colonna('Telefono'), $rigaAnna], '+39 340 5556677');
        $foglio->setCellValue([$colonna('Città'), $rigaAnna], 'Livorno');
        $nuova = $foglio->getHighestRow() + 1;
        $foglio->setCellValue([$colonna('Numero Socio'), $nuova], '777');
        $foglio->setCellValue([$colonna('Email'), $nuova], 'bruno@vicina.test');
        $foglio->setCellValue([$colonna('Telefono'), $nuova], '+39 000 0000000');
        $modificato = sys_get_temp_dir().'/flussi06_import_'.uniqid().'.xlsx';
        $this->fileTemporanei[] = $modificato;
        IOFactory::createWriter($foglio->getParent(), 'Xlsx')->save($modificato);

        // 3. Anteprima: solo i due campi cambiati, la riga di B non trovata
        $anteprima = $this->comeAdmin()->post('/export-import/import/anteprima', [
            'file' => new UploadedFile($modificato, 'anagrafica.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ]);
        $this->assertNon500($anteprima, 'anteprima import aggiornamento');
        $anteprima->assertOk();
        $report = $anteprima->viewData('report');
        $this->assertCount(1, $report['modifiche'], 'Una sola riga con differenze: '.json_encode($report['modifiche']));
        $this->assertSame($anna->id, $report['modifiche'][0]['socio_id']);
        $this->assertSame(['citta', 'telefono'], collect($report['modifiche'][0]['campi'])->keys()->sort()->values()->all(), 'Diff limitato a telefono e città: '.json_encode($report['modifiche'][0]['campi']));
        $this->assertSame(['da' => '3330000001', 'a' => '+39 340 5556677'], $report['modifiche'][0]['campi']['telefono']);
        $this->assertSame(1, $report['invariati'], 'Bea invariata');
        $this->assertSame(['777'], array_column($report['non_trovati'], 'identificativo'), 'Il socio di B non si abbina in A');
        $this->assertSame([], $report['errori']);
        $this->assertSame('3330000001', Socio::query()->findOrFail($anna->id)->telefono, 'L\'anteprima non scrive nulla');

        // 4. Applica
        $this->comeAdmin()->post('/export-import/import/applica', ['token' => $anteprima->viewData('token')])
            ->assertRedirect(route('export-import.index'))->assertSessionHas('success');

        $dopoAnna = Socio::query()->findOrFail($anna->id)->getAttributes();
        $this->assertSame('+39 340 5556677', $dopoAnna['telefono']);
        $this->assertSame('Livorno', $dopoAnna['citta']);
        $diff = array_keys(array_diff_assoc(
            Arr::except($dopoAnna, ['updated_at']),
            Arr::except($primaAnna, ['updated_at'])
        ));
        sort($diff);
        $this->assertSame(['citta', 'telefono'], $diff, 'Nessun altro campo di Anna modificato');
        $this->assertSame($primaBea, Socio::query()->findOrFail($bea->id)->getAttributes());
        $this->assertSame($primaB, Socio::allTenants()->findOrFail($diB->id)->getAttributes());
        $this->assertTrue(
            StoricoAttivitaSocio::query()->where('socio_id', $anna->id)->where('descrizione', 'like', '%import Excel%')->exists(),
            'Storico della modifica registrato'
        );
        $this->assertFalse(StoricoAttivitaSocio::allTenants()->where('socio_id', $diB->id)->exists());

        // Il token non è riutilizzabile
        $this->comeAdmin()->post('/export-import/import/applica', ['token' => $anteprima->viewData('token')])
            ->assertRedirect(route('export-import.index'))->assertSessionHas('error');
    }

    public function test_backup_dump_php_su_mariadb_e_reimportabile_e_contiene_tutte_le_tabelle(): void
    {
        $this->usaMariaDb();
        $restore = $this->creaDatabase(self::DB_RESTORE);

        // Dati con caratteri delicati per il quoting del dump
        $socio = $this->creaSocio([
            'nome' => "Dell'Orto",
            'cognome' => 'Ünïcode 😀',
            'note' => "Riga 1\nRiga 2 con \"virgolette\" e backslash \\ finale",
            'disponibilita' => ['giorni' => ['lun'], 'nota' => "l'una e mezza"],
        ]);
        $this->creaSocio([], $this->altraAssociazione);
        Tessera::factory()->create(['associazione_id' => $this->associazione->id, 'socio_id' => $socio->id, 'numero_tessera' => '20260001']);
        Quota::factory()->create(['associazione_id' => $this->associazione->id, 'socio_id' => $socio->id, 'anno' => 2026, 'importo' => 12.34]);
        Evento::factory()->create(['associazione_id' => $this->associazione->id, 'descrizione' => '<p>HTML & "entità"</p>']);
        $this->associazione->iscrizioni()->aggiorna(['iscrizione_form_config' => ['intro_html' => '<p>Ciao</p>', 'campi_standard' => [], 'campi_personalizzati' => []]]);
        $this->associazione->tesseramento()->aggiorna(['tipo_scadenza_default' => 'annuale']);
        Sondaggio::factory()->create(['associazione_id' => $this->associazione->id, 'opzioni' => ['Sì', 'No', "Forse; poi\nvedremo"]]);

        $file = sys_get_temp_dir().'/flussi06_backup_'.uniqid().'.sql.gz';
        $this->fileTemporanei[] = $file;
        $this->assertSame($file, app(DumpDatabasePhp::class)->dump($file, 'mysql'));
        $sql = (string) gzdecode((string) file_get_contents($file));
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS = 0;', $sql);

        // Reimport istruzione per istruzione in un database vuoto
        $pdo = $this->server($restore, self::DB_RESTORE);
        $fallite = [];
        foreach (SchemaSql::istruzioni($sql) as $i => $istruzione) {
            try {
                $pdo->exec($istruzione);
            } catch (\PDOException $e) {
                $fallite[] = "#{$i}: ".mb_substr($e->getMessage(), 0, 200).' — '.mb_substr($istruzione, 0, 120);
            }
        }
        $this->assertSame([], $fallite, "Istruzioni del backup fallite al reimport:\n".implode("\n", $fallite));

        $origine = DB::connection('mysql');
        $tabelleOrigine = collect($origine->select("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'", [self::DB_SCHEMA]))->pluck('t')->sort()->values()->all();
        $tabelleRipristino = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '".self::DB_RESTORE."' AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
        sort($tabelleRipristino);
        $this->assertSame($tabelleOrigine, $tabelleRipristino);

        $conteggiOrigine = [];
        $conteggiRipristino = [];
        foreach ($tabelleOrigine as $tabella) {
            $conteggiOrigine[$tabella] = (int) $origine->table($tabella)->count();
            $conteggiRipristino[$tabella] = (int) $pdo->query("SELECT COUNT(*) FROM `{$tabella}`")->fetchColumn();
        }
        $this->assertSame($conteggiOrigine, $conteggiRipristino);
        foreach (['soci', 'tessere', 'quote', 'eventi', 'settings', 'associazioni', 'utenti', 'roles', 'permissions', 'migrations'] as $principale) {
            $this->assertGreaterThan(0, $conteggiRipristino[$principale], "Tabella {$principale} ripristinata con i dati");
        }

        // Valori delicati identici byte per byte
        $riga = $pdo->query("SELECT nome, cognome, note, disponibilita FROM soci WHERE id = '{$socio->id}'")->fetch(PDO::FETCH_ASSOC);
        $originale = (array) $origine->table('soci')->where('id', $socio->id)->first(['nome', 'cognome', 'note', 'disponibilita']);
        $this->assertSame($originale, $riga);
        $this->assertSame(['giorni' => ['lun'], 'nota' => "l'una e mezza"], json_decode((string) $riga['disponibilita'], true));
        $this->assertSame(
            $origine->table('sondaggi')->value('opzioni'),
            $pdo->query('SELECT opzioni FROM sondaggi')->fetchColumn()
        );
        $this->assertSame(
            $origine->table('migrations')->orderBy('migration')->pluck('migration')->all(),
            $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    public function test_numero_socio_e_numero_tessera_restano_univoci_per_tenant_con_creazioni_consecutive_e_collisione_manuale(): void
    {
        // Tre iscrizioni consecutive senza numero: progressivo senza buchi
        foreach (['uno', 'due', 'tre'] as $nome) {
            $this->comeAdmin()->post('/soci', $this->datiFormSocio(['nome' => ucfirst($nome), 'email' => $nome.'@radici.test', 'genera_tessera' => '0']))
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        $soci = Socio::query()->orderBy('numero_socio')->get();
        $this->assertSame(['001', '002', '003'], $soci->pluck('numero_socio')->all());
        $this->assertSame(0, Tessera::query()->count(), 'genera_tessera=0: nessuna tessera automatica');

        // Emissione on-demand per i tre soci: AAAA0001..3 e contatore allineato
        foreach ($soci as $socio) {
            $this->comeAdmin()->post('/soci/'.$socio->id.'/tessere')->assertRedirect();
        }
        $this->assertSame(['20260001', '20260002', '20260003'], Tessera::query()->orderBy('numero_tessera')->pluck('numero_tessera')->all());
        $this->assertSame($soci->pluck('id')->all(), Tessera::query()->orderBy('numero_tessera')->pluck('socio_id')->all());
        $this->assertSame(3, (int) TesseraCounter::query()->where('associazione_id', $this->associazione->id)->where('anno', 2026)->value('ultimo_numero'));

        // Idempotente: una seconda emissione restituisce la tessera esistente
        $this->comeAdmin()->post('/soci/'.$soci[0]->id.'/tessere')->assertRedirect();
        $this->assertSame(3, Tessera::query()->count());

        // Stessi numeri in un altro tenant: ammessi
        $adminB = $this->nuovoAdmin($this->altraAssociazione);
        $this->comeAdmin($adminB)->post('/soci', $this->datiFormSocio(['email' => 'uno@vicina.test', 'numero_socio' => '001', 'genera_tessera' => '0']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $socioB = Socio::allTenants()->where('email', 'uno@vicina.test')->firstOrFail();
        $this->assertSame('001', $socioB->numero_socio);
        $this->comeAdmin($adminB)->post('/tessere', [
            'socio_id' => $socioB->id, 'numero_tessera' => '20260001', 'anno_validita' => '2026', 'data_emissione' => '2026-03-10', 'stato' => Tessera::STATO_ATTIVA,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, Tessera::allTenants()->where('numero_tessera', '20260001')->count());

        // Collisione manuale sulla tessera: errore di validazione
        $risposta = $this->comeAdmin()->post('/tessere', [
            'socio_id' => $soci[1]->id, 'numero_tessera' => '20260002', 'anno_validita' => '2026', 'data_emissione' => '2026-03-10', 'stato' => Tessera::STATO_ATTIVA,
        ]);
        $this->assertNon500($risposta, 'numero tessera già usato');
        $risposta->assertSessionHasErrors(['numero_tessera' => 'Numero tessera già esistente per questa associazione.']);
        $this->assertSame(3, Tessera::query()->count());

        // Collisione manuale sul numero socio in creazione: errore di validazione, non 500
        $risposta = $this->comeAdmin()->post('/soci', $this->datiFormSocio(['email' => 'quattro@radici.test', 'numero_socio' => '002']));
        $this->assertNon500($risposta, 'POST /soci con numero socio già usato nel tenant');
        $risposta->assertSessionHasErrors('numero_socio');
        $this->assertSame(3, Socio::query()->count());

        // Il prossimo progressivo resta 004
        $this->comeAdmin()->post('/soci', $this->datiFormSocio(['email' => 'quattro@radici.test', 'genera_tessera' => '0']))->assertSessionHasNoErrors();
        $this->assertSame('004', Socio::query()->where('email', 'quattro@radici.test')->value('numero_socio'));
    }
}
