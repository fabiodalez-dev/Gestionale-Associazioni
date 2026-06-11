<?php

namespace App\Console\Commands;

use App\Models\Scadenza;
use App\Models\Utente;
use App\Notifications\PromemoriaScadenzaNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Promemoria reali per le scadenze (estensione §6.3 della spec):
 * seleziona le scadenze Attive entrate nella finestra promemoria per-record
 * (data_scadenza - oggi <= promemoria_giorni) non ancora notificate e invia
 * la notifica all'assegnatario, o agli admin del tenant se non assegnata.
 * L'invio è tracciato in promemoria_inviato_at per evitare duplicati.
 *
 * Schedulato giornalmente (routes/console.php).
 */
class InviaPromemoriaScadenze extends Command
{
    protected $signature = 'scadenze:invia-promemoria';

    protected $description = 'Invia i promemoria email per le scadenze attive in finestra promemoria';

    public function handle(): int
    {
        $oggi = Carbon::today();
        $inviate = 0;

        Scadenza::allTenants()
            ->where('stato', Scadenza::STATO_ATTIVA)
            ->whereNull('promemoria_inviato_at')
            ->orderBy('data_scadenza')
            ->get()
            // Finestra per-record: confronto in PHP (promemoria_giorni varia per riga)
            ->filter(fn (Scadenza $s) => $s->data_scadenza->startOfDay()->lte($oggi->copy()->addDays($s->promemoria_giorni)))
            ->each(function (Scadenza $scadenza) use (&$inviate) {
                $destinatari = $scadenza->assegnato_a !== null
                    ? Utente::query()->whereKey($scadenza->assegnato_a)->where('attivo', true)->get()
                    : Utente::query()
                        ->where('associazione_id', $scadenza->associazione_id)
                        ->where('ruolo', Utente::RUOLO_ADMIN_ASSOCIAZIONE)
                        ->where('attivo', true)
                        ->get();

                if ($destinatari->isEmpty()) {
                    return;
                }

                Notification::send($destinatari, new PromemoriaScadenzaNotification($scadenza));

                // updateQuietly: non è una modifica utente, niente activity log/updated_at "sporco"
                $scadenza->promemoria_inviato_at = now();
                $scadenza->saveQuietly();

                $inviate++;
            });

        $this->info("Promemoria inviati: {$inviate}");

        return self::SUCCESS;
    }
}
