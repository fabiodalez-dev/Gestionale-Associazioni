<?php

namespace App\Http\Controllers\Iscrizioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Iscrizioni\ApprovaRichiestaIscrizioneRequest;
use App\Http\Requests\Iscrizioni\RifiutaRichiestaIscrizioneRequest;
use App\Models\CampoPersonalizzato;
use App\Models\RichiestaIscrizione;
use App\Models\Utente;
use App\Services\Iscrizioni\ApprovazioneIscrizioneService;
use App\Services\Iscrizioni\IscrizioneException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Pagina admin "Richieste iscrizione" (PIANO-ISCRIZIONI §Admin):
 * richieste pendenti del form pubblico /a/{slug}/iscriviti con i dati
 * inviati espandibili, azioni APPROVA (socio → Attivo, account collegato,
 * tessera opzionale) / RIFIUTA (motivo obbligatorio, socio preiscritto
 * eliminato) e storico delle richieste già gestite.
 *
 * Autorizzazione: RichiestaIscrizionePolicy (soci.view / soci.update).
 * Una richiesta di un altro tenant produce 404 (global scope sul binding).
 */
class RichiestaIscrizioneController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly ApprovazioneIscrizioneService $approvazioni) {}

    public function index(): View
    {
        $this->authorize('viewAny', RichiestaIscrizione::class);

        $pendenti = RichiestaIscrizione::query()
            ->inAttesa()
            ->with(['account' => fn ($q) => $q->withTrashed(), 'socio'])
            ->orderBy('created_at')
            ->get();

        $storico = RichiestaIscrizione::query()
            ->whereIn('stato', [RichiestaIscrizione::STATO_APPROVATA, RichiestaIscrizione::STATO_RIFIUTATA])
            ->with(['account' => fn ($q) => $q->withTrashed(), 'socio', 'gestore'])
            ->orderByDesc('gestita_at')
            ->paginate(25, ['*'], 'storico');

        return view('iscrizioni.richieste', [
            'pendenti' => $pendenti,
            'storico' => $storico,
            // Nomi dei campi personalizzati per rendere leggibile dati_form
            'nomiCampi' => CampoPersonalizzato::query()->pluck('nome_campo', 'id'),
        ]);
    }

    public function approva(ApprovaRichiestaIscrizioneRequest $request, RichiestaIscrizione $richiesta): RedirectResponse
    {
        $this->authorize('gestisci', $richiesta);

        /** @var Utente $utente */
        $utente = $request->user();

        try {
            $this->approvazioni->approva($richiesta, $utente, $request->boolean('genera_tessera'));
        } catch (IscrizioneException $e) {
            return redirect()
                ->route('richieste-iscrizione.index')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('richieste-iscrizione.index')
            ->with('success', 'Richiesta approvata: il socio è ora attivo e collegato all\'account.'
                .($request->boolean('genera_tessera') ? ' Tessera generata.' : ''));
    }

    public function rifiuta(RifiutaRichiestaIscrizioneRequest $request, RichiestaIscrizione $richiesta): RedirectResponse
    {
        $this->authorize('gestisci', $richiesta);

        /** @var Utente $utente */
        $utente = $request->user();

        try {
            $this->approvazioni->rifiuta($richiesta, $utente, (string) $request->validated('motivo'));
        } catch (IscrizioneException $e) {
            return redirect()
                ->route('richieste-iscrizione.index')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('richieste-iscrizione.index')
            ->with('success', 'Richiesta rifiutata: il richiedente riceverà il motivo via email.');
    }
}
