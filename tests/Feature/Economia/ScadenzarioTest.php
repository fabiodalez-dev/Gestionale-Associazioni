<?php

namespace Tests\Feature\Economia;

use App\Models\Associazione;
use App\Models\Carica;
use App\Models\DocumentoSocio;
use App\Models\Quota;
use App\Models\Scadenza;
use App\Models\Socio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.18 — Scadenzario unificato: aggrega quote non pagate, documenti
 * socio, mandati cariche e scadenze custom, con filtri per tipo e
 * range, gating per permesso di modulo e isolamento tenant.
 */
class ScadenzarioTest extends TestCase
{
    use ConfiguraTenantEconomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
    }

    protected function creaScenarioCompleto(): Socio
    {
        $socio = $this->creaSocio();

        // Quota non pagata in scadenza tra 10 giorni
        $this->creaQuota($socio, ['data_scadenza' => now()->addDays(10)->toDateString()]);

        // Documento socio in scadenza tra 20 giorni
        DocumentoSocio::create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
            'nome_file' => 'certificato.pdf',
            'percorso_file' => 'documenti/certificato.pdf',
            'tipo_documento' => 'Certificato medico',
            'data_scadenza' => now()->addDays(20)->toDateString(),
        ]);

        // Mandato carica in scadenza tra 30 giorni
        Carica::create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
            'ruolo' => 'Presidente',
            'inizio' => now()->subYear()->toDateString(),
            'fine' => now()->addDays(30)->toDateString(),
        ]);

        // Scadenza custom attiva tra 5 giorni
        Scadenza::create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Rinnovo assicurazione',
            'data_scadenza' => now()->addDays(5)->toDateString(),
            'tipo_scadenza' => 'Generale',
            'stato' => Scadenza::STATO_ATTIVA,
        ]);

        return $socio;
    }

    public function test_scadenzario_richiede_login(): void
    {
        $this->get(route('scadenzario.index'))->assertRedirect();
    }

    public function test_scadenzario_aggrega_tutte_le_fonti_ordinate_per_data(): void
    {
        $this->creaScenarioCompleto();

        $response = $this->actingAs($this->admin)->get(route('scadenzario.index'));

        $response->assertOk();

        $voci = $response->viewData('voci');
        $this->assertCount(4, $voci);

        // Ordinamento crescente per data
        $this->assertSame(
            ['scadenza', 'quota', 'documento', 'carica'],
            $voci->pluck('tipo')->all()
        );
    }

    public function test_filtro_per_tipo(): void
    {
        $this->creaScenarioCompleto();

        $response = $this->actingAs($this->admin)
            ->get(route('scadenzario.index', ['tipo' => 'quota']));

        $response->assertOk();

        $voci = $response->viewData('voci');
        $this->assertCount(1, $voci);
        $this->assertSame('quota', $voci->first()['tipo']);
    }

    public function test_filtro_per_range_di_date(): void
    {
        $this->creaScenarioCompleto();

        // Solo le voci entro 15 giorni: scadenza custom (+5) e quota (+10)
        $response = $this->actingAs($this->admin)->get(route('scadenzario.index', [
            'dal' => now()->toDateString(),
            'al' => now()->addDays(15)->toDateString(),
        ]));

        $response->assertOk();

        $voci = $response->viewData('voci');
        $this->assertCount(2, $voci);
        $this->assertEqualsCanonicalizing(['scadenza', 'quota'], $voci->pluck('tipo')->all());
    }

    public function test_quote_pagate_non_compaiono(): void
    {
        $socio = $this->creaSocio();
        $this->creaQuota($socio, [
            'data_scadenza' => now()->addDays(10)->toDateString(),
            'data_pagamento' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('scadenzario.index'));

        $response->assertOk();
        $this->assertCount(0, $response->viewData('voci'));
    }

    public function test_utente_senza_permessi_vede_scadenzario_vuoto(): void
    {
        $this->creaScenarioCompleto();

        $response = $this->actingAs($this->creaUtenteSenzaPermessi())
            ->get(route('scadenzario.index'));

        $response->assertOk();
        $this->assertCount(0, $response->viewData('voci'));
    }

    public function test_isolamento_tenant(): void
    {
        $altra = Associazione::factory()->create();
        $socioAltrui = Socio::factory()->create(['associazione_id' => $altra->id]);
        Quota::factory()->create([
            'associazione_id' => $altra->id,
            'socio_id' => $socioAltrui->id,
            'data_pagamento' => null,
            'data_scadenza' => now()->addDays(10)->toDateString(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('scadenzario.index'));

        $response->assertOk();
        $this->assertCount(0, $response->viewData('voci'));
    }
}
