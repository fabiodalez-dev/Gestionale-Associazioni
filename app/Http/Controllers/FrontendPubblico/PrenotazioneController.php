<?php

namespace App\Http\Controllers\FrontendPubblico;

use App\Http\Controllers\Controller;
use App\Http\Requests\FrontendPubblico\PrenotaEventoRequest;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Services\Prenotazioni\PrenotazioneException;
use App\Services\Prenotazioni\PrenotazioneService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Prenotazione eventi dal frontend pubblico (guard 'account', verificato).
 *
 * Controller sottile: tutta la macchina a stati (mutex per-evento, esiti
 * confermata / in_attesa_pagamento / lista_attesa, doppia prenotazione,
 * finestra iscrizioni) vive in PrenotazioneService.
 *
 * Nota: {evento} e {prenotazione} sono parametri stringa, NON binding
 * impliciti: vengono risolti qui sotto tenant scope (il binding implicito
 * scatterebbe prima che RisolviTenantPubblico bindi tenant.pubblico).
 */
class PrenotazioneController extends Controller
{
    public function __construct(protected PrenotazioneService $prenotazioni) {}

    /**
     * POST /a/{slug}/eventi/{evento}/prenota
     */
    public function store(PrenotaEventoRequest $request, Associazione $associazione, string $evento): RedirectResponse
    {
        // Tenant scope attivo (tenant.pubblico + guard account): un evento
        // di un altro tenant o non pubblicato è un 404 uniforme
        $evento = Evento::query()->pubblicati()->findOrFail($evento);

        $account = $request->user('account');

        Gate::forUser($account)->authorize('create', [Prenotazione::class, $evento]);

        try {
            $prenotazione = $this->prenotazioni->crea(
                $account,
                $evento,
                (int) $request->validated('posti'),
            );
        } catch (PrenotazioneException $e) {
            return back()
                ->withErrors(['posti' => $e->getMessage()])
                ->withInput();
        }

        return redirect()->route('pubblico.prenotazioni.esito', [
            'associazione' => $associazione->slug,
            'prenotazione' => $prenotazione->id,
        ]);
    }

    /**
     * GET /a/{slug}/prenotazioni/{prenotazione}/esito
     *
     * Pagina di esito post-prenotazione: conferma con codice, attesa
     * pagamento con countdown, posizione in lista d'attesa.
     */
    public function esito(Request $request, Associazione $associazione, string $prenotazione): View
    {
        $prenotazione = Prenotazione::query()
            ->with(['evento' => fn ($q) => $q->withoutGlobalScope('associazione')])
            ->findOrFail($prenotazione);

        Gate::forUser($request->user('account'))->authorize('view', $prenotazione);

        $posizioneCoda = null;

        if ($prenotazione->stato === Prenotazione::STATO_LISTA_ATTESA) {
            // Stesso ordinamento FIFO della promozione: created_at, poi id
            $posizioneCoda = Prenotazione::query()
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

        return view('pubblico.prenotazioni.esito', [
            'associazione' => $associazione,
            'prenotazione' => $prenotazione,
            'evento' => $prenotazione->evento,
            'posizioneCoda' => $posizioneCoda,
            // Return URL dal checkout ('success'|'cancel'|null): puramente
            // informativo, fa SEMPRE fede lo stato della prenotazione
            // (la conferma reale arriva dal webhook)
            'esitoPagamento' => in_array($request->query('esito'), ['success', 'cancel'], true)
                ? $request->query('esito')
                : null,
        ]);
    }
}
