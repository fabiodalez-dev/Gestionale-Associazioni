<?php

namespace App\Http\Controllers\AnagraficaPro;

use App\Exports\AnagraficaPro\AnagraficaProExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnagraficaPro\ImportAggiornamentoRequest;
use App\Models\Associazione;
use App\Models\Socio;
use App\Services\AnagraficaPro\ImportAggiornamentoService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Export/Import pro (f.25 PIANO-VITA-SOCIALE): export Excel multi-foglio
 * (Soci, Tessere anno, Quote anno, Libro soci) e import Excel di
 * AGGIORNAMENTO soci con anteprima diff prima di applicare.
 */
class ExportImportController extends Controller
{
    use AuthorizesRequests;

    private const DISK = 'local';

    private const SESSION_KEY = 'anagrafica_pro_import';

    public function __construct(private readonly ImportAggiornamentoService $importService) {}

    public function index(): View
    {
        $this->authorize('viewAny', Socio::class);

        return view('anagrafica-pro.export-import.index', [
            'anno' => (int) now()->year,
            'campiAggiornabili' => ImportAggiornamentoService::CAMPI,
        ]);
    }

    /**
     * Export Excel multi-foglio.
     */
    public function export(Request $request)
    {
        $this->authorize('viewAny', Socio::class);

        $associazione = Associazione::findOrFail(Socio::currentAssociazioneId());

        $anno = (int) $request->integer('anno', (int) now()->year);
        if ($anno < 2000 || $anno > 2100) {
            $anno = (int) now()->year;
        }

        return Excel::download(
            new AnagraficaProExport($associazione, $anno),
            'anagrafica_'.Str::slug($associazione->nome).'_'.$anno.'_'.now()->format('Ymd_His').'.xlsx'
        );
    }

    /**
     * Step 1 import: upload + anteprima diff (nessuna scrittura).
     */
    public function anteprima(ImportAggiornamentoRequest $request): View|RedirectResponse
    {
        // Stesso permesso dell'import soci esistente (SocioPolicy::create)
        $this->authorize('create', Socio::class);

        $associazione = Associazione::findOrFail(Socio::currentAssociazioneId());

        // Il file viene parcheggiato su disco: l'applicazione (step 2) lo rilegge
        $percorso = $request->file('file')->store('anagrafica-pro/import-aggiornamenti', self::DISK);
        $token = (string) Str::uuid();

        session()->put(self::SESSION_KEY, [
            'token' => $token,
            'percorso' => $percorso,
            'associazione_id' => $associazione->id,
            'nome_file' => $request->file('file')->getClientOriginalName(),
        ]);

        $report = $this->importService->anteprima($associazione, Storage::disk(self::DISK)->path($percorso));

        return view('anagrafica-pro.export-import.anteprima', [
            'report' => $report,
            'token' => $token,
            'nomeFile' => $request->file('file')->getClientOriginalName(),
            'campiAggiornabili' => ImportAggiornamentoService::CAMPI,
        ]);
    }

    /**
     * Step 2 import: applica le modifiche dell'anteprima confermata.
     */
    public function applica(Request $request): RedirectResponse
    {
        // Stesso permesso dell'import soci esistente (SocioPolicy::create)
        $this->authorize('create', Socio::class);

        $request->validate(['token' => ['required', 'uuid']]);

        $associazione = Associazione::findOrFail(Socio::currentAssociazioneId());
        $pending = session(self::SESSION_KEY);

        if (
            ! is_array($pending)
            || ($pending['token'] ?? null) !== $request->input('token')
            || ($pending['associazione_id'] ?? null) !== $associazione->id
            || ! Storage::disk(self::DISK)->exists($pending['percorso'] ?? '')
        ) {
            return redirect()
                ->route('export-import.index')
                ->with('error', 'Anteprima non più valida: ricarica il file e ripeti l\'import.');
        }

        $esito = $this->importService->applica($associazione, Storage::disk(self::DISK)->path($pending['percorso']));

        Storage::disk(self::DISK)->delete($pending['percorso']);
        session()->forget(self::SESSION_KEY);

        return redirect()
            ->route('export-import.index')
            ->with('success', sprintf(
                'Import applicato: %d soci aggiornati (%d campi), %d invariati, %d non trovati, %d righe con errori.',
                $esito['aggiornati'],
                $esito['campi_aggiornati'],
                $esito['invariati'],
                $esito['non_trovati'],
                $esito['errori'],
            ));
    }
}
