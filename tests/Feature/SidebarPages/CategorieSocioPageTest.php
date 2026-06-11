<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\CategoriaSocio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina sidebar "Categorie di Socio" (/categorie-socio).
 *
 * Varianti rispetto a Soci\SociConfigTest (che copre CRUD e operatore→403
 * in GET): redirect ospite, titolo reale, isolamento tenant in lista,
 * categoria creata visibile con il testo tessera, update vietato all'operatore.
 */
class CategorieSocioPageTest extends TestCase
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
        $this->get('/categorie-socio')->assertRedirect('/login');
    }

    public function test_admin_vede_la_pagina_con_il_titolo(): void
    {
        $this->actingAs($this->admin)
            ->get('/categorie-socio')
            ->assertOk()
            ->assertSee('Categorie di Socio');
    }

    public function test_isolamento_tenant_le_categorie_di_b_non_sono_visibili_all_admin_di_a(): void
    {
        CategoriaSocio::create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Categoria Mia Visibile',
        ]);
        CategoriaSocio::create([
            'associazione_id' => Associazione::factory()->create()->id,
            'nome' => 'Categoria Estranea Nascosta',
        ]);

        $this->actingAs($this->admin)
            ->get('/categorie-socio')
            ->assertOk()
            ->assertSee('Categoria Mia Visibile')
            ->assertDontSee('Categoria Estranea Nascosta');
    }

    public function test_la_categoria_creata_appare_in_lista_con_descrizione(): void
    {
        CategoriaSocio::create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Senior Agonisti',
            'descrizione' => 'Atleti over 18 con agonismo',
        ]);

        $this->actingAs($this->admin)
            ->get('/categorie-socio')
            ->assertOk()
            ->assertSee('Senior Agonisti')
            ->assertSee('Atleti over 18 con agonismo');
    }

    public function test_operatore_non_puo_modificare_categorie(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $categoria = CategoriaSocio::create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Originale',
        ]);

        $this->actingAs($operatore)
            ->put("/categorie-socio/{$categoria->id}", ['nome' => 'Manomessa'])
            ->assertForbidden();

        $this->assertSame('Originale', $categoria->refresh()->nome);
    }
}
