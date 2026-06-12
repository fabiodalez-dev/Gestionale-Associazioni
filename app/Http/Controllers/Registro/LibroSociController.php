<?php

namespace App\Http\Controllers\Registro;

use App\Exports\LibroSociExport;
use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\Socio;
use App\Services\Registro\LibroSociService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Libro soci ufficiale (f.13 del piano vita sociale): vista read-only del
 * registro cronologico per anno (numero progressivo di iscrizione, dati
 * anagrafici, ammissione con delibera, cessazione) + export PDF intestato
 * ed Excel. Autorizzazione: SocioPolicy::viewAny (soci.view).
 */
class LibroSociController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly LibroSociService $libro) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Socio::class);

        $anno = $this->annoFiltro($request);
        $righe = $this->libro->righe($anno);

        return view('registro.libro-soci.index', [
            'righe' => $righe,
            'perAnno' => collect($righe)->groupBy('anno')->sortKeysDesc(),
            'anno' => $anno,
            'anni' => $this->libro->anni(),
        ]);
    }

    /**
     * Export PDF intestato (f.13).
     */
    public function pdf(Request $request): Response
    {
        $this->authorize('viewAny', Socio::class);

        $anno = $this->annoFiltro($request);
        $righe = $this->libro->righe($anno);

        $pdf = Pdf::loadView('registro.libro-soci.pdf', [
            'perAnno' => collect($righe)->groupBy('anno')->sortKeysDesc(),
            'anno' => $anno,
            'associazione' => Associazione::query()->find(session('associazione_id')),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('libro-soci'.($anno !== null ? '-'.$anno : '').'-'.now()->format('Ymd').'.pdf');
    }

    /**
     * Export Excel (f.13).
     */
    public function excel(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', Socio::class);

        $anno = $this->annoFiltro($request);

        return Excel::download(
            new LibroSociExport($this->libro->righe($anno)),
            'libro-soci'.($anno !== null ? '-'.$anno : '').'-'.now()->format('Ymd').'.xlsx'
        );
    }

    private function annoFiltro(Request $request): ?int
    {
        $anno = $request->query('anno');

        return is_numeric($anno) ? (int) $anno : null;
    }
}
