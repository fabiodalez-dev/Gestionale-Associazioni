<?php

namespace App\Console\Commands;

use App\Models\Associazione;
use App\Services\Comunicazione\CompleanniService;
use Illuminate\Console\Command;

/**
 * Auguri di compleanno automatici (f.9 piano Gestione 2).
 *
 * Per ogni tenant con auguri_automatici attivo invia gli auguri ai soci
 * attivi che compiono gli anni oggi (email presente, no opt-out), con
 * tracking anti-duplicazione in promemoria_inviati (tipo 'compleanno',
 * step = anno corrente): rilanciare il comando non produce doppi invii.
 *
 * La schedulazione (daily) è demandata a routes/console.php (Rifiniture).
 */
class CompleanniAuguri extends Command
{
    protected $signature = 'compleanni:auguri';

    protected $description = 'Invia gli auguri di compleanno ai soci attivi (tenant con auguri automatici attivi)';

    public function handle(CompleanniService $service): int
    {
        $totale = 0;

        Associazione::query()
            ->where('auguri_automatici', true)
            ->orderBy('id')
            ->each(function (Associazione $associazione) use ($service, &$totale): void {
                $inviati = $service->inviaAuguri($associazione);
                $totale += $inviati;

                if ($inviati > 0) {
                    $this->info("{$associazione->nome}: {$inviati} auguri inviati.");
                }
            });

        $this->info("Totale auguri di compleanno inviati: {$totale}.");

        return self::SUCCESS;
    }
}
