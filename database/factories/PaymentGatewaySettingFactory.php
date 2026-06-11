<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\PaymentGatewaySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentGatewaySetting>
 */
class PaymentGatewaySettingFactory extends Factory
{
    protected $model = PaymentGatewaySetting::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'stripe_enabled' => false,
            'stripe_publishable_key' => '',
            'paypal_enabled' => false,
            'paypal_client_id' => '',
            'paypal_mode' => 'sandbox',
            'paypal_webhook_id' => '',
            'auto_attivazione_pagamento' => true,
            'valuta' => 'EUR',
        ];
    }

    public function conStripe(): static
    {
        return $this->state(fn (array $attributes) => [
            'stripe_enabled' => true,
            'stripe_publishable_key' => 'pk_test_'.fake()->lexify('????????????'),
            'stripe_secret_key_encrypted' => 'sk_test_'.fake()->lexify('????????????'),
            'stripe_webhook_secret_encrypted' => 'whsec_'.fake()->lexify('????????????'),
        ]);
    }

    public function conPayPal(): static
    {
        return $this->state(fn (array $attributes) => [
            'paypal_enabled' => true,
            'paypal_client_id' => 'pp_client_'.fake()->lexify('????????????'),
            'paypal_client_secret_encrypted' => 'pp_secret_'.fake()->lexify('????????????'),
            'paypal_mode' => 'sandbox',
        ]);
    }
}
