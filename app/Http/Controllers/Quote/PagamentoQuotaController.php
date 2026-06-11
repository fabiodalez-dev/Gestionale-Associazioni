<?php

namespace App\Http\Controllers\Quote;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quote\RegistraPagamentoRequest;
use App\Models\PaymentGatewaySetting;
use App\Models\Quota;
use App\Services\Pagamenti\PaymentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Registrazione pagamento manuale e generazione link di pagamento pubblico.
 */
class PagamentoQuotaController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected PaymentService $paymentService) {}

    /**
     * POST /quote/{quota}/pagamento — registra un pagamento offline,
     * oppure (metodo stripe/paypal) genera il link di pagamento pubblico.
     */
    public function store(RegistraPagamentoRequest $request, Quota $quota): RedirectResponse
    {
        $this->authorize('registraPagamento', $quota);

        if ($quota->isPagata()) {
            return redirect()->route('quote.index')->with('error', 'Questa quota risulta già pagata.');
        }

        if ($request->isOnlineMethod()) {
            return $this->generaLink($quota);
        }

        $this->paymentService->registerOfflinePayment(
            $quota,
            (string) $request->input('payment_method'),
            (string) $request->input('payment_date'),
            $request->user()
        );

        return redirect()->route('quote.index')->with('success', 'Pagamento registrato con successo.');
    }

    /**
     * POST /quote/{quota}/payment-link — genera il token e mostra il link copiabile.
     */
    public function paymentLink(Request $request, Quota $quota): RedirectResponse
    {
        $this->authorize('registraPagamento', $quota);

        if ($quota->isPagata()) {
            return redirect()->route('quote.index')->with('error', 'Questa quota risulta già pagata.');
        }

        return $this->generaLink($quota);
    }

    protected function generaLink(Quota $quota): RedirectResponse
    {
        // Check forte (§10.5 spec): il link viene generato solo se almeno
        // un gateway è completamente configurato (non il check debole legacy)
        $config = PaymentGatewaySetting::forTenant($quota->associazione_id);

        if ($config === null || ! $config->hasOperationalGateway()) {
            return redirect()->route('quote.index')
                ->with('error', 'Nessun metodo di pagamento online configurato. Configura Stripe o PayPal nelle impostazioni pagamenti.');
        }

        $this->paymentService->generatePaymentToken($quota);
        $link = $this->paymentService->paymentUrl($quota->refresh());

        return redirect()->route('quote.index')
            ->with('success', 'Link pagamento generato: '.$link)
            ->with('payment_link', $link);
    }
}
