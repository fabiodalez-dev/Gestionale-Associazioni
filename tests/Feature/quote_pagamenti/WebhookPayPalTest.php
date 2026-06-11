<?php

namespace Tests\Feature\quote_pagamenti;

use App\Models\Quota;
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
}
