<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\Tag;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina sidebar "Gestione Tag" (/config-tags).
 *
 * Varianti rispetto a Soci\SociConfigTest (che copre il CRUD via POST/DELETE
 * e operatore→403 in GET): redirect ospite, titolo reale, isolamento tenant
 * in lista, rendering di nome e colore del tag, delete vietata all'operatore.
 */
class ConfigTagsPageTest extends TestCase
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
        $this->get('/config-tags')->assertRedirect('/login');
    }

    public function test_admin_vede_la_pagina_con_il_titolo(): void
    {
        $this->actingAs($this->admin)
            ->get('/config-tags')
            ->assertOk()
            ->assertSee('Gestione Tag');
    }

    public function test_isolamento_tenant_i_tag_di_b_non_sono_visibili_all_admin_di_a(): void
    {
        Tag::create([
            'associazione_id' => $this->associazione->id,
            'nome_tag' => 'Tag Mio Visibile',
            'colore' => '#FF7B11',
        ]);
        Tag::create([
            'associazione_id' => Associazione::factory()->create()->id,
            'nome_tag' => 'Tag Estraneo Nascosto',
            'colore' => '#000000',
        ]);

        $this->actingAs($this->admin)
            ->get('/config-tags')
            ->assertOk()
            ->assertSee('Tag Mio Visibile')
            ->assertDontSee('Tag Estraneo Nascosto');
    }

    public function test_il_tag_creato_appare_in_lista_con_nome_e_colore(): void
    {
        Tag::create([
            'associazione_id' => $this->associazione->id,
            'nome_tag' => 'Volontariato Estivo',
            'colore' => '#11AA22',
        ]);

        $this->actingAs($this->admin)
            ->get('/config-tags')
            ->assertOk()
            ->assertSee('Volontariato Estivo')
            ->assertSee('#11AA22');
    }

    public function test_operatore_non_puo_eliminare_tag(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $tag = Tag::create([
            'associazione_id' => $this->associazione->id,
            'nome_tag' => 'Intoccabile',
            'colore' => '#FF7B11',
        ]);

        $this->actingAs($operatore)
            ->delete("/config-tags/{$tag->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }
}
