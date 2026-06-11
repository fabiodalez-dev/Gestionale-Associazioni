<?php

namespace App\Console\Commands;

use App\Services\Prenotazioni\PrenotazioneService;
use Illuminate\Console\Command;

/**
 * Scadenze pagamento prenotazioni (macchina a stati, piano prenotazioni):
 * ogni in_attesa_pagamento con scadenza_pagamento_at superata passa a
 * 'scaduta'; i posti rilasciati promuovono la lista d'attesa (FIFO stretto,
 * stessa transazione con mutex per-evento dentro PrenotazioneService).
 *
 * Schedulato ogni minuto con withoutOverlapping (routes/console.php).
 */
class PrenotazioniGestisciScadenze extends Command
{
    protected $signature = 'prenotazioni:gestisci-scadenze';

    protected $description = 'Marca scadute le prenotazioni con pagamento oltre finestra e promuove la lista d\'attesa';

    public function handle(PrenotazioneService $prenotazioni): int
    {
        $scadute = $prenotazioni->scadiPagamenti();

        $this->info("Prenotazioni scadute: {$scadute}");

        return self::SUCCESS;
    }
}
