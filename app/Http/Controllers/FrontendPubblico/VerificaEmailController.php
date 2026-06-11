<?php

namespace App\Http\Controllers\FrontendPubblico;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Associazione;
use App\Notifications\Accounts\BenvenutoAccount;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Verifica email degli account del frontend pubblico:
 * - avviso (autenticato, non ancora verificato) con reinvio;
 * - verifica via link firmato (signed + throttle), valido anche
 *   senza sessione: la firma è la prova di possesso dell'email.
 */
class VerificaEmailController extends Controller
{
    public function avviso(Request $request, Associazione $associazione): View|RedirectResponse
    {
        $account = $request->user('account');

        if ($account instanceof Account && $account->hasVerifiedEmail()) {
            return redirect()->route('pubblico.home.show', $associazione->slug);
        }

        return view('pubblico.auth.verifica-email', ['associazione' => $associazione]);
    }

    public function verifica(Request $request, Associazione $associazione, string $id, string $hash): RedirectResponse
    {
        /** @var Account $account 404 uniforme se l'id non appartiene al tenant */
        $account = Account::query()
            ->where('associazione_id', $associazione->id)
            ->findOrFail($id);

        abort_unless(
            hash_equals(sha1((string) $account->getEmailForVerification()), $hash),
            403
        );

        if (! $account->hasVerifiedEmail()) {
            $account->markEmailAsVerified();
            event(new Verified($account));

            // Benvenuto best-effort (database + mail per tenant): un
            // fallimento di invio non blocca mai la verifica
            try {
                $account->notify(new BenvenutoAccount);
            } catch (\Throwable $e) {
                Log::error('VerificaEmail: invio benvenuto fallito', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (Auth::guard('account')->id() === $account->id) {
            return redirect()
                ->route('pubblico.home.show', $associazione->slug)
                ->with('success', 'Email verificata con successo!');
        }

        return redirect()
            ->route('pubblico.login', $associazione->slug)
            ->with('success', 'Email verificata con successo! Ora puoi accedere.');
    }

    public function reinvia(Request $request, Associazione $associazione): RedirectResponse
    {
        $account = $request->user('account');

        if ($account instanceof Account && ! $account->hasVerifiedEmail()) {
            $account->sendEmailVerificationNotification();
        }

        return back()->with('success', 'Ti abbiamo inviato una nuova email di verifica.');
    }
}
