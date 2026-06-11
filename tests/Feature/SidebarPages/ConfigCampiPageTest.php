<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina sidebar "Campi Personalizzati" (/config-campi).
 *
 * Varianti rispetto a Soci\SociConfigTest (che copre operatore→403 in GET,
 * admin→200 senza titolo, preset e validazione select): qui si verificano
 * redirect ospite, titolo reale, isolamento tenant in lista, visibilità
 * del record creato e divieto di creazione per l'operatore.
 */
class ConfigCampiPageTest extends TestCase
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
        $this->get('/config-campi')->assertRedirect('/login');
    }

    public function test_admin_vede_la_pagina_con_il_titolo(): void
    {
        $this->actingAs($this->admin)
            ->get('/config-campi')
            ->assertOk()
            ->assertSee('Campi Personalizzati');
    }

    public function test_isolamento_tenant_i_campi_di_b_non_sono_visibili_all_admin_di_a(): void
    {
        CampoPersonalizzato::create([
            'associazione_id' => $this->associazione->id,
            'nome_campo' => 'Campo Mio Visibile',
            'tipo_campo' => 'text',
        ]);
        CampoPersonalizzato::create([
            'associazione_id' => Associazione::factory()->create()->id,
            'nome_campo' => 'Campo Estraneo Nascosto',
            'tipo_campo' => 'text',
        ]);

        $this->actingAs($this->admin)
            ->get('/config-campi')
            ->assertOk()
            ->assertSee('Campo Mio Visibile')
            ->assertDontSee('Campo Estraneo Nascosto');
    }

    public function test_campo_select_creato_dal_form_appare_in_lista(): void
    {
        $this->actingAs($this->admin)->post('/config-campi', [
            'nome_campo' => 'Taglia Maglietta',
            'tipo_campo' => 'select',
            'opzioni' => 'S, M, L, XL',
        ])->assertRedirect(route('config-campi.index'));

        $this->assertDatabaseHas('campi_personalizzati', [
            'nome_campo' => 'Taglia Maglietta',
            'tipo_campo' => 'select',
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/config-campi')
            ->assertOk()
            ->assertSee('Taglia Maglietta');
    }

    public function test_operatore_non_puo_creare_campi(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)->post('/config-campi', [
            'nome_campo' => 'Campo Vietato',
            'tipo_campo' => 'text',
        ])->assertForbidden();

        $this->assertDatabaseMissing('campi_personalizzati', ['nome_campo' => 'Campo Vietato']);
    }
}
