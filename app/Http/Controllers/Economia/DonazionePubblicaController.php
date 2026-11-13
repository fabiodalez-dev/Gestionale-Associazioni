<?php

namespace App\Http\Controllers\Economia;

use App\Http\Controllers\Controller;
use App\Http\Requests\Economia\DonaRequest;
use App\Models\Associazione;
use App\Models\Donazione;
use App\Models\PaymentGatewaySetting;
use App\Services\Pagamenti\PaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Donazioni online dal mini-sito pubblico /a/{slug}/dona (f.15 del
 * piano "Gestione 2"): importo libero con minimo, donatore con email
 * (per la ricevuta), anonimato opzionale, checkout Stripe/PayPal via
 * interfaccia Pagabile. La conferma reale arriva SEMPRE dal webhook
 * (dispatch tipo 'donazione'), mai dal redirect di ritorno.
 *
 * La pagina di esito mostra importo ed email del donatore: è raggiungibile
 * solo con l'URL firmato (APP_KEY) consegnato al gateway come URL di
 * ritorno, non con il solo UUID della donazione.
 */
class DonazionePubblicaController extends Controller
{
    public function __construct(protected PaymentService $paymentService) {}

    /**
     * GET /a/{slug}/dona — form di donazione.
     */
    public function show(Associazione $associazione): View
    {
        $config = PaymentGatewaySetting::forTenant($associazione->id);

        return view('pubblico.dona.show', [
            'associazione' => $associazione,
            'hasStripe' => $config !== null && $config->isStripeOperational(),
            'hasPayPal' => $config !== null && $config->isPayPalOperational(),
            'importoMinimo' => DonaRequest::IMPORTO_MINIMO,
        ]);
    }

    /**
     * POST /a/{slug}/dona — crea la donazione (in_attesa) e redirige
     * al checkout del gateway scelto.
     */
    public function store(DonaRequest $request, Associazione $associazione): RedirectResponse
    {
        $config = PaymentGatewaySetting::forTenant($associazione->id);
        $gateway = (string) $request->input('gateway');

        $gatewayOperativo = $gateway === 'stripe'
            ? $config !== null && $config->isStripeOperational()
            : $config !== null && $config->isPayPalOperational();

        if (! $gatewayOperativo) {
            return redirect()->route('pubblico.dona.show', $associazione->slug)
                ->with('checkout_error', 'Metodo di pagamento non disponibile. Riprova.')
                ->withInput();
        }

        $donazione = Donazione::create([
            ...$request->datiDonazione(),
            'associazione_id' => $associazione->id,
        ]);

        // URL di ritorno firmati: la firma copre anche l'esito, così la pagina
        // non è indovinabile dall'UUID e l'esito non è alterabile
        $returnUrl = URL::signedRoute('pubblico.dona.esito', [
            'associazione' => $associazione->slug,
            'donazione' => $donazione->id,
            'esito' => 'success',
        ]);
        $cancelUrl = URL::signedRoute('pubblico.dona.esito', [
            'associazione' => $associazione->slug,
            'donazione' => $donazione->id,
            'esito' => 'cancel',
        ]);

        $url = $gateway === 'stripe'
            ? $this->paymentService->createStripeCheckoutDonazione($donazione, $returnUrl, $cancelUrl)
            : $this->paymentService->createPayPalOrderDonazione($donazione, $returnUrl, $cancelUrl);

        if ($url !== null) {
            return redirect()->away($url);
        }

        // Checkout non creato: niente donazione orfana in elenco
        $donazione->delete();

        return redirect()->route('pubblico.dona.show', $associazione->slug)
            ->with('checkout_error', 'Errore nella creazione del pagamento. Riprova.')
            ->withInput();
    }

    /**
     * Parametri che i gateway aggiungono all'URL di ritorno (PayPal: token e
     * PayerID; Stripe: session_id se usato il placeholder): esclusi dalla
     * verifica della firma.
     */
    private const PARAMETRI_GATEWAY = ['token', 'PayerID', 'session_id'];

    /**
     * GET /a/{slug}/dona/{donazione}/esito — esito con stato live
     * (lo stato reale lo decide il webhook). Solo con URL firmato.
     */
    public function esito(Request $request, Associazione $associazione, string $donazione): View
    {
        // Firma mancante o manomessa → 403, prima della lookup
        abort_unless($request->hasValidSignatureWhileIgnoring(self::PARAMETRI_GATEWAY), 403);

        // Lookup tenant-scoped (global scope via tenant.pubblico):
        // 404 uniforme cross-tenant
        /** @var Donazione $donazione */
        $donazione = Donazione::query()->findOrFail($donazione);

        return view('pubblico.dona.esito', [
            'associazione' => $associazione,
            'donazione' => $donazione,
            'annullata' => $request->query('esito') === 'cancel',
        ]);
    }
}
