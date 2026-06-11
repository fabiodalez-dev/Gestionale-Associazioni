<?php

namespace App\Console\Commands;

use App\Services\Prenotazioni\PrenotazioneService;
use Illuminate\Console\Command;

/**
 * Promemoria evento per le prenotazioni CONFERMATE di eventi che iniziano
 * tra 24h e 48h, se la preferenza promemoria dell'account è attiva
 * (default attiva). La finestra di 24h con esecuzione giornaliera evita
 * doppi invii. L'invio reale (PromemoriaEventoNotification) viene
 * collegato nella fase Rifiniture: qui la selezione è completa e passa
 * dall'hook notificaPromemoriaEvento() del service.
 *
 * Schedulato ogni giorno alle 08:30 (routes/console.php).
 */
class PrenotazioniPromemoriaEventi extends Command
{
    protected $signature = 'prenotazioni:promemoria-eventi';

    protected $description = 'Invia i promemoria per gli eventi confermati che iniziano tra 24 e 48 ore';

    public function handle(PrenotazioneService $prenotazioni): int
    {
        $inviati = $prenotazioni->inviaPromemoriaEventi();

        $this->info("Promemoria inviati: {$inviati}");

        return self::SUCCESS;
    }
}
