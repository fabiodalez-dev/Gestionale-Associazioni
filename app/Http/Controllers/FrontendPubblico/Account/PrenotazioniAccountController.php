<?php

namespace App\Http\Controllers\FrontendPubblico\Account;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Services\Prenotazioni\BigliettoService;
use App\Services\Prenotazioni\PrenotazioneException;
use App\Services\Prenotazioni\PrenotazioneService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prenotazioni dell'area riservata account: elenco con filtri di stato
 * e posizione in coda, dettaglio con QR inline e dati pagamento,
 * biglietto PDF (solo confermate), annullo e storico eventi passati.
 *
 * {prenotazione} è un parametro stringa risolto qui sotto tenant scope
 * (PrenotazionePolicy: l'account vede SOLO le proprie).
 */
class PrenotazioniAccountController extends Controller
{
    public function __construct(
        protected PrenotazioneService $prenotazioni,
        protected BigliettoService $biglietti,
    ) {}

    /**
     * GET /a/{slug}/account/prenotazioni — elenco con filtro per stato.
     */
    public function index(Request $request, Associazione $associazione): View
    {
        /** @var Account $account */
        $account = $request->user('account');

        $stato = $request->query('stato');

        if (! is_string($stato) || ! in_array($stato, Prenotazione::STATI, true)) {
            $stato = null;
        }

        $prenotazioni = $account->prenotazioni()
            ->when($stato !== null, fn ($q) => $q->where('stato', $stato))
            ->with(['evento' => fn ($q) => $q->withoutGlobalScope('associazione')])
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        // Posizione in coda per le prenotazioni in lista d'attesa
        $posizioniCoda = collect($prenotazioni->items())
            ->filter(fn (Prenotazione $p) => $p->stato === Prenotazione::STATO_LISTA_ATTESA)
            ->mapWithKeys(fn (Prenotazione $p) => [$p->id => $this->posizioneInCoda($p)]);

        return view('pubblico.account.prenotazioni', [
            'associazione' => $associazione,
            'account' => $account,
            'prenotazioni' => $prenotazioni,
            'statoFiltro' => $stato,
            'posizioniCoda' => $posizioniCoda,
        ]);
    }

    /**
     * GET /a/{slug}/account/prenotazioni/{prenotazione} — dettaglio con
     * QR inline (SVG, solo confermate) e dettagli pagamento.
     */
    public function show(Request $request, Associazione $associazione, string $prenotazione): View
    {
        $prenotazione = $this->trovaPrenotazione($prenotazione);

        Gate::forUser($request->user('account'))->authorize('view', $prenotazione);

        return view('pubblico.account.prenotazione-dettaglio', [
            'associazione' => $associazione,
            'prenotazione' => $prenotazione,
            'evento' => $prenotazione->evento,
            'qrSvg' => $prenotazione->stato === Prenotazione::STATO_CONFERMATA
                ? $this->biglietti->qrSvg($prenotazione)
                : null,
            'posizioneCoda' => $prenotazione->stato === Prenotazione::STATO_LISTA_ATTESA
                ? $this->posizioneInCoda($prenotazione)
                : null,
            'annullabile' => $this->annullabileDaAccount($prenotazione),
        ]);
    }

    /**
     * GET /a/{slug}/account/prenotazioni/{prenotazione}/biglietto.pdf
     *
     * Download del biglietto: SOLO per le prenotazioni confermate
     * (404 uniforme negli altri stati).
     */
    public function biglietto(Request $request, Associazione $associazione, string $prenotazione): Response
    {
        $prenotazione = $this->trovaPrenotazione($prenotazione);

        Gate::forUser($request->user('account'))->authorize('view', $prenotazione);

        abort_unless($prenotazione->stato === Prenotazione::STATO_CONFERMATA, 404);

        return $this->biglietti->pdfResponse($prenotazione);
    }

    /**
     * POST /a/{slug}/account/prenotazioni/{prenotazione}/annulla
     *
     * Le regole di stato (finestra di annullamento, check-in già fatto,
     * stati terminali) vivono in PrenotazioneService.
     */
    public function annulla(Request $request, Associazione $associazione, string $prenotazione): RedirectResponse
    {
        $prenotazione = $this->trovaPrenotazione($prenotazione);

        /** @var Account $account */
        $account = $request->user('account');

        Gate::forUser($account)->authorize('annulla', $prenotazione);

        try {
            $this->prenotazioni->annulla($prenotazione, $account);
        } catch (PrenotazioneException $e) {
            return back()->withErrors(['annulla' => $e->getMessage()]);
        }

        return redirect()
            ->route('pubblico.account.prenotazioni.show', [
                'associazione' => $associazione->slug,
                'prenotazione' => $prenotazione->id,
            ])
            ->with('success', 'Prenotazione annullata.');
    }

    /**
     * GET /a/{slug}/account/storico — eventi passati con esito check-in.
     */
    public function storico(Request $request, Associazione $associazione): View
    {
        /** @var Account $account */
        $account = $request->user('account');

        $storico = $account->prenotazioni()
            ->whereHas('evento', fn ($q) => $q->where('data_evento', '<', now()))
            ->with(['evento' => fn ($q) => $q->withoutGlobalScope('associazione')])
            ->get()
            ->sortByDesc(fn (Prenotazione $p) => $p->evento?->data_evento)
            ->values();

        return view('pubblico.account.storico', [
            'associazione' => $associazione,
            'account' => $account,
            'storico' => $storico,
        ]);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * Risolve {prenotazione} sotto tenant scope: una prenotazione di un
     * altro tenant è un 404 uniforme (la proprietà la verifica la Policy).
     */
    protected function trovaPrenotazione(string $id): Prenotazione
    {
        return Prenotazione::query()
            ->with(['evento' => fn ($q) => $q->withoutGlobalScope('associazione')])
            ->findOrFail($id);
    }

    /**
     * Posizione in coda (1-based) di una prenotazione in lista d'attesa:
     * stesso ordinamento FIFO della promozione (created_at, poi id).
     */
    protected function posizioneInCoda(Prenotazione $prenotazione): int
    {
        return Prenotazione::query()
            ->where('evento_id', $prenotazione->evento_id)
            ->where('stato', Prenotazione::STATO_LISTA_ATTESA)
            ->where(function ($query) use ($prenotazione): void {
                $query->where('created_at', '<', $prenotazione->created_at)
                    ->orWhere(function ($query) use ($prenotazione): void {
                        $query->where('created_at', $prenotazione->created_at)
                            ->where('id', '<', $prenotazione->id);
                    });
            })
            ->count() + 1;
    }

    /**
     * Specchio (sola UI) delle regole di PrenotazioneService per mostrare
     * o nascondere il bottone "Annulla": la verifica vincolante resta nel
     * service dentro la transazione.
     */
    protected function annullabileDaAccount(Prenotazione $prenotazione): bool
    {
        if (in_array($prenotazione->stato, [
            Prenotazione::STATO_IN_ATTESA_PAGAMENTO,
            Prenotazione::STATO_LISTA_ATTESA,
        ], true)) {
            return true;
        }

        if ($prenotazione->stato !== Prenotazione::STATO_CONFERMATA || $prenotazione->checkin_at !== null) {
            return false;
        }

        /** @var Evento|null $evento */
        $evento = $prenotazione->evento;

        if ($evento === null) {
            return false;
        }

        $termine = $evento->data_evento->copy()->subHours((int) $evento->annullamento_entro_ore);

        return now()->lessThanOrEqualTo($termine);
    }
}
