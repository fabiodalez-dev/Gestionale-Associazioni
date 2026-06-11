<?php

namespace Tests\Feature\Prenotazioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\PaymentGatewaySetting;
use App\Models\Prenotazione;
use App\Models\Quota;
use App\Models\Socio;
use App\Services\Pagamenti\Gateways\PayPalGateway;
use App\Services\Pagamenti\Gateways\StripeGateway;
use App\Services\Pagamenti\Pagabile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payload dei gateway sull'astrazione Pagabile:
 * - Stripe → metadata {tipo, pagabile_id, associazione_id, quota_id solo se tipo=quota};
 * - PayPal → custom_id "prenotazione:{uuid}" per prenotazioni, UUID nudo per quote.
 */
class CheckoutParamsGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected Associazione $associazione;

    protected PaymentGatewaySetting $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create(['nome' => 'Circolo Test']);
        $this->config = PaymentGatewaySetting::factory()->conStripe()->conPayPal()->create([
            'associazione_id' => $this->associazione->id,
            'valuta' => 'EUR',
        ]);
    }

    protected function creaQuota(): Quota
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'socio@example.com',
        ]);

        return Quota::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
            'importo' => 25.50,
            'anno' => 2026,
        ]);
    }

    protected function creaPrenotazione(): Prenotazione
    {
        $evento = Evento::factory()->pubblicato()->aPagamento(12.5)->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'account@example.com',
        ]);

        return Prenotazione::factory()->inAttesaPagamento(12.5)->create([
            'evento_id' => $evento->id,
            'associazione_id' => $this->associazione->id,
            'account_id' => $account->id,
            'posti' => 2,
            'prezzo_unitario' => 12.5,
            'importo_totale' => 25.0,
        ]);
    }

    public function test_stripe_quota_ha_metadata_con_tipo_e_quota_id(): void
    {
        $quota = $this->creaQuota();

        $params = (new StripeGateway)->buildCheckoutParams($this->config, $quota, 'https://ok', 'https://ko');

        $this->assertSame([
            'tipo' => Pagabile::TIPO_QUOTA,
            'pagabile_id' => $quota->id,
            'associazione_id' => $this->associazione->id,
            'quota_id' => $quota->id,
        ], $params['metadata']);

        $this->assertSame(2550, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('eur', $params['line_items'][0]['price_data']['currency']);
        $this->assertSame('socio@example.com', $params['customer_email']);
        $this->assertSame('https://ok', $params['success_url']);
        $this->assertSame('https://ko', $params['cancel_url']);
    }

    public function test_stripe_prenotazione_ha_metadata_con_tipo_senza_quota_id(): void
    {
        $prenotazione = $this->creaPrenotazione();

        $params = (new StripeGateway)->buildCheckoutParams($this->config, $prenotazione, 'https://ok', 'https://ko');

        $this->assertSame([
            'tipo' => Pagabile::TIPO_PRENOTAZIONE,
            'pagabile_id' => $prenotazione->id,
            'associazione_id' => $this->associazione->id,
        ], $params['metadata']);

        $this->assertArrayNotHasKey('quota_id', $params['metadata']);
        $this->assertSame(2500, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('account@example.com', $params['customer_email']);
        $this->assertStringContainsString('Circolo Test', $params['line_items'][0]['price_data']['product_data']['name']);
        $this->assertStringContainsString($prenotazione->codice, $params['line_items'][0]['price_data']['product_data']['name']);
    }

    public function test_paypal_quota_usa_custom_id_nudo_retrocompatibile(): void
    {
        $quota = $this->creaQuota();

        $payload = (new PayPalGateway)->buildOrderPayload($this->config, $quota, 'https://ok', 'https://ko');

        $this->assertSame($quota->id, $payload['purchase_units'][0]['custom_id']);
        $this->assertSame('25.50', $payload['purchase_units'][0]['amount']['value']);
        $this->assertSame('EUR', $payload['purchase_units'][0]['amount']['currency_code']);
        $this->assertSame('CAPTURE', $payload['intent']);
    }

    public function test_paypal_prenotazione_usa_custom_id_prefissato(): void
    {
        $prenotazione = $this->creaPrenotazione();

        $payload = (new PayPalGateway)->buildOrderPayload($this->config, $prenotazione, 'https://ok', 'https://ko');

        $this->assertSame('prenotazione:'.$prenotazione->id, $payload['purchase_units'][0]['custom_id']);
        $this->assertSame('25.00', $payload['purchase_units'][0]['amount']['value']);
        $this->assertSame('https://ok', $payload['application_context']['return_url']);
        $this->assertSame('https://ko', $payload['application_context']['cancel_url']);
    }
}
