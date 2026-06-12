<?php

namespace App\Console\Commands;

use App\Models\Associazione;
use App\Services\Rinnovi\PromemoriaRinnoviService;
use Illuminate\Console\Command;

/**
 * Promemoria rinnovo tessere multi-step (f.3 del piano Vita sociale):
 * per ogni tenant attivo invia i promemoria ai soci con tessera in
 * scadenza secondo gli step configurati (promemoria_rinnovo_config,
 * default [30, 15, 3] giorni), con tracking anti-duplicazione in
 * promemoria_inviati.
 *
 * Pensato per lo scheduler giornaliero (la registrazione in
 * routes/console.php è demandata alla fase Rifiniture).
 */
class RinnoviPromemoria extends Command
{
    protected $signature = 'rinnovi:promemoria
                            {--associazione= : Limita l\'invio a un solo tenant (UUID)}';

    protected $description = 'Invia i promemoria multi-step di rinnovo per le tessere in scadenza';

    public function handle(PromemoriaRinnoviService $promemoria): int
    {
        $query = Associazione::query()->where('attiva', true);

        if ($id = $this->option('associazione')) {
            $query->whereKey($id);
        }

        $totale = 0;

        foreach ($query->orderBy('nome')->cursor() as $associazione) {
            $inviati = $promemoria->processa($associazione);
            $totale += $inviati;

            if ($inviati > 0) {
                $this->line("{$associazione->nome}: {$inviati} promemoria inviati.");
            }
        }

        $this->info("Promemoria rinnovo inviati: {$totale}");

        return self::SUCCESS;
    }
}
