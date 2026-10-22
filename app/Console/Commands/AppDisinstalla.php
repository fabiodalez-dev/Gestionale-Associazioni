<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Disinstallazione (come uninstall.php della vecchia app): backup, poi
 * cancellazione di tutte le tabelle e dei file caricati, e ritorno
 * all'installer web. Il codice, il file .env e i backup restano.
 */
class AppDisinstalla extends Command
{
    protected $signature = 'app:disinstalla
        {--senza-backup : Non eseguire il backup prima di cancellare}
        {--prova : Mostra cosa verrebbe cancellato senza toccare nulla}
        {--database= : Connessione del database da svuotare (predefinita: quella dell\'app)}
        {--force : Non chiedere la conferma digitata}';

    protected $description = 'Cancella database e file caricati (dopo un backup) e riporta l\'app all\'installer';

    public const CONFERMA = 'DISINSTALLA';

    public function handle(): int
    {
        $connessione = (string) ($this->option('database') ?: config('database.default'));
        /** @var list<string> $cartelleFile */
        $cartelleFile = (array) config('installer.cartelle_file', [storage_path('app/public'), storage_path('app/media-private')]);
        $lock = (string) config('installer.lock_file');

        $this->warn('Disinstallazione dell\'app:');
        $this->line(' 1. '.($this->option('senza-backup') ? 'nessun backup (--senza-backup)' : 'backup di database e file (spatie/laravel-backup)'));
        $this->line(' 2. cancellazione di tutte le tabelle della connessione "'.$connessione.'"');
        $this->line(' 3. cancellazione dei file caricati in: '.implode(', ', array_map(fn ($c) => str_replace(base_path().'/', '', $c), $cartelleFile)));
        $this->line(' 4. rimozione di '.str_replace(base_path().'/', '', $lock).': al prossimo accesso si apre l\'installer');
        $this->line('Restano il codice, il file .env e i backup in storage/app/private.');

        if ($this->option('prova')) {
            $this->info('Prova: nessuna modifica eseguita.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && $this->ask('Scrivi '.self::CONFERMA.' per confermare') !== self::CONFERMA) {
            $this->error('Disinstallazione annullata.');

            return self::FAILURE;
        }

        if (! $this->option('senza-backup')) {
            $this->info('Backup in corso…');
            if (Artisan::call('backup:run', ['--disable-notifications' => true]) !== 0) {
                $this->error('Backup non riuscito: disinstallazione interrotta, nulla è stato cancellato.');
                $this->line(Artisan::output());

                return self::FAILURE;
            }
        }

        Artisan::call('db:wipe', ['--database' => $connessione, '--force' => true]);
        $this->info('Tabelle cancellate.');

        foreach ($cartelleFile as $cartella) {
            if (File::isDirectory($cartella)) {
                File::cleanDirectory($cartella);
            }
        }
        $this->info('File caricati cancellati.');

        if (File::exists($lock)) {
            File::delete($lock);
        }
        $this->info('Fatto. Al prossimo accesso l\'app mostrerà l\'installer.');

        return self::SUCCESS;
    }
}
