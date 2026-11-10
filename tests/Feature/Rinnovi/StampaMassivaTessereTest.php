<?php

namespace Tests\Feature\Rinnovi;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * f.4 — Stampa massiva tessere: un unico PDF multi-tessera dai filtri
 * della lista o dalla selezione esplicita di id.
 */
class StampaMassivaTessereTest extends RinnoviTestCase
{
    use RefreshDatabase;

    private function creaTessera(?Socio $socio = null): Tessera
    {
        $socio ??= $this->creaSocio();

        return Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_richiede_login(): void
    {
        $this->get(route('tessere.stampa-massiva'))->assertRedirect('/login');
    }

    public function test_negata_senza_permesso_tessere_view(): void
    {
        $this->configuraTenant();
        $utente = $this->creaUtenteSenzaPermessi();

        $this->actingAs($utente)->get(route('tessere.stampa-massiva'))->assertForbidden();
    }

    public function test_pdf_unico_con_le_tessere_dell_anno(): void
    {
        $this->configuraTenant();
        $this->creaTessera();
        $this->creaTessera();

        $response = $this->actingAs($this->admin)
            ->get(route('tessere.stampa-massiva', ['anno' => now()->year]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    /** Pagine e dimensioni (MediaBox) del PDF prodotto da dompdf. */
    private function paginePdf(string $pdf): array
    {
        preg_match_all('#/Type\s*/Page[^s]#', $pdf, $pagine);
        preg_match('#/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)#', $pdf, $box);

        return [count($pagine[0]), isset($box[3]) ? [round((float) $box[3]), round((float) $box[4])] : null];
    }

    public function test_formato_carta_di_credito_una_tessera_per_pagina_cr80(): void
    {
        $this->configuraTenant();
        $this->creaTessera();
        $this->creaTessera();

        $pdf = (string) $this->actingAs($this->admin)
            ->get(route('tessere.stampa-massiva', ['anno' => now()->year, 'formato' => 'cr80']))
            ->assertOk()->getContent();

        // 85,6 × 54 mm = 243 × 153 punti, una tessera per pagina
        $this->assertSame([2, [243.0, 153.0]], $this->paginePdf($pdf));
    }

    public function test_fogli_a4_da_dieci_tessere(): void
    {
        $this->configuraTenant();
        for ($i = 0; $i < 23; $i++) {
            $this->creaTessera();
        }

        $pdf = (string) $this->actingAs($this->admin)
            ->get(route('tessere.stampa-massiva', ['anno' => now()->year, 'formato' => 'biglietti']))
            ->assertOk()->getContent();

        // 23 tessere → 3 fogli A4 (595 × 842 punti)
        $this->assertSame([3, [595.0, 842.0]], $this->paginePdf($pdf));
    }

    public function test_formato_sconosciuto_rifiutato(): void
    {
        $this->configuraTenant();

        $this->actingAs($this->admin)
            ->get(route('tessere.stampa-massiva', ['anno' => now()->year, 'formato' => 'a3']))
            ->assertSessionHasErrors('formato');
    }

    public function test_selezione_esplicita_per_id(): void
    {
        $this->configuraTenant();
        $tessera = $this->creaTessera();
        $this->creaTessera(); // non selezionata

        $response = $this->actingAs($this->admin)
            ->get(route('tessere.stampa-massiva', ['ids' => [$tessera->id]]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_nessuna_tessera_redirect_con_avviso(): void
    {
        $this->configuraTenant();

        $response = $this->actingAs($this->admin)
            ->get(route('tessere.stampa-massiva', ['anno' => now()->year]));

        $response->assertRedirect(route('tessere.index', ['anno' => now()->year]));
        $response->assertSessionHas('warning');
    }

    public function test_isolamento_tenant_le_tessere_altrui_non_vengono_stampate(): void
    {
        $this->configuraTenant();

        // Solo tessere di un ALTRO tenant per l'anno corrente
        $altra = Associazione::factory()->create();
        $socioAltrui = Socio::factory()->create(['associazione_id' => $altra->id]);
        Tessera::factory()->create([
            'socio_id' => $socioAltrui->id,
            'associazione_id' => $altra->id,
        ]);

        // Anche selezionandole per id il global scope le esclude → nessuna tessera
        $response = $this->actingAs($this->admin)
            ->get(route('tessere.stampa-massiva', ['ids' => [Tessera::allTenants()->firstOrFail()->id]]));

        $response->assertRedirect();
        $response->assertSessionHas('warning');
    }

    public function test_pulsante_stampa_pdf_presente_nella_lista_tessere(): void
    {
        $this->configuraTenant();

        $this->actingAs($this->admin)->get(route('tessere.index'))
            ->assertOk()
            ->assertSee('Stampa PDF');
    }
}
