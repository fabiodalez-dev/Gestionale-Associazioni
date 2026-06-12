<?php

namespace Tests\Feature\Rinnovi;

use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Socio;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * f.5 — Apertura anno sociale: pagina con anteprima, generazione bulk
 * idempotente delle quote del nuovo anno, comando rinnovi:apri-anno.
 */
class AperturaAnnoTest extends RinnoviTestCase
{
    use RefreshDatabase;

    public function test_pagina_richiede_login(): void
    {
        $this->get(route('apertura-anno.index'))->assertRedirect('/login');
    }

    public function test_pagina_negata_senza_permessi_quote(): void
    {
        $this->configuraTenant();
        $utente = $this->creaUtenteSenzaPermessi();

        $this->actingAs($utente)->get(route('apertura-anno.index'))->assertForbidden();
    }

    public function test_anteprima_mostra_conteggi_e_importi(): void
    {
        $this->configuraTenant(); // costo_tessera 20.00
        $anno = (int) now()->year + 1;

        $tipo = $this->creaTipoSocio(50.00);
        $this->creaSocio(['tipo_socio_id' => $tipo->id]);
        $this->creaSocio(); // fallback 20.00

        // Socio con quota già aperta per l'anno prossimo
        $conQuota = $this->creaSocio();
        Quota::factory()->create([
            'socio_id' => $conQuota->id,
            'associazione_id' => $this->associazione->id,
            'anno' => $anno,
        ]);

        // Sospeso: fuori dall'apertura
        $this->creaSocio(['stato' => Socio::STATO_SOSPESO]);

        $response = $this->actingAs($this->admin)->get(route('apertura-anno.index'));

        $response->assertOk();
        $response->assertSee('Apri anno sociale '.$anno);
        $response->assertSee('data-testid="anteprima-soci-attivi"', false);
        $response->assertSeeInOrder(['anteprima-soci-attivi', '3'], false);
        $response->assertSeeInOrder(['anteprima-gia-aperte', '1'], false);
        $response->assertSeeInOrder(['anteprima-da-generare', '2'], false);
        // 50.00 + 20.00 = 70.00
        $response->assertSee('70,00');
    }

    public function test_genera_quote_bulk_solo_per_soci_attivi_senza_quota(): void
    {
        $this->configuraTenant();
        $anno = (int) now()->year + 1;

        $tipo = $this->creaTipoSocio(50.00);
        $socio1 = $this->creaSocio(['tipo_socio_id' => $tipo->id]);
        $socio2 = $this->creaSocio();
        $sospeso = $this->creaSocio(['stato' => Socio::STATO_SOSPESO]);

        $conQuota = $this->creaSocio();
        Quota::factory()->create([
            'socio_id' => $conQuota->id,
            'associazione_id' => $this->associazione->id,
            'anno' => $anno,
        ]);

        $response = $this->actingAs($this->admin)->post(route('apertura-anno.genera'), ['anno' => $anno]);

        $response->assertRedirect(route('apertura-anno.index', ['anno' => $anno]));
        $response->assertSessionHas('success');

        $this->assertSame(3, Quota::allTenants()->where('anno', $anno)->count()); // 2 nuove + 1 esistente
        $this->assertSame('50.00', (string) Quota::allTenants()->where('socio_id', $socio1->id)->where('anno', $anno)->value('importo'));
        $this->assertSame('20.00', (string) Quota::allTenants()->where('socio_id', $socio2->id)->where('anno', $anno)->value('importo'));
        $this->assertSame(0, Quota::allTenants()->where('socio_id', $sospeso->id)->count());

        $nuova = Quota::allTenants()->where('socio_id', $socio1->id)->where('anno', $anno)->firstOrFail();
        $this->assertSame($anno.'-12-31', $nuova->data_scadenza->toDateString());
        $this->assertNull($nuova->data_pagamento);
    }

    public function test_generazione_idempotente(): void
    {
        $this->configuraTenant();
        $anno = (int) now()->year + 1;
        $this->creaSocio();

        $this->actingAs($this->admin)->post(route('apertura-anno.genera'), ['anno' => $anno]);
        $this->actingAs($this->admin)->post(route('apertura-anno.genera'), ['anno' => $anno]);

        $this->assertSame(1, Quota::allTenants()->where('anno', $anno)->count());
    }

    public function test_generazione_negata_senza_permessi(): void
    {
        $this->configuraTenant();
        $utente = $this->creaUtenteSenzaPermessi();

        $this->actingAs($utente)
            ->post(route('apertura-anno.genera'), ['anno' => (int) now()->year + 1])
            ->assertForbidden();
    }

    public function test_isolamento_tenant_l_apertura_non_tocca_altri_tenant(): void
    {
        $this->configuraTenant();
        $anno = (int) now()->year + 1;
        $this->creaSocio();

        $altra = Associazione::factory()->create();
        $estraneo = Socio::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)->post(route('apertura-anno.genera'), ['anno' => $anno]);

        $this->assertSame(0, Quota::allTenants()->where('socio_id', $estraneo->id)->count());
    }

    public function test_comando_apri_anno_genera_le_quote(): void
    {
        $this->configuraTenant();
        $anno = (int) now()->year + 1;
        $this->creaSocio();
        $this->creaSocio();

        $this->artisan('rinnovi:apri-anno', ['anno' => $anno])->assertSuccessful();

        $this->assertSame(2, Quota::allTenants()->where('anno', $anno)->count());
    }

    public function test_comando_dry_run_non_scrive_nulla(): void
    {
        $this->configuraTenant();
        $anno = (int) now()->year + 1;
        $this->creaSocio();

        $this->artisan('rinnovi:apri-anno', ['anno' => $anno, '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Quota::allTenants()->where('anno', $anno)->count());
    }
}
