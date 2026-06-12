<?php

namespace Tests\Feature\Rinnovi;

use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * f.1 — Rinnovo self-service dall'area soci: POST /area-soci/rinnova
 * crea la quota dell'anno (se mancante) con payment_token e porta
 * alla pagina di pagamento pubblica.
 */
class RinnovoSelfServiceTest extends RinnoviTestCase
{
    use RefreshDatabase;

    public function test_guest_redirige_al_login_area_soci(): void
    {
        $this->post('/area-soci/rinnova')
            ->assertRedirect(route('area-soci.login'));
    }

    public function test_socio_attivo_senza_tessera_crea_quota_e_va_al_pagamento(): void
    {
        $this->configuraTenant();
        $tipo = $this->creaTipoSocio(35.00);
        $socio = $this->creaSocio([
            'tipo_socio_id' => $tipo->id,
            'password_hash' => 'password',
        ]);

        $response = $this->actingAs($socio, 'socio')->post('/area-soci/rinnova');

        /** @var Quota $quota */
        $quota = Quota::allTenants()
            ->where('socio_id', $socio->id)
            ->where('anno', now()->year)
            ->firstOrFail();

        $this->assertSame('35.00', (string) $quota->importo);
        $this->assertTrue($quota->hasValidPaymentToken());
        $this->assertNull($quota->data_pagamento);

        $response->assertRedirect(route('pagamento.show', ['token' => $quota->payment_token]));
    }

    public function test_importo_fallback_costo_tessera_associazione_senza_tipo_socio(): void
    {
        $this->configuraTenant(); // costo_tessera = 20.00
        $socio = $this->creaSocio(['password_hash' => 'password', 'tipo_socio_id' => null]);

        $this->actingAs($socio, 'socio')->post('/area-soci/rinnova');

        $quota = Quota::allTenants()->where('socio_id', $socio->id)->firstOrFail();
        $this->assertSame('20.00', (string) $quota->importo);
    }

    public function test_riusa_quota_non_pagata_esistente_senza_crearne_una_nuova(): void
    {
        $this->configuraTenant();
        $socio = $this->creaSocio(['password_hash' => 'password']);

        $esistente = Quota::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno' => (int) now()->year,
            'importo' => 42.00,
        ]);

        $response = $this->actingAs($socio, 'socio')->post('/area-soci/rinnova');

        $this->assertSame(1, Quota::allTenants()->where('socio_id', $socio->id)->count());

        $esistente->refresh();
        $this->assertTrue($esistente->hasValidPaymentToken());
        $response->assertRedirect(route('pagamento.show', ['token' => $esistente->payment_token]));
    }

    public function test_con_tessera_dell_anno_gia_emessa_niente_quota_e_avviso(): void
    {
        $this->configuraTenant();
        $socio = $this->creaSocio(['password_hash' => 'password']);
        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno_validita' => (int) now()->year,
        ]);

        $response = $this->actingAs($socio, 'socio')->post('/area-soci/rinnova');

        $response->assertRedirect(route('area-soci.dashboard'));
        $response->assertSessionHas('warning');
        $this->assertSame(0, Quota::allTenants()->where('socio_id', $socio->id)->count());
    }

    public function test_socio_non_attivo_non_puo_rinnovare(): void
    {
        $this->configuraTenant();
        $socio = $this->creaSocio([
            'password_hash' => 'password',
            'stato' => Socio::STATO_SOSPESO,
        ]);

        $response = $this->actingAs($socio, 'socio')->post('/area-soci/rinnova');

        $response->assertRedirect(route('area-soci.dashboard'));
        $response->assertSessionHas('warning');
        $this->assertSame(0, Quota::allTenants()->where('socio_id', $socio->id)->count());
    }

    public function test_pulsante_rinnova_visibile_in_dashboard_area_soci(): void
    {
        $this->configuraTenant();
        $socio = $this->creaSocio(['password_hash' => 'password']);

        $this->actingAs($socio, 'socio')->get(route('area-soci.dashboard'))
            ->assertOk()
            ->assertSee('Rinnova per il '.now()->year);
    }

    public function test_pulsante_rinnova_nascosto_se_tessera_anno_presente(): void
    {
        $this->configuraTenant();
        $socio = $this->creaSocio(['password_hash' => 'password']);
        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno_validita' => (int) now()->year,
        ]);

        $this->actingAs($socio, 'socio')->get(route('area-soci.dashboard'))
            ->assertOk()
            ->assertDontSee('Rinnova per il '.now()->year);
    }
}
