<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\GruppoDinamico;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina sidebar "Gruppi Dinamici" (/config-gruppi).
 *
 * Varianti rispetto a Soci\SociConfigTest (che copre creazione con whitelist
 * filtri e operatore→403 in GET): redirect ospite, titolo reale, isolamento
 * tenant in lista, gruppo visibile in lista, creazione vietata all'operatore.
 */
class ConfigGruppiPageTest extends TestCase
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
        $this->get('/config-gruppi')->assertRedirect('/login');
    }

    public function test_admin_vede_la_pagina_con_il_titolo(): void
    {
        $this->actingAs($this->admin)
            ->get('/config-gruppi')
            ->assertOk()
            ->assertSee('Gruppi Dinamici');
    }

    public function test_isolamento_tenant_i_gruppi_di_b_non_sono_visibili_all_admin_di_a(): void
    {
        GruppoDinamico::create([
            'associazione_id' => $this->associazione->id,
            'nome_gruppo' => 'Gruppo Mio Visibile',
            'filtri_json' => ['stato' => 'Attivo'],
        ]);
        GruppoDinamico::create([
            'associazione_id' => Associazione::factory()->create()->id,
            'nome_gruppo' => 'Gruppo Estraneo Nascosto',
            'filtri_json' => ['stato' => 'Attivo'],
        ]);

        $this->actingAs($this->admin)
            ->get('/config-gruppi')
            ->assertOk()
            ->assertSee('Gruppo Mio Visibile')
            ->assertDontSee('Gruppo Estraneo Nascosto');
    }

    public function test_il_gruppo_creato_appare_in_lista(): void
    {
        GruppoDinamico::create([
            'associazione_id' => $this->associazione->id,
            'nome_gruppo' => 'Soci Attivi di Torino',
            'descrizione' => 'Filtro su stato e citta',
            'filtri_json' => ['stato' => 'Attivo', 'citta' => 'Torino'],
        ]);

        $this->actingAs($this->admin)
            ->get('/config-gruppi')
            ->assertOk()
            ->assertSee('Soci Attivi di Torino');
    }

    public function test_operatore_non_puo_creare_gruppi(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)->post('/config-gruppi', [
            'nome_gruppo' => 'Gruppo Vietato',
            'filtri' => ['stato' => 'Attivo'],
        ])->assertForbidden();

        $this->assertDatabaseMissing('gruppi_dinamici', ['nome_gruppo' => 'Gruppo Vietato']);
    }
}
