<?php

namespace Tests\Feature\quote_pagamenti;

use App\Models\PaymentGatewaySetting;
use App\Models\Quota;
use App\Models\Socio;
use App\Services\Pagamenti\Gateways\PayPalGatewayInterface;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
