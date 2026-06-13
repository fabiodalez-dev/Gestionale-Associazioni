<?php

namespace App\Console\Commands;

use App\Models\Adempimento;
use App\Models\Utente;
use App\Notifications\Struttura\PromemoriaAdempimentoNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Promemoria mensile degli adempimenti ricorrenti (f.20 del piano
 * "Organizzazione 3"): seleziona gli adempimenti attivi in scadenza nel
 * mese corrente, non ancora completati per l'anno, e invia la notifica
 * brandizzata agli admin del rispettivo tenant.
 *
 * Eseguibile manualmente o da scheduler: la registrazione nello scheduler
 * (routes/console.php) è a cura di Rifiniture, NON di questo modulo.
 */
class AdempimentiPromemoria extends Command
{
    protected $signature = 'adempimenti:promemoria {--mese= : Mese 1-12 da controllare (default: mese corrente)}';

    protected $description = 'Invia i promemoria mensili degli adempimenti ricorrenti in scadenza non completati';

    public function handle(): int
    {
        $mese = $this->option('mese') !== null ? (int) $this->option('mese') : (int) now()->format('n');

        if ($mese < 1 || $mese > 12) {
            $this->error('Il mese deve essere compreso tra 1 e 12.');

            return self::FAILURE;
        }

        $anno = (int) now()->format('Y');
        $inviati = 0;

        Adempimento::allTenants()
            ->where('attivo', true)
            ->where('mese_scadenza', $mese)
            ->orderBy('associazione_id')
            ->get()
            ->reject(fn (Adempimento $adempimento) => $adempimento->isCompletatoPerAnno($anno))
            ->each(function (Adempimento $adempimento) use ($anno, &$inviati) {
                $destinatari = Utente::query()
                    ->where('associazione_id', $adempimento->associazione_id)
                    ->where('ruolo', Utente::RUOLO_ADMIN_ASSOCIAZIONE)
                    ->where('attivo', true)
                    ->get();

                if ($destinatari->isEmpty()) {
                    return;
                }

                Notification::send($destinatari, new PromemoriaAdempimentoNotification($adempimento, $anno));
                $inviati++;
            });

        $this->info("Promemoria adempimenti inviati: {$inviati}");

        return self::SUCCESS;
    }
}
