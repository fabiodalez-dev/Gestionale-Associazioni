<?php

namespace App\Services\EventiVita;

use App\Models\Account;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Prenotazione;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Builder;

/**
 * Allinea i due canali delle presenze di un SOCIO a un evento:
 * - eventi_partecipanti "Confermato" (check-in con la tessera, registrazione admin);
 * - prenotazioni con check-in (biglietto, presenze in blocco) dell'account
 *   collegato al socio.
 *
 * Una presenza registrata su un canale viene riportata sull'altro, così
 * vita sociale, feedback post-evento e statistiche contano la stessa
 * persona allo stesso modo da qualunque ingresso sia passata. I conteggi
 * per evento (kiosk) evitano il doppio conteggio con
 * sociGiaContatiInPrenotazione().
 */
class PresenzeSociService
{
    /**
     * Check-in con la tessera: segna anche la prenotazione confermata
     * dell'account collegato al socio per lo stesso evento, se non ancora
     * arrivata (stessi campi del check-in del biglietto).
     */
    public function segnaPrenotazioneDelSocio(Evento $evento, Socio $socio, ?string $utenteId): int
    {
        $accountIds = Account::query()
            ->withoutGlobalScope('associazione')
            ->where('associazione_id', $socio->associazione_id)
            ->where('socio_id', $socio->id)
            ->pluck('id');

        if ($accountIds->isEmpty()) {
            return 0;
        }

        return Prenotazione::query()
            ->withoutGlobalScope('associazione')
            ->where('evento_id', $evento->id)
            ->whereIn('account_id', $accountIds)
            ->where('stato', Prenotazione::STATO_CONFERMATA)
            ->whereNull('checkin_at')
            ->update([
                'checkin_at' => now(),
                'checkin_utente_id' => $utenteId,
            ]);
    }

    /**
     * Presenza registrata sulle prenotazioni (presenze in blocco, scanner
     * biglietti): i soci ATTIVI collegati agli account di quelle
     * prenotazioni con check-in risultano anche partecipanti confermati
     * dell'evento, come dopo il check-in con la tessera.
     *
     * @param  iterable<string>  $prenotazioneIds
     */
    public function registraSociDellePrenotazioni(Evento $evento, iterable $prenotazioneIds): int
    {
        $prenotazioneIds = collect($prenotazioneIds)->values();

        if ($prenotazioneIds->isEmpty()) {
            return 0;
        }

        $socioIds = Socio::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('associazione_id', $evento->associazione_id)
            ->where('stato', Socio::STATO_ATTIVO)
            ->whereIn('id', Account::query()
                ->withoutGlobalScope('associazione')
                ->whereNotNull('socio_id')
                ->whereIn('id', Prenotazione::query()
                    ->withoutGlobalScope('associazione')
                    ->where('evento_id', $evento->id)
                    ->whereIn('id', $prenotazioneIds)
                    ->whereNotNull('checkin_at')
                    ->select('account_id'))
                ->select('socio_id'))
            ->pluck('id');

        $registrati = 0;

        foreach ($socioIds as $socioId) {
            $partecipante = EventoPartecipante::query()
                ->withoutGlobalScopes()
                ->where('evento_id', $evento->id)
                ->where('socio_id', $socioId)
                ->first();

            if ($partecipante?->stato_partecipazione === EventoPartecipante::STATO_CONFERMATO) {
                continue;
            }

            if ($partecipante !== null) {
                $partecipante->update([
                    'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
                    'data_conferma' => now(),
                ]);
            } else {
                EventoPartecipante::create([
                    'associazione_id' => $evento->associazione_id,
                    'evento_id' => $evento->id,
                    'socio_id' => $socioId,
                    'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
                    'data_conferma' => now(),
                ]);
            }

            $registrati++;
        }

        return $registrati;
    }

    /**
     * Soci dell'evento già contati tramite una prenotazione con check-in
     * (account collegato): da escludere quando si sommano partecipanti
     * soci e posti delle prenotazioni, per non contare due volte la
     * stessa persona.
     *
     * @return Builder<Account>
     */
    public function sociGiaContatiInPrenotazione(Evento $evento): Builder
    {
        return Account::query()
            ->withoutGlobalScope('associazione')
            ->whereNotNull('socio_id')
            ->whereIn('id', Prenotazione::query()
                ->withoutGlobalScope('associazione')
                ->where('evento_id', $evento->id)
                ->whereNotNull('checkin_at')
                ->select('account_id'))
            ->select('socio_id');
    }
}
