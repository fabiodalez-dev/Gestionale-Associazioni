<?php

namespace App\Http\Controllers\EventiVita;

use App\Http\Controllers\Controller;
use App\Http\Requests\EventiVita\FeedbackCompilaRequest;
use App\Models\FeedbackEvento;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Form pubblico di feedback post-evento (f.11).
 *
 * Il GET arriva dal link FIRMATO dell'email (middleware 'signed': link
 * non falsificabile né alterabile); il token di 64 hex unique è il
 * capability token che identifica il feedback. Il POST è protetto da
 * CSRF + throttle e dal token stesso (404 uniforme se non esiste).
 * Un solo feedback per prenotazione: ricompilazioni bloccate.
 */
class FeedbackPubblicoController extends Controller
{
    public function show(string $token): View
    {
        $feedback = $this->trovaFeedback($token);

        return view('eventi-vita.pubblico.feedback', [
            'feedback' => $feedback,
            'evento' => $feedback->evento()->withoutGlobalScope('associazione')->first(),
            'associazione' => $feedback->associazione,
            'inviato' => false,
        ]);
    }

    public function store(FeedbackCompilaRequest $request, string $token): View|RedirectResponse
    {
        $feedback = $this->trovaFeedback($token);

        if ($feedback->isCompilato()) {
            return back()->withErrors(['voto' => 'Il feedback per questa prenotazione è già stato inviato.']);
        }

        $feedback->forceFill([
            'voto' => (int) $request->validated('voto'),
            'commento' => $request->validated('commento'),
            'compilato_at' => now(),
        ])->save();

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
