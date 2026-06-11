<?php

namespace App\Http\Requests\Quote;

use App\Models\PaymentGatewaySetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImpostazioniPagamentiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stripe_enabled' => ['sometimes', 'boolean'],
            'stripe_publishable_key' => ['nullable', 'string', 'max:255'],
            // Le secret sono opzionali: se lasciate vuote NON sovrascrivono quelle salvate
            'stripe_secret_key' => ['nullable', 'string', 'max:255'],
            'stripe_webhook_secret' => ['nullable', 'string', 'max:255'],
            'paypal_enabled' => ['sometimes', 'boolean'],
            'paypal_client_id' => ['nullable', 'string', 'max:255'],
            'paypal_client_secret' => ['nullable', 'string', 'max:255'],
            'paypal_mode' => ['required', Rule::in(PaymentGatewaySetting::PAYPAL_MODES)],
            'paypal_webhook_id' => ['nullable', 'string', 'max:255'],
            'auto_attivazione_pagamento' => ['sometimes', 'boolean'],
            'valuta' => ['required', 'string', 'size:3', 'alpha'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'stripe_enabled' => $this->boolean('stripe_enabled'),
            'paypal_enabled' => $this->boolean('paypal_enabled'),
            'auto_attivazione_pagamento' => $this->boolean('auto_attivazione_pagamento'),
            'valuta' => strtoupper((string) $this->input('valuta', 'EUR')),
        ]);
    }
}
