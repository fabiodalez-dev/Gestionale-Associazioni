<?php

namespace App\Http\Controllers\VolontariTurni;

use App\Http\Controllers\Controller;
use App\Http\Requests\VolontariTurni\StoreOreManualiRequest;
use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use App\Services\VolontariTurni\RegistroOreService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registro ore volontariato (f.5 del piano "Organizzazione 3"): elenco ore
 * registrate, inserimento manuale "fuori turno" e attestato PDF annuale
 * intestato all'associazione.
 */
class RegistroOreController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly RegistroOreService $registro) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Socio::class);

        $anno = (int) $request->query('anno', (string) now()->year);

        $voci = TurnoAssegnazione::query()
            ->with(['turno:id,titolo,data,ora_inizio,ora_fine', 'socio:id,nome,cognome,numero_socio'])
            ->whereNotNull('ore_effettive')
            ->whereHas('turno', fn ($q) => $q->whereYear('data', $anno))
            ->get()
            ->sortByDesc(fn (TurnoAssegnazione $a) => $a->turno?->data?->toDateString())
            ->values();

        $totale = round((float) $voci->sum('ore_effettive'), 2);

        return view('volontari-turni.registro.index', [
            'voci' => $voci,
            'anno' => $anno,
            'totale' => $totale,
            'sociVolontari' => Socio::query()
                ->where('volontario_attivo', true)
                ->orderBy('cognome')->orderBy('nome')
                ->get(['id', 'nome', 'cognome', 'numero_socio']),
        ]);
    }

    /**
     * Inserimento manuale ore "fuori turno" (f.5).
     */
    public function storeManuali(StoreOreManualiRequest $request): RedirectResponse
    {
        $this->authorize('viewAny', Socio::class);

        /** @var Socio $socio */
        $socio = Socio::query()->findOrFail($request->validated('socio_id'));

        $this->authorize('update', $socio);

        $this->registro->aggiungiOreManuali(
            $socio,
            (float) $request->validated('ore'),
            Carbon::parse($request->validated('data')),
            $request->validated('descrizione'),
        );

        return redirect()
            ->route('registro-ore.index', ['anno' => Carbon::parse($request->validated('data'))->year])
            ->with('success', 'Ore registrate.');
    }

    /**
     * Elimina una voce del registro (azzera le ore; rimuove il
     * turno-contenitore se era un inserimento manuale).
     */
    public function destroy(TurnoAssegnazione $assegnazione): RedirectResponse
    {
        $socio = $assegnazione->socio;
        if ($socio !== null) {
            $this->authorize('update', $socio);
        } else {
            $this->authorize('viewAny', Socio::class);
        }

        $assegnazione->loadMissing('turno');

        if ($this->registro->isManuale($assegnazione)) {
            $turno = $assegnazione->turno;
            $assegnazione->delete();
            $turno?->delete();
        } else {
            $assegnazione->update(['ore_effettive' => null]);
        }

        return back()->with('success', 'Voce del registro rimossa.');
    }

    /**
     * Attestato PDF annuale delle ore di volontariato di un socio (f.5).
     */
    public function attestato(Request $request, Socio $socio): Response
    {
        $this->authorize('view', $socio);

        $anno = (int) $request->query('anno', (string) now()->year);

        $voci = $this->registro->vociAnno($socio, $anno);
        $totale = $this->registro->totaleOreSocio($socio, $anno);

        $associazione = Associazione::query()->find($socio->associazione_id);

        $pdf = Pdf::loadView('volontari-turni.pdf.attestato', [
            'socio' => $socio,
            'associazione' => $associazione,
            'anno' => $anno,
            'voci' => $voci,
            'totale' => $totale,
            'registro' => $this->registro,
        ]);

        $nomeFile = 'attestato-volontariato-'.str($socio->nome_completo)->slug()->limit(50, '').'-'.$anno.'.pdf';

        return $pdf->download($nomeFile);
    }
}
