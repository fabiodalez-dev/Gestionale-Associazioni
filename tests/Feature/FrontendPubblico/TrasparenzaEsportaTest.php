<?php

namespace Tests\Feature\FrontendPubblico;

use App\Models\Associazione;
use App\Models\Documento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trasparenza scaricabile in CSV e JSON (#76): gli stessi documenti pubblici
 * della pagina, mai quelli riservati.
 */
class TrasparenzaEsportaTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    protected function setUp(): void
    {
        parent::setUp();
        $this->associazione = Associazione::factory()->create(['slug' => 'faro', 'frontend_pubblico_attivo' => true]);

        Documento::query()->create([
            'associazione_id' => $this->associazione->id, 'nome_file' => 'bilancio-2025.pdf', 'percorso_file' => 'x/b.pdf',
            'descrizione' => 'Rendiconto; approvato "all\'unanimità"', 'pubblico' => true, 'categoria_pubblica' => 'bilancio', 'dimensione_kb' => 120,
        ]);
        Documento::query()->create([
            'associazione_id' => $this->associazione->id, 'nome_file' => 'riservato.pdf', 'percorso_file' => 'x/r.pdf',
            'pubblico' => false,
        ]);
    }

    public function test_csv_in_utf8_con_bom_e_separatore_punto_e_virgola(): void
    {
        $risposta = $this->get('/a/faro/trasparenza.csv')->assertOk()->assertDownload('trasparenza-faro.csv');
        $csv = $risposta->streamedContent();

        $this->assertStringStartsWith("\u{FEFF}categoria;titolo;descrizione;pubblicato_il;dimensione_kb;url", $csv);
        $righe = array_map(fn ($r) => str_getcsv($r, ';', '"', ''), array_filter(explode("\n", trim(substr($csv, 3)))));
        $this->assertCount(2, $righe);
        $this->assertSame(['bilancio', 'bilancio-2025.pdf', 'Rendiconto; approvato "all\'unanimità"'], array_slice($righe[1], 0, 3));
        $this->assertStringNotContainsString('riservato.pdf', $csv);
    }

    public function test_json_con_date_iso_e_link_di_download(): void
    {
        $this->getJson('/a/faro/trasparenza.json')
            ->assertOk()
            ->assertJsonCount(1, 'documenti')
            ->assertJsonPath('documenti.0.titolo', 'bilancio-2025.pdf')
            ->assertJsonPath('documenti.0.categoria', 'bilancio')
            ->assertJsonPath('documenti.0.dimensione_kb', 120)
            ->assertJsonPath('documenti.0.pubblicato_il', now()->toDateString());
    }

    public function test_link_nella_pagina_e_formati_diversi_rifiutati(): void
    {
        $this->get('/a/faro/trasparenza')->assertSee(route('pubblico.trasparenza.esporta', ['faro', 'csv']), false);
        $this->get('/a/faro/trasparenza.xml')->assertNotFound();
    }
}
