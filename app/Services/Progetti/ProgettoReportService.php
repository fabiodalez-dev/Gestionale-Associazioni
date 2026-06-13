<?php

namespace App\Services\Progetti;

use App\Models\CategoriaMovimento;
use App\Models\Movimento;
use App\Models\Progetto;
use App\Models\TurnoAssegnazione;
use Illuminate\Support\Collection;

/**
 * Aggregazioni del progetto condivise dal dettaglio (f.13/f.14) e dal
 * report PDF (f.15): rendiconto economico dai movimenti di prima nota
 * imputati al progetto e ore di volontariato dai turni collegati.
 */
class ProgettoReportService
{
    /**
     * Rendiconto entrate/uscite del progetto (f.13): totali, saldo e
     * dettaglio per categoria, partendo dai movimenti imputati al progetto.
     *
     * @return array{
     *     entrate: float,
     *     uscite: float,
     *     saldo: float,
     *     budget: float|null,
     *     scostamento: float|null,
     *     perCategoria: Collection<int, array{categoria: string, tipo: string, totale: float}>,
     *     numeroMovimenti: int
     * }
     */
    public function rendiconto(Progetto $progetto): array
    {
        $movimenti = Movimento::query()
            ->where('progetto_id', $progetto->id)
            ->with('categoria')
            ->get(['id', 'tipo', 'importo', 'categoria_id']);

        $entrate = (float) $movimenti->where('tipo', Movimento::TIPO_ENTRATA)->sum('importo');
        $uscite = (float) $movimenti->where('tipo', Movimento::TIPO_USCITA)->sum('importo');
        $budget = $progetto->budget !== null ? (float) $progetto->budget : null;

        $perCategoria = $movimenti
            ->groupBy(fn (Movimento $m): string => (string) $m->categoria_id)
            ->map(function (Collection $gruppo): array {
                /** @var Movimento $primo */
                $primo = $gruppo->first();

                return [
                    // categoria_id è nullable (nullOnDelete): ?? cattura il caso
                    // di categoria mancante senza nullsafe (idioma del codebase)
                    'categoria' => $primo->categoria->nome ?? 'Senza categoria',
                    'tipo' => (string) $primo->tipo,
                    'totale' => (float) $gruppo->sum('importo'),
                ];
            })
            ->sortByDesc('totale')
            ->values();

        return [
            'entrate' => $entrate,
            'uscite' => $uscite,
            'saldo' => $entrate - $uscite,
            'budget' => $budget,
            // Scostamento budget − uscite (positivo = margine residuo)
            'scostamento' => $budget !== null ? $budget - $uscite : null,
            'perCategoria' => $perCategoria,
            'numeroMovimenti' => $movimenti->count(),
        ];
    }

    /**
     * Ore di volontariato registrate sui turni collegati al progetto (f.15):
     * somma delle ore_effettive delle assegnazioni dei turni del progetto.
     *
     * @return array{totale: float, perSocio: Collection<int, array{socio: string, ore: float}>}
     */
    public function oreVolontariato(Progetto $progetto): array
    {
        $turniIds = $progetto->turni()->pluck('id');

        if ($turniIds->isEmpty()) {
            return ['totale' => 0.0, 'perSocio' => collect()];
        }

        $assegnazioni = TurnoAssegnazione::query()
            ->whereIn('turno_id', $turniIds)
            ->whereNotNull('ore_effettive')
            ->with('socio:id,nome,cognome')
            ->get(['id', 'turno_id', 'socio_id', 'ore_effettive']);

        $perSocio = $assegnazioni
            ->groupBy(fn (TurnoAssegnazione $a): string => (string) $a->socio_id)
            ->map(function (Collection $gruppo): array {
                /** @var TurnoAssegnazione $primo */
                $primo = $gruppo->first();
                $socio = $primo->socio;

                return [
                    'socio' => $socio !== null
                        ? trim($socio->cognome.' '.$socio->nome)
                        : 'Socio',
                    'ore' => (float) $gruppo->sum('ore_effettive'),
                ];
            })
            ->sortByDesc('ore')
            ->values();

        return [
            'totale' => (float) $assegnazioni->sum('ore_effettive'),
            'perSocio' => $perSocio,
        ];
    }

    /**
     * Categorie di movimento del tenant per la select "imputa al progetto"
     * nella prima nota (f.13).
     *
     * @return Collection<int, CategoriaMovimento>
     */
    public function categorieTenant(): Collection
    {
        return CategoriaMovimento::query()
            ->orderBy('tipo')
            ->orderBy('nome')
            ->get();
    }
}
