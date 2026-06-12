<?php

namespace Tests\Feature\Registro;

use App\Models\Associazione;
use App\Models\Cessazione;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use App\Models\Utente;
use App\Services\Registro\LibroSociService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Libro soci ufficiale (f.13 del piano vita sociale): numerazione
 * cronologica per anno, delibera di ammissione, cessazioni, export.
 */
class LibroSociTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('libro-soci.index'))->assertRedirect('/login');
    }

    public function test_numerazione_progressiva_cronologica_per_anno(): void
    {
        // 2 ammissioni nel 2025 (in ordine cronologico inverso di creazione)
        $secondo2025 = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Bianchi',
            'data_iscrizione' => '2025-09-10',
        ]);
        $primo2025 = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Rossi',
            'data_iscrizione' => '2025-02-01',
        ]);
        // 1 ammissione nel 2026: il progressivo riparte da 1
        $primo2026 = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Verdi',
            'data_iscrizione' => '2026-01-15',
        ]);

        $this->actingAs($this->admin);

        $righe = app(LibroSociService::class)->righe();

        $this->assertCount(3, $righe);

        $perSocio = collect($righe)->keyBy(fn (array $riga) => $riga['socio']->id);

        $this->assertSame(1, $perSocio[$primo2025->id]['progressivo']);
        $this->assertSame(2025, $perSocio[$primo2025->id]['anno']);
        $this->assertSame(2, $perSocio[$secondo2025->id]['progressivo']);
        $this->assertSame(1, $perSocio[$primo2026->id]['progressivo']);
        $this->assertSame(2026, $perSocio[$primo2026->id]['anno']);
    }

    public function test_pagina_mostra_il_registro_con_numerazione(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Maria',
            'cognome' => 'Rossini',
            'data_iscrizione' => '2026-03-01',
        ]);

        $this->actingAs($this->admin)
            ->get(route('libro-soci.index'))
            ->assertOk()
            ->assertSee('Libro soci')
            ->assertSee('Rossini')
            ->assertSee('1/2026')
            ->assertSee('Anno 2026');
    }

    public function test_pre_iscrizioni_non_compaiono_nel_libro(): void
    {
        Socio::factory()->inAttesa()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Pendentini',
        ]);
        Socio::factory()->inAttesaDiPagamento()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Pagantini',
        ]);

        $this->actingAs($this->admin)
            ->get(route('libro-soci.index'))
            ->assertOk()
            ->assertDontSee('Pendentini')
            ->assertDontSee('Pagantini');
    }

    public function test_isolamento_tenant_il_libro_non_mostra_soci_di_altre_associazioni(): void
    {
        $altra = Associazione::factory()->create();
        Socio::factory()->create([
            'associazione_id' => $altra->id,
            'cognome' => 'Estraneo',
            'data_iscrizione' => '2026-01-01',
        ]);

        $this->actingAs($this->admin)
            ->get(route('libro-soci.index'))
            ->assertOk()
            ->assertDontSee('Estraneo');
    }

    public function test_delibera_di_ammissione_compare_nel_libro(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_iscrizione' => '2026-02-20',
        ]);

        RichiestaIscrizione::factory()
            ->approvata($this->admin)
            ->create([
                'associazione_id' => $this->associazione->id,
                'socio_id' => $socio->id,
                'delibera_numero' => '7/2026',
                'delibera_data' => '2026-02-18',
            ]);

        $this->actingAs($this->admin)
            ->get(route('libro-soci.index'))
            ->assertOk()
            ->assertSee('n. 7/2026')
            ->assertSee('18/02/2026');
    }

    public function test_cessazione_compare_nel_libro(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato' => Socio::STATO_RADIATO,
            'data_iscrizione' => '2024-05-05',
        ]);

        Cessazione::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'tipo' => Cessazione::TIPO_DIMISSIONI,
            'data' => '2026-04-30',
            'motivo' => 'Trasferimento all\'estero',
        ]);

        $this->actingAs($this->admin)
            ->get(route('libro-soci.index'))
            ->assertOk()
            ->assertSee('30/04/2026')
            ->assertSee('Dimissioni');
    }

    public function test_filtro_per_anno_mantiene_il_progressivo_originale(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Vecchini',
            'data_iscrizione' => '2025-01-01',
        ]);
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Nuovini',
            'data_iscrizione' => '2026-01-01',
        ]);

        $this->actingAs($this->admin)
            ->get(route('libro-soci.index', ['anno' => 2026]))
            ->assertOk()
            ->assertSee('Nuovini')
            ->assertDontSee('Vecchini');
    }

    public function test_export_excel_scarica_il_file(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_iscrizione' => '2026-01-01',
        ]);

        $response = $this->actingAs($this->admin)->get(route('libro-soci.excel'));

        $response->assertOk();
        $response->assertDownload('libro-soci-'.now()->format('Ymd').'.xlsx');
    }

    public function test_export_pdf_scarica_il_file(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_iscrizione' => '2026-01-01',
        ]);

        $response = $this->actingAs($this->admin)->get(route('libro-soci.pdf'));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }
}
