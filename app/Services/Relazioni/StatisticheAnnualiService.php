<?php

namespace App\Services\Relazioni;

use App\Models\Cessazione;
use App\Models\Donazione;
use App\Models\EventoPartecipante;
use App\Models\Movimento;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\TurnoAssegnazione;

/**
 * "Anno in cifre" (f.25 del piano "Organizzazione 3"): aggrega le metriche
 * annuali del tenant e le confronta con l'anno precedente per la pagina
 * Statistiche annuali (grafici Chart.js) e l'export PDF per l'assemblea.
 *
 * Tutte le query si appoggiano al global scope BelongsToAssociazione
 * (isolamento multi-tenant); l'estrazione dell'anno usa whereYear, portabile
 * fra SQLite (test) e MySQL (E2E/produzione).
 *
 * @phpstan-type Metriche array{
 *     nuovi_soci: int, cessazioni: int, retention: float, tessere: int,
 *     partecipazioni: int, ore_volontariato: float, entrate: float,
 *     uscite: float, saldo: float, donazioni: float
 * }
 */
class StatisticheAnnualiService
{
    /**
     * Metriche dei due anni a confronto + variazioni percentuali.
     *
     * @return array{
     *     anno: int,
     *     anno_precedente: int,
     *     corrente: Metriche,
     *     precedente: Metriche,
     *     variazioni: array<string, ?float>
     * }
     */
    public function confronto(int $anno): array
    {
        $corrente = $this->metriche($anno);
        $precedente = $this->metriche($anno - 1);

        $variazioni = [];
        foreach ($corrente as $chiave => $valore) {
            $variazioni[$chiave] = $this->variazionePercentuale(
                (float) $precedente[$chiave],
                (float) $valore
            );
        }

        return [
            'anno' => $anno,
            'anno_precedente' => $anno - 1,
            'corrente' => $corrente,
            'precedente' => $precedente,
            'variazioni' => $variazioni,
        ];
    }

    /**
     * Tutte le metriche per un singolo anno solare.
     *
     * @return Metriche
     */
    public function metriche(int $anno): array
    {
        $nuoviSoci = Socio::query()->whereYear('data_iscrizione', $anno)->count();
        $cessazioni = Cessazione::query()->whereYear('data', $anno)->count();

        // Soci attivi a fine anno precedente (base di calcolo della retention)
        $baseInizio = Socio::query()
            ->whereDate('data_iscrizione', '<', $anno.'-01-01')
            ->count();

        $retention = $baseInizio > 0
            ? round((($baseInizio - $cessazioni) / $baseInizio) * 100, 1)
            : 0.0;

        $tessere = Tessera::query()->where('anno_validita', $anno)->count();

        $partecipazioni = EventoPartecipante::query()
            ->where('stato_partecipazione', EventoPartecipante::STATO_CONFERMATO)
            ->whereHas('evento', fn ($q) => $q->whereYear('data_evento', $anno))
            ->count();

        $oreVolontariato = round((float) TurnoAssegnazione::query()
            ->whereNotNull('ore_effettive')
            ->whereHas('turno', fn ($q) => $q->whereYear('data', $anno))
            ->sum('ore_effettive'), 2);

        $entrate = round((float) Movimento::query()
            ->where('tipo', Movimento::TIPO_ENTRATA)
            ->whereYear('data', $anno)
            ->sum('importo'), 2);

        $uscite = round((float) Movimento::query()
            ->where('tipo', Movimento::TIPO_USCITA)
            ->whereYear('data', $anno)
            ->sum('importo'), 2);

        $donazioni = round((float) Donazione::query()
            ->where('stato', Donazione::STATO_PAGATA)
            ->whereYear('pagata_at', $anno)
            ->sum('importo'), 2);

        return [
            'nuovi_soci' => $nuoviSoci,
            'cessazioni' => $cessazioni,
            'retention' => $retention,
            'tessere' => $tessere,
            'partecipazioni' => $partecipazioni,
            'ore_volontariato' => $oreVolontariato,
            'entrate' => $entrate,
            'uscite' => $uscite,
            'saldo' => round($entrate - $uscite, 2),
            'donazioni' => $donazioni,
        ];
    }

    /**
     * Variazione percentuale fra due valori (null se la base è zero:
     * "nuovo" senza confronto significativo).
     */
    private function variazionePercentuale(float $base, float $valore): ?float
    {
        if (abs($base) < 0.0001) {
            return null;
        }

        return round((($valore - $base) / abs($base)) * 100, 1);
    }

    /**
     * Anni per cui esistono dati (per il selettore della pagina).
     * Include sempre l'anno corrente.
     *
     * @return list<int>
     */
    public function anniDisponibili(): array
    {
        $anni = [];

        foreach (Socio::query()->pluck('data_iscrizione') as $data) {
            if ($data !== null) {
                $anni[(int) $data->format('Y')] = true;
            }
        }

        $anni[(int) date('Y')] = true;

        $lista = array_keys($anni);
        rsort($lista);

        return $lista;
    }
}
