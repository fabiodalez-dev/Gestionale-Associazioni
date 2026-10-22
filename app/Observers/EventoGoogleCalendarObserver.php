<?php

namespace App\Observers;

use App\Jobs\SincronizzaEventoGoogleCalendar;
use App\Models\Associazione;
use App\Models\Evento;
use App\Settings\GoogleCalendarSettings;

/**
 * Accoda la sincronizzazione con Google Calendar quando un evento cambia in
 * un campo visibile sul calendario, solo per le associazioni che l'hanno
 * attivata. Il job parte dopo il commit della transazione.
 */
class EventoGoogleCalendarObserver
{
    private const CAMPI = ['titolo', 'descrizione', 'data_evento', 'data_fine', 'luogo', 'stato_pubblicazione'];

    public function created(Evento $evento): void
    {
        $this->accoda($evento);
    }

    public function updated(Evento $evento): void
    {
        if ($evento->wasChanged(self::CAMPI)) {
            $this->accoda($evento);
        }
    }

    public function deleted(Evento $evento): void
    {
        $this->accoda($evento);
    }

    public function forceDeleted(Evento $evento): void
    {
        if (filled($evento->google_event_id)) {
            $this->accoda($evento, conIdEsterno: true);
        }
    }

    public function restored(Evento $evento): void
    {
        $this->accoda($evento);
    }

    private function accoda(Evento $evento, bool $conIdEsterno = false): void
    {
        $associazione = Associazione::query()->find($evento->associazione_id);
        if ($associazione === null || ! GoogleCalendarSettings::per($associazione)->attivaEConfigurata()) {
            return;
        }

        SincronizzaEventoGoogleCalendar::dispatch(
            (string) $evento->getKey(),
            $conIdEsterno ? (string) $evento->associazione_id : null,
            $conIdEsterno ? (string) $evento->google_event_id : null,
        )->afterCommit();
    }
}
