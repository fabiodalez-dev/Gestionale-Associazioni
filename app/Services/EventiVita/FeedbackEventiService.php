<?php

namespace App\Services\EventiVita;

use App\Models\Account;
use App\Models\Evento;
use App\Models\FeedbackEvento;
use App\Models\Prenotazione;
use App\Notifications\EventiVita\FeedbackEventoNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Feedback post-evento (f.11 del piano Vita sociale).
 *
 * Invio: il giorno dopo data_evento (comando eventi:invia-feedback,
 * esecuzione giornaliera — la registrazione nello scheduler è demandata
 * alla fase Rifiniture), email ai soli partecipanti con check-in.
 * Anti-duplicazione strutturale: la riga feedback_eventi (unique per
 * prenotazione) viene creata PRIMA dell'invio e fa da marcatore.
 */
class FeedbackEventiService
{
    /**
     * Crea e invia le richieste di feedback per gli eventi svoltisi IERI
     * (prenotazioni confermate con check-in, senza feedback esistente).
     * Ritorna il numero di richieste inviate.
     */
    public function inviaRichieste(): int
    {
        $eventoIds = Evento::allTenants()
            ->whereBetween('data_evento', [
                now()->subDay()->startOfDay(),
                now()->subDay()->endOfDay(),
            ])
            ->pluck('id');

        if ($eventoIds->isEmpty()) {
            return 0;
        }

        $prenotazioni = Prenotazione::allTenants()
            ->whereIn('evento_id', $eventoIds)
            ->where('stato', Prenotazione::STATO_CONFERMATA)
            ->whereNotNull('checkin_at')
            ->whereNotIn('id', FeedbackEvento::allTenants()->select('prenotazione_id'))
            ->orderBy('created_at')
            ->get();

        $inviati = 0;

        foreach ($prenotazioni as $prenotazione) {
            try {
                if ($this->inviaPerPrenotazione($prenotazione)) {
                    $inviati++;
                }
            } catch (\Throwable $e) {
                Log::error('FeedbackEventiService: invio richiesta feedback fallito', [
                    'prenotazione_id' => $prenotazione->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $inviati;
    }

    /**
     * Crea il feedback (token + inviato_at) e notifica l'account della
     * prenotazione. False se l'account non esiste o è disattivato.
     */
    protected function inviaPerPrenotazione(Prenotazione $prenotazione): bool
    {
        /** @var Account|null $account */
        $account = $prenotazione->account()
            ->withoutGlobalScope('associazione')
            ->first();

        if ($account === null || ! $account->attivo) {
            return false;
        }

        $feedback = FeedbackEvento::create([
            'associazione_id' => $prenotazione->associazione_id,
            'evento_id' => $prenotazione->evento_id,
            'prenotazione_id' => $prenotazione->id,
            'token' => FeedbackEvento::generaToken(),
            'inviato_at' => now(),
        ]);

        $account->notify(new FeedbackEventoNotification($feedback));

        return true;
    }

    /**
     * Statistiche feedback di un evento per il report admin (f.11):
     * media voto, distribuzione 1–5, inviati/compilati, commenti.
     *
     * @return array{media: float|null, totale_compilati: int, totale_inviati: int, distribuzione: array<int, int>, commenti: Collection<int, FeedbackEvento>}
     */
    public function statistiche(Evento $evento): array
    {
        $compilati = $evento->feedback()->compilati()->get();

        $distribuzione = array_fill_keys(
            range(FeedbackEvento::VOTO_MIN, FeedbackEvento::VOTO_MAX),
            0
        );

        foreach ($compilati as $feedback) {
            if ($feedback->voto !== null && isset($distribuzione[$feedback->voto])) {
                $distribuzione[$feedback->voto]++;
            }
        }

        return [
            'media' => $compilati->isNotEmpty()
                ? round((float) $compilati->avg('voto'), 2)
                : null,
            'totale_compilati' => $compilati->count(),
            'totale_inviati' => $evento->feedback()->count(),
            'distribuzione' => $distribuzione,
            'commenti' => $compilati
                ->filter(fn (FeedbackEvento $f) => filled($f->commento))
                ->sortByDesc('compilato_at')
                ->values(),
        ];
    }
}
