<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\TipoSocio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina sidebar "Tipi di Socio" (/tipi-socio).
 *
 * Varianti rispetto a Soci\SociConfigTest (che copre CRUD, PUT cross-tenant
 * →404 con assertDontSee e operatore→403 in GET): redirect ospite, titolo
 * reale, DELETE cross-tenant→404, tipo visibile in lista con la descrizione,
 * creazione vietata all'operatore.
 */
class TipiSocioPageTest extends TestCase
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
        $this->get('/tipi-socio')->assertRedirect('/login');
    }

    public function test_admin_vede_la_pagina_con_il_titolo(): void
    {
        $this->actingAs($this->admin)
            ->get('/tipi-socio')
            ->assertOk()
            ->assertSee('Tipi di Socio');
    }

    public function test_isolamento_tenant_admin_di_a_non_puo_eliminare_un_tipo_di_b(): void
    {
        $tipoAltrui = TipoSocio::create([
            'associazione_id' => Associazione::factory()->create()->id,
            'nome' => 'Tipo Altrui',
        ]);

        $this->actingAs($this->admin)
            ->delete("/tipi-socio/{$tipoAltrui->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('tipi_socio', ['id' => $tipoAltrui->id]);
    }

    public function test_il_tipo_creato_appare_in_lista_con_la_descrizione(): void
    {
        TipoSocio::create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Benemerito Fondatore',
            'descrizione' => 'Socio fondatore con merito speciale',
            'costo_tessera' => '75.00',
        ]);

        $this->actingAs($this->admin)
            ->get('/tipi-socio')
            ->assertOk()
            ->assertSee('Benemerito Fondatore')
            ->assertSee('Socio fondatore con merito speciale');
    }

    public function test_operatore_non_puo_creare_tipi_socio(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)->post('/tipi-socio', [
            'nome' => 'Tipo Vietato',
        ])->assertForbidden();

        $this->assertDatabaseMissing('tipi_socio', ['nome' => 'Tipo Vietato']);
    }
}
