<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigurazioniPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/configurazioni')->assertRedirect('/login');
    }

    public function test_admin_associazione_vede_la_pagina_con_il_titolo(): void
    {
        $associazione = Associazione::factory()->create();
        $admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $associazione->id,
        ]);

        $this->actingAs($admin)
            ->get('/configurazioni')
            ->assertOk()
            ->assertSee('Configurazioni');
    }

    public function test_isolamento_tenant_admin_di_a_non_vede_il_nome_di_b(): void
    {
        $assocA = Associazione::factory()->create(['nome' => 'Tenant Alfa Sportiva']);
        $assocB = Associazione::factory()->create(['nome' => 'Tenant Beta Culturale']);
        $adminA = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $assocA->id,
        ]);

        $response = $this->actingAs($adminA)->get('/configurazioni');

        $response->assertOk();
        $response->assertSee('Tenant Alfa Sportiva');
        $response->assertDontSee('Tenant Beta Culturale');
    }

    public function test_form_dati_anagrafici_precompilato_con_i_dati_dell_associazione(): void
    {
        $associazione = Associazione::factory()->create([
            'nome' => 'Polisportiva Verdi',
            'email' => 'segreteria@verdi.example.com',
        ]);
        $admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $associazione->id,
        ]);

        $response = $this->actingAs($admin)->get('/configurazioni?section=info');

        $response->assertOk();
        $response->assertSee('Dati anagrafici');
        $response->assertSee('value="Polisportiva Verdi"', false);
        $response->assertSee('value="segreteria@verdi.example.com"', false);
    }

    public function test_sezione_sconosciuta_ricade_sui_dati_anagrafici(): void
    {
        $associazione = Associazione::factory()->create();
        $admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $associazione->id,
        ]);

        $response = $this->actingAs($admin)->get('/configurazioni?section=inesistente');

        $response->assertOk();
        // Fallback sulla sezione info: il form anagrafica è visibile
        $response->assertSee('Salva dati anagrafici');
    }
}
