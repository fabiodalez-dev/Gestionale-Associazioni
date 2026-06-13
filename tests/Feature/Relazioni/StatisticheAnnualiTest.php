<?php

namespace Tests\Feature\Relazioni;

use App\Models\Associazione;
use App\Models\Cessazione;
use App\Models\Donazione;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Movimento;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Services\Relazioni\StatisticheAnnualiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.25 — "Anno in cifre": pagina Statistiche annuali con confronto anno
 * corrente vs precedente (metriche aggregate), grafici Chart.js ed export PDF.
 */
class StatisticheAnnualiTest extends TestCase
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

    public function test_il_servizio_calcola_le_metriche_dell_anno(): void
    {
        $anno = 2025;

        Socio::factory()->count(3)->create([
            'associazione_id' => $this->associazione->id,
            'data_iscrizione' => $anno.'-04-01',
        ]);
        Movimento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'tipo' => Movimento::TIPO_ENTRATA,
            'importo' => 100,
            'data' => $anno.'-06-01',
        ]);
        Movimento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'tipo' => Movimento::TIPO_USCITA,
            'importo' => 40,
            'data' => $anno.'-07-01',
        ]);

        $service = app(StatisticheAnnualiService::class);

        // Forza il contesto tenant per il global scope
        session(['associazione_id' => $this->associazione->id]);
        $this->actingAs($this->admin);

        $m = $service->metriche($anno);

        $this->assertSame(3, $m['nuovi_soci']);
        $this->assertSame(100.0, $m['entrate']);
        $this->assertSame(40.0, $m['uscite']);
        $this->assertSame(60.0, $m['saldo']);
    }

    public function test_pagina_statistiche_mostra_il_confronto(): void
    {
        Socio::factory()->count(2)->create([
            'associazione_id' => $this->associazione->id,
            'data_iscrizione' => '2025-02-01',
        ]);
        Socio::factory()->count(5)->create([
            'associazione_id' => $this->associazione->id,
            'data_iscrizione' => '2024-02-01',
        ]);

        $response = $this->actingAs($this->admin)->get('/statistiche-annuali?anno=2025');

        $response->assertOk();
        $response->assertViewIs('relazioni.statistiche.index');
        $response->assertSee('Statistiche annuali');
        $response->assertSee('Nuovi soci');

        $dati = $response->viewData('dati');
        $this->assertSame(2, $dati['corrente']['nuovi_soci']);
        $this->assertSame(5, $dati['precedente']['nuovi_soci']);
    }

    public function test_metriche_complete_partecipazioni_tessere_donazioni_cessazioni(): void
    {
        $anno = 2025;
        session(['associazione_id' => $this->associazione->id]);
        $this->actingAs($this->admin);

        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_iscrizione' => '2020-01-01',
        ]);

        Tessera::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
            'anno_validita' => $anno,
        ]);

        $evento = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => $anno.'-05-10 10:00:00',
        ]);
        EventoPartecipante::create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $evento->id,
            'socio_id' => $socio->id,
            'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
        ]);

        Cessazione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
            'data' => $anno.'-09-01',
        ]);

        Donazione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'importo' => 50,
            'stato' => Donazione::STATO_PAGATA,
            'pagata_at' => $anno.'-03-01 12:00:00',
        ]);

        $m = app(StatisticheAnnualiService::class)->metriche($anno);

        $this->assertSame(1, $m['tessere']);
        $this->assertSame(1, $m['partecipazioni']);
        $this->assertSame(1, $m['cessazioni']);
        $this->assertSame(50.0, $m['donazioni']);
    }

    public function test_export_pdf(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_iscrizione' => '2025-01-01',
        ]);

        $response = $this->actingAs($this->admin)->get('/statistiche-annuali/pdf?anno=2025');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_isolamento_tenant_nelle_metriche(): void
    {
        Socio::factory()->count(4)->create([
            'associazione_id' => $this->associazione->id,
            'data_iscrizione' => '2025-01-01',
        ]);

        $altra = Associazione::factory()->create();
        Socio::factory()->count(7)->create([
            'associazione_id' => $altra->id,
            'data_iscrizione' => '2025-01-01',
        ]);

        $response = $this->actingAs($this->admin)->get('/statistiche-annuali?anno=2025');

        $dati = $response->viewData('dati');
        $this->assertSame(4, $dati['corrente']['nuovi_soci']);
    }

    public function test_super_admin_senza_tenant_e_respinto(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get('/statistiche-annuali')
            ->assertRedirect(route('dashboard'));
    }
}
