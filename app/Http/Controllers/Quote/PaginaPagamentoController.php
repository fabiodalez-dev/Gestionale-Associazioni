<?php

namespace App\Http\Controllers\Quote;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewaySetting;
use App\Models\Quota;
use App\Services\Pagamenti\PaymentService;
use App\Services\Pagamenti\RicevutaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pagina pubblica di pagamento (ex pagamento.php): nessuna autenticazione,
 * l'autorizzazione è il token stesso (64 char hex, scadenza 72h, ridotta
 * a una breve finestra di grazia dopo il pagamento online).
 * Il tenant deriva dalla quota: lookup senza global scope.
 */
class PaginaPagamentoController extends Controller
{
    public function __construct(protected PaymentService $paymentService) {}

    public function show(Request $request, string $token): View
    {
        $quota = $this->resolveQuota($token);

        $config = PaymentGatewaySetting::forTenant($quota->associazione_id);
        $tokenExpired = $this->tokenScaduto($quota);

        return view('pagamento.show', [
            'quota' => $quota,
            'socio' => $quota->socio()->withTrashed()->first(),
            'associazione' => $quota->associazione,
            'config' => $config,
            'hasStripe' => $config !== null && $config->isStripeOperational(),
            'hasPayPal' => $config !== null && $config->isPayPalOperational(),
            'valuta' => strtoupper($config->valuta ?? 'EUR'),
            'tokenExpired' => $tokenExpired,
            // A token scaduto la pagina mostra solo "link scaduto": non rivela
            // lo stato della quota né offre la ricevuta (che resta disponibile
            // dall'area riservata e via email)
            'alreadyPaid' => ! $tokenExpired && $quota->isPagata(),
            // result=success è puramente informativo: la conferma reale arriva dal webhook
            'result' => $request->query('result'),
            'token' => $token,
        ]);
    }

    /**
     * POST checkout: avvia il pagamento sul gateway scelto.
     */
    public function checkout(Request $request, string $token): RedirectResponse
    {
        $quota = $this->resolveQuota($token);

        $validated = $request->validate([
            'gateway' => ['required', 'in:stripe,paypal'],
        ]);

        // Doppio guard server-side (parità legacy §6.3)
        if ($this->tokenScaduto($quota) || $quota->isPagata()) {
            return redirect()->route('pagamento.show', ['token' => $token]);
        }

        $returnUrl = route('pagamento.show', ['token' => $token, 'result' => 'success']);
        $cancelUrl = route('pagamento.show', ['token' => $token, 'result' => 'cancel']);

        if ($validated['gateway'] === 'stripe') {
            $url = $this->paymentService->createStripeCheckout($quota, $returnUrl, $cancelUrl);

            if ($url !== null) {
                return redirect()->away($url);
            }

            return redirect()->route('pagamento.show', ['token' => $token])
                ->with('checkout_error', 'Errore nella creazione del pagamento Stripe. Riprova.');
        }

        $url = $this->paymentService->createPayPalOrder($quota, $returnUrl, $cancelUrl);

        if ($url !== null) {
            return redirect()->away($url);
        }

        return redirect()->route('pagamento.show', ['token' => $token])
            ->with('checkout_error', 'Errore nella creazione del pagamento PayPal. Riprova.');
    }

    /**
     * Ricevuta PDF pubblica (disponibile solo a pagamento avvenuto e con
     * token ancora valido: dopo la finestra di grazia post-pagamento la
     * ricevuta, che contiene dati personali, si scarica solo dall'area
     * riservata). È la stessa ricevuta NUMERATA dell'area soci e dell'area
     * amministrativa (f.21): emessa on demand se manca, idempotente.
     */
    public function ricevuta(string $token, RicevutaService $ricevute): Response|RedirectResponse
    {
        $quota = $this->resolveQuota($token);

        if ($this->tokenScaduto($quota) || ! $quota->isPagata()) {
            return redirect()->route('pagamento.show', ['token' => $token]);
        }

        $ricevuta = $ricevute->emettiPerQuota($quota);

        if ($ricevuta === null) {
            return redirect()->route('pagamento.show', ['token' => $token]);
        }

        return response($ricevute->pdfContent($ricevuta), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$ricevute->nomeFile($ricevuta).'"',
        ]);
    }

    protected function tokenScaduto(Quota $quota): bool
    {
        return $quota->payment_token_expires !== null && $quota->payment_token_expires->isPast();
    }

    /**
     * Lookup per token senza filtro tenant (il tenant deriva dalla quota).
     * Il formato token è già garantito dal vincolo di rotta [a-fA-F0-9]{64}.
     */
    protected function resolveQuota(string $token): Quota
    {
        /** @var Quota|null $quota */
        $quota = Quota::allTenants()
            ->where('payment_token', strtolower($token))
            ->first();

        abort_if($quota === null, 404, 'Link di pagamento non trovato o non valido.');

        return $quota;
    }
}
