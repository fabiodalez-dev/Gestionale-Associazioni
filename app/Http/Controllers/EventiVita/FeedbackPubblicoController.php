<?php

namespace App\Http\Controllers\EventiVita;

use App\Http\Controllers\Controller;
use App\Http\Requests\EventiVita\FeedbackCompilaRequest;
use App\Models\FeedbackEvento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Form pubblico di feedback post-evento (f.11).
 *
 * GET e POST arrivano dal link FIRMATO con scadenza dell'email
 * (middleware 'signed': link non falsificabile né alterabile); il token
 * di 64 hex unique è il capability token che identifica il feedback. Il
 * POST è protetto anche da CSRF + throttle (404 uniforme se il token non
 * esiste). Un solo feedback per prenotazione: ricompilazioni bloccate
 * con un update condizionale.
 */
class FeedbackPubblicoController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $feedback = $this->trovaFeedback($token);

        return view('eventi-vita.pubblico.feedback', [
            'feedback' => $feedback,
            'evento' => $feedback->evento()->withoutGlobalScope('associazione')->first(),
            'associazione' => $feedback->associazione,
            'inviato' => false,
            // La form rimanda alla stessa URL firmata (firma e scadenza incluse):
            // la rotta POST ha il middleware 'signed' come la GET.
            'urlInvio' => $request->fullUrl(),
        ]);
    }

    public function store(FeedbackCompilaRequest $request, string $token): View|RedirectResponse
    {
        $feedback = $this->trovaFeedback($token);

        // Salvataggio atomico: l'update condizionale su compilato_at NULL
        // garantisce un solo feedback anche con due invii concorrenti.
        $salvato = FeedbackEvento::allTenants()
            ->whereKey($feedback->id)
            ->whereNull('compilato_at')
            ->update([
                'voto' => (int) $request->validated('voto'),
                'commento' => $request->validated('commento'),
                'compilato_at' => now(),
            ]);

        if ($salvato === 0) {
            return back()->withErrors(['voto' => 'Il feedback per questa prenotazione è già stato inviato.']);
        }

        return view('eventi-vita.pubblico.feedback', [
            'feedback' => $feedback->refresh(),
            'evento' => $feedback->evento()->withoutGlobalScope('associazione')->first(),
            'associazione' => $feedback->associazione,
            'inviato' => true,
        ]);
    }

    /**
     * Rotta fuori dal contesto tenant: lookup per token su tutti i
     * tenant, 404 uniforme se il token non esiste.
     */
    protected function trovaFeedback(string $token): FeedbackEvento
    {
        return FeedbackEvento::allTenants()
            ->where('token', $token)
            ->firstOrFail();
    }
}
