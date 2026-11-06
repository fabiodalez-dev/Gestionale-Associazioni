<?php

namespace App\Services\Prenotazioni;

use App\Models\Evento;
use App\Models\Prenotazione;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Regola "la capienza non scende sotto i posti occupati" (SUM(posti) delle
 * prenotazioni confermate + riservate in attesa di pagamento).
 *
 * Le FormRequest (EventoRequest, SerieUpdateRequest) la applicano come
 * primo controllo, fuori dal lock; il salvataggio la ricontrolla DENTRO
 * il mutex per-evento di PrenotazioneService (SELECT ... FOR UPDATE sulla
 * riga eventi), così una prenotazione confermata arrivata tra validazione
 * e salvataggio non porta l'evento in overbooking.
 */
class CapienzaEventoService
{
    /**
     * Usa il mutex per-evento di PrenotazioneService.
     */
    public function __construct(protected PrenotazioneService $prenotazioni) {}

    /**
     * Capienza richiesta, con lo stesso parsing della regola integer
     * (es. "+1" vale 1). null = illimitata o valore non valido (già
     * segnalato dalle regole integer/min).
     */
    public static function capienzaRichiesta(mixed $valore): ?int
    {
        $capienza = filter_var($valore, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $capienza === false ? null : $capienza;
    }

    /**
     * Messaggio d'errore se la capienza richiesta scende sotto i posti
     * occupati dell'evento, altrimenti null. Solo una vera riduzione è
     * vietata: un evento già in overbooking (riconferma dopo la scadenza)
     * resta modificabile a capienza invariata.
     *
     * $occorrenza = true: messaggio per la modifica di serie (indica la data).
     */
    public static function errore(Evento $evento, int $capienza, bool $occorrenza = false): ?string
    {
        $occupati = Prenotazione::postiOccupati($evento->id);
        $riduzione = $evento->capienza === null || $capienza < (int) $evento->capienza;

        if ($capienza >= $occupati || ! $riduzione) {
            return null;
        }

        $dove = $occorrenza
            ? "l'occorrenza del ".$evento->data_evento->format('d/m/Y H:i')." ha già {$occupati} posti occupati "
            : "ci sono già {$occupati} posti occupati ";

        return "La capienza non può essere ridotta a {$capienza}: {$dove}"
            .'da prenotazioni confermate o riservate (in attesa di pagamento). '
            .'Annulla prima le prenotazioni in eccesso.';
    }

    /**
     * Ricontrollo da eseguire DENTRO il lock dell'evento: rifiuta con una
     * ValidationException sul campo capienza (stesso errore della FormRequest).
     *
     * @param  array<string, mixed>  $dati
     *
     * @throws ValidationException
     */
    public function verificaSottoLock(Evento $bloccato, array $dati, bool $occorrenza = false): void
    {
        if (! array_key_exists('capienza', $dati)) {
            return; // capienza non modificata
        }

        $capienza = self::capienzaRichiesta($dati['capienza']);

        if ($capienza === null) {
            return; // illimitata
        }

        $errore = self::errore($bloccato, $capienza, $occorrenza);

        if ($errore !== null) {
            throw ValidationException::withMessages(['capienza' => $errore]);
        }
    }

    /**
     * Modifica del singolo evento: lock della riga (stesso mutex di
     * prenotazione/promozione), ricontrollo della capienza sui posti
     * occupati correnti, salvataggio. Ritorna l'evento aggiornato.
     *
     * @param  array<string, mixed>  $dati  attributi validati
     *
     * @throws ValidationException
     */
    public function aggiornaEvento(Evento $evento, array $dati): Evento
    {
        return DB::transaction(function () use ($evento, $dati): Evento {
            $bloccato = $this->prenotazioni->lockEvento($evento->id);

            $this->verificaSottoLock($bloccato, $dati);

            // update() sul modello appena letto sotto lock: cast Eloquent e
            // activity log partono dai valori correnti
            $bloccato->update($dati);

            return $bloccato;
        });
    }
}
