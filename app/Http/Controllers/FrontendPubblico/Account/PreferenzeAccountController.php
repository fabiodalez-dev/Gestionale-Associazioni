<?php

namespace App\Http\Controllers\FrontendPubblico\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\FrontendPubblico\Account\AggiornaPreferenzeRequest;
use App\Models\Account;
use App\Models\Associazione;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Preferenze json dell'account: newsletter, promemoria eventi, marketing.
 * Il promemoria eventi è attivo per default (coerente con
 * PrenotazioneService::inviaPromemoriaEventi).
 */
class PreferenzeAccountController extends Controller
{
    public function show(Request $request, Associazione $associazione): View
    {
        /** @var Account $account */
        $account = $request->user('account');

        $preferenze = (array) ($account->preferenze ?? []);

        return view('pubblico.account.preferenze', [
            'associazione' => $associazione,
            'account' => $account,
            'preferenze' => [
                'newsletter' => (bool) ($preferenze['newsletter'] ?? false),
                'promemoria_eventi' => ($preferenze['promemoria_eventi'] ?? true) !== false,
                'marketing' => (bool) ($preferenze['marketing'] ?? false),
            ],
        ]);
    }

    public function aggiorna(AggiornaPreferenzeRequest $request, Associazione $associazione): RedirectResponse
    {
        /** @var Account $account */
        $account = $request->user('account');

        Gate::forUser($account)->authorize('update', $account);

        // Merge sulle preferenze esistenti: eventuali altre chiavi
        // (es. motivazione richiesta socio) restano intatte
        $account->forceFill([
            'preferenze' => array_merge((array) ($account->preferenze ?? []), $request->preferenze()),
        ])->save();

        return redirect()
            ->route('pubblico.account.preferenze', ['associazione' => $associazione->slug])
            ->with('success', 'Preferenze salvate.');
    }
}
