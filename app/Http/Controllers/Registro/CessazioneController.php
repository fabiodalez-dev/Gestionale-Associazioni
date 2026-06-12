<?php

namespace App\Http\Controllers\Registro;

use App\Http\Controllers\Controller;
use App\Http\Requests\Registro\StoreCessazioneRequest;
use App\Models\Cessazione;
use App\Models\Socio;
use App\Models\Utente;
use App\Services\Registro\CessazioneNonAmmessaException;
use App\Services\Registro\CessazioneService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Cessazioni formali (f.15 del piano vita sociale): registro append-only
 * delle dimissioni/decadenze/espulsioni/decessi. La registrazione è
 * l'azione "Cessazione" sul socio: aggiorna lo stato, annulla le tessere
 * attive e scrive cessazioni + storico (alimenta il libro soci, f.13).
 */
class CessazioneController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly CessazioneService $cessazioni) {}

    public function index(): View
    {
        $this->authorize('viewAny', Cessazione::class);

        $cessazioni = Cessazione::query()
            ->with(['socio' => fn ($q) => $q->withTrashed(), 'registrataDa'])
            ->orderByDesc('data')
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        // Soci per cui è registrabile una cessazione (modale "Registra cessazione")
        $sociCessabili = Socio::query()
            ->whereIn('stato', CessazioneService::STATI_AMMESSI)
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get(['id', 'nome', 'cognome', 'numero_socio', 'stato']);

        return view('registro.cessazioni.index', [
            'cessazioni' => $cessazioni,
            'sociCessabili' => $sociCessabili,
            'tipi' => Cessazione::TIPI,
        ]);
    }

    public function store(StoreCessazioneRequest $request): RedirectResponse
    {
        $this->authorize('create', Cessazione::class);

        /** @var Socio $socio */
        $socio = Socio::query()->findOrFail($request->validated('socio_id'));

        /** @var Utente $utente */
        $utente = $request->user();

        try {
            $this->cessazioni->registra($socio, $utente, [
                'tipo' => (string) $request->validated('tipo'),
                'data' => (string) $request->validated('data'),
                'motivo' => $request->validated('motivo'),
                'delibera_numero' => $request->validated('delibera_numero'),
                'delibera_data' => $request->validated('delibera_data'),
            ]);
        } catch (CessazioneNonAmmessaException $e) {
            return redirect()->route('cessazioni.index')->with('error', $e->getMessage());
        }

        return redirect()
            ->route('cessazioni.index')
            ->with('success', "Cessazione di {$socio->nome_completo} registrata: stato aggiornato e tessere attive annullate.");
    }
}
