<?php

namespace App\Http\Controllers\Rinnovi;

use App\Http\Controllers\Controller;
use App\Models\Socio;
use App\Services\Rinnovi\RinnovoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Rinnovo self-service dall'area soci (f.1): il socio autenticato avvia
 * il rinnovo per l'anno corrente. La quota dell'anno viene creata se
 * mancante (PaymentService::createQuotaForSocio) e il socio è portato
 * alla pagina di pagamento pubblica con il payment_token esistente.
 */
class RinnovoSocioController extends Controller
{
    public function __construct(private readonly RinnovoService $rinnovi) {}

    public function store(Request $request): RedirectResponse
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        // Anno calcolato una volta sola: quotaPerRinnovo può emettere la
        // tessera e un nuovo calcolo indicherebbe nel messaggio l'anno dopo
        $anno = $this->rinnovi->annoRinnovo($socio);
        $avevaTessera = $this->rinnovi->haTesseraAnno($socio, $anno);
        $quota = $this->rinnovi->quotaPerRinnovo($socio, $anno);

        // Quota dell'anno già pagata: nessun pagamento, la tessera mancante
        // è stata emessa adesso e l'esito è un successo, non un avviso
        if ($quota === null && ! $avevaTessera && $this->rinnovi->haTesseraAnno($socio, $anno)) {
            return redirect()
                ->route('area-soci.dashboard')
                ->with('success', 'La quota '.$anno.' risulta già pagata: la tessera per il '.$anno.' è stata emessa.');
        }

        // Quota già pagata ma emissione della tessera non riuscita: la tessera
        // manca ancora, non è il caso "già emessa" dell'avviso generico
        if ($quota === null && ! $this->rinnovi->haTesseraAnno($socio, $anno)
            && $this->rinnovi->puoRinnovare($socio, $anno) && $this->rinnovi->quotaAnnoPagata($socio, $anno)) {
            return redirect()
                ->route('area-soci.dashboard')
                ->with('warning', 'La quota '.$anno.' risulta già pagata, ma la tessera non è stata emessa. Riprova più tardi o contatta la segreteria.');
        }

        if ($quota === null) {
            return redirect()
                ->route('area-soci.dashboard')
                ->with('warning', 'Il rinnovo non è disponibile: la tessera per il '.$anno.' risulta già emessa oppure il tuo profilo non è attivo.');
        }

        return redirect()->route('pagamento.show', ['token' => $quota->payment_token]);
    }
}
