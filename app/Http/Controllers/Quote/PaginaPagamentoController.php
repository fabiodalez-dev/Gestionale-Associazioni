<?php

namespace App\Http\Controllers\Quote;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewaySetting;
use App\Models\Quota;
use App\Services\Pagamenti\PaymentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pagina pubblica di pagamento (ex pagamento.php): nessuna autenticazione,
 * l'autorizzazione è il token stesso (64 char hex, scadenza 72h).
 * Il tenant deriva dalla quota: lookup senza global scope.
 */
class PaginaPagamentoController extends Controller
{
    public function __construct(protected PaymentService $paymentService) {}

    public function show(Request $request, string $token): View
    {
        $quota = $this->resolveQuota($token);

        $config = PaymentGatewaySetting::forTenant($quota->associazione_id);

        return view('pagamento.show', [
            'quota' => $quota,
            'socio' => $quota->socio()->withTrashed()->first(),
            'associazione' => $quota->associazione,
            'config' => $config,
            'hasStripe' => $config !== null && $config->isStripeOperational(),
            'hasPayPal' => $config !== null && $config->isPayPalOperational(),
            'valuta' => strtoupper($config->valuta ?? 'EUR'),
            'tokenExpired' => $quota->payment_token_expires !== null && $quota->payment_token_expires->isPast(),
            'alreadyPaid' => $quota->isPagata(),
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
        $tokenExpired = $quota->payment_token_expires !== null && $quota->payment_token_expires->isPast();

        if ($tokenExpired || $quota->isPagata()) {
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
     * Ricevuta PDF pubblica (disponibile solo a pagamento avvenuto).
     */
    public function ricevuta(string $token): Response|RedirectResponse
    {
        $quota = $this->resolveQuota($token);

        if (! $quota->isPagata()) {
            return redirect()->route('pagamento.show', ['token' => $token]);
        }

        $quota->loadMissing(['associazione', 'socio' => fn ($q) => $q->withTrashed()]);

        $pdf = Pdf::loadView('quote.ricevuta-pdf', ['quota' => $quota]);

        return $pdf->download("ricevuta-quota-{$quota->anno}.pdf");
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
