<?php

namespace App\Http\Requests\Soci;

use App\Models\PaymentGatewaySetting;
use App\Models\Socio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Conferma pagamento pre-iscrizione (spec soci §2.4).
 * I metodi online (stripe/paypal) sono ammessi solo se il gateway
 * è abilitato nelle payment_gateway_settings del tenant.
 */
class ConfermaPagamentoRequest extends FormRequest
{
    public const METODI_MANUALI = ['contanti', 'bonifico', 'carta', 'altro'];

    public const METODI_ONLINE = ['stripe', 'paypal'];

    public function authorize(): bool
    {
        return true; // autorizzazione via SocioPolicy nel controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'importo' => ['required', 'numeric', 'min:0', 'max:999999'],
            'metodo' => ['required', Rule::in($this->metodiAmmessi())],
            'data_pagamento' => ['nullable', 'date'],
            'tipo' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function isMetodoOnline(): bool
    {
        return in_array($this->input('metodo'), self::METODI_ONLINE, true);
    }

    /**
     * @return list<string>
     */
    private function metodiAmmessi(): array
    {
        $metodi = self::METODI_MANUALI;

        $gateway = PaymentGatewaySetting::allTenants()
            ->where('associazione_id', Socio::currentAssociazioneId())
            ->first();

        if ($gateway?->stripe_enabled) {
            $metodi[] = 'stripe';
        }

        if ($gateway?->paypal_enabled) {
            $metodi[] = 'paypal';
        }

        return $metodi;
    }
}
