<?php

namespace App\Services\EventiVita;

use App\Models\Evento;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Eventi ricorrenti (f.8 del piano Vita sociale): genera le occorrenze
 * come eventi "fratelli" con serie_id condiviso (uuid), e gestisce le
 * operazioni sull'intera serie (aggiornamento campi comuni, annullo).
 *
 * La singola occorrenza resta un Evento a tutti gli effetti: si modifica
 * o elimina con il CRUD standard senza toccare le altre.
 */
class SerieEventiService
{
    public const FREQUENZA_SETTIMANALE = 'settimanale';

    public const FREQUENZA_MENSILE = 'mensile';

    public const FREQUENZE = [
        self::FREQUENZA_SETTIMANALE,
        self::FREQUENZA_MENSILE,
    ];

    /** Tetto massimo di occorrenze generabili per una serie (spec f.8). */
    public const MAX_OCCORRENZE = 52;

    /**
     * Campi dell'evento che un aggiornamento di serie propaga a tutte le
     * occorrenze (mai le date: ogni occorrenza ha le proprie).
     */
    public const CAMPI_SERIE = [
        'titolo',
        'descrizione',
        'luogo',
        'stato_pubblicazione',
        'capienza',
        'prezzo',
        'iscrizioni_aperte',
        'richiede_approvazione',
        'solo_soci',
        'immagine_url',
    ];

    /**
     * Crea la serie: la prima occorrenza alla data_evento indicata, le
     * successive a passi di una settimana o un mese (fine mese sicuro,
     * niente overflow: 31 gen + 1 mese = 28/29 feb) fino a $finoAl
     * incluso, con il tetto MAX_OCCORRENZE.
     *
     * @param  array<model-property<Evento>, mixed>  $dati  attributi validati dell'evento (senza serie_id)
     * @return list<Evento> le occorrenze create, in ordine cronologico
     */
    public function creaSerie(array $dati, string $frequenza, CarbonInterface $finoAl): array
    {
        $serieId = (string) Str::uuid();

        $inizio = Carbon::parse($dati['data_evento']);
        $fine = filled($dati['data_fine'] ?? null) ? Carbon::parse($dati['data_fine']) : null;
        // Durata dell'evento preservata su ogni occorrenza
        $durataMinuti = $fine !== null ? $inizio->diffInMinutes($fine) : null;

        unset($dati['data_evento'], $dati['data_fine']);

        return DB::transaction(function () use ($dati, $frequenza, $finoAl, $serieId, $inizio, $durataMinuti): array {
            $occorrenze = [];
            $cursore = $inizio->copy();

            while ($cursore->lte($finoAl) && count($occorrenze) < self::MAX_OCCORRENZE) {
                $occorrenze[] = Evento::create($dati + [
                    'serie_id' => $serieId,
                    'data_evento' => $cursore->copy(),
                    'data_fine' => $durataMinuti !== null
                        ? $cursore->copy()->addMinutes($durataMinuti)
                        : null,
                ]);

                $cursore = $frequenza === self::FREQUENZA_MENSILE
                    ? $cursore->copy()->addMonthNoOverflow()
                    : $cursore->copy()->addWeek();
            }

            return $occorrenze;
        });
    }

    /**
     * Propaga i campi comuni (CAMPI_SERIE) a TUTTE le occorrenze della
     * serie dell'evento. Ritorna il numero di occorrenze aggiornate.
     *
     * @param  array<string, mixed>  $dati
     */
    public function aggiornaSerie(Evento $evento, array $dati): int
    {
        if ($evento->serie_id === null) {
            return 0;
        }

        $dati = array_intersect_key($dati, array_flip(self::CAMPI_SERIE));

        if ($dati === []) {
            return 0;
        }

        $aggiornate = 0;

        // update() per modello: passa dai cast Eloquent e dall'activity log
        Evento::query()
            ->dellaSerie($evento->serie_id)
            ->where('associazione_id', $evento->associazione_id)
            ->get()
            ->each(function (Evento $occorrenza) use ($dati, &$aggiornate): void {
                $occorrenza->update($dati);
                $aggiornate++;
            });

        return $aggiornate;
    }

    /**
     * Annulla (soft delete) TUTTE le occorrenze della serie dell'evento.
     * Ritorna il numero di occorrenze eliminate.
     */
    public function eliminaSerie(Evento $evento): int
    {
        if ($evento->serie_id === null) {
            return 0;
        }

        $eliminate = 0;

        Evento::query()
            ->dellaSerie($evento->serie_id)
            ->where('associazione_id', $evento->associazione_id)
            ->get()
            ->each(function (Evento $occorrenza) use (&$eliminate): void {
                $occorrenza->delete();
                $eliminate++;
            });

        return $eliminate;
    }
}
