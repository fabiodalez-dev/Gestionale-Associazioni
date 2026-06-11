<?php

namespace App\Http\Controllers\FrontendPubblico\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\FrontendPubblico\Account\CancellaAccountRequest;
use App\Models\Account;
use App\Models\Associazione;
use App\Models\Prenotazione;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Privacy GDPR dell'area riservata account:
 * - export JSON completo dei propri dati + prenotazioni (portabilità);
 * - cancellazione con conferma password → Account::anonimizza()
 *   (le prenotazioni restano intatte, scollegate dall'identità).
 */
class PrivacyAccountController extends Controller
{
    public function show(Request $request, Associazione $associazione): View
    {
        /** @var Account $account */
        $account = $request->user('account');

        return view('pubblico.account.privacy', [
            'associazione' => $associazione,
            'account' => $account,
        ]);
    }

    /**
     * GET /a/{slug}/account/privacy/export — download JSON dei dati.
     */
    public function export(Request $request, Associazione $associazione): JsonResponse
    {
        /** @var Account $account */
        $account = $request->user('account');

        Gate::forUser($account)->authorize('export', $account);

        $prenotazioni = $account->prenotazioni()
            ->with(['evento' => fn ($q) => $q->withoutGlobalScope('associazione')])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Prenotazione $p): array => [
                'codice' => $p->codice,
                'stato' => $p->stato,
                'posti' => (int) $p->posti,
                'prezzo_unitario' => (float) $p->prezzo_unitario,
                'importo_totale' => (float) $p->importo_totale,
                'gateway' => $p->gateway,
                'pagata_at' => $p->pagata_at?->toIso8601String(),
                'promossa_at' => $p->promossa_at?->toIso8601String(),
                'annullata_at' => $p->annullata_at?->toIso8601String(),
                'motivo_annullamento' => $p->motivo_annullamento,
                'checkin_at' => $p->checkin_at?->toIso8601String(),
                'creata_at' => $p->created_at?->toIso8601String(),
                'evento' => $p->evento === null ? null : [
                    'titolo' => $p->evento->titolo,
                    'data_evento' => $p->evento->data_evento->toIso8601String(),
                    'luogo' => $p->evento->luogo,
                ],
            ]);

        $dati = [
            'esportato_at' => now()->toIso8601String(),
            'associazione' => $associazione->nome,
            'account' => [
                'id' => $account->id,
                'nome' => $account->nome,
                'cognome' => $account->cognome,
                'email' => $account->email,
                'telefono' => $account->telefono,
                'email_verificata_at' => $account->email_verified_at?->toIso8601String(),
                'privacy_accettata_at' => $account->privacy_accettata_at?->toIso8601String(),
                'richiesta_socio_at' => $account->richiesta_socio_at?->toIso8601String(),
                'socio_collegato' => $account->socio_id !== null,
                'preferenze' => $account->preferenze,
                'registrato_at' => $account->created_at?->toIso8601String(),
                'ultimo_accesso_at' => $account->ultimo_accesso_at?->toIso8601String(),
            ],
            'prenotazioni' => $prenotazioni,
        ];

        return response()
            ->json($dati, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('Content-Disposition', 'attachment; filename="dati-account-'.$associazione->slug.'.json"');
    }

    /**
     * POST /a/{slug}/account/privacy/cancella — anonimizzazione GDPR
     * con conferma password, poi logout e ritorno alla home pubblica.
     */
    public function cancella(CancellaAccountRequest $request, Associazione $associazione): RedirectResponse
    {
        /** @var Account $account */
        $account = $request->user('account');

        Gate::forUser($account)->authorize('anonimizza', $account);

        $account->anonimizza();

        Auth::guard('account')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('pubblico.home.show', ['associazione' => $associazione->slug])
            ->with('success', 'Il tuo account è stato cancellato. I dati personali sono stati anonimizzati.');
    }
}
