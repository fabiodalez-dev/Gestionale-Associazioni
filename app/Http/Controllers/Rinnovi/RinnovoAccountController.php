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

    /**
     * Rinnovo dal mini-sito per l'account collegato a un socio del tenant:
     * avvia il pagamento della quota o emette la tessera se la quota è già pagata.
     */
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

        // Anno calcolato una volta sola: quotaPerRinnovo può emettere la
        // tessera e un nuovo calcolo indicherebbe nel messaggio l'anno dopo
        $anno = $this->rinnovi->annoRinnovo($socio);
        $avevaTessera = $this->rinnovi->haTesseraAnno($socio, $anno);
        $quota = $this->rinnovi->quotaPerRinnovo($socio, $anno);

        // Quota dell'anno già pagata: nessun pagamento, la tessera mancante
        // è stata emessa adesso e l'esito è un successo, non un avviso
        if ($quota === null && ! $avevaTessera && $this->rinnovi->haTesseraAnno($socio, $anno)) {
            return redirect()
                ->route('pubblico.account.tessera', $associazione)
                ->with('success', 'La quota '.$anno.' risulta già pagata: la tessera per il '.$anno.' è stata emessa.');
        }

        // Quota già pagata ma emissione della tessera non riuscita: la tessera
        // manca ancora, non è il caso "già emessa" dell'avviso generico
        if ($quota === null && ! $this->rinnovi->haTesseraAnno($socio, $anno)
            && $this->rinnovi->puoRinnovare($socio, $anno) && $this->rinnovi->quotaAnnoPagata($socio, $anno)) {
            return redirect()
                ->route('pubblico.account.tessera', $associazione)
                ->with('warning', 'La quota '.$anno.' risulta già pagata, ma la tessera non è stata emessa. Riprova più tardi o contatta la segreteria.');
        }

        if ($quota === null) {
            return redirect()
                ->route('pubblico.account.tessera', $associazione)
                ->with('warning', 'Il rinnovo non è disponibile: la tessera per il '.$anno.' risulta già emessa oppure il profilo socio non è attivo.');
        }

        return redirect()->route('pagamento.show', ['token' => $quota->payment_token]);
    }
}
