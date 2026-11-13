<?php

namespace Tests\Feature\quote_pagamenti;

use App\Models\PaymentGatewaySetting;
use App\Models\Quota;
use App\Models\Socio;
use App\Services\Pagamenti\Gateways\PayPalGatewayInterface;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use App\Services\Pagamenti\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PaginaPagamentoTest extends TestCase
{
    use ConfiguraTenantQuote;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
    }

    protected function quotaConToken(array $quotaAttributes = []): Quota
    {
        $socio = $this->creaSocio(['nome' => 'Anna', 'cognome' => 'Bianchi']);

        return $this->creaQuota($socio, [
            'payment_token' => str_repeat('ab12', 16),
            'payment_token_expires' => now()->addHours(72),
            'importo' => '50.00',
            ...$quotaAttributes,
        ]);
    }

    public function test_pagina_con_token_valido_mostra_dettagli_e_gateway(): void
    {
        $this->configuraStripe();
        $quota = $this->quotaConToken();

        $response = $this->get(route('pagamento.show', ['token' => $quota->payment_token]));

        $response->assertOk();
        $response->assertSee('Anna Bianchi');
        $response->assertSee($quota->tipo);
        $response->assertSee('EUR 50,00');
        $response->assertSee('Paga con Carta (Stripe)');
        $response->assertDontSee('Paga con PayPal');
    }

    public function test_token_sconosciuto_restituisce_404(): void
    {
        $this->get(route('pagamento.show', ['token' => str_repeat('ff', 32)]))->assertNotFound();
    }

    public function test_token_formato_invalido_restituisce_404(): void
    {
        $this->get('/pagamento/token-non-valido')->assertNotFound();
    }

    public function test_token_scaduto_mostra_link_scaduto(): void
    {
        $this->configuraStripe();
        $quota = $this->quotaConToken(['payment_token_expires' => now()->subHour()]);

        $response = $this->get(route('pagamento.show', ['token' => $quota->payment_token]));

        $response->assertOk();
        $response->assertSee('Link scaduto');
        $response->assertDontSee('Paga con Carta');
    }

    public function test_quota_gia_pagata_mostra_pagamento_gia_effettuato(): void
    {
        $quota = $this->quotaConToken(['data_pagamento' => now()->toDateString()]);

        $response = $this->get(route('pagamento.show', ['token' => $quota->payment_token]));

        $response->assertOk();
        $response->assertSee('Pagamento già effettuato');
    }

    public function test_result_success_mostra_schermata_di_conferma(): void
    {
        $quota = $this->quotaConToken();

        $response = $this->get(route('pagamento.show', [
            'token' => $quota->payment_token,
            'result' => 'success',
        ]));

        $response->assertOk();
        $response->assertSee('Pagamento completato con successo!');
        $response->assertSee('Riceverai una conferma via email');
    }

    public function test_senza_gateway_configurato_mostra_avviso(): void
    {
        PaymentGatewaySetting::factory()->create(['associazione_id' => $this->associazione->id]);
        $quota = $this->quotaConToken();

        $response = $this->get(route('pagamento.show', ['token' => $quota->payment_token]));

        $response->assertOk();
        $response->assertSee('Nessun metodo di pagamento online configurato');
    }

    public function test_checkout_stripe_redirige_alla_sessione(): void
    {
        $this->configuraStripe();
        $quota = $this->quotaConToken();

        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')
                ->once()
                ->andReturn('https://checkout.stripe.com/c/pay/cs_test_123');
        });

        $response = $this->post(route('pagamento.checkout', ['token' => $quota->payment_token]), [
            'gateway' => 'stripe',
        ]);

        $response->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');
    }

    public function test_checkout_paypal_redirige_all_approvazione(): void
    {
        $this->configuraPayPal();
        $quota = $this->quotaConToken();

        $this->mock(PayPalGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('createOrder')
                ->once()
                ->andReturn('https://www.sandbox.paypal.com/checkoutnow?token=ORD123');
        });

        $response = $this->post(route('pagamento.checkout', ['token' => $quota->payment_token]), [
            'gateway' => 'paypal',
        ]);

        $response->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORD123');
    }

    public function test_checkout_con_errore_gateway_mostra_messaggio(): void
    {
        $this->configuraStripe();
        $quota = $this->quotaConToken();

        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')->once()->andReturnNull();
        });

        $response = $this->post(route('pagamento.checkout', ['token' => $quota->payment_token]), [
            'gateway' => 'stripe',
        ]);

        $response->assertRedirect(route('pagamento.show', ['token' => $quota->payment_token]));
        $response->assertSessionHas('checkout_error');
    }

    public function test_checkout_bloccato_se_quota_gia_pagata(): void
    {
        $this->configuraStripe();
        $quota = $this->quotaConToken(['data_pagamento' => now()->toDateString()]);

        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')->never();
        });

        $response = $this->post(route('pagamento.checkout', ['token' => $quota->payment_token]), [
            'gateway' => 'stripe',
        ]);

        $response->assertRedirect(route('pagamento.show', ['token' => $quota->payment_token]));
    }

    public function test_il_tenant_deriva_dalla_quota_senza_sessione(): void
    {
        // La pagina pubblica funziona senza alcun contesto tenant in sessione
        $this->configuraStripe();
        $quota = $this->quotaConToken();

        $this->assertNull(session('associazione_id'));

        $this->get(route('pagamento.show', ['token' => $quota->payment_token]))->assertOk();
    }

    public function test_ricevuta_pubblica_disponibile_solo_se_pagata(): void
    {
        $quotaNonPagata = $this->quotaConToken();

        $this->get(route('pagamento.ricevuta', ['token' => $quotaNonPagata->payment_token]))
            ->assertRedirect(route('pagamento.show', ['token' => $quotaNonPagata->payment_token]));

        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $quotaPagata = $this->creaQuota($socio, [
            'payment_token' => str_repeat('cd34', 16),
            'payment_token_expires' => now()->addDay(),
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => 'stripe',
        ]);

        $response = $this->get(route('pagamento.ricevuta', ['token' => $quotaPagata->payment_token]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    // ------------------------------------------------------------------
    // Hardening (audit run-1): il token scaduto non serve più la ricevuta
    // né rivela lo stato della quota; dopo il pagamento online il token
    // resta valido solo per una breve finestra di grazia
    // ------------------------------------------------------------------

    public function test_ricevuta_pubblica_non_servita_con_token_scaduto_anche_se_pagata(): void
    {
        $quota = $this->quotaConToken([
            'payment_token_expires' => now()->subHour(),
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => 'bonifico',
        ]);

        $this->get(route('pagamento.ricevuta', ['token' => $quota->payment_token]))
            ->assertRedirect(route('pagamento.show', ['token' => $quota->payment_token]));

        // La pagina mostra solo "link scaduto": niente ricevuta né stato della quota
        $this->get(route('pagamento.show', ['token' => $quota->payment_token]))
            ->assertOk()
            ->assertSee('Link scaduto')
            ->assertDontSee('Scarica ricevuta')
            ->assertDontSee('Pagamento già effettuato');
    }

    public function test_dopo_il_pagamento_online_il_token_resta_valido_solo_per_la_finestra_di_grazia(): void
    {
        Notification::fake();
        $quota = $this->quotaConToken(['payment_token_expires' => now()->addHours(72)]);

        app(PaymentService::class)->onPaymentCompleted($quota->id, $this->associazione->id, 'stripe', 'pi_grazia');

        $quota->refresh();
        $this->assertTrue($quota->isPagata());
        $this->assertNotNull($quota->payment_token);
        $this->assertTrue($quota->payment_token_expires->isFuture());
        $this->assertTrue($quota->payment_token_expires->lte(now()->addHours(PaymentService::TOKEN_GRACE_AFTER_PAYMENT_HOURS)));

        // Subito dopo (ritorno dal gateway): ricevuta scaricabile dal link
        $this->get(route('pagamento.ricevuta', ['token' => $quota->payment_token]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Passata la finestra di grazia: la ricevuta si scarica solo dall'area riservata
        $this->travel(PaymentService::TOKEN_GRACE_AFTER_PAYMENT_HOURS + 1)->hours();

        $this->get(route('pagamento.ricevuta', ['token' => $quota->payment_token]))
            ->assertRedirect(route('pagamento.show', ['token' => $quota->payment_token]));
        $this->get(route('pagamento.show', ['token' => $quota->payment_token]))
            ->assertOk()
            ->assertSee('Link scaduto')
            ->assertDontSee('Scarica ricevuta');
    }

    public function test_il_pagamento_online_non_allunga_un_token_che_scade_prima_della_finestra_di_grazia(): void
    {
        Notification::fake();
        $scadenza = now()->addMinutes(10);
        $quota = $this->quotaConToken(['payment_token_expires' => $scadenza]);

        app(PaymentService::class)->onPaymentCompleted($quota->id, $this->associazione->id, 'paypal', 'CAP-grazia');

        $this->assertSame($scadenza->toDateTimeString(), $quota->fresh()->payment_token_expires->toDateTimeString());
    }
}
