<?php

namespace App\Console\Commands;

use App\Services\Update\RiparaSchema;
use Illuminate\Console\Command;

/**
 * Crea le tabelle e le colonne di database/installer/schema.sql che mancano
 * nel database. Idempotente: eseguito anche dall'aggiornamento in-app dopo le
 * migration e, una volta per versione, al primo caricamento dopo un cambio
 * di file.
 */
class RiparaSchemaCommand extends Command
{
    protected $signature = 'app:ripara-schema';

    protected $description = 'Crea tabelle e colonne mancanti rispetto a schema.sql (solo aggiunte)';

    public function handle(RiparaSchema $riparaSchema): int
    {
        $esito = $riparaSchema->ripara();

        if (! $esito['eseguita']) {
            $this->warn('Controllo dello schema non eseguito: '.$esito['motivo'].'.');

            return self::SUCCESS;
        }

        if ($esito['tabelle'] === [] && $esito['colonne'] === []) {
            $this->info('Schema allineato: nessuna tabella o colonna mancante.');

            return self::SUCCESS;
        }

        foreach ($esito['tabelle'] as $tabella) {
            $this->line("Creata la tabella {$tabella}");
        }
        foreach ($esito['colonne'] as $colonna) {
            $this->line("Aggiunta la colonna {$colonna}");
        }

        return self::SUCCESS;
    }
}
