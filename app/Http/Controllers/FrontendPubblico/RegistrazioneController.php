<?php

namespace App\Http\Controllers\FrontendPubblico;

use App\Forms\RegistrazioneAccountForm;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontendPubblico\RegistrazioneAccountRequest;
use App\Models\Account;
use App\Models\Associazione;
use App\Notifications\Accounts\TentativoRegistrazioneAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Registrazione account dal frontend pubblico.
 *
 * Protezioni sulla rotta POST: honeypot spatie (finto successo per i bot)
 * + throttle 5/m. La risposta è la stessa sia per un'email nuova sia per
 * una già registrata nel tenant (niente oracolo sugli account):
 * - email nuova: nasce l'account e parte l'email di verifica;
 * - email già registrata: nessun account duplicato, il titolare riceve un
 *   avviso con il link al recupero password.
 * In entrambi i casi si torna alla pagina di accesso con l'invito a
 * controllare la posta: niente login automatico, che rivelerebbe quale
 * dei due casi si è verificato.
 */
class RegistrazioneController extends Controller
{
    /** Avvisi "tentativo di registrazione" al massimo per account al giorno. */
    public const AVVISI_PER_ACCOUNT = 3;

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
        $email = $request->string('email')->toString();

        // Stesso confronto del vecchio vincolo unique(associazione_id, email),
        // inclusi gli account nel cestino che occuperebbero comunque l'indice
        $esistente = Account::withoutGlobalScopes()
            ->withTrashed()
            ->where('associazione_id', $associazione->id)
            ->where('email', $email)
            ->first();

        if ($esistente !== null) {
            $this->avvisaTitolare($esistente, $request->string('password')->toString());
        } else {
            $account = Account::create([
                'associazione_id' => $associazione->id,
                'nome' => $request->string('nome')->toString(),
                'cognome' => $request->string('cognome')->toString(),
                'email' => $email,
                'password' => $request->string('password')->toString(), // hash via cast 'hashed'
                'telefono' => $request->filled('telefono') ? $request->string('telefono')->toString() : null,
                'attivo' => true,
                'privacy_accettata_at' => now(),
            ]);

            $account->sendEmailVerificationNotification();
        }

        return redirect()
            ->route('pubblico.login', $associazione->slug)
            ->with('success', self::MESSAGGIO_INVIATA);
    }

    public const MESSAGGIO_INVIATA = 'Registrazione ricevuta! Ti abbiamo inviato un\'email: apri il link per confermare l\'indirizzo, poi accedi con la tua email e la password.';

    /**
     * Email già registrata: avviso al titolare (best-effort, con un limite
     * giornaliero per non inondarne la casella) e traccia nel log.
     */
    private function avvisaTitolare(Account $esistente, string $password): void
    {
        // Stesso costo di una registrazione vera (hash della password): i tempi
        // di risposta non distinguono i due casi
        Hash::make($password);

        Log::info('Registrazione account: email già registrata nel tenant, nessun account creato', [
            'associazione_id' => $esistente->associazione_id,
            'account_id' => $esistente->id,
        ]);

        if ($esistente->trashed()) {
            return;
        }

        if (RateLimiter::hit('registrazione-account-avviso:'.$esistente->id, 86400) > self::AVVISI_PER_ACCOUNT) {
            return;
        }

        try {
            $esistente->notify(new TentativoRegistrazioneAccount);
        } catch (\Throwable $e) {
            Log::warning('Registrazione account: avviso al titolare non inviato', [
                'account_id' => $esistente->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
