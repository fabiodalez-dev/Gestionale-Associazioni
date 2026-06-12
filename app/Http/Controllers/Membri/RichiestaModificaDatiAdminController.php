<?php

namespace App\Http\Controllers\Membri;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membri\RifiutaRichiestaModificaRequest;
use App\Models\RichiestaModificaDati;
use App\Models\Utente;
use App\Services\Membri\ModificaDatiService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pagina admin "Richieste modifiche" (f.19 — piano "Gestione 2"):
 * richieste pendenti con diff campo per campo (valore attuale → proposto),
 * azioni APPROVA (applica al socio + storico) / RIFIUTA (motivo
 * obbligatorio) e storico delle richieste già gestite.
 *
 * Autorizzazione: RichiestaModificaDatiPolicy (soci.view / soci.update).
 * Una richiesta di un altro tenant produce 404 (global scope sul binding).
 */
class RichiestaModificaDatiAdminController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly ModificaDatiService $modifiche) {}

    public function index(): View
    {
        $this->authorize('viewAny', RichiestaModificaDati::class);

        $pendenti = RichiestaModificaDati::query()
            ->inAttesa()
            ->with(['socio' => fn ($q) => $q->withTrashed(), 'origine'])
            ->orderBy('created_at')
            ->get();

        $storico = RichiestaModificaDati::query()
            ->whereIn('stato', [RichiestaModificaDati::STATO_APPROVATA, RichiestaModificaDati::STATO_RIFIUTATA])
            ->with(['socio' => fn ($q) => $q->withTrashed(), 'gestitaDa'])
            ->orderByDesc('gestita_at')
            ->paginate(25, ['*'], 'storico');

        return view('membri.richieste-modifica.index', [
            'pendenti' => $pendenti,
            'storico' => $storico,
            'service' => $this->modifiche,
        ]);
    }

    public function approva(Request $request, RichiestaModificaDati $richiesta): RedirectResponse
    {
        $this->authorize('gestisci', $richiesta);

        if (! $richiesta->isInAttesa()) {
            return redirect()
                ->route('richieste-modifica-dati.index')
                ->with('error', 'La richiesta è già stata gestita.');
        }

        /** @var Utente $utente */
        $utente = $request->user();

        $this->modifiche->approva($richiesta, $utente);

        return redirect()
            ->route('richieste-modifica-dati.index')
            ->with('success', 'Richiesta approvata: le modifiche sono state applicate alla scheda del socio.');
    }

    public function rifiuta(RifiutaRichiestaModificaRequest $request, RichiestaModificaDati $richiesta): RedirectResponse
    {
        $this->authorize('gestisci', $richiesta);

        if (! $richiesta->isInAttesa()) {
            return redirect()
                ->route('richieste-modifica-dati.index')
                ->with('error', 'La richiesta è già stata gestita.');
        }

        /** @var Utente $utente */
        $utente = $request->user();

        $this->modifiche->rifiuta($richiesta, $utente, (string) $request->validated('motivo'));

        return redirect()
            ->route('richieste-modifica-dati.index')
            ->with('success', 'Richiesta rifiutata: il socio vedrà il motivo nella sua area riservata.');
    }
}
