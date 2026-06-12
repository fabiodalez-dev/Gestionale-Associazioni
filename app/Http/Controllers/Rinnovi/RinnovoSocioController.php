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

        $quota = $this->rinnovi->quotaPerRinnovo($socio);

        if ($quota === null) {
            return redirect()
                ->route('area-soci.dashboard')
                ->with('warning', 'Il rinnovo non è disponibile: la tessera per il '.$this->rinnovi->annoCorrente().' risulta già emessa oppure il tuo profilo non è attivo.');
        }

        return redirect()->route('pagamento.show', ['token' => $quota->payment_token]);
    }
}
