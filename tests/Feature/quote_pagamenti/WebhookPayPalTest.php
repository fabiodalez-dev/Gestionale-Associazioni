<?php

namespace Tests\Feature\quote_pagamenti;

use App\Models\Quota;
use App\Services\Pagamenti\Gateways\PayPalGateway;
use App\Services\Pagamenti\Gateways\PayPalGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebhookPayPalTest extends TestCase
{
    use ConfiguraTenantQuote;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
        Notification::fake();
    }

    /**
     * @return array<string, mixed>
     */
    protected function paypalPayload(Quota $quota): array
    {
        return [
            'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => [
                'id' => 'ORDER-1AB23456',
                'purchase_units' => [
                    ['custom_id' => $quota->id],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function captureCompletata(Quota $quota): array
    {
        return [
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'payments' => [
                    'captures' => [[
                        'id' => 'CAPTURE-789',
                        'custom_id' => $quota->id,
                    ]],
                ],
            ]],
        ];
    }

    public function test_body_vuoto_restituisce_400(): void
    {
        $this->call('POST', route('webhooks.paypal'), [], [], [], [], '')->assertStatus(400);
    }

    public function test_evento_senza_custom_id_viene_ignorato_con_200(): void
    {
        $response = $this->postJson(route('webhooks.paypal'), [
            'event_type' => 'PAYMENT.CAPTURE.DENIED',
            'resource' => ['id' => 'X'],
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ignored']);
    }

    public function test_quota_inesistente_restituisce_404(): void
    {
        $response = $this->postJson(route('webhooks.paypal'), [
            'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => [
                'id' => 'ORDER-X',
                'purchase_units' => [['custom_id' => (string) Str::uuid()]],
            ],
        ]);

        $response->assertNotFound();
        $response->assertJson(['error' => 'Quota not found']);
    }

    public function test_ordine_approvato_esegue_capture_e_aggiorna_quota(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);
        $this->configuraPayPal();

        $this->mock(PayPalGatewayInterface::class, function ($mock) use ($quota) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturnTrue();
            $mock->shouldReceive('captureOrder')
                ->once()
                ->withArgs(fn ($config, $orderId) => $orderId === 'ORDER-1AB23456')
                ->andReturn($this->captureCompletata($quota));
        });

        $response = $this->postJson(route('webhooks.paypal'), $this->paypalPayload($quota));

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);

        $quota->refresh();
        $this->assertSame(Quota::STATO_PAGATA, $quota->getRawOriginal('stato'));
        $this->assertSame('paypal', $quota->gateway);
        $this->assertSame('CAPTURE-789', $quota->gateway_transaction_id);
        $this->assertSame('paypal', $quota->metodo_pagamento);
    }

    public function test_capture_non_completato_restituisce_400(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);
        $this->configuraPayPal();

        $this->mock(PayPalGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturnTrue();
            $mock->shouldReceive('captureOrder')->once()->andReturn(['status' => 'DECLINED']);
        });

        $response = $this->postJson(route('webhooks.paypal'), $this->paypalPayload($quota));

        $response->assertStatus(400);
        $this->assertNull($quota->fresh()->data_pagamento);
    }

    public function test_firma_webhook_invalida_restituisce_400(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);
        $this->configuraPayPal(['paypal_webhook_id' => 'WH-123']);

        $this->mock(PayPalGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturnFalse();
            $mock->shouldReceive('captureOrder')->never();
        });

        $response = $this->postJson(route('webhooks.paypal'), $this->paypalPayload($quota));

        $response->assertStatus(400);
        $this->assertNull($quota->fresh()->data_pagamento);
    }

    public function test_paypal_non_abilitato_per_il_tenant_restituisce_400(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);
        // Nessuna config gateway per il tenant

        $response = $this->postJson(route('webhooks.paypal'), $this->paypalPayload($quota));

        $response->assertStatus(400);
    }

    public function test_evento_di_tipo_diverso_viene_ackato(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);
        $this->configuraPayPal();

        $this->mock(PayPalGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturnTrue();
            $mock->shouldReceive('captureOrder')->never();
        });

        $payload = $this->paypalPayload($quota);
        $payload['event_type'] = 'CHECKOUT.ORDER.COMPLETED';

        $response = $this->postJson(route('webhooks.paypal'), $payload);

        $response->assertOk();
        $this->assertNull($quota->fresh()->data_pagamento);
    }

    // ------------------------------------------------------------------
    // Hardening (audit run-1): importo/valuta dal capture, custom_id mai
    // dall'evento, webhook id obbligatorio, rate limit
    // ------------------------------------------------------------------

    /**
     * Capture completato con l'importo effettivamente incassato
     * (è la risposta dell'API: la fonte di verità).
     *
     * @return array<string, mixed>
     */
    protected function captureConImporto(Quota $quota, string $valore, string $valuta = 'EUR'): array
    {
        $capture = $this->captureCompletata($quota);
        $capture['purchase_units'][0]['payments']['captures'][0]['amount'] = [
            'value' => $valore,
            'currency_code' => $valuta,
        ];

        return $capture;
    }

    public function test_capture_con_importo_e_valuta_coerenti_accredita_la_quota(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, ['importo' => '35.00']);
        $this->configuraPayPal();

        $this->mock(PayPalGatewayInterface::class, function ($mock) use ($quota) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturnTrue();
            $mock->shouldReceive('captureOrder')->once()->andReturn($this->captureConImporto($quota, '35.00'));
        });

        $this->postJson(route('webhooks.paypal'), $this->paypalPayload($quota))
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertTrue($quota->fresh()->isPagata());
    }

    public function test_capture_con_importo_diverso_non_accredita_e_risponde_ignorato(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, ['importo' => '35.00']);
        $this->configuraPayPal();

        // Ordine creato con il solo client_id pubblico per 1 EUR, custom_id della
        // quota da 35 EUR: il capture riesce ma l'importo non torna
        $this->mock(PayPalGatewayInterface::class, function ($mock) use ($quota) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturnTrue();
            $mock->shouldReceive('captureOrder')->once()->andReturn($this->captureConImporto($quota, '1.00'));
        });

        // Ack a PayPal (un retry non cambierebbe nulla), ma nessun accredito
        $this->postJson(route('webhooks.paypal'), $this->paypalPayload($quota))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $quota->refresh();
        $this->assertNull($quota->data_pagamento);
        $this->assertNull($quota->gateway_transaction_id);
        $this->assertDatabaseMissing('storico_attivita_socio', ['socio_id' => $socio->id, 'tipo_attivita' => 'Pagamento Online']);
    }

    public function test_capture_con_valuta_diversa_non_accredita(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, ['importo' => '35.00']);
        $this->configuraPayPal(['valuta' => 'EUR']);

        $this->mock(PayPalGatewayInterface::class, function ($mock) use ($quota) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturnTrue();
            $mock->shouldReceive('captureOrder')->once()->andReturn($this->captureConImporto($quota, '35.00', 'USD'));
        });

        $this->postJson(route('webhooks.paypal'), $this->paypalPayload($quota))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->assertNull($quota->fresh()->data_pagamento);
    }

    public function test_il_custom_id_dell_evento_non_viene_usato_se_capture_e_ordine_non_lo_confermano(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);
        $this->configuraPayPal();

        // Capture completato ma senza custom_id, e dettagli ordine non leggibili:
        // il custom_id presente nell'evento (contenuto non attendibile) non basta
        $this->mock(PayPalGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturnTrue();
            $mock->shouldReceive('captureOrder')->once()->andReturn([
                'status' => 'COMPLETED',
                'purchase_units' => [['payments' => ['captures' => [['id' => 'CAPTURE-SENZA-CUSTOM']]]]],
            ]);
            $mock->shouldReceive('showOrderDetails')->once()->withArgs(fn ($config, $orderId) => $orderId === 'ORDER-1AB23456')->andReturnNull();
        });

        $this->postJson(route('webhooks.paypal'), $this->paypalPayload($quota))->assertStatus(400);

        $this->assertNull($quota->fresh()->data_pagamento);
    }

    public function test_custom_id_assente_dal_capture_viene_letto_dai_dettagli_dell_ordine(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, ['importo' => '35.00']);
        $this->configuraPayPal();

        $this->mock(PayPalGatewayInterface::class, function ($mock) use ($quota) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturnTrue();
            $mock->shouldReceive('captureOrder')->once()->andReturn([
                'status' => 'COMPLETED',
                'purchase_units' => [['payments' => ['captures' => [[
                    'id' => 'CAPTURE-DA-ORDINE',
                    'amount' => ['value' => '35.00', 'currency_code' => 'EUR'],
                ]]]]],
            ]);
            $mock->shouldReceive('showOrderDetails')->once()->andReturn([
                'id' => 'ORDER-1AB23456',
                'purchase_units' => [['custom_id' => $quota->id]],
            ]);
        });

        $this->postJson(route('webhooks.paypal'), $this->paypalPayload($quota))
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $quota->refresh();
        $this->assertTrue($quota->isPagata());
        $this->assertSame('CAPTURE-DA-ORDINE', $quota->gateway_transaction_id);
    }

    public function test_senza_webhook_id_configurato_il_webhook_viene_rifiutato(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);
        $this->configuraPayPal(['paypal_webhook_id' => '']);

        // Fail closed: senza webhook id la firma non è verificabile, nessun capture
        $this->mock(PayPalGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('verifyWebhookSignature')->never();
            $mock->shouldReceive('captureOrder')->never();
        });

        $this->postJson(route('webhooks.paypal'), $this->paypalPayload($quota))->assertStatus(400);

        $this->assertNull($quota->fresh()->data_pagamento);
    }

    public function test_la_verifica_firma_del_gateway_reale_fallisce_senza_webhook_id(): void
    {
        $config = $this->configuraPayPal(['paypal_webhook_id' => '']);

        // Nessuna chiamata di rete: con webhook id vuoto il gateway rifiuta subito
        $this->assertFalse((new PayPalGateway)->verifyWebhookSignature($config, [], ['event_type' => 'CHECKOUT.ORDER.APPROVED']));
    }

    public function test_il_webhook_e_limitato_a_60_richieste_al_minuto_per_ip(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->call('POST', route('webhooks.paypal'), [], [], [], [], '')->assertStatus(400);
        }

        $this->call('POST', route('webhooks.paypal'), [], [], [], [], '')->assertStatus(429);
    }
}
