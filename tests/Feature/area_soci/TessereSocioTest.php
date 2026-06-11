<?php

namespace Tests\Feature\AreaSoci;

use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TessereSocioTest extends TestCase
{
    use RefreshDatabase;

    public function test_elenco_tessere_con_stato_calcolato(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();

        // Attiva (scadenza oltre 30 giorni)
        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'numero_tessera' => 'TES-ATTIVA',
            'stato' => Tessera::STATO_ATTIVA,
            'data_scadenza' => now()->addMonths(6)->toDateString(),
        ]);

        // Stato DB "Attiva" ma scadenza superata: il portale mostra "Scaduta"
        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'numero_tessera' => 'TES-VECCHIA',
            'anno_validita' => (int) now()->subYear()->year,
            'stato' => Tessera::STATO_ATTIVA,
            'data_scadenza' => now()->subDays(10)->toDateString(),
        ]);

        // Sospesa: lo stato DB diverso da Attiva vince sempre
        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'numero_tessera' => 'TES-SOSPESA',
            'anno_validita' => (int) now()->subYears(2)->year,
            'stato' => Tessera::STATO_SOSPESA,
            'data_scadenza' => now()->addMonths(6)->toDateString(),
        ]);

        $response = $this->actingAs($socio, 'socio')->get(route('area-soci.tessere'));

        $response->assertOk();
        $response->assertSee('TES-ATTIVA');
        $response->assertSee('TES-VECCHIA');
        $response->assertSee('Scaduta');
        $response->assertSee('Sospesa');
    }

    public function test_download_pdf_della_propria_tessera(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create(['numero_socio' => '0042']);
        $tessera = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'anno_validita' => 2026,
        ]);

        $response = $this->actingAs($socio, 'socio')
            ->get(route('area-soci.tessere.pdf', ['tessera' => $tessera->id]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertDownload('tessera_0042_2026.pdf');
    }

    public function test_pdf_di_una_tessera_altrui_restituisce_404(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();

        $altroSocio = Socio::factory()->create(['associazione_id' => $socio->associazione_id]);
        $tesseraAltrui = Tessera::factory()->create(['socio_id' => $altroSocio->id]);

        $this->actingAs($socio, 'socio')
            ->get(route('area-soci.tessere.pdf', ['tessera' => $tesseraAltrui->id]))
            ->assertNotFound();
    }

    public function test_pdf_di_una_tessera_di_altro_tenant_restituisce_404(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();

        $socioAltroTenant = Socio::factory()->create();
        $tesseraAltroTenant = Tessera::factory()->create(['socio_id' => $socioAltroTenant->id]);

        $this->actingAs($socio, 'socio')
            ->get(route('area-soci.tessere.pdf', ['tessera' => $tesseraAltroTenant->id]))
            ->assertNotFound();
    }

    public function test_isolamento_il_socio_non_vede_le_tessere_di_altri(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();

        $altroSocio = Socio::factory()->create();
        Tessera::factory()->create([
            'socio_id' => $altroSocio->id,
            'numero_tessera' => 'TES-SEGRETA-ALTRUI',
        ]);

        $response = $this->actingAs($socio, 'socio')->get(route('area-soci.tessere'));

        $response->assertOk();
        $response->assertDontSee('TES-SEGRETA-ALTRUI');
    }

    public function test_ospite_non_accede_alle_tessere(): void
    {
        $this->get(route('area-soci.tessere'))
            ->assertRedirect(route('area-soci.login'));
    }
}
