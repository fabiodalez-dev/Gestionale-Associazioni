<?php

namespace Tests\Feature\Auth;

use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_cambio_password_ruota_il_ricordami_e_chiude_le_altre_sessioni(): void
    {
        $utente = Utente::factory()->create([
            'password_hash' => 'vecchia-password',
            'remember_token' => 'token-ricordami-vecchio',
        ]);
        $altro = Utente::factory()->create();

        // La sessione corrente (id passato nel cookie di sessione) e una
        // sessione dello stesso utente su un altro dispositivo
        $sessioneCorrente = str_repeat('s', 40);
        $this->withCookie($this->app['session']->getName(), $sessioneCorrente);
        DB::table('sessions')->insert([
            ['id' => $sessioneCorrente, 'user_id' => $utente->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'altro-dispositivo', 'user_id' => $utente->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'sessione-di-altri', 'user_id' => $altro->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->actingAs($utente)->put('/user/password', [
            'current_password' => 'vecchia-password',
            'password' => 'nuova-password-123',
            'password_confirmation' => 'nuova-password-123',
        ])->assertSessionHasNoErrors();

        $utente->refresh();
        $this->assertTrue(Hash::check('nuova-password-123', $utente->password_hash));
        $this->assertNotSame('token-ricordami-vecchio', $utente->remember_token);
        $this->assertSame(60, strlen($utente->remember_token));

        // L'altro dispositivo è fuori, chi ha cambiato la password resta dentro
        $this->assertDatabaseMissing('sessions', ['id' => 'altro-dispositivo']);
        $this->assertDatabaseHas('sessions', ['id' => $sessioneCorrente]);
        $this->assertDatabaseHas('sessions', ['id' => 'sessione-di-altri']);
        $this->assertAuthenticatedAs($utente);
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
