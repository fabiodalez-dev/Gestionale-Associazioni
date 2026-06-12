<?php

namespace App\Console\Commands;

use App\Models\AttivitaAdmin;
use App\Notifications\Operativita\PromemoriaAttivitaNotification;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

/**
 * Promemoria attività admin (f.4 del piano "Gestione 2"): il giorno della
 * scadenza invia una email brandizzata all'assegnatario di ogni attività
 * ancora aperta.
 *
 * Anti-duplicazione: ogni invio viene marcato nel log spatie/activitylog
 * (evento 'promemoria_inviato' sull'attività) — il comando è rieseguibile
 * in sicurezza nello stesso giorno.
 *
 * NON registrato nello scheduler: la pianificazione giornaliera in
 * routes/console.php è demandata alla fase Rifiniture.
 */
class AttivitaPromemoria extends Command
{
    public const EVENTO_LOG = 'promemoria_inviato';

    protected $signature = 'attivita:promemoria';

    protected $description = 'Invia il promemoria email per le attività admin in scadenza oggi (assegnatario)';

    public function handle(): int
    {
        $inviati = 0;

        $daNotificare = AttivitaAdmin::allTenants()
            ->where('stato', AttivitaAdmin::STATO_APERTA)
            ->whereDate('scadenza', today())
            ->whereNotNull('assegnatario_id')
            ->with('assegnatario')
            ->get();

        foreach ($daNotificare as $attivita) {
            $assegnatario = $attivita->assegnatario;

            if ($assegnatario === null || ! $assegnatario->attivo || blank($assegnatario->email)) {
                continue;
            }

            if ($this->giaInviatoOggi($attivita)) {
                continue;
            }

            $assegnatario->notify(new PromemoriaAttivitaNotification($attivita));

            // Marcatore anti-duplicazione (durabile, tenant-aware via subject)
            activity()
                ->performedOn($attivita)
                ->event(self::EVENTO_LOG)
                ->log(self::EVENTO_LOG);

            $inviati++;
        }

        $this->info("Promemoria attività inviati: {$inviati}");

        return self::SUCCESS;
    }

    private function giaInviatoOggi(AttivitaAdmin $attivita): bool
    {
        return Activity::query()
            ->where('subject_type', $attivita->getMorphClass())
            ->where('subject_id', $attivita->id)
            ->where('description', self::EVENTO_LOG)
            ->whereDate('created_at', today())
            ->exists();
    }
}
