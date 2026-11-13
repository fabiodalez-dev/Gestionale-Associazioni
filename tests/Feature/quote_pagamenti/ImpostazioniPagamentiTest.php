<?php

namespace Tests\Feature\quote_pagamenti;

use App\Models\PaymentGatewaySetting;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImpostazioniPagamentiTest extends TestCase
{
    use ConfiguraTenantQuote;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
    }

    public function test_admin_associazione_vede_la_pagina(): void
    {
        $this->actingAs($this->admin)
            ->get(route('quote.impostazioni.edit'))
            ->assertOk()
            ->assertSee('Impostazioni Pagamenti');
    }

    public function test_operatore_non_puo_gestire_le_impostazioni(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $operatore->assignRole('operatore');

        $this->actingAs($operatore)->get(route('quote.impostazioni.edit'))->assertForbidden();
        $this->actingAs($operatore)->put(route('quote.impostazioni.update'), [
            'paypal_mode' => 'sandbox',
            'valuta' => 'EUR',
        ])->assertForbidden();
    }

    public function test_upsert_crea_la_riga_per_il_tenant_con_segreti_cifrati(): void
    {
        $response = $this->actingAs($this->admin)->put(route('quote.impostazioni.update'), [
            'stripe_enabled' => '1',
            'stripe_publishable_key' => 'pk_test_abc',
            'stripe_secret_key' => 'sk_test_segreta',
            'stripe_webhook_secret' => 'whsec_segreto',
            'paypal_enabled' => '0',
            'paypal_mode' => 'sandbox',
            'valuta' => 'eur',
            'auto_attivazione_pagamento' => '1',
        ]);

        $response->assertRedirect(route('quote.impostazioni.edit'));
        $response->assertSessionHas('success');

        $settings = PaymentGatewaySetting::forTenant($this->associazione->id);
        $this->assertNotNull($settings);
        $this->assertTrue($settings->stripe_enabled);
        $this->assertSame('pk_test_abc', $settings->stripe_publishable_key);
        $this->assertSame('EUR', $settings->valuta);
        // L'accessor decifra...
        $this->assertSame('sk_test_segreta', $settings->stripe_secret_key);
        // ...ma at-rest il valore è cifrato (mai in chiaro nel DB)
        $raw = DB::table('payment_gateway_settings')
            ->where('associazione_id', $this->associazione->id)
            ->value('stripe_secret_key_encrypted');
        $this->assertNotSame('sk_test_segreta', $raw);
        $this->assertStringNotContainsString('sk_test_segreta', (string) $raw);
    }

    public function test_update_senza_secret_preserva_quelle_salvate(): void
    {
        $this->configuraStripe([
            'stripe_secret_key_encrypted' => 'sk_originale',
        ]);

        $this->actingAs($this->admin)->put(route('quote.impostazioni.update'), [
            'stripe_enabled' => '1',
            'stripe_publishable_key' => 'pk_aggiornata',
            'stripe_secret_key' => '',
            'paypal_mode' => 'sandbox',
            'valuta' => 'EUR',
            'auto_attivazione_pagamento' => '1',
        ])->assertRedirect();

        $settings = PaymentGatewaySetting::forTenant($this->associazione->id);
        $this->assertSame('pk_aggiornata', $settings->stripe_publishable_key);
        $this->assertSame('sk_originale', $settings->stripe_secret_key);
    }

    public function test_validazione_valuta_e_paypal_mode(): void
    {
        $this->actingAs($this->admin)->put(route('quote.impostazioni.update'), [
            'paypal_mode' => 'staging',
            'valuta' => 'EURO',
        ])->assertSessionHasErrors(['paypal_mode', 'valuta']);
    }

    public function test_la_pagina_non_espone_le_secret_salvate(): void
    {
        $this->configuraStripe([
            'stripe_secret_key_encrypted' => 'sk_test_riservata',
        ]);

        $response = $this->actingAs($this->admin)->get(route('quote.impostazioni.edit'));

        $response->assertOk();
        $response->assertDontSee('sk_test_riservata');
    }

    // ------------------------------------------------------------------
    // Hardening (audit run-1): il webhook id PayPal è obbligatorio se
    // PayPal è abilitato (senza non si verifica la firma dei webhook)
    // ------------------------------------------------------------------

    public function test_paypal_abilitato_richiede_il_webhook_id(): void
    {
        $base = [
            'paypal_enabled' => '1',
            'paypal_client_id' => 'pp_client',
            'paypal_client_secret' => 'pp_secret',
            'paypal_mode' => 'sandbox',
            'valuta' => 'EUR',
        ];

        $this->actingAs($this->admin)
            ->put(route('quote.impostazioni.update'), $base + ['paypal_webhook_id' => ''])
            ->assertSessionHasErrors('paypal_webhook_id');
        $this->assertNull(PaymentGatewaySetting::forTenant($this->associazione->id));

        // PayPal disabilitato: il webhook id può restare vuoto
        $this->actingAs($this->admin)
            ->put(route('quote.impostazioni.update'), ['paypal_enabled' => '0', 'paypal_mode' => 'sandbox', 'valuta' => 'EUR'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->put(route('quote.impostazioni.update'), $base + ['paypal_webhook_id' => 'WH-ABC'])
            ->assertSessionHasNoErrors();

        $settings = PaymentGatewaySetting::forTenant($this->associazione->id);
        $this->assertSame('WH-ABC', $settings->paypal_webhook_id);
        $this->assertTrue($settings->isPayPalOperational());
    }

    public function test_configurazioni_struttura_richiedono_il_webhook_id_se_paypal_abilitato(): void
    {
        $this->actingAs($this->admin)
            ->post(route('configurazioni.pagamenti'), [
                'paypal_enabled' => '1',
                'paypal_client_id' => 'pp_client',
                'paypal_client_secret' => 'pp_secret',
                'paypal_mode' => 'sandbox',
                'valuta' => 'EUR',
            ])
            ->assertSessionHasErrors('paypal_webhook_id');
        $this->assertNull(PaymentGatewaySetting::forTenant($this->associazione->id));

        $this->actingAs($this->admin)
            ->post(route('configurazioni.pagamenti'), [
                'stripe_enabled' => '1',
                'stripe_publishable_key' => 'pk_test_x',
                'paypal_mode' => 'sandbox',
                'valuta' => 'EUR',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_paypal_non_e_operativo_senza_webhook_id(): void
    {
        $settings = $this->configuraPayPal(['paypal_webhook_id' => '']);
        $this->assertFalse($settings->isPayPalOperational());

        $settings->paypal_webhook_id = 'WH-1';
        $this->assertTrue($settings->isPayPalOperational());
    }

    public function test_il_form_segnala_il_webhook_id_come_obbligatorio(): void
    {
        $this->actingAs($this->admin)
            ->get(route('quote.impostazioni.edit'))
            ->assertOk()
            ->assertSee('Obbligatorio se PayPal è abilitato');
    }
}
