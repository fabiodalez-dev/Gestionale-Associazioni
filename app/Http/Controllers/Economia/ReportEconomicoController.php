<?php

namespace App\Http\Controllers\Economia;

use App\Exports\Economia\MovimentiExport;
use App\Http\Controllers\Controller;
use App\Models\Movimento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Report economico (f.14 del piano "Gestione 2"): periodo selezionabile,
 * entrate/uscite per categoria, andamento mensile (Chart.js vendorizzato),
 * saldo del periodo ed export Excel dei movimenti.
 */
class ReportEconomicoController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Movimento::class);

        [$dal, $al] = $this->periodo($request);

        $movimenti = $this->movimentiDelPeriodo($dal, $al)->get();

        $totaleEntrate = (float) $movimenti->where('tipo', Movimento::TIPO_ENTRATA)->sum('importo');
        $totaleUscite = (float) $movimenti->where('tipo', Movimento::TIPO_USCITA)->sum('importo');

        // Totali per categoria, separati per tipo
        $perCategoria = $movimenti
            ->groupBy(fn (Movimento $m) => $m->tipo.'|'.($m->categoria->nome ?? 'Senza categoria'))
            ->map(fn ($gruppo) => [
                'tipo' => $gruppo->first()->tipo,
                'categoria' => $gruppo->first()->categoria->nome ?? 'Senza categoria',
                'totale' => (float) $gruppo->sum('importo'),
                'numero' => $gruppo->count(),
            ])
            ->sortByDesc('totale')
            ->values();

        // Andamento mensile (aggregato in PHP: portabile SQLite/MySQL)
        $mensile = [];
        $cursore = $dal->copy()->startOfMonth();

        while ($cursore->lte($al)) {
            $mensile[$cursore->format('Y-m')] = ['entrate' => 0.0, 'uscite' => 0.0];
            $cursore->addMonth();
        }

        foreach ($movimenti as $movimento) {
            $mese = $movimento->data->format('Y-m');

            if (! isset($mensile[$mese])) {
                continue;
            }

            $chiave = $movimento->tipo === Movimento::TIPO_USCITA ? 'uscite' : 'entrate';
            $mensile[$mese][$chiave] += (float) $movimento->importo;
        }

        return view('economia.report.index', [
            'dal' => $dal,
            'al' => $al,
            'totaleEntrate' => $totaleEntrate,
            'totaleUscite' => $totaleUscite,
            'saldo' => $totaleEntrate - $totaleUscite,
            'perCategoria' => $perCategoria,
            'mensile' => $mensile,
            'numeroMovimenti' => $movimenti->count(),
        ]);
    }

    /**
     * Export Excel dei movimenti del periodo (f.14).
     */
    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', Movimento::class);

        [$dal, $al] = $this->periodo($request);

        $movimenti = $this->movimentiDelPeriodo($dal, $al)
            ->orderBy('data')
            ->orderBy('created_at')
            ->get();

        return Excel::download(
            new MovimentiExport($movimenti),
            'report-economico_'.$dal->format('Ymd').'-'.$al->format('Ymd').'.xlsx'
        );
    }

    /**
     * Periodo selezionato (default: anno corrente), sempre dal <= al.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function periodo(Request $request): array
    {
        try {
            $dal = filled($request->query('dal'))
                ? Carbon::parse((string) $request->query('dal'))->startOfDay()
                : now()->startOfYear();
        } catch (\Throwable) {
            $dal = now()->startOfYear();
        }

        try {
            $al = filled($request->query('al'))
                ? Carbon::parse((string) $request->query('al'))->endOfDay()
                : now()->endOfYear();
        } catch (\Throwable) {
            $al = now()->endOfYear();
        }

        if ($dal->gt($al)) {
            [$dal, $al] = [$al->copy()->startOfDay(), $dal->copy()->endOfDay()];
        }

        return [$dal, $al];
    }

    /**
     * @return Builder<Movimento>
     */
    protected function movimentiDelPeriodo(Carbon $dal, Carbon $al): Builder
    {
        return Movimento::query()
            ->with('categoria')
            ->delPeriodo($dal->toDateString(), $al->toDateString());
    }
}
