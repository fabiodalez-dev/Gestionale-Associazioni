<?php

namespace App\Jobs;

use App\Models\Associazione;
use App\Models\Evento;
use App\Services\Calendario\CalendarioEsterno;
use App\Settings\GoogleCalendarSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Throwable;

/**
 * Allinea un evento al Google Calendar della sua associazione: pubblicato →
 * crea o aggiorna; bozza o eliminato → rimuove. google_event_id salvato
 * sull'evento senza riattivare l'observer. Un solo job alla volta per evento.
 */
class SincronizzaEventoGoogleCalendar implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** Durata predefinita quando l'evento non ha un orario di fine. */
    public const ORE_DURATA_PREDEFINITA = 2;

    /**
     * $associazioneId e $googleEventId servono solo dopo un'eliminazione
     * definitiva, quando l'evento non esiste più.
     */
    public function __construct(
        public string $eventoId,
        public ?string $associazioneId = null,
        public ?string $googleEventId = null,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('google-calendar:'.$this->eventoId))->releaseAfter(30)->expireAfter(300)];
    }

    public function handle(CalendarioEsterno $calendario): void
    {
        $evento = Evento::query()->withoutGlobalScopes()->withTrashed()->find($this->eventoId);
        if ($evento === null) {
            $this->eliminaOrfano($calendario);

            return;
        }

        $associazione = Associazione::query()->find($evento->associazione_id);
        if ($associazione === null) {
            return;
        }

        $impostazioni = GoogleCalendarSettings::per($associazione);
        if (! $impostazioni->attivaEConfigurata()) {
            return;
        }

        try {
            if ($evento->trashed() || $evento->stato_pubblicazione === Evento::STATO_BOZZA) {
                if (filled($evento->google_event_id)) {
                    $calendario->elimina($impostazioni, (string) $evento->google_event_id);
                    $evento->forceFill(['google_event_id' => null])->saveQuietly();
                }

                return;
            }

            $id = $calendario->salva($impostazioni, [
                'titolo' => (string) $evento->titolo,
                'descrizione' => Str::limit(trim(strip_tags((string) $evento->descrizione)), 7000),
                'luogo' => $evento->luogo,
                'inizio' => $evento->data_evento,
                'fine' => $evento->data_fine ?? $evento->data_evento->copy()->addHours(self::ORE_DURATA_PREDEFINITA),
            ], $evento->google_event_id);

            if ($id !== $evento->google_event_id) {
                $evento->forceFill(['google_event_id' => $id])->saveQuietly();
            }

            if (filled($impostazioni->ultimo_errore)) {
                $impostazioni->aggiorna(['ultimo_errore' => null, 'ultimo_errore_at' => null]);
            }
        } catch (Throwable $e) {
            $impostazioni->aggiorna([
                'ultimo_errore' => Str::limit($e->getMessage(), 500),
                'ultimo_errore_at' => now()->toIso8601String(),
            ]);

            throw $e;
        }
    }

    private function eliminaOrfano(CalendarioEsterno $calendario): void
    {
        if (blank($this->associazioneId) || blank($this->googleEventId)) {
            return;
        }

        $associazione = Associazione::query()->find($this->associazioneId);
        if ($associazione === null) {
            return;
        }

        $impostazioni = GoogleCalendarSettings::per($associazione);
        if ($impostazioni->attivaEConfigurata()) {
            $calendario->elimina($impostazioni, (string) $this->googleEventId);
        }
    }
}
