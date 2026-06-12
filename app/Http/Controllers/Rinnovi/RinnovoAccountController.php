<?php

namespace App\Http\Controllers\Rinnovi;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Associazione;
use App\Models\Socio;
use App\Services\Rinnovi\RinnovoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Rinnovo self-service dall'area account del mini-sito pubblico (f.1):
 * disponibile SOLO per gli account collegati a un socio (404 uniforme
 * altrimenti, stessa semantica della pagina tessera & quote).
 */
class RinnovoAccountController extends Controller
{
    public function __construct(private readonly RinnovoService $rinnovi) {}

    public function store(Request $request, Associazione $associazione): RedirectResponse
    {
        /** @var Account $account */
        $account = $request->user('account');

        // Difesa in profondità oltre al logout cross-tenant del middleware:
        // l'account deve appartenere al tenant del mini-sito
        abort_if($account->associazione_id !== $associazione->id, 404);

        // La pagina/azione esiste solo per gli account collegati a un socio
        abort_if($account->socio_id === null, 404);

        /** @var Socio $socio */
        $socio = $account->socio()
            ->withoutGlobalScope('associazione')
            ->where('associazione_id', $account->associazione_id)
            ->firstOrFail();

        $quota = $this->rinnovi->quotaPerRinnovo($socio);

        if ($quota === null) {
            return redirect()
                ->route('pubblico.account.tessera', $associazione)
                ->with('warning', 'Il rinnovo non è disponibile: la tessera per il '.$this->rinnovi->annoCorrente().' risulta già emessa oppure il profilo socio non è attivo.');
        }

        return redirect()->route('pagamento.show', ['token' => $quota->payment_token]);
    }
}
