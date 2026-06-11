<?php

namespace Tests\Feature\quote_pagamenti;

use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Notifications\Quote\BenvenutoSocioNotification;
use App\Notifications\Quote\PagamentoQuotaNotification;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WebhookStripeTest extends TestCase
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
    protected function stripePayload(Quota $quota): array
    {
        return [
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_123',
                    'payment_intent' => 'pi_test_456',
                    'metadata' => [
                        'quota_id' => $quota->id,
                        'associazione_id' => $quota->associazione_id,
                    ],
                ],
            ],
        ];
    }

    protected function mockFirmaValida(): void
    {
        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('constructWebhookEvent')
                ->andReturnUsing(fn (string $payload) => json_decode($payload, true));
        });
    }

    public function test_payload_o_firma_mancanti_restituiscono_400(): void
    {
        $this->post(route('webhooks.stripe'), [])->assertStatus(400);
    }

    public function test_json_invalido_restituisce_400(): void
    {
        $response = $this->call(
            'POST',
            route('webhooks.stripe'),
            [],
            [],
            [],
            ['HTTP_STRIPE-SIGNATURE' => 't=1,v1=abc', 'CONTENT_TYPE' => 'application/json'],
            'non-json'
        );

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Invalid JSON']);
    }

    public function test_evento_senza_associazione_id_viene_ignorato_con_200(): void
    {
        $response = $this->postJson(route('webhooks.stripe'), [
            'type' => 'payment_intent.created',
            'data' => ['object' => ['id' => 'pi_x']],
        ], ['Stripe-Signature' => 't=1,v1=abc']);

        $response->assertOk();
        $response->assertJson(['status' => 'ignored']);
    }

    public function test_firma_invalida_restituisce_400(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);
        $this->configuraStripe();

        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('constructWebhookEvent')->once()->andReturnNull();
        });

        $response = $this->postJson(route('webhooks.stripe'), $this->stripePayload($quota), [
            'Stripe-Signature' => 't=1,v1=firma-falsa',
        ]);

        $response->assertStatus(400);
        $this->assertNull($quota->fresh()->data_pagamento);
    }

    public function test_webhook_secret_non_configurato_restituisce_400(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);
        $this->configuraStripe(['stripe_webhook_secret_encrypted' => null]);

        $response = $this->postJson(route('webhooks.stripe'), $this->stripePayload($quota), [
            'Stripe-Signature' => 't=1,v1=abc',
        ]);

        $response->assertStatus(400);
    }

    public function test_evento_di_tipo_diverso_viene_ackato_senza_azione(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);
        $this->configuraStripe();
        $this->mockFirmaValida();

        $payload = $this->stripePayload($quota);
        $payload['type'] = 'charge.refunded';

        $response = $this->postJson(route('webhooks.stripe'), $payload, [
            'Stripe-Signature' => 't=1,v1=abc',
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);
        $this->assertNull($quota->fresh()->data_pagamento);
    }

    public function test_checkout_completato_aggiorna_quota_e_logga(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);
        $this->configuraStripe();
        $this->mockFirmaValida();

        $response = $this->postJson(route('webhooks.stripe'), $this->stripePayload($quota), [
            'Stripe-Signature' => 't=1,v1=abc',
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);

        $quota->refresh();
        $this->assertSame(Quota::STATO_PAGATA, $quota->getRawOriginal('stato'));
        $this->assertSame(now()->toDateString(), $quota->data_pagamento?->toDateString());
        $this->assertSame('stripe', $quota->gateway);
        $this->assertSame('pi_test_456', $quota->gateway_transaction_id);
        $this->assertSame('stripe', $quota->metodo_pagamento);

        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Pagamento Online',
        ]);

        Notification::assertSentTo($socio, PagamentoQuotaNotification::class);
    }

    public function test_auto_attivazione_socio_in_attesa_con_tessera(): void
    {
        $socio = $this->creaSocio(['stato' => Socio::STATO_IN_ATTESA_DI_PAGAMENTO]);
        $quota = $this->creaQuota($socio);
        $this->configuraStripe(['auto_attivazione_pagamento' => true]);
        $this->mockFirmaValida();

        $this->postJson(route('webhooks.stripe'), $this->stripePayload($quota), [
            'Stripe-Signature' => 't=1,v1=abc',
        ])->assertOk();

        $socio->refresh();
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);
        $this->assertSame(now()->toDateString(), $socio->data_iscrizione?->toDateString());

        $tessera = Tessera::allTenants()->where('socio_id', $socio->id)->first();
        $this->assertNotNull($tessera);
        $this->assertSame('0001', $tessera->numero_tessera);
        $this->assertSame(Tessera::STATO_ATTIVA, $tessera->stato);
        $this->assertSame((int) now()->year, $tessera->anno_validita);

        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Attivazione automatica',
        ]);
        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Generazione Tessera',
        ]);

        Notification::assertSentTo($socio, BenvenutoSocioNotification::class);
    }

    public function test_auto_attivazione_disattivata_non_tocca_il_socio(): void
    {
        $socio = $this->creaSocio(['stato' => Socio::STATO_IN_ATTESA_DI_PAGAMENTO]);
        $quota = $this->creaQuota($socio);
        $this->configuraStripe(['auto_attivazione_pagamento' => false]);
        $this->mockFirmaValida();

        $this->postJson(route('webhooks.stripe'), $this->stripePayload($quota), [
            'Stripe-Signature' => 't=1,v1=abc',
        ])->assertOk();

        $this->assertSame(Socio::STATO_IN_ATTESA_DI_PAGAMENTO, $socio->fresh()->stato);
        $this->assertSame(0, Tessera::allTenants()->where('socio_id', $socio->id)->count());
    }

    public function test_webhook_duplicato_e_idempotente(): void
    {
        $socio = $this->creaSocio(['stato' => Socio::STATO_IN_ATTESA_DI_PAGAMENTO]);
        $quota = $this->creaQuota($socio);
        $this->configuraStripe();
        $this->mockFirmaValida();

        $this->postJson(route('webhooks.stripe'), $this->stripePayload($quota), [
            'Stripe-Signature' => 't=1,v1=abc',
        ])->assertOk();

        $dataPagamento = $quota->fresh()->data_pagamento;

        // Secondo invio identico: ack 200 senza ripetere effetti collaterali
        $this->postJson(route('webhooks.stripe'), $this->stripePayload($quota), [
            'Stripe-Signature' => 't=1,v1=abc',
        ])->assertOk();

        $this->assertEquals($dataPagamento, $quota->fresh()->data_pagamento);
        $this->assertSame(1, Tessera::allTenants()->where('socio_id', $socio->id)->count());
        $this->assertSame(
            1,
            \App\Models\StoricoAttivitaSocio::allTenants()
                ->where('socio_id', $socio->id)
                ->where('tipo_attivita', 'Pagamento Online')
                ->count()
        );
    }
}
