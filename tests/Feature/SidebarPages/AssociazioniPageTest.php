<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssociazioniPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/associazioni')->assertRedirect('/login');
    }

    public function test_super_admin_vede_la_pagina_con_il_titolo(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get('/associazioni')
            ->assertOk()
            ->assertSee('Gestione Associazioni');
    }

    public function test_admin_associazione_riceve_403(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create();

        $this->actingAs($admin)->get('/associazioni')->assertForbidden();
    }

    public function test_elenco_mostra_tutte_le_associazioni_con_email(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        Associazione::factory()->create(['nome' => 'Circolo Alfa', 'email' => 'alfa@example.com']);
        Associazione::factory()->create(['nome' => 'Circolo Beta', 'email' => 'beta@example.com']);

        $response = $this->actingAs($superAdmin)->get('/associazioni');

        $response->assertOk();
        $response->assertSee('Circolo Alfa');
        $response->assertSee('alfa@example.com');
        $response->assertSee('Circolo Beta');
        $response->assertSee('beta@example.com');
    }

    public function test_admin_associazione_non_puo_eliminare_un_associazione(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create();
        $associazione = Associazione::factory()->create(['nome' => 'Intoccabile']);

        $this->actingAs($admin)
            ->delete('/associazioni/'.$associazione->id, ['conferma_nome' => 'Intoccabile'])
            ->assertForbidden();

        $this->assertDatabaseHas('associazioni', ['id' => $associazione->id]);
    }
}
