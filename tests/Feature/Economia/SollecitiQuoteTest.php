<?php

namespace Tests\Feature\Economia;

use App\Models\Associazione;
use App\Models\PromemoriaInviato;
use App\Models\Quota;
use App\Models\Socio;
use App\Notifications\Economia\SollecitoQuotaNotification;
use App\Services\Economia\SollecitoQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * f.16 — Solleciti quote non pagate: invio singolo e bulk con link di
 * pagamento, tracking in promemoria_inviati (tipo 'sollecito_quota'),
 * permessi e isolamento tenant.
 */
class SollecitiQuoteTest extends TestCase
{
    use ConfiguraTenantEconomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
        Notification::fake();
    }

    public function test_lista_richiede_login_e_permesso(): void
    {
        $this->get(route('solleciti-quote.index'))->assertRedirect();

        $this->actingAs($this->creaUtenteSenzaPermessi())
            ->get(route('solleciti-quote.index'))
            ->assertForbidden();
    }

    public function test_lista_mostra_solo_le_quote_non_pagate_del_tenant(): void
    {
        $socio = $this->creaSocio();
        $this->creaQuota($socio);
        $this->creaQuota($socio, ['anno' => 2025]);
        $this->creaQuota($socio, ['anno' => 2024, 'data_pagamento' => now()->toDateString()]);

        $altra = Associazione::factory()->create();
        $socioAltrui = Socio::factory()->create(['associazione_id' => $altra->id]);
        Quota::factory()->create([
            'socio_id' => $socioAltrui->id,
            'associazione_id' => $altra->id,
            'data_pagamento' => null,
        ]);

        $response = $this->actingAs($this->admin)->get(route('solleciti-quote.index'));

        $response->assertOk();
        $response->assertViewHas('quote', fn ($quote) => $quote->total() === 2);
    }

    public function test_sollecito_singolo_invia_notifica_e_traccia(): void
    {
        $socio = $this->creaSocio(['email' => 'socio@example.com', 'email_opt_out' => false]);
        $quota = $this->creaQuota($socio, ['anno' => 2026]);

        $response = $this->actingAs($this->admin)
            ->post(route('solleciti-quote.invia', $quota));

        $response->assertRedirect(route('solleciti-quote.index'));
        $response->assertSessionHas('success');

        Notification::assertSentTo(
            $socio,
            SollecitoQuotaNotification::class,
            fn ($notification) => $notification->quota->id === $quota->id
                && $notification->paymentUrl !== null
        );

        $this->assertDatabaseHas('promemoria_inviati', [
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
            'tipo' => SollecitoQuoteService::TIPO_SOLLECITO_QUOTA,
            'step' => 2026,
        ]);

        // Il token di pagamento è stato generato
        $this->assertNotNull($quota->fresh()->payment_token);
    }

    public function test_sollecito_su_quota_pagata_non_invia_nulla(): void
    {
        $socio = $this->creaSocio(['email' => 'socio@example.com']);
        $quota = $this->creaQuota($socio, ['data_pagamento' => now()->toDateString()]);

        $response = $this->actingAs($this->admin)
            ->post(route('solleciti-quote.invia', $quota));

        $response->assertSessionHas('error');
        Notification::assertNothingSent();
        $this->assertSame(0, PromemoriaInviato::allTenants()->count());
    }

    public function test_sollecito_rispetta_opt_out_e_socio_senza_email(): void
    {
        $optOut = $this->creaSocio(['email' => 'optout@example.com', 'email_opt_out' => true]);
        $quotaOptOut = $this->creaQuota($optOut);

        $this->actingAs($this->admin)
            ->post(route('solleciti-quote.invia', $quotaOptOut))
            ->assertSessionHas('error');

        Notification::assertNothingSent();
    }

    public function test_sollecito_bulk_invia_alle_selezionate_e_conta_le_saltate(): void
    {
        $socio1 = $this->creaSocio(['email' => 'uno@example.com', 'email_opt_out' => false]);
        $socio2 = $this->creaSocio(['email' => 'due@example.com', 'email_opt_out' => false]);
        $socioOptOut = $this->creaSocio(['email' => 'tre@example.com', 'email_opt_out' => true]);

        $quota1 = $this->creaQuota($socio1, ['anno' => 2026]);
        $quota2 = $this->creaQuota($socio2, ['anno' => 2026]);
        $quota3 = $this->creaQuota($socioOptOut, ['anno' => 2026]);

        $response = $this->actingAs($this->admin)->post(route('solleciti-quote.bulk'), [
            'quota_ids' => [$quota1->id, $quota2->id, $quota3->id],
        ]);

        $response->assertRedirect(route('solleciti-quote.index'));
        $response->assertSessionHas('success');

        Notification::assertSentTo($socio1, SollecitoQuotaNotification::class);
        Notification::assertSentTo($socio2, SollecitoQuotaNotification::class);
        Notification::assertNotSentTo($socioOptOut, SollecitoQuotaNotification::class);

        $this->assertSame(2, PromemoriaInviato::allTenants()
            ->where('tipo', SollecitoQuoteService::TIPO_SOLLECITO_QUOTA)
            ->count());
    }

    public function test_bulk_ignora_quote_di_altro_tenant(): void
    {
        $altra = Associazione::factory()->create();
        $socioAltrui = Socio::factory()->create([
            'associazione_id' => $altra->id,
            'email' => 'altrui@example.com',
            'email_opt_out' => false,
        ]);
        $quotaAltrui = Quota::factory()->create([
            'socio_id' => $socioAltrui->id,
            'associazione_id' => $altra->id,
            'data_pagamento' => null,
        ]);

        $this->actingAs($this->admin)->post(route('solleciti-quote.bulk'), [
            'quota_ids' => [$quotaAltrui->id],
        ])->assertSessionHas('error');

        Notification::assertNothingSent();
    }

    public function test_sollecito_singolo_su_quota_di_altro_tenant_404(): void
    {
        $altra = Associazione::factory()->create();
        $socioAltrui = Socio::factory()->create(['associazione_id' => $altra->id]);
        $quotaAltrui = Quota::factory()->create([
            'socio_id' => $socioAltrui->id,
            'associazione_id' => $altra->id,
            'data_pagamento' => null,
        ]);

        $this->actingAs($this->admin)
            ->post(route('solleciti-quote.invia', $quotaAltrui->id))
            ->assertNotFound();
    }

    public function test_conteggio_e_ultimo_sollecito_in_lista(): void
    {
        $socio = $this->creaSocio(['email' => 'socio@example.com', 'email_opt_out' => false]);
        $quota = $this->creaQuota($socio, ['anno' => 2026]);

        $this->actingAs($this->admin)->post(route('solleciti-quote.invia', $quota));
        $this->actingAs($this->admin)->post(route('solleciti-quote.invia', $quota));

        $response = $this->actingAs($this->admin)->get(route('solleciti-quote.index'));

        $response->assertOk();

        $solleciti = $response->viewData('solleciti');
        $this->assertSame(2, $solleciti[$quota->id]['conteggio']);
        $this->assertNotNull($solleciti[$quota->id]['ultimo']);
    }
}
