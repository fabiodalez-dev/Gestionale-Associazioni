<?php

namespace Tests\Feature\Auth;

use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pagina_profilo_viene_mostrata(): void
    {
        $utente = Utente::factory()->create();

        $response = $this->actingAs($utente)->get('/profilo');

        $response->assertOk();
        $response->assertSee('Cambio password');
        $response->assertSee('Autenticazione a due fattori');
        $response->assertSee('Attiva 2FA');
    }

    public function test_attivazione_2fa_mostra_il_qr_code_da_confermare(): void
    {
        $utente = Utente::factory()->create();

        // La conferma password è richiesta dalle rotte 2FA (confirmPassword => true)
        $this->actingAs($utente)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/two-factor-authentication')
            ->assertRedirect();

        $utente->refresh();
        $this->assertNotNull($utente->two_factor_secret);
        $this->assertNull($utente->two_factor_confirmed_at);

        $response = $this->actingAs($utente)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/profilo');

        $response->assertOk();
        $response->assertSee('Conferma e attiva');
        $response->assertSee('<svg', false);
    }

    public function test_cambio_password_dal_profilo(): void
    {
        $utente = Utente::factory()->create(['password_hash' => 'vecchia-password']);

        $response = $this->actingAs($utente)->put('/user/password', [
            'current_password' => 'vecchia-password',
            'password' => 'nuova-password-123',
            'password_confirmation' => 'nuova-password-123',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('nuova-password-123', $utente->fresh()->password_hash));
    }

    public function test_cambio_password_con_password_attuale_errata(): void
    {
        $utente = Utente::factory()->create(['password_hash' => 'vecchia-password']);

        $response = $this->actingAs($utente)->put('/user/password', [
            'current_password' => 'password-sbagliata',
            'password' => 'nuova-password-123',
            'password_confirmation' => 'nuova-password-123',
        ]);

        $response->assertSessionHasErrorsIn('updatePassword', ['current_password']);
        $this->assertTrue(Hash::check('vecchia-password', $utente->fresh()->password_hash));
    }
}
