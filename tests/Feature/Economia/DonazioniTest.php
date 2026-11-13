<?php

namespace Tests\Feature\Economia;

use App\Models\Associazione;
use App\Models\Donazione;
use App\Models\Movimento;
use App\Notifications\Economia\RicevutaDonazioneNotification;
use App\Services\Economia\PrimaNotaService;
use App\Services\Pagamenti\Gateways\PayPalGatewayInterface;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Honeypot\ProtectAgainstSpam;
use Tests\TestCase;

/**
 * f.15 — Donazioni online: pagina pubblica /a/{slug}/dona, checkout via
 * Pagabile, webhook con dispatch tipo 'donazione', ricevuta email,
 * movimento automatico in prima nota, elenco admin.
 */
class DonazioniTest extends TestCase
{
    use ConfiguraTenantEconomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();

        $this->associazione->forceFill([
            'slug' => 'dona-test',
            'frontend_pubblico_attivo' => true,
        ])->save();

        $this->withoutMiddleware(ProtectAgainstSpam::class);
        Notification::fake();
    }

    /**
     * @return array<string, mixed>
     */
    protected function payloadDonazione(array $extra = []): array
    {
        return array_merge([
            'importo' => '25.00',
            'donatore_nome' => 'Mario Rossi',
            'donatore_email' => 'mario.rossi@example.com',
            'gateway' => 'stripe',
        ], $extra);
    }

    public function test_pagina_dona_visibile_con_gateway_configurato(): void
    {
        $this->configuraStripe();

        $this->get('/a/dona-test/dona')
            ->assertOk()
            ->assertSee('Sostieni')
            ->assertSee('name="importo"', false)
            ->assertSee('name="donatore_email"', false);
    }

    public function test_pagina_dona_404_su_slug_inesistente(): void
    {
        $this->get('/a/non-esiste/dona')->assertNotFound();
    }

    public function test_pagina_dona_avvisa_se_nessun_gateway_configurato(): void
    {
        $this->get('/a/dona-test/dona')
            ->assertOk()
            ->assertSee('non sono al momento disponibili');
    }

    public function test_store_crea_donazione_e_redirige_al_checkout(): void
    {
        $this->configuraStripe();

        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')
                ->once()
                ->andReturn('https://checkout.stripe.com/test-session');
        });

        $response = $this->post('/a/dona-test/dona', $this->payloadDonazione());

        $response->assertRedirect('https://checkout.stripe.com/test-session');

        $this->assertDatabaseHas('donazioni', [
            'associazione_id' => $this->associazione->id,
            'donatore_email' => 'mario.rossi@example.com',
            'stato' => Donazione::STATO_IN_ATTESA,
            'anonimo' => false,
        ]);
    }

    public function test_store_rifiuta_importo_sotto_il_minimo(): void
    {
        $this->configuraStripe();

        $response = $this->post('/a/dona-test/dona', $this->payloadDonazione(['importo' => '0.50']));

        $response->assertSessionHasErrors('importo');
        $this->assertSame(0, Donazione::allTenants()->count());
    }

    public function test_store_richiede_email(): void
    {
        $this->configuraStripe();

        $response = $this->post('/a/dona-test/dona', $this->payloadDonazione(['donatore_email' => '']));

        $response->assertSessionHasErrors('donatore_email');
    }

    public function test_store_anonima_senza_nome_e_consentita(): void
    {
        $this->configuraStripe();

        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')->andReturn('https://checkout.stripe.com/x');
        });

        $this->post('/a/dona-test/dona', $this->payloadDonazione([
            'donatore_nome' => '',
            'anonimo' => '1',
        ]))->assertRedirect('https://checkout.stripe.com/x');

        $this->assertDatabaseHas('donazioni', [
            'associazione_id' => $this->associazione->id,
            'anonimo' => true,
        ]);
    }

    public function test_checkout_fallito_elimina_la_donazione(): void
    {
        $this->configuraStripe();

        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')->andReturnNull();
        });

        $response = $this->post('/a/dona-test/dona', $this->payloadDonazione());

        $response->assertRedirect(route('pubblico.dona.show', 'dona-test'));
        $this->assertSame(0, Donazione::allTenants()->count());
    }

    public function test_webhook_stripe_tipo_donazione_marca_pagata_con_movimento_e_ricevuta(): void
    {
        $this->configuraStripe();

        $donazione = Donazione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'importo' => 40,
            'donatore_email' => 'donatore@example.com',
        ]);

        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('constructWebhookEvent')
                ->andReturnUsing(fn (string $payload) => json_decode($payload, true));
        });

        $payload = [
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_dona',
                    'payment_intent' => 'pi_dona_1',
                    'metadata' => [
                        'tipo' => 'donazione',
                        'pagabile_id' => $donazione->id,
                        'associazione_id' => $donazione->associazione_id,
                    ],
                ],
            ],
        ];

        $this->postJson(route('webhooks.stripe'), $payload, ['Stripe-Signature' => 't=1,v1=abc'])
            ->assertOk();

        $donazione->refresh();

        $this->assertSame(Donazione::STATO_PAGATA, $donazione->stato);
        $this->assertSame('stripe', $donazione->gateway);
        $this->assertSame('pi_dona_1', $donazione->transaction_id);
        $this->assertNotNull($donazione->pagata_at);

        // Movimento automatico in prima nota
        $movimento = Movimento::allTenants()
            ->where('origine_type', $donazione->getMorphClass())
            ->where('origine_id', $donazione->id)
            ->first();

        $this->assertNotNull($movimento);
        $this->assertSame(PrimaNotaService::CATEGORIA_DONAZIONI, $movimento->categoria?->nome);
        $this->assertEqualsWithDelta(40.0, (float) $movimento->importo, 0.001);

        // Ricevuta email on-demand al donatore
        Notification::assertSentOnDemand(
            RicevutaDonazioneNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'donatore@example.com'
        );
    }

    public function test_replay_del_webhook_donazione_idempotente(): void
    {
        $this->configuraStripe();

        $donazione = Donazione::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('constructWebhookEvent')
                ->andReturnUsing(fn (string $payload) => json_decode($payload, true));
        });

        $payload = [
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_dona',
                    'payment_intent' => 'pi_dona_replay',
                    'metadata' => [
                        'tipo' => 'donazione',
                        'pagabile_id' => $donazione->id,
                        'associazione_id' => $donazione->associazione_id,
                    ],
                ],
            ],
        ];

        $this->postJson(route('webhooks.stripe'), $payload, ['Stripe-Signature' => 't=1,v1=abc'])->assertOk();
        $this->postJson(route('webhooks.stripe'), $payload, ['Stripe-Signature' => 't=1,v1=abc'])->assertOk();

        $this->assertSame(1, Movimento::allTenants()
            ->where('origine_type', $donazione->getMorphClass())
            ->where('origine_id', $donazione->id)
            ->count());

        Notification::assertSentOnDemandTimes(RicevutaDonazioneNotification::class, 1);
    }

    // ------------------------------------------------------------------
    // Hardening (audit run-1): webhook PayPal con custom_id "donazione:{uuid}"
    // (prima rifiutato con 400) e confronto importo/valuta sul capture
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function eventoPayPalDonazione(string $customId, string $orderId = 'ORDER-DONA-1'): array
    {
        return [
            'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => [
                'id' => $orderId,
                'purchase_units' => [['custom_id' => $customId]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function capturePayPalDonazione(string $customId, string $valore, string $valuta = 'EUR'): array
    {
        return [
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'payments' => ['captures' => [[
                    'id' => 'CAPTURE-DONA-1',
                    'custom_id' => $customId,
                    'amount' => ['value' => $valore, 'currency_code' => $valuta],
                ]]],
            ]],
        ];
    }

    public function test_webhook_paypal_tipo_donazione_viene_catturato_e_marca_pagata_con_movimento_e_ricevuta(): void
    {
        $this->configuraPayPal();

        $donazione = Donazione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'importo' => 30,
            'donatore_email' => 'donatore.paypal@example.com',
        ]);
        $customId = 'donazione:'.$donazione->id;

        $this->mock(PayPalGatewayInterface::class, function ($mock) use ($customId) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturnTrue();
            $mock->shouldReceive('captureOrder')
                ->once()
                ->withArgs(fn ($config, $orderId) => $orderId === 'ORDER-DONA-1')
                ->andReturn($this->capturePayPalDonazione($customId, '30.00'));
        });

        $this->postJson(route('webhooks.paypal'), $this->eventoPayPalDonazione($customId))
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $donazione->refresh();
        $this->assertSame(Donazione::STATO_PAGATA, $donazione->stato);
        $this->assertSame('paypal', $donazione->gateway);
        $this->assertSame('CAPTURE-DONA-1', $donazione->transaction_id);
        $this->assertNotNull($donazione->pagata_at);

        $this->assertSame(1, Movimento::allTenants()
            ->where('origine_type', $donazione->getMorphClass())
            ->where('origine_id', $donazione->id)
            ->count());

        Notification::assertSentOnDemand(
            RicevutaDonazioneNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'donatore.paypal@example.com'
        );
    }

    public function test_webhook_paypal_donazione_inesistente_restituisce_404(): void
    {
        $this->configuraPayPal();

        $this->postJson(route('webhooks.paypal'), $this->eventoPayPalDonazione('donazione:00000000-0000-0000-0000-000000000000'))
            ->assertNotFound()
            ->assertJson(['error' => 'Donazione not found']);
    }

    public function test_webhook_paypal_donazione_con_importo_diverso_viene_ignorato_senza_accredito(): void
    {
        $this->configuraPayPal();

        $donazione = Donazione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'importo' => 30,
        ]);
        $customId = 'donazione:'.$donazione->id;

        $this->mock(PayPalGatewayInterface::class, function ($mock) use ($customId) {
            $mock->shouldReceive('verifyWebhookSignature')->once()->andReturnTrue();
            $mock->shouldReceive('captureOrder')->once()->andReturn($this->capturePayPalDonazione($customId, '5.00'));
        });

        $this->postJson(route('webhooks.paypal'), $this->eventoPayPalDonazione($customId))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $donazione->refresh();
        $this->assertSame(Donazione::STATO_IN_ATTESA, $donazione->stato);
        $this->assertNull($donazione->transaction_id);
        $this->assertSame(0, Movimento::allTenants()->where('origine_id', $donazione->id)->count());
    }

    public function test_webhook_stripe_donazione_con_importo_diverso_viene_ignorato_senza_accredito(): void
    {
        $this->configuraStripe();

        $donazione = Donazione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'importo' => 40,
        ]);

        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('constructWebhookEvent')
                ->andReturnUsing(fn (string $payload) => json_decode($payload, true));
        });

        $payload = [
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_dona_importo',
                    'payment_intent' => 'pi_dona_importo',
                    'payment_status' => 'paid',
                    'amount_total' => 500,
                    'currency' => 'eur',
                    'metadata' => [
                        'tipo' => 'donazione',
                        'pagabile_id' => $donazione->id,
                        'associazione_id' => $donazione->associazione_id,
                    ],
                ],
            ],
        ];

        $this->postJson(route('webhooks.stripe'), $payload, ['Stripe-Signature' => 't=1,v1=abc'])
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->assertSame(Donazione::STATO_IN_ATTESA, $donazione->fresh()->stato);
    }

    /** URL di esito firmato, come quello consegnato al gateway per il ritorno. */
    private function urlEsitoFirmato(Donazione $donazione, string $esito = 'success', string $slug = 'dona-test'): string
    {
        return URL::signedRoute('pubblico.dona.esito', [
            'associazione' => $slug,
            'donazione' => $donazione->id,
            'esito' => $esito,
        ]);
    }

    public function test_esito_mostra_grazie_per_donazione_pagata(): void
    {
        $donazione = Donazione::factory()->pagata()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->get($this->urlEsitoFirmato($donazione))
            ->assertOk()
            ->assertSee('Grazie di cuore');
    }

    /**
     * Hardening (audit run-1): la pagina di esito mostra importo ed email del
     * donatore; prima bastava conoscere l'UUID della donazione. Ora serve
     * l'URL firmato consegnato al gateway: senza firma (o con l'esito
     * alterato) 403, senza alcun dato della donazione.
     */
    public function test_esito_senza_url_firmato_e_negato_senza_rivelare_i_dati(): void
    {
        $donazione = Donazione::factory()->pagata()->create([
            'associazione_id' => $this->associazione->id,
            'donatore_email' => 'riservata@example.com',
        ]);

        $this->get("/a/dona-test/dona/{$donazione->id}/esito")
            ->assertForbidden()
            ->assertDontSee('riservata@example.com')
            ->assertDontSee('Grazie di cuore');

        $this->get("/a/dona-test/dona/{$donazione->id}/esito?esito=success&signature=manomessa")
            ->assertForbidden();

        // Firma valida per 'cancel' riusata con esito=success → la firma non torna
        $url = str_replace('esito=cancel', 'esito=success', $this->urlEsitoFirmato($donazione, 'cancel'));
        $this->get($url)->assertForbidden();

        // Firma valida per un'altra donazione → 403 anche se l'UUID esiste
        $altra = Donazione::factory()->pagata()->create(['associazione_id' => $this->associazione->id]);
        $this->get(str_replace($altra->id, $donazione->id, $this->urlEsitoFirmato($altra)))->assertForbidden();
    }

    public function test_esito_firmato_accetta_i_parametri_aggiunti_dal_gateway(): void
    {
        $donazione = Donazione::factory()->create(['associazione_id' => $this->associazione->id]);

        // PayPal aggiunge token e PayerID all'URL di ritorno
        $this->get($this->urlEsitoFirmato($donazione).'&token=5AB12345&PayerID=XYZ')
            ->assertOk()
            ->assertSee('Pagamento in elaborazione');

        $this->get($this->urlEsitoFirmato($donazione, 'cancel').'&token=5AB12345')
            ->assertOk()
            ->assertSee('Donazione annullata');
    }

    public function test_store_consegna_al_gateway_url_di_ritorno_firmati(): void
    {
        $this->configuraStripe();

        $urls = [];
        $this->mock(StripeGatewayInterface::class, function ($mock) use (&$urls) {
            $mock->shouldReceive('createCheckoutSession')
                ->once()
                ->withArgs(function ($config, $pagabile, string $successUrl, string $cancelUrl) use (&$urls): bool {
                    $urls = [$successUrl, $cancelUrl];

                    return true;
                })
                ->andReturn('https://checkout.stripe.com/test-session');
        });

        $this->post('/a/dona-test/dona', $this->payloadDonazione())
            ->assertRedirect('https://checkout.stripe.com/test-session');

        $donazione = Donazione::query()->firstOrFail();
        $this->assertSame($this->urlEsitoFirmato($donazione, 'success'), $urls[0]);
        $this->assertSame($this->urlEsitoFirmato($donazione, 'cancel'), $urls[1]);
        $this->assertStringContainsString('signature=', $urls[0]);

        // Il ritorno dal gateway apre la pagina di esito
        $this->get($urls[0])->assertOk()->assertSee('Pagamento in elaborazione');
        $this->get($urls[1])->assertOk()->assertSee('Donazione annullata');
    }

    public function test_esito_di_donazione_di_altro_tenant_404(): void
    {
        $altra = Associazione::factory()->create([
            'slug' => 'altra-assoc',
            'frontend_pubblico_attivo' => true,
        ]);
        $donazioneAltrui = Donazione::factory()->create(['associazione_id' => $altra->id]);

        // Anche con URL firmato (per lo slug dona-test): il tenant non corrisponde
        $this->get($this->urlEsitoFirmato($donazioneAltrui))->assertNotFound();
    }

    public function test_elenco_admin_richiede_permesso(): void
    {
        $this->actingAs($this->creaUtenteSenzaPermessi())
            ->get(route('donazioni.index'))
            ->assertForbidden();
    }

    public function test_elenco_admin_mostra_solo_le_donazioni_del_tenant(): void
    {
        Donazione::factory()->pagata()->count(2)->create(['associazione_id' => $this->associazione->id]);

        $altra = Associazione::factory()->create();
        Donazione::factory()->pagata()->create(['associazione_id' => $altra->id]);

        $response = $this->actingAs($this->admin)->get(route('donazioni.index'));

        $response->assertOk();
        $response->assertViewHas('donazioni', fn ($donazioni) => $donazioni->total() === 2);
        $response->assertViewHas('numeroPagate', 2);
    }
}
