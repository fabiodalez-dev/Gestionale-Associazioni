<?php

namespace App\Http\Controllers\Quote;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quote\ImpostazioniPagamentiRequest;
use App\Models\PaymentGatewaySetting;
use App\Models\Quota;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Impostazioni gateway di pagamento per tenant (upsert, una riga per associazione).
 * Le chiavi segrete sono cifrate at-rest e mai ri-mostrate in chiaro.
 */
class ImpostazioniPagamentiController extends Controller
{
    use AuthorizesRequests;

    public function edit(Request $request): View
    {
        $this->authorize('gestisciImpostazioni', Quota::class);

        $settings = PaymentGatewaySetting::forTenant(session('associazione_id'));

        return view('quote.impostazioni', [
            'settings' => $settings,
            'stripeWebhookUrl' => route('webhooks.stripe'),
            'paypalWebhookUrl' => route('webhooks.paypal'),
        ]);
    }

    public function update(ImpostazioniPagamentiRequest $request): RedirectResponse
    {
        $this->authorize('gestisciImpostazioni', Quota::class);

        $associazioneId = (string) session('associazione_id');

        $settings = PaymentGatewaySetting::forTenant($associazioneId)
            ?? new PaymentGatewaySetting(['associazione_id' => $associazioneId]);

        $settings->fill([
            'stripe_enabled' => $request->boolean('stripe_enabled'),
            'stripe_publishable_key' => (string) $request->input('stripe_publishable_key', ''),
            'paypal_enabled' => $request->boolean('paypal_enabled'),
            'paypal_client_id' => (string) $request->input('paypal_client_id', ''),
            'paypal_mode' => (string) $request->input('paypal_mode', 'sandbox'),
            'paypal_webhook_id' => (string) $request->input('paypal_webhook_id', ''),
            'auto_attivazione_pagamento' => $request->boolean('auto_attivazione_pagamento'),
            'valuta' => (string) $request->input('valuta', 'EUR'),
        ]);

        // Le secret vengono aggiornate solo se valorizzate (mai ri-mostrate, mai azzerate per sbaglio)
        if (filled($request->input('stripe_secret_key'))) {
            $settings->stripe_secret_key_encrypted = (string) $request->input('stripe_secret_key');
        }
        if (filled($request->input('stripe_webhook_secret'))) {
            $settings->stripe_webhook_secret_encrypted = (string) $request->input('stripe_webhook_secret');
        }
        if (filled($request->input('paypal_client_secret'))) {
            $settings->paypal_client_secret_encrypted = (string) $request->input('paypal_client_secret');
        }

        $settings->save();

        return redirect()->route('quote.impostazioni.edit')
            ->with('success', 'Impostazioni pagamenti salvate.');
    }
}
