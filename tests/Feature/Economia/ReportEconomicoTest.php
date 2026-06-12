<?php

namespace Tests\Feature\Economia;

use App\Models\Associazione;
use App\Models\CategoriaMovimento;
use App\Models\Movimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * f.14 — Report economico: periodo selezionabile, totali per categoria,
 * serie mensile per Chart.js, export Excel, permessi e isolamento tenant.
 */
class ReportEconomicoTest extends TestCase
{
    use ConfiguraTenantEconomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
    }

    public function test_report_richiede_login_e_permesso(): void
    {
        $this->get(route('report-economico.index'))->assertRedirect();

        $this->actingAs($this->creaUtenteSenzaPermessi())
            ->get(route('report-economico.index'))
            ->assertForbidden();
    }

    public function test_report_calcola_totali_e_serie_mensile_del_periodo(): void
    {
        $categoria = CategoriaMovimento::factory()->entrata()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Quote associative',
        ]);

        Movimento::factory()->entrata()->create([
            'associazione_id' => $this->associazione->id,
            'categoria_id' => $categoria->id,
            'importo' => 100,
            'data' => '2026-02-10',
        ]);
        Movimento::factory()->uscita()->create([
            'associazione_id' => $this->associazione->id,
            'importo' => 40,
            'data' => '2026-03-05',
        ]);
        // Fuori periodo: ignorato
        Movimento::factory()->entrata()->create([
            'associazione_id' => $this->associazione->id,
            'importo' => 999,
            'data' => '2025-12-31',
        ]);

        // Altro tenant: ignorato
        $altra = Associazione::factory()->create();
        Movimento::factory()->entrata()->create([
            'associazione_id' => $altra->id,
            'importo' => 500,
            'data' => '2026-02-15',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('report-economico.index', ['dal' => '2026-01-01', 'al' => '2026-12-31']));

        $response->assertOk();
        $response->assertViewHas('totaleEntrate', 100.0);
        $response->assertViewHas('totaleUscite', 40.0);
        $response->assertViewHas('saldo', 60.0);
        $response->assertViewHas('numeroMovimenti', 2);

        $mensile = $response->viewData('mensile');
        $this->assertCount(12, $mensile);
        $this->assertEqualsWithDelta(100.0, $mensile['2026-02']['entrate'], 0.001);
        $this->assertEqualsWithDelta(40.0, $mensile['2026-03']['uscite'], 0.001);

        $perCategoria = $response->viewData('perCategoria');
        $this->assertTrue(
            $perCategoria->contains(
                fn ($riga) => $riga['categoria'] === 'Quote associative' && abs($riga['totale'] - 100.0) < 0.001
            )
        );
    }

    public function test_periodo_invertito_viene_normalizzato(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('report-economico.index', ['dal' => '2026-12-31', 'al' => '2026-01-01']));

        $response->assertOk();
        $this->assertTrue($response->viewData('dal')->lte($response->viewData('al')));
    }

    public function test_export_excel_scarica_il_file(): void
    {
        Excel::fake();

        Movimento::factory()->entrata()->create([
            'associazione_id' => $this->associazione->id,
            'data' => '2026-02-10',
        ]);

        $this->actingAs($this->admin)
            ->get(route('report-economico.export', ['dal' => '2026-01-01', 'al' => '2026-12-31']))
            ->assertOk();

        Excel::assertDownloaded('report-economico_20260101-20261231.xlsx');
    }

    public function test_export_richiede_permesso(): void
    {
        $this->actingAs($this->creaUtenteSenzaPermessi())
            ->get(route('report-economico.export'))
            ->assertForbidden();
    }
}
