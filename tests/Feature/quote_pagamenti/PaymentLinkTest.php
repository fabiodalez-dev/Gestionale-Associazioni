<?php

namespace Tests\Feature\quote_pagamenti;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentLinkTest extends TestCase
{
    use ConfiguraTenantQuote;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
    }

    public function test_genera_token_64_hex_con_scadenza_72_ore(): void
    {
        $this->configuraStripe();
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);

        $response = $this->actingAs($this->admin)->post(route('quote.payment-link', $quota));

        $response->assertRedirect(route('quote.index'));
        $response->assertSessionHas('payment_link');

        $quota->refresh();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $quota->payment_token);
        $this->assertTrue(
            $quota->payment_token_expires->between(now()->addHours(71), now()->addHours(73)),
            'La scadenza del token deve essere ~72 ore da adesso'
        );

        $this->assertStringContainsString(
            route('pagamento.show', ['token' => $quota->payment_token]),
            (string) session('payment_link')
        );
    }

    public function test_rigenerazione_sovrascrive_il_token_precedente(): void
    {
        $this->configuraPayPal();
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);

        $this->actingAs($this->admin)->post(route('quote.payment-link', $quota));
        $primoToken = $quota->fresh()->payment_token;

        $this->actingAs($this->admin)->post(route('quote.payment-link', $quota));
        $secondoToken = $quota->fresh()->payment_token;

        $this->assertNotSame($primoToken, $secondoToken);
    }

    public function test_rifiutato_se_quota_gia_pagata(): void
    {
        $this->configuraStripe();
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, ['data_pagamento' => now()->toDateString()]);

        $response = $this->actingAs($this->admin)->post(route('quote.payment-link', $quota));

        $response->assertSessionHas('error', 'Questa quota risulta già pagata.');
        $this->assertNull($quota->fresh()->payment_token);
    }

    public function test_rifiutato_senza_gateway_completamente_configurato(): void
    {
        // Check forte: enabled + publishable key ma SENZA secret key → non operativo
        $this->configuraStripe([
            'stripe_secret_key_encrypted' => null,
        ]);
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);

        $response = $this->actingAs($this->admin)->post(route('quote.payment-link', $quota));

        $response->assertSessionHas('error');
        $this->assertNull($quota->fresh()->payment_token);
    }
}
