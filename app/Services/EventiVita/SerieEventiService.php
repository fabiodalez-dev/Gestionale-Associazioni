<?php

namespace App\Services\EventiVita;

use App\Models\Evento;
use App\Services\Prenotazioni\CapienzaEventoService;
use App\Services\Prenotazioni\PrenotazioneService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    /**
     * Prenotazioni per il mutex per-evento, capienza per il ricontrollo
     * sotto lock della modifica di serie.
     */
    public function __construct(
        protected PrenotazioneService $prenotazioni,
        protected CapienzaEventoService $capienza,
    ) {}

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
     * Gli id delle occorrenze vengono letti PRIMA della transazione (lettura
     * semplice, in autocommit); nella transazione ogni occorrenza viene
     * bloccata per chiave primaria col mutex per-evento di
     * PrenotazioneService, in ordine di id: ordine stabile fra modifiche di
     * serie concorrenti, niente deadlock. Poi la capienza viene ricontrollata
     * sui posti occupati correnti di ogni occorrenza. Se anche una sola
     * scenderebbe sotto → ValidationException su capienza e nessuna
     * occorrenza viene modificata.
     *
     * Da chiamare fuori da transazioni aperte (come fa il controller): in
     * InnoDB (REPEATABLE READ) una lettura semplice dentro una transazione
     * già aperta fisserebbe lo snapshot prima dei lock e la SUM dei posti
     * occupati non vedrebbe le prenotazioni confermate nel frattempo.
     *
     * @param  array<string, mixed>  $dati
     *
     * @throws ValidationException
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

        // Fuori dalla transazione: una SELECT ... FOR UPDATE sull'indice
        // serie_id bloccherebbe le righe nell'ordine della scansione, non
        // necessariamente per id, e una SELECT semplice come prima istruzione
        // della transazione ne fisserebbe lo snapshot prima dei lock
        $ids = Evento::query()
            ->dellaSerie($evento->serie_id)
            ->where('associazione_id', $evento->associazione_id)
            ->orderBy('id')
            ->pluck('id');

        return DB::transaction(function () use ($ids, $dati): int {
            $occorrenze = $ids
                ->map(fn (string $id): Evento => $this->prenotazioni->lockEvento($id))
                // eliminata nel frattempo (lockEvento ignora i global scope)
                ->reject(fn (Evento $occorrenza): bool => $occorrenza->trashed())
                ->sortBy('data_evento')
                ->values();

            // Ricontrollo sotto lock, in ordine cronologico (primo errore)
            foreach ($occorrenze as $occorrenza) {
                $this->capienza->verificaSottoLock($occorrenza, $dati, occorrenza: true);
            }

            // update() per modello: passa dai cast Eloquent e dall'activity log
            $occorrenze->each(fn (Evento $occorrenza) => $occorrenza->update($dati));

            return $occorrenze->count();
        });
    }

    /**
     * Annulla (soft delete) TUTTE le occorrenze della serie dell'evento.
     * Le prenotazioni aperte delle date future vengono annullate con
     * notifica, come per l'eliminazione del singolo evento.
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
                // Annullamento prenotazioni e soft delete nella stessa
                // transazione, una per occorrenza (mutex per-evento)
                $this->prenotazioni->eliminaEvento($occorrenza);
                $eliminate++;
            });

        return $eliminate;
    }
}
