<?php

namespace App\Console\Commands;

use App\Services\Comunicazione\DiscussioniService;
use Illuminate\Console\Command;

/**
 * Riepilogo giornaliero delle discussioni seguite (issue #13): una email per
 * socio con le discussioni che hanno nuovi messaggi dall'ultimo riepilogo.
 * Idempotente: ogni iscrizione ricorda quando ha ricevuto l'ultimo.
 */
class DiscussioniRiepilogo extends Command
{
    protected $signature = 'discussioni:riepilogo';

    protected $description = 'Invia ai soci il riepilogo dei nuovi messaggi nelle discussioni che seguono';

    public function handle(DiscussioniService $discussioni): int
    {
        $this->info('Riepiloghi inviati: '.$discussioni->inviaRiepiloghi());

        return self::SUCCESS;
    }
}
