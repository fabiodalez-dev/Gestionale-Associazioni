<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TenantSwitchRequest;
use App\Models\Associazione;
use Illuminate\Http\RedirectResponse;

/**
 * Context switch del super_admin: seleziona (o deseleziona) l'associazione
 * su cui operare. Sostituisce il meccanismo legacy `?assoc_id=` / `?switch_assoc=1`.
 */
class TenantSwitchController extends Controller
{
    public function __invoke(TenantSwitchRequest $request): RedirectResponse
    {
        $associazioneId = $request->validated('associazione_id');

        if (blank($associazioneId)) {
            // Vista globale: nessun tenant selezionato
            session()->forget(['associazione_id', 'associazione_nome']);

            return redirect()
                ->route('dashboard')
                ->with('success', 'Vista globale attivata.');
        }

        $associazione = Associazione::query()
            ->where('id', $associazioneId)
            ->where('attiva', true)
            ->first();

        if (! $associazione) {
            return redirect()
                ->route('dashboard')
                ->with('error', 'Associazione non trovata o non attiva.');
        }

        session([
            'associazione_id' => $associazione->id,
            'associazione_nome' => $associazione->nome,
        ]);

        return redirect()
            ->route('dashboard')
            ->with('success', 'Stai operando su: '.$associazione->nome);
    }
}
