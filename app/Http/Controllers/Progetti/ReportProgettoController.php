<?php

namespace App\Http\Controllers\Progetti;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\EventoPartecipante;
use App\Models\Progetto;
use App\Services\Progetti\ProgettoReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Report progetto in PDF intestato (f.15 del piano "Organizzazione 3"):
 * anagrafica progetto, rendiconto economico (f.13), eventi svolti con
 * numero partecipanti confermati e ore di volontariato dai turni (f.14).
 * Stessa famiglia permessi della visualizzazione (struttura.view → view).
 */
class ReportProgettoController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly ProgettoReportService $report) {}

    public function __invoke(Progetto $progetto): Response
    {
        $this->authorize('view', $progetto);

        $progetto->load([
            'responsabile',
            'eventi' => fn ($q) => $q->orderByDesc('data_evento'),
            'milestones' => fn ($q) => $q->orderBy('ordine')->orderBy('scadenza'),
        ]);

        // Partecipanti confermati per ciascun evento collegato
        $partecipantiPerEvento = EventoPartecipante::query()
            ->whereIn('evento_id', $progetto->eventi->pluck('id'))
            ->where('stato_partecipazione', EventoPartecipante::STATO_CONFERMATO)
            ->selectRaw('evento_id, COUNT(*) as totale')
            ->groupBy('evento_id')
            ->pluck('totale', 'evento_id');

        $pdf = Pdf::loadView('progetti.report-pdf', [
            'progetto' => $progetto,
            'associazione' => Associazione::query()->find($progetto->associazione_id),
            'rendiconto' => $this->report->rendiconto($progetto),
            'oreVolontariato' => $this->report->oreVolontariato($progetto),
            'partecipantiPerEvento' => $partecipantiPerEvento,
        ])->setPaper('a4', 'portrait');

        $slug = Str::slug($progetto->nome) ?: 'progetto';

        return $pdf->download('report-progetto-'.$slug.'-'.now()->format('Ymd').'.pdf');
    }
}
