<?php

namespace App\Console\Commands;

use App\Models\Utente;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Support\ActivityLogStatus;

/**
 * Importa i dati dal database della vecchia app procedurale (`associazione_soci_saas`).
 *
 * Lo schema legacy e quello nuovo condividono le stesse tabelle e le stesse
 * chiavi UUID CHAR(36) (fonte di verità: docs/schema-reale-mysql.sql), quindi
 * l'import è una copia riga-per-riga limitata alle colonne comuni alle due
 * tabelle (le colonne nuove restano ai loro default, quelle rimosse vengono ignorate).
 *
 * La connessione al DB legacy si configura con le variabili d'ambiente
 * LEGACY_DB_HOST / LEGACY_DB_PORT / LEGACY_DB_DATABASE / LEGACY_DB_USERNAME / LEGACY_DB_PASSWORD
 * (vedi .env.example), oppure definendo una connessione `legacy` in config/database.php.
 */
class ImportLegacy extends Command
{
    protected $signature = 'app:import-legacy
        {--tables= : Solo queste tabelle (separate da virgola), nell\'ordine indicato}
        {--chunk=500 : Righe per blocco di insert}
        {--dry-run : Mostra cosa verrebbe importato senza scrivere nulla}
        {--force : Importa anche nelle tabelle di destinazione non vuote}';

    protected $description = 'Importa i dati dal DB della vecchia app (connessione legacy, env LEGACY_DB_*): stesse tabelle, stessi UUID';

    /**
     * Tabelle da importare, in ordine sicuro rispetto alle foreign key.
     *
     * @var list<string>
     */
    private const TABLES = [
        'associazioni',
        'utenti',
        'sedi',
        'tipi_socio',
        'categorie_socio',
        'soci',
        'tessera_templates',
        'tessere',
        'saved_filters',
        'quote',
        'eventi',
        'eventi_partecipanti',
        'documenti',
        'documenti_socio',
        'campi_personalizzati',
        'valori_campi_personalizzati',
        'tags',
        'socio_tags',
        'storico_attivita_socio',
        'gruppi_dinamici',
        'verbali',
        'scadenze',
        'smtp_settings',
        'payment_gateway_settings',
        'email_templates',
        'email_queue',
        'email_log',
        'api_keys',
    ];

    public function handle(): int
    {
        try {
            DB::connection('legacy')->getPdo();
        } catch (\Throwable $e) {
            $this->error('Impossibile connettersi al database legacy: '.$e->getMessage());
            $this->line('Configura LEGACY_DB_HOST / LEGACY_DB_DATABASE / LEGACY_DB_USERNAME / LEGACY_DB_PASSWORD nel file .env.');

            return self::FAILURE;
        }

        $tables = $this->resolveTables();
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $imported = 0;

        // Niente audit per i dati storici: l'import non deve generare
        // righe fittizie in activity_log (spatie/laravel-activitylog).
        app(ActivityLogStatus::class)->disable();
        config(['activitylog.enabled' => false]);

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($tables as $table) {
                $count = $this->importTable($table, $chunk, $dryRun);
                if ($count === null) {
                    continue;
                }
                $imported += $count;
            }

            if (! $dryRun) {
                $this->syncRoles();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
            app(ActivityLogStatus::class)->enable();
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry-run completato: {$imported} righe verrebbero importate."
            : "Import completato: {$imported} righe importate.");

        if (! $dryRun && in_array('smtp_settings', $tables, true)) {
            $this->warn('Nota: le password SMTP legacy sono cifrate con AES-256-CBC (APP_ENCRYPTION_KEY della vecchia app), non con la APP_KEY Laravel: reinseriscile da Email > Impostazioni oppure ri-cifrale come da docs/specs/email.md §11.');
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveTables(): array
    {
        $only = (string) $this->option('tables');
        if ($only === '') {
            return self::TABLES;
        }

        $requested = array_values(array_filter(array_map('trim', explode(',', $only))));
        $unknown = array_diff($requested, self::TABLES);
        foreach ($unknown as $table) {
            $this->warn("Tabella '{$table}' non gestita dall'import: ignorata.");
        }

        return array_values(array_intersect($requested, self::TABLES));
    }

    /**
     * Copia una tabella; ritorna il numero di righe (anche in dry-run) o null se saltata.
     */
    private function importTable(string $table, int $chunk, bool $dryRun): ?int
    {
        $legacySchema = Schema::connection('legacy');

        if (! $legacySchema->hasTable($table)) {
            $this->warn("[{$table}] assente nel DB legacy: saltata.");

            return null;
        }

        if (! Schema::hasTable($table)) {
            $this->warn("[{$table}] assente nel DB di destinazione: saltata (eseguire prima le migrations).");

            return null;
        }

        $columns = array_values(array_intersect(
            $legacySchema->getColumnListing($table),
            Schema::getColumnListing($table),
        ));

        if ($columns === []) {
            $this->warn("[{$table}] nessuna colonna in comune: saltata.");

            return null;
        }

        $total = DB::connection('legacy')->table($table)->count();
        $existing = DB::table($table)->count();

        if ($existing > 0 && ! $this->option('force')) {
            $this->warn("[{$table}] la destinazione contiene già {$existing} righe: saltata (usa --force per importare comunque).");

            return null;
        }

        if ($dryRun) {
            $this->line(sprintf('[%s] %d righe, %d colonne in comune (dry-run).', $table, $total, count($columns)));

            return $total;
        }

        $buffer = [];
        $copied = 0;

        DB::transaction(function () use ($table, $columns, $chunk, &$buffer, &$copied): void {
            $rows = DB::connection('legacy')->table($table)->select($columns)->cursor();

            foreach ($rows as $row) {
                $buffer[] = (array) $row;

                if (count($buffer) >= $chunk) {
                    DB::table($table)->insertOrIgnore($buffer);
                    $copied += count($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                DB::table($table)->insertOrIgnore($buffer);
                $copied += count($buffer);
            }
        });

        $this->info(sprintf('[%s] importate %d/%d righe.', $table, $copied, $total));

        return $copied;
    }

    /**
     * Allinea i ruoli spatie/permission alla colonna `ruolo` degli utenti importati.
     */
    private function syncRoles(): void
    {
        $valid = ['super_admin', 'admin_associazione', 'operatore'];

        Utente::query()->withoutGlobalScopes()->each(function (Utente $utente) use ($valid): void {
            if (in_array($utente->ruolo, $valid, true) && ! $utente->hasRole($utente->ruolo)) {
                $utente->assignRole($utente->ruolo);
            }
        });

        $this->info('Ruoli spatie/permission sincronizzati con la colonna utenti.ruolo.');
    }
}
