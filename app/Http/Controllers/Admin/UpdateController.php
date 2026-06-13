<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PerformUpdateRequest;
use App\Models\UpdateLog;
use App\Models\Utente;
use App\Services\Update\UpdateService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pagina "Aggiornamenti" (/aggiornamenti) — SOLO super_admin.
 * Mostra versione corrente vs ultima release GitHub, lo storico e il
 * pulsante per applicare l'aggiornamento in-app.
 */
class UpdateController extends Controller
{
    public function __construct(private readonly UpdateService $updater) {}

    public function index(Request $request): View
    {
        $this->soloSuperAdmin($request);

        return view('aggiornamenti.index', [
            'stato' => $this->updater->controllaAggiornamenti(),
            'selfUpdate' => $this->updater->selfUpdateAbilitato(),
            'storico' => UpdateLog::query()
                ->with('esecutore')
                ->orderByDesc('avviato_at')
                ->limit(15)
                ->get(),
            'requisiti' => $this->requisiti(),
        ]);
    }

    /** Controllo live (AJAX) della disponibilità di un aggiornamento. */
    public function controlla(Request $request): JsonResponse
    {
        $this->soloSuperAdmin($request);

        return response()->json($this->updater->controllaAggiornamenti());
    }

    public function aggiorna(PerformUpdateRequest $request): RedirectResponse
    {
        $this->soloSuperAdmin($request);

        /** @var Utente $utente */
        $utente = $request->user();

        $esito = $this->updater->applicaAggiornamento($request->validated('versione'), $utente);

        if ($esito['success']) {
            return redirect()
                ->route('aggiornamenti.index')
                ->with('success', 'Aggiornamento alla versione '.$request->validated('versione').' completato.');
        }

        return redirect()
            ->route('aggiornamenti.index')
            ->withErrors(['update' => $esito['error'] ?? 'Aggiornamento non riuscito.']);
    }

    private function soloSuperAdmin(Request $request): void
    {
        $utente = $request->user();
        abort_unless($utente instanceof Utente && $utente->isSuperAdmin(), 403);
    }

    /** @return array<string, bool> */
    private function requisiti(): array
    {
        return [
            'Estensione zip' => extension_loaded('zip'),
            'Cartella applicativa scrivibile' => is_writable(base_path('app')),
            'Cartella storage scrivibile' => is_writable(storage_path()),
            'File VERSION scrivibile' => is_writable(base_path('VERSION')),
        ];
    }
}
