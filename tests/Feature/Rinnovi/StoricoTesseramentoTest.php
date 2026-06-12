<?php

namespace Tests\Feature\Rinnovi;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\Rinnovi\StoricoTesseramentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * f.6 — Storico tesseramento: tab nella scheda socio con timeline per
 * anno (tessere, quote, pagamenti, presenze) ed export Excel.
 */
class StoricoTesseramentoTest extends RinnoviTestCase
{
    use RefreshDatabase;

    private function popolaStorico(Socio $socio): void
    {
        $annoCorrente = (int) now()->year;

        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno_validita' => $annoCorrente,
            'numero_tessera' => $annoCorrente.'0042',
        ]);

        Quota::factory()->pagata()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno' => $annoCorrente,
            'importo' => 25.00,
        ]);

        Quota::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno' => $annoCorrente - 1,
            'importo' => 22.00,
        ]);

        $evento = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->subMonth(),
        ]);
        EventoPartecipante::create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $evento->id,
            'socio_id' => $socio->id,
            'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
            'data_conferma' => now()->subMonth(),
        ]);
    }

    public function test_timeline_per_anno_con_tessere_quote_e_presenze(): void
    {
        $this->configuraTenant();
        $socio = $this->creaSocio();
        $this->popolaStorico($socio);

        $timeline = app(StoricoTesseramentoService::class)->timeline($socio);
        $annoCorrente = (int) now()->year;

        $this->assertSame([$annoCorrente, $annoCorrente - 1], $timeline->pluck('anno')->all());

        $rigaCorrente = $timeline->firstWhere('anno', $annoCorrente);
        $this->assertCount(1, $rigaCorrente['tessere']);
        $this->assertCount(1, $rigaCorrente['quote']);
        $this->assertSame(1, $rigaCorrente['presenze']);

        $rigaPassata = $timeline->firstWhere('anno', $annoCorrente - 1);
        $this->assertCount(0, $rigaPassata['tessere']);
        $this->assertCount(1, $rigaPassata['quote']);
        $this->assertSame(0, $rigaPassata['presenze']);
    }

    public function test_scheda_socio_mostra_il_tab_tesseramento(): void
    {
        $this->configuraTenant();
        $socio = $this->creaSocio();
        $this->popolaStorico($socio);

        $response = $this->actingAs($this->admin)->get(route('soci.show', $socio));

        $response->assertOk();
        $response->assertSee('Tesseramento');
        $response->assertSee('Storico tesseramento');
        $response->assertSee(now()->year.'0042');
        $response->assertSee('Esporta Excel');
    }

    public function test_export_excel_scarica_il_file(): void
    {
        $this->configuraTenant();
        $socio = $this->creaSocio();
        $this->popolaStorico($socio);

        $response = $this->actingAs($this->admin)
            ->get(route('soci.storico-tesseramento.export', $socio));

        $response->assertOk();
        $this->assertStringContainsString(
            'storico-tesseramento_',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_export_negato_senza_permesso_soci_view(): void
    {
        $this->configuraTenant();
        $utente = $this->creaUtenteSenzaPermessi();
        $socio = $this->creaSocio();

        $this->actingAs($utente)
            ->get(route('soci.storico-tesseramento.export', $socio))
            ->assertForbidden();
    }

    public function test_export_cross_tenant_restituisce_404(): void
    {
        $this->configuraTenant();

        $altra = Associazione::factory()->create();
        $estraneo = Socio::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->get(route('soci.storico-tesseramento.export', $estraneo))
            ->assertNotFound();
    }
}
