<?php

namespace App\Http\Controllers\FrontendPubblico\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\FrontendPubblico\Account\DiventaSocioRequest;
use App\Models\Account;
use App\Models\Associazione;
use App\Models\Utente;
use App\Notifications\Accounts\RichiestaSocioRicevuta;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Richiesta "diventa socio" dall'area riservata: form con motivazione,
 * set di richiesta_socio_at e notifica best-effort agli amministratori
 * dell'associazione (un fallimento di invio non blocca la richiesta).
 *
 * Il collegamento al socio resta SEMPRE un'azione esplicita dell'admin
 * (mai automatico: anti account-takeover).
 */
class DiventaSocioController extends Controller
{
    public function show(Request $request, Associazione $associazione): View
    {
        /** @var Account $account */
        $account = $request->user('account');

        return view('pubblico.account.diventa-socio', [
            'associazione' => $associazione,
            'account' => $account,
        ]);
    }

    public function store(DiventaSocioRequest $request, Associazione $associazione): RedirectResponse
    {
        /** @var Account $account */
        $account = $request->user('account');

        Gate::forUser($account)->authorize('update', $account);

        if ($account->socio_id !== null) {
            return redirect()
                ->route('pubblico.account.tessera', ['associazione' => $associazione->slug])
                ->with('success', 'Sei già socio: trovi tessera e quote qui.');
        }

        if ($account->richiesta_socio_at !== null) {
            return redirect()
                ->route('pubblico.account.diventa-socio', ['associazione' => $associazione->slug])
                ->with('success', 'La tua richiesta è già stata inviata: l\'associazione ti contatterà.');
        }

        $motivazione = (string) $request->validated('motivazione');

        $account->forceFill([
            'richiesta_socio_at' => now(),
            // La motivazione accompagna la richiesta (visibile anche in admin)
            'preferenze' => array_merge(
                (array) ($account->preferenze ?? []),
                ['richiesta_socio_motivazione' => $motivazione],
            ),
        ])->save();

        $this->notificaAdmin($account, $motivazione);

        return redirect()
            ->route('pubblico.account.diventa-socio', ['associazione' => $associazione->slug])
            ->with('success', 'Richiesta inviata! L\'associazione la valuterà e ti contatterà.');
    }

    /**
     * Notifica best-effort agli admin del tenant: qualunque errore viene
     * loggato senza far fallire la richiesta dell'utente.
     */
    protected function notificaAdmin(Account $account, string $motivazione): void
    {
        try {
            $admins = Utente::query()
                ->where('associazione_id', $account->associazione_id)
                ->where('ruolo', Utente::RUOLO_ADMIN_ASSOCIAZIONE)
                ->where('attivo', true)
                ->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new RichiestaSocioRicevuta($account, $motivazione));
            }
        } catch (\Throwable $e) {
            Log::error('DiventaSocio: notifica admin fallita', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
