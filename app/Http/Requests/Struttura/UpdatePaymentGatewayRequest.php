<?php

namespace App\Http\Requests\Struttura;

use App\Models\PaymentGatewaySetting;
use Illuminate\Validation\Rule;

/**
 * Impostazioni pagamento Stripe/PayPal per tenant (spec §4.2.9).
 * Regola segreti: campo vuoto = mantieni il valore cifrato esistente.
 */
class UpdatePaymentGatewayRequest extends ConfigurazioniRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stripe_enabled' => ['nullable', 'boolean'],
            'stripe_publishable_key' => ['nullable', 'string', 'max:255'],
            'stripe_secret_key' => ['nullable', 'string', 'max:255'],
            'stripe_webhook_secret' => ['nullable', 'string', 'max:255'],
            'paypal_enabled' => ['nullable', 'boolean'],
            'paypal_client_id' => ['nullable', 'string', 'max:255'],
            'paypal_client_secret' => ['nullable', 'string', 'max:255'],
            'paypal_mode' => ['nullable', Rule::in(PaymentGatewaySetting::PAYPAL_MODES)],
            'paypal_webhook_id' => ['nullable', 'string', 'max:255'],
            'auto_attivazione_pagamento' => ['nullable', 'boolean'],
            'valuta' => ['nullable', 'string', 'size:3', 'alpha'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Whitelist legacy: fuori lista → sandbox
        if (! in_array($this->input('paypal_mode'), PaymentGatewaySetting::PAYPAL_MODES, true)) {
            $this->merge(['paypal_mode' => 'sandbox']);
        }
    }
}
