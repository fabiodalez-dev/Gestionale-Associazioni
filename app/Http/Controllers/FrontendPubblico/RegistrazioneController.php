<?php

namespace App\Http\Controllers\FrontendPubblico;

use App\Forms\RegistrazioneAccountForm;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontendPubblico\RegistrazioneAccountRequest;
use App\Models\Account;
use App\Models\Associazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Registrazione account dal frontend pubblico.
 *
 * Protezioni sulla rotta POST: honeypot spatie (finto successo per i bot)
 * + throttle 5/m. Dopo la creazione: invio email di verifica (notifica
 * tenant-aware), login immediato e redirect all'avviso di verifica.
 */
class RegistrazioneController extends Controller
{
    public function create(Associazione $associazione): View|RedirectResponse
    {
        if (Auth::guard('account')->check()) {
            return redirect()->route('pubblico.home.show', $associazione->slug);
        }

        return view('pubblico.auth.registrazione', [
            'associazione' => $associazione,
            'form' => new RegistrazioneAccountForm(Account::class, ['associazione' => $associazione]),
        ]);
    }

    public function store(RegistrazioneAccountRequest $request, Associazione $associazione): RedirectResponse
    {
        $account = Account::create([
            'associazione_id' => $associazione->id,
            'nome' => $request->string('nome')->toString(),
            'cognome' => $request->string('cognome')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(), // hash via cast 'hashed'
            'telefono' => $request->filled('telefono') ? $request->string('telefono')->toString() : null,
            'attivo' => true,
            'privacy_accettata_at' => now(),
        ]);

        $account->sendEmailVerificationNotification();

        Auth::guard('account')->login($account);

        // Anti session-fixation
        $request->session()->regenerate();

        return redirect()
            ->route('pubblico.verifica-email.avviso', $associazione->slug)
            ->with('success', 'Registrazione completata! Ti abbiamo inviato un\'email con il link per verificare il tuo indirizzo.');
    }
}
