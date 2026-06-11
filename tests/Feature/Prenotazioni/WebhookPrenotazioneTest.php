<?php

namespace Tests\Feature\Prenotazioni;

use App\Models\Account;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Quota;
use App\Services\Pagamenti\Gateways\PayPalGatewayInterface;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\quote_pagamenti\ConfiguraTenantQuote;
use Tests\TestCase;

/**
 * Dispatch del webhook per tipo di pagabile:
 * - metadata.tipo = 'prenotazione' (Stripe) / custom_id "prenotazione:{uuid}"
 *   (PayPal) → PrenotazioneService::onPagamentoCompletato;
 * - metadata senza tipo / custom_id UUID nudo → flusso quota invariato
 *   (retrocompatibilità con le sessioni checkout legacy in volo).
 */
class WebhookPrenotazioneTest extends TestCase
{
    use ConfiguraTenantQuote;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
        Notification::fake();
    }

    protected function creaPrenotazioneInAttesa(array $attributes = []): Prenotazione
    {
        $evento = Evento::factory()->pubblicato()->aPagamento(10.0)->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $account = Account::factory()->create(['associazione_id' => $this->associazione->id]);

        return Prenotazione::factory()->inAttesaPagamento(10.0)->create([
            'evento_id' => $evento->id,
            'associazione_id' => $this->associazione->id,
            'account_id' => $account->id,
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function stripePayloadPrenotazione(Prenotazione $prenotazione): array
    {
        return [
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_pren',
                    'payment_intent' => 'pi_pren_456',
                    'metadata' => [
                        'tipo' => 'prenotazione',
                        'pagabile_id' => $prenotazione->id,
                        'associazione_id' => $prenotazione->associazione_id,
                    ],
                ],
            ],
        ];
    }

    protected function mockFirmaStripeValida(): void
    {
        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('constructWebhookEvent')
                ->andReturnUsing(fn (string $payload) => json_decode($payload, true));
        });
    }

    public function test_webhook_stripe_tipo_prenotazione_conferma_la_prenotazione(): void
    {
        $prenotazione = $this->creaPrenotazioneInAttesa();
        $this->configuraStripe();
        $this->mockFirmaStripeValida();

        $response = $this->postJson(route('webhooks.stripe'), $this->stripePayloadPrenotazione($prenotazione), [
            'Stripe-Signature' => 't=1,v1=abc',
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);

        $prenotazione->refresh();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $prenotazione->stato);
        $this->assertNotNull($prenotazione->pagata_at);
        $this->assertSame('stripe', $prenotazione->gateway);
        $this->assertSame('pi_pren_456', $prenotazione->gateway_transaction_id);
    }

    public function test_webhook_stripe_prenotazione_e_idempotente_sul_replay(): void
    {
        $prenotazione = $this->creaPrenotazioneInAttesa();
        $this->configuraStripe();
        $this->mockFirmaStripeValida();

        $this->postJson(route('webhooks.stripe'), $this->stripePayloadPrenotazione($prenotazione), [
            'Stripe-Signature' => 't=1,v1=abc',
        ])->assertOk();

        $pagataAt = $prenotazione->fresh()->pagata_at;

        $this->travel(2)->minutes();

        // Replay identico: ack 200 senza ripetere effetti collaterali
        $this->postJson(route('webhooks.stripe'), $this->stripePayloadPrenotazione($prenotazione), [
            'Stripe-Signature' => 't=1,v1=abc',
        ])->assertOk();

        $prenotazione->refresh();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $prenotazione->stato);
        $this->assertEquals($pagataAt, $prenotazione->pagata_at);
    }

    public function test_webhook_stripe_prenotazione_scaduta_viene_riconfermata(): void
    {
        // Pagamento arrivato dopo la scadenza: riconferma anche in overbooking
        $prenotazione = $this->creaPrenotazioneInAttesa();
        $prenotazione->forceFill([
            'stato' => Prenotazione::STATO_SCADUTA,
            'scadenza_pagamento_at' => now()->subHour(),
        ])->save();

        $this->configuraStripe();
        $this->mockFirmaStripeValida();

        $this->postJson(route('webhooks.stripe'), $this->stripePayloadPrenotazione($prenotazione), [
            'Stripe-Signature' => 't=1,v1=abc',
        ])->assertOk();

        $prenotazione->refresh();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $prenotazione->stato);
        $this->assertNotNull($prenotazione->pagata_at);
    }

    public function test_webhook_stripe_prenotazione_senza_pagabile_id_fallisce(): void
    {
        $prenotazione = $this->creaPrenotazioneInAttesa();
        $this->configuraStripe();
        $this->mockFirmaStripeValida();

        $payload = $this->stripePayloadPrenotazione($prenotazione);
        unset($payload['data']['object']['metadata']['pagabile_id']);

        $this->postJson(route('webhooks.stripe'), $payload, [
            'Stripe-Signature' => 't=1,v1=abc',
        ])->assertStatus(400);

        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $prenotazione->fresh()->stato);
    }

    public function test_webhook_stripe_senza_tipo_resta_sul_flusso_quota(): void
    {
        // Sessione legacy in volo: solo quota_id nei metadata, nessun tipo
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);
        $this->configuraStripe();
        $this->mockFirmaStripeValida();

        $this->postJson(route('webhooks.stripe'), [
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_legacy',
                    'payment_intent' => 'pi_legacy_1',
                    'metadata' => [
                        'quota_id' => $quota->id,
                        'associazione_id' => $quota->associazione_id,
                    ],
                ],
            ],
        ], ['Stripe-Signature' => 't=1,v1=abc'])->assertOk();

        $quota->refresh();
        $this->assertSame(Quota::STATO_PAGATA, $quota->getRawOriginal('stato'));
        $this->assertSame('pi_legacy_1', $quota->gateway_transaction_id);
    }

    public function test_webhook_paypal_custom_id_prefissato_conferma_la_prenotazione(): void
    {
        $prenotazione = $this->creaPrenotazioneInAttesa();
        $this->configuraPayPal();

        $customId = 'prenotazione:'.$prenotazione->id;

        $this->mock(PayPalGatewayInterface::class, function ($mock) use ($customId) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturnTrue();
            $mock->shouldReceive('captureOrder')
                ->once()
                ->withArgs(fn ($config, $orderId) => $orderId === 'ORDER-PREN-1')
                ->andReturn([
                    'status' => 'COMPLETED',
                    'purchase_units' => [[
                        'payments' => [
                            'captures' => [[
                                'id' => 'CAPTURE-PREN-1',
                                'custom_id' => $customId,
                            ]],
                        ],
                    ]],
                ]);
        });

        $response = $this->postJson(route('webhooks.paypal'), [
            'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => [
                'id' => 'ORDER-PREN-1',
                'purchase_units' => [['custom_id' => $customId]],
            ],
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);

        $prenotazione->refresh();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $prenotazione->stato);
        $this->assertSame('paypal', $prenotazione->gateway);
        $this->assertSame('CAPTURE-PREN-1', $prenotazione->gateway_transaction_id);
    }

    public function test_webhook_paypal_prenotazione_inesistente_restituisce_404(): void
    {
        $this->configuraPayPal();

        $this->postJson(route('webhooks.paypal'), [
            'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => [
                'id' => 'ORDER-X',
                'purchase_units' => [['custom_id' => 'prenotazione:00000000-0000-0000-0000-000000000000']],
            ],
        ])->assertNotFound();
    }

    public function test_webhook_paypal_custom_id_prenotazione_malformato_restituisce_400(): void
    {
        $this->configuraPayPal();

        $this->postJson(route('webhooks.paypal'), [
            'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => [
                'id' => 'ORDER-X',
                'purchase_units' => [['custom_id' => 'prenotazione:non-un-uuid']],
            ],
        ])->assertStatus(400);
    }
}
