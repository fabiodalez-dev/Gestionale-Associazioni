<?php

namespace App\Console\Commands;

use App\Support\RuoliAssociazione;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Command;

/**
 * Allinea ruoli e permessi alla matrice di App\Support\RuoliAssociazione.
 * Idempotente: eseguito anche dall'aggiornamento in-app dopo le migration.
 */
class AggiornaPermessi extends Command
{
    protected $signature = 'app:aggiorna-permessi';

    protected $description = 'Crea o aggiorna ruoli e permessi secondo la matrice dell\'applicazione';

    public function handle(): int
    {
        (new RolesSeeder)->run();

        $this->info(sprintf(
            'Ruoli e permessi allineati: %d ruoli, %d permessi.',
            count(RuoliAssociazione::ruoli()),
            count(RuoliAssociazione::tuttiIPermessi()),
        ));

        return self::SUCCESS;
    }
}
