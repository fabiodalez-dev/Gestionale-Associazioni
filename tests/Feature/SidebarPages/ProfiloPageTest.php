<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfiloPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/profilo')->assertRedirect('/login');
    }

    public function test_utente_autenticato_vede_la_pagina_con_il_titolo(): void
    {
        $utente = Utente::factory()->adminAssociazione()->create();

        $this->actingAs($utente)
            ->get('/profilo')
            ->assertOk()
            ->assertSee('Il mio profilo');
    }

    public function test_la_pagina_e_accessibile_a_tutti_i_ruoli(): void
    {
        $this->actingAs(Utente::factory()->operatore()->create())
            ->get('/profilo')
            ->assertOk();

        $this->actingAs(Utente::factory()->superAdmin()->create())
            ->get('/profilo')
            ->assertOk();
    }

    public function test_mostra_i_dati_dell_utente_corrente_e_non_quelli_altrui(): void
    {
        $utente = Utente::factory()->adminAssociazione()->create([
            'nome' => 'Riccardo',
            'cognome' => 'Montaldi',
            'email' => 'riccardo.montaldi@example.com',
        ]);
        Utente::factory()->adminAssociazione()->create([
            'nome' => 'Altro',
            'cognome' => 'Estraneo',
            'email' => 'altro.estraneo@example.com',
        ]);

        $response = $this->actingAs($utente)->get('/profilo');

        $response->assertOk();
        $response->assertSee('Riccardo Montaldi');
        $response->assertSee('riccardo.montaldi@example.com');
        $response->assertDontSee('Altro Estraneo');
        $response->assertDontSee('altro.estraneo@example.com');
    }

    public function test_cambio_password_con_conferma_non_corrispondente_viene_rifiutato(): void
    {
        $utente = Utente::factory()->create(['password_hash' => 'vecchia-password']);

        $this->actingAs($utente)->put('/user/password', [
            'current_password' => 'vecchia-password',
            'password' => 'nuova-password-123',
            'password_confirmation' => 'password-diversa-456',
        ])->assertSessionHasErrorsIn('updatePassword', ['password']);

        $this->assertTrue(Hash::check('vecchia-password', $utente->fresh()->password_hash));
    }
}
