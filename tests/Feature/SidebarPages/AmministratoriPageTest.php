<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmministratoriPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/amministratori')->assertRedirect('/login');
    }

    public function test_super_admin_vede_la_pagina_con_il_titolo(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get('/amministratori')
            ->assertOk()
            ->assertSee('Utenti Amministratori');
    }

    public function test_operatore_riceve_403(): void
    {
        $operatore = Utente::factory()->operatore()->create();

        $this->actingAs($operatore)->get('/amministratori')->assertForbidden();
    }

    public function test_lista_mostra_gli_amministratori_con_email_e_associazione(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $associazione = Associazione::factory()->create(['nome' => 'Bocciofila Arcobaleno']);
        Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $associazione->id,
            'nome' => 'Carla',
            'cognome' => 'Verdellini',
            'email' => 'carla.verdellini@example.com',
        ]);

        $response = $this->actingAs($superAdmin)->get('/amministratori');

        $response->assertOk();
        $response->assertSee('Carla Verdellini');
        $response->assertSee('carla.verdellini@example.com');
        $response->assertSee('Bocciofila Arcobaleno');
    }

    public function test_admin_associazione_non_puo_creare_utenti(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create();

        $this->actingAs($admin)->post('/amministratori', [
            'nome' => 'Abusivo',
            'cognome' => 'Negato',
            'email' => 'abusivo.negato@example.com',
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE,
            'associazione_id' => $admin->associazione_id,
            'password' => 'password123',
        ])->assertForbidden();

        $this->assertDatabaseMissing('utenti', ['email' => 'abusivo.negato@example.com']);
    }
}
