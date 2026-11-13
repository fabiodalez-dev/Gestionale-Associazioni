<?php

namespace Tests\Feature\quote_pagamenti;

use App\Models\Quota;
use App\Models\Socio;
use App\Models\StoricoAttivitaSocio;
use App\Models\Tessera;
use App\Notifications\Quote\BenvenutoSocioNotification;
use App\Notifications\Quote\PagamentoQuotaNotification;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
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
        // Numerazione unificata di TesseraIssuer: {anno}{progressivo 4 cifre}
        $this->assertSame(now()->year.'0001', $tessera->numero_tessera);
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
            StoricoAttivitaSocio::allTenants()
                ->where('socio_id', $socio->id)
                ->where('tipo_attivita', 'Pagamento Online')
                ->count()
        );
    }

    // ------------------------------------------------------------------
    // Hardening (audit run-1): stato/importo/valuta della sessione, dedup
    // per id evento, livemode, doppio incasso, rate limit
    // ------------------------------------------------------------------

    /**
     * Payload con campi aggiuntivi sull'oggetto sessione (e opzionalmente
     * sull'evento, es. id e livemode).
     *
     * @param  array<string, mixed>  $sessione
     * @param  array<string, mixed>  $evento
     * @return array<string, mixed>
     */
    protected function stripePayloadCon(Quota $quota, array $sessione = [], array $evento = []): array
    {
        $payload = array_merge($this->stripePayload($quota), $evento);
        $payload['data']['object'] = array_merge($payload['data']['object'], $sessione);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function inviaStripe(array $payload): TestResponse
    {
        return $this->postJson(route('webhooks.stripe'), $payload, ['Stripe-Signature' => 't=1,v1=abc']);
    }

    public function test_sessione_pagata_con_importo_e_valuta_coerenti_accredita(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, ['importo' => '42.50']);
        $this->configuraStripe(['valuta' => 'EUR']);
        $this->mockFirmaValida();

        $this->inviaStripe($this->stripePayloadCon($quota, [
            'payment_status' => 'paid',
            'amount_total' => 4250,
            'currency' => 'eur',
        ]))->assertOk()->assertJson(['status' => 'ok']);

        $this->assertTrue($quota->fresh()->isPagata());
    }

    public function test_sessione_completata_ma_non_pagata_viene_ignorata_senza_accredito(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, ['importo' => '42.50']);
        $this->configuraStripe();
        $this->mockFirmaValida();

        // Metodo di pagamento asincrono: la sessione si chiude con payment_status=unpaid
        $this->inviaStripe($this->stripePayloadCon($quota, [
            'payment_status' => 'unpaid',
            'amount_total' => 4250,
            'currency' => 'eur',
        ]))->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertNull($quota->fresh()->data_pagamento);
    }

    public function test_importo_diverso_dalla_quota_non_accredita_e_risponde_ignorato(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, ['importo' => '42.50']);
        $this->configuraStripe();
        $this->mockFirmaValida();

        $this->inviaStripe($this->stripePayloadCon($quota, [
            'payment_status' => 'paid',
            'amount_total' => 100,
            'currency' => 'eur',
        ]))->assertOk()->assertJson(['status' => 'ignored']);

        $quota->refresh();
        $this->assertNull($quota->data_pagamento);
        $this->assertNull($quota->gateway_transaction_id);
        $this->assertDatabaseMissing('storico_attivita_socio', ['socio_id' => $socio->id, 'tipo_attivita' => 'Pagamento Online']);
    }

    public function test_valuta_diversa_dal_tenant_non_accredita(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, ['importo' => '42.50']);
        $this->configuraStripe(['valuta' => 'EUR']);
        $this->mockFirmaValida();

        $this->inviaStripe($this->stripePayloadCon($quota, [
            'payment_status' => 'paid',
            'amount_total' => 4250,
            'currency' => 'usd',
        ]))->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertNull($quota->fresh()->data_pagamento);
    }

    public function test_evento_con_lo_stesso_id_viene_scartato_anche_se_il_contenuto_cambia(): void
    {
        $socio = $this->creaSocio();
        $quotaA = $this->creaQuota($socio);
        $quotaB = $this->creaQuota($socio, ['anno' => (int) now()->year + 1]);
        $this->configuraStripe();
        $this->mockFirmaValida();

        $this->inviaStripe($this->stripePayloadCon($quotaA, [], ['id' => 'evt_unico']))->assertOk();
        $this->assertTrue($quotaA->fresh()->isPagata());

        // Stesso id evento, metadata di un'altra quota: dedup per id, nessun effetto
        $this->inviaStripe($this->stripePayloadCon($quotaB, ['payment_intent' => 'pi_altro'], ['id' => 'evt_unico']))
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertNull($quotaB->fresh()->data_pagamento);
    }

    public function test_evento_fallito_non_resta_in_cache_e_il_retry_viene_elaborato(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);
        $this->configuraStripe();
        $this->mockFirmaValida();

        // Primo tentativo non elaborabile (tipo donazione senza pagabile_id) → 400
        $rotto = $this->stripePayloadCon($quota, [], ['id' => 'evt_retry']);
        $rotto['data']['object']['metadata'] = [
            'tipo' => 'donazione',
            'associazione_id' => $quota->associazione_id,
        ];
        $this->inviaStripe($rotto)->assertStatus(400);

        // Retry con lo stesso id evento: la chiave di dedup è stata liberata
        $this->inviaStripe($this->stripePayloadCon($quota, [], ['id' => 'evt_retry']))->assertOk();

        $this->assertTrue($quota->fresh()->isPagata());
    }

    public function test_livemode_incoerente_con_la_chiave_di_test_viene_ignorato(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);
        // La factory usa una chiave sk_test_…: attesi eventi con livemode=false
        $this->configuraStripe();
        $this->mockFirmaValida();

        $this->inviaStripe($this->stripePayloadCon($quota, [], ['livemode' => true]))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);
        $this->assertNull($quota->fresh()->data_pagamento);

        $this->inviaStripe($this->stripePayloadCon($quota, [], ['livemode' => false]))->assertOk()->assertJson(['status' => 'ok']);
        $this->assertTrue($quota->fresh()->isPagata());
    }

    public function test_livemode_non_viene_controllato_se_la_chiave_non_ha_un_prefisso_riconoscibile(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);
        $this->configuraStripe(['stripe_secret_key_encrypted' => 'chiave-senza-prefisso']);
        $this->mockFirmaValida();

        $this->inviaStripe($this->stripePayloadCon($quota, [], ['livemode' => true]))->assertOk()->assertJson(['status' => 'ok']);

        $this->assertTrue($quota->fresh()->isPagata());
    }

    public function test_pagamento_online_su_quota_gia_pagata_con_altra_transazione_logga_il_duplicato_da_rimborsare(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, [
            'data_pagamento' => now()->subDay()->toDateString(),
            'metodo_pagamento' => 'bonifico',
        ]);
        $this->configuraStripe();
        $this->mockFirmaValida();
        Log::spy();

        // Ack al gateway, ma la quota resta com'era e il doppio incasso è tracciato
        $this->inviaStripe($this->stripePayload($quota))->assertOk();

        $quota->refresh();
        $this->assertSame('bonifico', $quota->metodo_pagamento);
        $this->assertNull($quota->gateway_transaction_id);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $messaggio, array $contesto = []) => str_contains($messaggio, 'quota già pagata')
                && ($contesto['transaction_id_duplicato'] ?? null) === 'pi_test_456'
                && ($contesto['quota_id'] ?? null) === $quota->id)
            ->once();
    }

    public function test_il_replay_della_stessa_transazione_non_logga_un_duplicato(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);
        $this->configuraStripe();
        $this->mockFirmaValida();

        $this->inviaStripe($this->stripePayload($quota))->assertOk();

        Log::spy();
        $this->inviaStripe($this->stripePayload($quota))->assertOk();

        Log::shouldNotHaveReceived('warning', fn (string $messaggio) => str_contains($messaggio, 'quota già pagata'));
    }

    public function test_il_webhook_e_limitato_a_60_richieste_al_minuto_per_ip(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->post(route('webhooks.stripe'), [])->assertStatus(400);
        }

        $this->post(route('webhooks.stripe'), [])->assertStatus(429);
    }
}
