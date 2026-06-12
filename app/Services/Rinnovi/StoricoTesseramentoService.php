<?php

namespace App\Services\Rinnovi;

use App\Models\EventoPartecipante;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Storico tesseramento del socio (f.6 del piano Vita sociale): timeline
 * per anno con tessere, quote/pagamenti e presenze agli eventi
 * (partecipazioni confermate, cioè con check-in).
 */
class StoricoTesseramentoService
{
    /**
     * Timeline per anno (decrescente). Le chiavi 'tessere' e 'quote'
     * contengono le Collection di Tessera/Quota dell'anno.
     *
     * @return Collection<int, array{
     *     anno: int,
     *     tessere: Collection<int|string, mixed>,
     *     quote: Collection<int|string, mixed>,
     *     presenze: int
     * }>
     */
    public function timeline(Socio $socio): Collection
    {
        $tessere = Tessera::allTenants()
            ->where('associazione_id', $socio->associazione_id)
            ->where('socio_id', $socio->id)
            ->orderByDesc('data_emissione')
            ->get()
            ->groupBy(fn (Tessera $t) => (int) $t->anno_validita);

        $quote = Quota::allTenants()
            ->where('associazione_id', $socio->associazione_id)
            ->where('socio_id', $socio->id)
            ->orderByDesc('data_scadenza')
            ->get()
            ->groupBy(fn (Quota $q) => (int) $q->anno);

        // Estrazione anno portabile: strftime su SQLite (test), YEAR() su MySQL
        $connection = DB::connection()->getDriverName();
        $annoExpr = $connection === 'sqlite'
            ? "CAST(strftime('%Y', eventi.data_evento) AS INTEGER)"
            : 'YEAR(eventi.data_evento)';

        $presenze = EventoPartecipante::allTenants()
            ->join('eventi', 'eventi.id', '=', 'eventi_partecipanti.evento_id')
            ->where('eventi_partecipanti.associazione_id', $socio->associazione_id)
            ->where('eventi_partecipanti.socio_id', $socio->id)
            ->where('eventi_partecipanti.stato_partecipazione', EventoPartecipante::STATO_CONFERMATO)
            ->selectRaw("{$annoExpr} as anno_evento, COUNT(*) as cnt")
            ->groupBy('anno_evento')
            ->toBase()
            ->pluck('cnt', 'anno_evento')
            ->mapWithKeys(fn ($cnt, $anno) => [(int) $anno => (int) $cnt]);

        $anni = $tessere->keys()
            ->merge($quote->keys())
            ->merge($presenze->keys())
            ->unique()
            ->sortDesc()
            ->values();

        return $anni->map(fn (int $anno) => [
            'anno' => $anno,
            'tessere' => $tessere->get($anno, collect()),
            'quote' => $quote->get($anno, collect()),
            'presenze' => (int) $presenze->get($anno, 0),
        ]);
    }
}
