<?php

namespace App\Http\Controllers\Soci;

use App\Exports\SociExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Soci\ImportSociRequest;
use App\Http\Requests\Soci\StoreSocioRequest;
use App\Http\Requests\Soci\UpdateSocioRequest;
use App\Imports\SociImport;
use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\CategoriaSocio;
use App\Models\GruppoDinamico;
use App\Models\SavedFilter;
use App\Models\Sede;
use App\Models\Socio;
use App\Models\Tag;
use App\Models\Tessera;
use App\Models\TipoSocio;
use App\Services\Soci\NumeroProgressivoService;
use App\Services\Soci\SociFilter;
use App\Services\Soci\SocioActivityLogger;
use App\Services\Soci\SocioService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class SocioController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly SocioService $socioService) {}

    /**
     * Lista soci con filtri (spec soci §1.8/§1.9), preset salvati (§11),
     * risposta AJAX {count, html} (§1.10) e paginazione (miglioramento legacy).
     */
    public function index(Request $request): View|JsonResponse
    {
        $this->authorize('viewAny', Socio::class);

        $associazione = Associazione::find(Socio::currentAssociazioneId());

        // Caricamento preset (spec §11.2): i parametri del preset sovrascrivono i default
        $params = $request->query();
        $presetCorrente = null;

        if ($request->filled('preset_id')) {
            $presetCorrente = SavedFilter::query()
                ->where('scope', 'soci')
                ->find($request->query('preset_id'));

            if ($presetCorrente !== null) {
                $params = array_merge((array) $presetCorrente->params_json, ['preset_id' => $presetCorrente->id]);
            }
        }

        $filter = new SociFilter($params, $associazione);

        $soci = $filter->build()
            ->paginate(25)
            ->appends(array_merge($filter->params(), array_filter(['preset_id' => $presetCorrente?->id])));

        $isSuperAdmin = $request->user()->isSuperAdmin();

        if ($request->boolean('ajax')) {
            return response()->json([
                'count' => $soci->total(),
                'html' => view('soci.partials.lista', [
                    'soci' => $soci,
                    'associazione' => $associazione,
                    'isSuperAdmin' => $isSuperAdmin,
                ])->render(),
            ]);
        }

        return view('soci.index', [
            'soci' => $soci,
            'filtri' => $filter->params(),
            'associazione' => $associazione,
            'isSuperAdmin' => $isSuperAdmin,
            'sedi' => Sede::query()->orderBy('nome')->get(['id', 'nome']),
            'categorie' => CategoriaSocio::query()->orderBy('nome')->get(['id', 'nome']),
            'gruppi' => GruppoDinamico::query()->orderBy('nome_gruppo')->get(['id', 'nome_gruppo']),
            'templatesTessera' => Tessera::query()->whereNotNull('template_tessera')->distinct()->orderBy('template_tessera')->pluck('template_tessera'),
            'presets' => SavedFilter::query()
                ->where('scope', 'soci')
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $request->user()->id))
                ->orderBy('name')
                ->get(),
            'presetCorrente' => $presetCorrente,
            'pendingCount' => Socio::query()
                ->whereIn('stato', [Socio::STATO_IN_ATTESA, Socio::STATO_IN_ATTESA_DI_PAGAMENTO])
                ->count(),
            'cestinoCount' => Socio::onlyTrashed()->count(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Socio::class);

        return view('soci.create', $this->datiForm());
    }

    public function store(StoreSocioRequest $request): RedirectResponse
    {
        $this->authorize('create', Socio::class);

        $socio = $this->socioService->crea(
            $request->datiSocio(),
            $request->tagIds(),
            $request->campiPersonalizzati(),
            $request->boolean('genera_tessera', true)
        );

        return redirect()
            ->route('soci.show', $socio)
            ->with('success', "Socio {$socio->nome_completo} creato con successo (n. {$socio->numero_socio}).");
    }

    /**
     * Dettaglio socio con tab anagrafica / quote e tessere / documenti / storico
     * (spec soci §10) + tag e campi personalizzati.
     */
    public function show(Socio $socio): View
    {
        $this->authorize('view', $socio);

        $socio->load([
            'tipoSocio', 'categoriaSocio', 'sede', 'associazione', 'tags',
            'valoriCampiPersonalizzati.campo',
            'tessere' => fn ($q) => $q->orderByDesc('anno_validita'),
            'quote' => fn ($q) => $q->orderByDesc('anno'),
            'documenti' => fn ($q) => $q->orderByDesc('created_at'),
            'storicoAttivita' => fn ($q) => $q->orderByDesc('data_attivita')->with('utente'),
        ]);

        return view('soci.show', [
            'socio' => $socio,
            // Costo tessera mostrato: override tipo socio, fallback associazione (spec §3.4)
            'costoTessera' => $socio->tipoSocio?->costo_tessera ?? $socio->associazione?->costo_tessera,
        ]);
    }

    public function edit(Socio $socio): View
    {
        $this->authorize('update', $socio);

        $socio->load(['tags', 'valoriCampiPersonalizzati']);

        return view('soci.edit', $this->datiForm() + [
            'socio' => $socio,
            'valoriCampi' => $socio->valoriCampiPersonalizzati->pluck('valore', 'campo_id'),
            'tagSelezionati' => $socio->tags->pluck('id')->all(),
        ]);
    }

    public function update(UpdateSocioRequest $request, Socio $socio): RedirectResponse
    {
        $this->authorize('update', $socio);

        $this->socioService->aggiorna(
            $socio,
            $request->datiSocio(),
            $request->tagIds(),
            $request->campiPersonalizzati()
        );

        return redirect()
            ->route('soci.show', $socio)
            ->with('success', 'Socio aggiornato con successo.');
    }

    /**
     * Eliminazione nel cestino (soft delete — miglioramento 11).
     */
    public function destroy(Socio $socio): RedirectResponse
    {
        $this->authorize('delete', $socio);

        $socio->delete();

        return redirect()
            ->route('soci.index')
            ->with('success', 'Socio spostato nel cestino. Puoi ripristinarlo dal cestino.');
    }

    /**
     * Cestino soci (miglioramento 11).
     */
    public function cestino(): View
    {
        $this->authorize('viewAny', Socio::class);

        return view('soci.cestino', [
            'soci' => Socio::onlyTrashed()
                ->orderByDesc('deleted_at')
                ->paginate(25),
        ]);
    }

    public function restore(string $id): RedirectResponse
    {
        $socio = Socio::onlyTrashed()->findOrFail($id);

        $this->authorize('restore', $socio);

        $socio->restore();

        return redirect()
            ->route('soci.cestino')
            ->with('success', "Socio {$socio->nome_completo} ripristinato.");
    }

    public function forceDestroy(string $id): RedirectResponse
    {
        $socio = Socio::onlyTrashed()->findOrFail($id);

        $this->authorize('forceDelete', $socio);

        $socio->forceDelete();

        return redirect()
            ->route('soci.cestino')
            ->with('success', 'Socio eliminato definitivamente.');
    }

    /**
     * Export Excel/CSV con gli stessi filtri della lista (spec §1.11 + miglioramento 13).
     */
    public function export(Request $request)
    {
        $this->authorize('viewAny', Socio::class);

        $associazione = Associazione::find(Socio::currentAssociazioneId());
        $filter = new SociFilter($request->query(), $associazione);

        $formato = $request->query('format', 'xlsx') === 'csv' ? 'csv' : 'xlsx';
        $writer = $formato === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX;

        return Excel::download(new SociExport($filter), 'soci_'.now()->format('Ymd_His').'.'.$formato, $writer);
    }

    public function import(): View
    {
        $this->authorize('create', Socio::class);

        return view('soci.import');
    }

    /**
     * Import da Excel/CSV con report errori riga per riga (miglioramento 12).
     */
    public function importStore(
        ImportSociRequest $request,
        NumeroProgressivoService $progressivi,
        SocioActivityLogger $activityLogger,
    ): RedirectResponse {
        $this->authorize('create', Socio::class);

        $associazione = Associazione::findOrFail(Socio::currentAssociazioneId());
        $import = new SociImport($associazione, $progressivi, $activityLogger);

        Excel::import($import, $request->file('file'));

        return redirect()
            ->route('soci.import')
            ->with('import_report', [
                'importati' => $import->importati,
                'errori' => $import->errori,
            ])
            ->with(
                $import->errori === [] ? 'success' : 'warning',
                "Import completato: {$import->importati} soci importati, ".count($import->errori).' righe scartate.'
            );
    }

    /**
     * Autocomplete soci (spec soci §12): solo soci Attivi del tenant,
     * match su nome/cognome in entrambi gli ordini, limite 20.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Socio::class);

        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json([]);
        }

        $query = Socio::query()->where('stato', Socio::STATO_ATTIVO);

        // Ogni token deve corrispondere a nome o cognome: copre sia
        // "Rossi Mario" sia "Mario Rossi" (equivalente delle CONCAT legacy).
        foreach (preg_split('/\s+/', $q) ?: [] as $token) {
            $query->where(function ($w) use ($token) {
                $w->where('nome', 'like', "%{$token}%")
                    ->orWhere('cognome', 'like', "%{$token}%");
            });
        }

        $risultati = $query->orderBy('cognome')->orderBy('nome')
            ->limit(20)
            ->get(['id', 'nome', 'cognome'])
            ->map(fn (Socio $s) => [
                'id' => $s->id,
                'nome_completo' => trim($s->cognome.' '.$s->nome),
            ]);

        return response()->json($risultati);
    }

    /**
     * Lookup condivisi dei form di creazione/modifica.
     *
     * @return array<string, mixed>
     */
    private function datiForm(): array
    {
        $associazione = Associazione::find(Socio::currentAssociazioneId());

        return [
            'associazione' => $associazione,
            'sedi' => Sede::query()->orderBy('nome')->get(['id', 'nome']),
            'tipiSocio' => TipoSocio::query()->orderBy('nome')->get(['id', 'nome', 'costo_tessera']),
            'categorie' => CategoriaSocio::query()->orderBy('nome')->get(['id', 'nome']),
            'tags' => Tag::query()->orderBy('nome_tag')->get(),
            'campiPersonalizzati' => CampoPersonalizzato::query()->orderBy('ordine')->orderBy('nome_campo')->get(),
            'campiObbligatori' => app(\App\Services\Soci\RequiredFieldsConfig::class)
                ->per($associazione, \App\Services\Soci\RequiredFieldsConfig::CONTESTO_BACKEND),
            'stati' => Socio::STATI,
        ];
    }
}
