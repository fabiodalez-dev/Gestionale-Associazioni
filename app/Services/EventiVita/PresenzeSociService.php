<?php

namespace App\Services\EventiVita;

use App\Models\Account;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Prenotazione;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Allinea i due canali delle presenze di un SOCIO a un evento:
 * - eventi_partecipanti "Confermato" (check-in con la tessera, registrazione admin);
 * - prenotazioni con check-in (biglietto, presenze in blocco) dell'account
 *   collegato al socio.
 *
 * Una presenza registrata su un canale viene riportata sull'altro, così
 * vita sociale, feedback post-evento e statistiche contano la stessa
 * persona allo stesso modo da qualunque ingresso sia passata. I conteggi
 * per evento (kiosk) sommano i posti_entrati delle prenotazioni ed evitano
 * il doppio conteggio con sociGiaContatiInPrenotazione().
 *
 * Il check-in riportato dalla tessera sulla prenotazione (origine
 * tessera/api) vale un posto e si annulla se il partecipante viene rimosso o
 * non è più Confermato (annullaCheckinSincronizzato).
 */
class PresenzeSociService
{
    /**
     * Check-in con la tessera (backoffice, kiosk) o dall'API v1: segna anche
     * la prenotazione confermata dell'account collegato al socio per lo
     * stesso evento, se non ancora arrivata. Registra un solo posto (quello
     * del socio: i compagni entrano con il biglietto) con origine
     * sincronizzata, annullata da annullaCheckinSincronizzato() se il
     * partecipante viene poi rimosso o non è più Confermato.
     *
     * @param  string  $origine  Prenotazione::CHECKIN_ORIGINE_TESSERA | CHECKIN_ORIGINE_API
     */
    public function segnaPrenotazioneDelSocio(Evento $evento, Socio $socio, ?string $utenteId, string $origine = Prenotazione::CHECKIN_ORIGINE_TESSERA): int
    {
        $accountIds = $this->accountDelSocio((string) $socio->associazione_id, (string) $socio->id);

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
                'posti_entrati' => 1,
                'checkin_origine' => $origine,
                'checkin_utente_id' => $utenteId,
            ]);
    }

    /**
     * Il partecipante socio è stato rimosso o non è più Confermato: annulla
     * il check-in che il suo ingresso aveva riportato sulla prenotazione
     * dell'account collegato (origine tessera/api). Il check-in sincronizzato
     * vale sempre un solo posto (quello del socio), quindi la prenotazione
     * torna "non arrivata": niente posto al kiosk, niente richiesta di
     * feedback, biglietto di nuovo valido. I check-in registrati a mano
     * (biglietto, presenze in blocco) o storici senza origine restano.
     *
     * Chiamato PRIMA di scrivere la partecipazione, nella stessa transazione:
     * stesso ordine dei lock del check-in con la tessera (prenotazione, poi
     * partecipante).
     */
    public function annullaCheckinSincronizzato(Evento $evento, string $socioId): int
    {
        $accountIds = $this->accountDelSocio((string) $evento->associazione_id, $socioId);

        if ($accountIds->isEmpty()) {
            return 0;
        }

        return Prenotazione::query()
            ->withoutGlobalScope('associazione')
            ->where('evento_id', $evento->id)
            ->whereIn('account_id', $accountIds)
            ->whereIn('checkin_origine', Prenotazione::CHECKIN_ORIGINI_SINCRONIZZATE)
            ->update([
                'checkin_at' => null,
                'posti_entrati' => 0,
                'checkin_origine' => null,
                'checkin_utente_id' => null,
            ]);
    }

    /**
     * Registrazione manuale dell'ingresso (scansione del biglietto, presenze
     * in blocco): tutti i posti ancora da far entrare delle prenotazioni
     * CONFERMATE indicate risultano entrati. Su un ingresso già iniziato (il
     * socio arrivato con la tessera) checkin_at resta l'orario del primo
     * ingresso; l'origine diventa quella manuale, così il check-in non è più
     * annullabile dalla partecipazione del socio. Le prenotazioni già entrate
     * per intero, di altri eventi o in altri stati vengono ignorate.
     * Ritorna il numero di prenotazioni aggiornate.
     *
     * @param  iterable<string>  $prenotazioneIds
     * @param  string  $origine  Prenotazione::CHECKIN_ORIGINE_BIGLIETTO | CHECKIN_ORIGINE_PRESENZE_BLOCCO
     */
    public function registraPostiRimanenti(Evento $evento, iterable $prenotazioneIds, string $origine, ?string $utenteId): int
    {
        $prenotazioneIds = collect($prenotazioneIds)->values();

        if ($prenotazioneIds->isEmpty()) {
            return 0;
        }

        $daCompletare = fn () => Prenotazione::query()
            ->withoutGlobalScope('associazione')
            ->where('evento_id', $evento->id)
            ->whereIn('id', $prenotazioneIds)
            ->where('stato', Prenotazione::STATO_CONFERMATA)
            ->whereColumn('posti_entrati', '<', 'posti');

        // Due UPDATE su insiemi disgiunti: ingresso già iniziato (checkin_at
        // conservato) e prima registrazione
        $completate = $daCompletare()
            ->whereNotNull('checkin_at')
            ->update([
                'posti_entrati' => DB::raw('posti'),
                'checkin_origine' => $origine,
                'checkin_utente_id' => $utenteId,
            ]);

        $nuove = $daCompletare()
            ->whereNull('checkin_at')
            ->update([
                'checkin_at' => now(),
                'posti_entrati' => DB::raw('posti'),
                'checkin_origine' => $origine,
                'checkin_utente_id' => $utenteId,
            ]);

        return $completate + $nuove;
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
                    // Solo biglietti validi: una prenotazione annullata dopo
                    // il check-in non registra il socio tra i presenti
                    ->where('stato', Prenotazione::STATO_CONFERMATA)
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
                try {
                    EventoPartecipante::create([
                        'associazione_id' => $evento->associazione_id,
                        'evento_id' => $evento->id,
                        'socio_id' => $socioId,
                        'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
                        'data_conferma' => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Registrato in parallelo (altro scanner o check-in con la
                    // tessera, unique evento+socio): già tra i partecipanti
                    continue;
                }
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

    /**
     * Account (frontend pubblico) collegati al socio.
     *
     * @return Collection<int, string>
     */
    private function accountDelSocio(string $associazioneId, string $socioId): Collection
    {
        return Account::query()
            ->withoutGlobalScope('associazione')
            ->where('associazione_id', $associazioneId)
            ->where('socio_id', $socioId)
            ->pluck('id');
    }
}
