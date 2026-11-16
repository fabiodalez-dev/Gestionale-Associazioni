<?php

namespace App\Http\Controllers\FrontendPubblico\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\FrontendPubblico\Account\AggiornaProfiloRequest;
use App\Http\Requests\FrontendPubblico\Account\CambiaEmailRequest;
use App\Http\Requests\FrontendPubblico\Account\CambiaPasswordRequest;
use App\Models\Account;
use App\Models\Associazione;
use App\Support\LegameSessione;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Profilo dell'area riservata account: anagrafica, cambio password
 * (con password corrente) e cambio email (azzera la verifica e
 * reinvia il link di conferma).
 */
class ProfiloAccountController extends Controller
{
    public function show(Request $request, Associazione $associazione): View
    {
        /** @var Account $account */
        $account = $request->user('account');

        return view('pubblico.account.profilo', [
            'associazione' => $associazione,
            'account' => $account,
        ]);
    }

    /**
     * PATCH /a/{slug}/account/profilo — anagrafica.
     */
    public function aggiorna(AggiornaProfiloRequest $request, Associazione $associazione): RedirectResponse
    {
        /** @var Account $account */
        $account = $request->user('account');

        Gate::forUser($account)->authorize('update', $account);

        $account->fill($request->validated())->save();

        return redirect()
            ->route('pubblico.account.profilo', ['associazione' => $associazione->slug])
            ->with('success', 'Profilo aggiornato.');
    }

    /**
     * PATCH /a/{slug}/account/profilo/password — cambio password
     * con verifica della password corrente (current_password:account).
     */
    public function password(CambiaPasswordRequest $request, Associazione $associazione): RedirectResponse
    {
        /** @var Account $account */
        $account = $request->user('account');

        Gate::forUser($account)->authorize('update', $account);

        // Cast 'hashed' sul modello: il valore viene hashato al salvataggio
        $account->forceFill(['password' => $request->validated('password')])->save();

        // Le altre sessioni dell'account non valgono più; questa resta aperta
        LegameSessione::aggiorna($request, 'account', $account);

        return redirect()
            ->route('pubblico.account.profilo', ['associazione' => $associazione->slug])
            ->with('success', 'Password aggiornata.');
    }

    /**
     * PATCH /a/{slug}/account/profilo/email — cambio email: azzera
     * email_verified_at e reinvia il link di verifica al nuovo indirizzo.
     * L'account torna "non verificato" finché non conferma.
     */
    public function email(CambiaEmailRequest $request, Associazione $associazione): RedirectResponse
    {
        /** @var Account $account */
        $account = $request->user('account');

        Gate::forUser($account)->authorize('update', $account);

        $nuovaEmail = (string) $request->validated('email');

        if ($nuovaEmail === $account->email) {
            return redirect()
                ->route('pubblico.account.profilo', ['associazione' => $associazione->slug])
                ->with('success', 'L\'indirizzo email è rimasto invariato.');
        }

        // Esito identico se l'indirizzo è libero o già di un altro account del
        // tenant, così la richiesta non rivela chi è registrato: se è già
        // occupato non si cambia nulla ma l'account torna "da verificare" e il
        // link parte verso il suo indirizzo attuale
        $occupata = Account::query()->withTrashed()
            ->where('associazione_id', $account->associazione_id)
            ->where('email', $nuovaEmail)
            ->whereKeyNot($account->id)
            ->exists();

        $account->forceFill(['email_verified_at' => null]);
        if (! $occupata) {
            $account->forceFill(['email' => $nuovaEmail]);
        }
        $account->save();

        LegameSessione::aggiorna($request, 'account', $account);

        $account->sendEmailVerificationNotification();

        return redirect()
            ->route('pubblico.verifica-email.avviso', ['associazione' => $associazione->slug])
            ->with('success', 'Email aggiornata: ti abbiamo inviato un nuovo link di verifica.');
    }
}
