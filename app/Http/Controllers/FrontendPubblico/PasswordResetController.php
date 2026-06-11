<?php

namespace App\Http\Controllers\FrontendPubblico;

use App\Http\Controllers\Controller;
use App\Http\Requests\FrontendPubblico\ResetPasswordAccountRequest;
use App\Models\Account;
use App\Models\Associazione;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Password reset degli account pubblici col broker 'accounts'.
 *
 * Il provider Eloquent applica il global scope multi-tenant (binding
 * tenant.pubblico): la richiesta vale solo per gli account del tenant
 * corrente. La risposta all'invio del link è NEUTRA (anti-enumeration).
 */
class PasswordResetController extends Controller
{
    public function richiesta(Associazione $associazione): View
    {
        return view('pubblico.auth.password-richiesta', ['associazione' => $associazione]);
    }

    public function invia(Request $request, Associazione $associazione): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        // Esito ignorato di proposito: risposta identica per email
        // esistente, inesistente o richiesta throttled (niente enumeration)
        Password::broker('accounts')->sendResetLink($request->only('email'));

        return back()->with(
            'status',
            'Se l\'indirizzo email è registrato, riceverai un messaggio con il link per reimpostare la password.'
        );
    }

    public function reset(Request $request, Associazione $associazione, string $token): View
    {
        return view('pubblico.auth.password-reset', [
            'associazione' => $associazione,
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function aggiorna(ResetPasswordAccountRequest $request, Associazione $associazione): RedirectResponse
    {
        $status = Password::broker('accounts')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Account $account, string $password): void {
                $account->forceFill([
                    'password' => $password, // hash via cast 'hashed'
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($account));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()
                ->route('pubblico.login', $associazione->slug)
                ->with('success', 'Password reimpostata con successo. Ora puoi accedere.');
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => 'Il link di reimpostazione non è valido o è scaduto.']);
    }
}
