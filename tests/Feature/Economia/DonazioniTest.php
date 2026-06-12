<?php

namespace Tests\Feature\Economia;

use App\Models\Associazione;
use App\Models\Donazione;
use App\Models\Movimento;
use App\Notifications\Economia\RicevutaDonazioneNotification;
use App\Services\Economia\PrimaNotaService;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    public function test_esito_mostra_grazie_per_donazione_pagata(): void
    {
        $donazione = Donazione::factory()->pagata()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->get("/a/dona-test/dona/{$donazione->id}/esito")
            ->assertOk()
            ->assertSee('Grazie di cuore');
    }

    public function test_esito_di_donazione_di_altro_tenant_404(): void
    {
        $altra = Associazione::factory()->create([
            'slug' => 'altra-assoc',
            'frontend_pubblico_attivo' => true,
        ]);
        $donazioneAltrui = Donazione::factory()->create(['associazione_id' => $altra->id]);

        $this->get("/a/dona-test/dona/{$donazioneAltrui->id}/esito")->assertNotFound();
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
