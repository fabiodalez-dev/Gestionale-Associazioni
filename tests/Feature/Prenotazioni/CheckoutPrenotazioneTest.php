<?php

namespace Tests\Feature\Prenotazioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\PaymentGatewaySetting;
use App\Models\Prenotazione;
use App\Services\Pagamenti\Gateways\PayPalGatewayInterface;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use App\Services\Pagamenti\Pagabile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rotte di pagamento prenotazione del frontend pubblico:
 * GET .../paga (scelta gateway + countdown), POST .../checkout/{gateway}.
 * Policy: solo il proprietario dell'account.
 */
class CheckoutPrenotazioneTest extends TestCase
{
    use RefreshDatabase;

    protected Associazione $associazione;

    protected Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        $this->account = Account::factory()->create(['associazione_id' => $this->associazione->id]);
    }

    protected function tearDown(): void
    {
        // Evita che il binding del tenant pubblico sporchi gli altri test
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    protected function creaPrenotazioneInAttesa(array $attributes = []): Prenotazione
    {
        $evento = Evento::factory()->pubblicato()->aPagamento(15.0)->create([
            'associazione_id' => $this->associazione->id,
        ]);

        return Prenotazione::factory()->inAttesaPagamento(15.0)->create([
            'evento_id' => $evento->id,
            'associazione_id' => $this->associazione->id,
            'account_id' => $this->account->id,
            'posti' => 2,
            'prezzo_unitario' => 15.0,
            'importo_totale' => 30.0,
            ...$attributes,
        ]);
    }

    protected function urlPaga(Prenotazione $prenotazione): string
    {
        return route('pubblico.prenotazioni.paga', [
            'associazione' => $this->associazione->slug,
            'prenotazione' => $prenotazione->id,
        ]);
    }

    protected function urlCheckout(Prenotazione $prenotazione, string $gateway): string
    {
        return route('pubblico.prenotazioni.checkout', [
            'associazione' => $this->associazione->slug,
            'prenotazione' => $prenotazione->id,
            'gateway' => $gateway,
        ]);
    }

    public function test_guest_viene_rediretto_alla_login_del_tenant(): void
    {
        $prenotazione = $this->creaPrenotazioneInAttesa();

        $this->get($this->urlPaga($prenotazione))
            ->assertRedirect(route('pubblico.login', ['associazione' => $this->associazione->slug]));
    }

    public function test_account_non_proprietario_riceve_403(): void
    {
        $prenotazione = $this->creaPrenotazioneInAttesa();
        $altro = Account::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($altro, 'account')
            ->get($this->urlPaga($prenotazione))
            ->assertForbidden();
    }

    public function test_prenotazione_di_altro_tenant_e_404_uniforme(): void
    {
        $altroTenant = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        $eventoAltro = Evento::factory()->pubblicato()->aPagamento()->create(['associazione_id' => $altroTenant->id]);
        $prenotazioneAltro = Prenotazione::factory()->inAttesaPagamento()->create([
            'evento_id' => $eventoAltro->id,
            'associazione_id' => $altroTenant->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get(route('pubblico.prenotazioni.paga', [
                'associazione' => $this->associazione->slug,
                'prenotazione' => $prenotazioneAltro->id,
            ]))
            ->assertNotFound();
    }

    public function test_pagina_paga_mostra_importo_countdown_e_gateway(): void
    {
        $prenotazione = $this->creaPrenotazioneInAttesa();
        PaymentGatewaySetting::factory()->conStripe()->conPayPal()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $response = $this->actingAs($this->account, 'account')->get($this->urlPaga($prenotazione));

        $response->assertOk();
        $response->assertSee($prenotazione->codice);
        $response->assertSee('30,00');
        $response->assertSee('id="countdown"', false);
        $response->assertSee('Paga con carta');
        $response->assertSee('Paga con PayPal');
    }

    public function test_pagina_paga_senza_gateway_configurati_mostra_avviso(): void
    {
        $prenotazione = $this->creaPrenotazioneInAttesa();

        $response = $this->actingAs($this->account, 'account')->get($this->urlPaga($prenotazione));

        $response->assertOk();
        $response->assertSee('Il pagamento online non è al momento disponibile.');
        $response->assertDontSee('Paga con carta');
    }

    public function test_pagina_paga_su_prenotazione_confermata_redirige_all_esito(): void
    {
        $prenotazione = $this->creaPrenotazioneInAttesa();
        $prenotazione->forceFill(['stato' => Prenotazione::STATO_CONFERMATA])->save();

        $this->actingAs($this->account, 'account')
            ->get($this->urlPaga($prenotazione))
            ->assertRedirect(route('pubblico.prenotazioni.esito', [
                'associazione' => $this->associazione->slug,
                'prenotazione' => $prenotazione->id,
            ]));
    }

    public function test_checkout_stripe_crea_la_sessione_sul_pagabile_giusto_e_redirige(): void
    {
        $prenotazione = $this->creaPrenotazioneInAttesa();
        PaymentGatewaySetting::factory()->conStripe()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->mock(StripeGatewayInterface::class, function ($mock) use ($prenotazione) {
            $mock->shouldReceive('createCheckoutSession')
                ->once()
                ->withArgs(function ($config, $pagabile, $successUrl, $cancelUrl) use ($prenotazione) {
                    return $pagabile instanceof Prenotazione
                        && $pagabile->id === $prenotazione->id
                        && $pagabile->getPagabileTipo() === Pagabile::TIPO_PRENOTAZIONE
                        && str_contains($successUrl, 'esito=success')
                        && str_contains($cancelUrl, 'esito=cancel');
                })
                ->andReturn('https://checkout.stripe.test/session');
        });

        $this->actingAs($this->account, 'account')
            ->post($this->urlCheckout($prenotazione, 'stripe'))
            ->assertRedirect('https://checkout.stripe.test/session');
    }

    public function test_checkout_paypal_crea_l_ordine_e_redirige(): void
    {
        $prenotazione = $this->creaPrenotazioneInAttesa();
        PaymentGatewaySetting::factory()->conPayPal()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->mock(PayPalGatewayInterface::class, function ($mock) use ($prenotazione) {
            $mock->shouldReceive('createOrder')
                ->once()
                ->withArgs(fn ($config, $pagabile) => $pagabile instanceof Prenotazione
                    && $pagabile->id === $prenotazione->id)
                ->andReturn('https://www.sandbox.paypal.com/checkoutnow?token=XYZ');
        });

        $this->actingAs($this->account, 'account')
            ->post($this->urlCheckout($prenotazione, 'paypal'))
            ->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=XYZ');
    }

    public function test_checkout_con_gateway_non_valido_e_404(): void
    {
        $prenotazione = $this->creaPrenotazioneInAttesa();

        $this->actingAs($this->account, 'account')
            ->post(route('pubblico.prenotazioni.checkout', [
                'associazione' => $this->associazione->slug,
                'prenotazione' => $prenotazione->id,
                'gateway' => 'bonifico',
            ]))
            ->assertNotFound();
    }

    public function test_checkout_su_prenotazione_non_in_attesa_non_crea_sessioni(): void
    {
        $prenotazione = $this->creaPrenotazioneInAttesa();
        $prenotazione->forceFill(['stato' => Prenotazione::STATO_CONFERMATA])->save();
        PaymentGatewaySetting::factory()->conStripe()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')->never();
        });

        $this->actingAs($this->account, 'account')
            ->post($this->urlCheckout($prenotazione, 'stripe'))
            ->assertRedirect($this->urlPaga($prenotazione));
    }

    public function test_checkout_oltre_la_scadenza_non_crea_sessioni(): void
    {
        $prenotazione = $this->creaPrenotazioneInAttesa([
            'scadenza_pagamento_at' => now()->subMinute(),
        ]);
        PaymentGatewaySetting::factory()->conStripe()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')->never();
        });

        $this->actingAs($this->account, 'account')
            ->post($this->urlCheckout($prenotazione, 'stripe'))
            ->assertRedirect($this->urlPaga($prenotazione));
    }

    public function test_checkout_fallito_torna_alla_pagina_con_errore(): void
    {
        $prenotazione = $this->creaPrenotazioneInAttesa();
        PaymentGatewaySetting::factory()->conStripe()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')->once()->andReturnNull();
        });

        $this->actingAs($this->account, 'account')
            ->post($this->urlCheckout($prenotazione, 'stripe'))
            ->assertRedirect($this->urlPaga($prenotazione))
            ->assertSessionHas('checkout_error');
    }

    public function test_esito_mostra_il_banner_di_ritorno_dal_checkout(): void
    {
        $prenotazione = $this->creaPrenotazioneInAttesa();

        $urlEsito = route('pubblico.prenotazioni.esito', [
            'associazione' => $this->associazione->slug,
            'prenotazione' => $prenotazione->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($urlEsito.'?esito=success')
            ->assertOk()
            ->assertSee('stiamo confermando la prenotazione');

        $this->actingAs($this->account, 'account')
            ->get($urlEsito.'?esito=cancel')
            ->assertOk()
            ->assertSee('Pagamento annullato');
    }
}
