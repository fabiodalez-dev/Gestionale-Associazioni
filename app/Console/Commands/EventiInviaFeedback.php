<?php

namespace App\Console\Commands;

use App\Services\EventiVita\FeedbackEventiService;
use Illuminate\Console\Command;

/**
 * Richieste di feedback post-evento (f.11 del piano Vita sociale):
 * il giorno dopo data_evento invia ai partecipanti con check-in il link
 * firmato al form pubblico di valutazione (voto 1–5 + commento).
 *
 * Anti-duplicazione: la riga feedback_eventi (unique per prenotazione)
 * viene creata prima dell'invio e fa da marcatore — il comando è
 * rieseguibile in sicurezza.
 *
 * NON registrato nello scheduler: la pianificazione giornaliera in
 * routes/console.php è demandata alla fase Rifiniture.
 */
class EventiInviaFeedback extends Command
{
    protected $signature = 'eventi:invia-feedback';

    protected $description = 'Invia le richieste di feedback per gli eventi svoltisi ieri (partecipanti con check-in)';

    public function handle(FeedbackEventiService $feedback): int
    {
        $inviati = $feedback->inviaRichieste();

        $this->info("Richieste di feedback inviate: {$inviati}");

        return self::SUCCESS;
    }
}
