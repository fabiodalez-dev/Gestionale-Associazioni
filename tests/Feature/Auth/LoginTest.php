<?php

namespace Tests\Feature\Auth;

use App\Models\Associazione;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pagina_di_login_viene_mostrata(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Accedi al pannello di gestione');
    }

    public function test_login_con_credenziali_valide(): void
    {
        $associazione = Associazione::factory()->create();
        $utente = Utente::factory()->create([
            'associazione_id' => $associazione->id,
            'email' => 'admin@example.com',
            'password_hash' => 'password123',
        ]);

        $response = $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($utente);

        // Il listener imposta il contesto tenant in sessione e aggiorna last_login
        $this->assertSame($associazione->id, session('associazione_id'));
        $this->assertSame($associazione->nome, session('associazione_nome'));
        $this->assertNotNull($utente->fresh()->last_login);
    }

    public function test_login_super_admin_senza_associazione(): void
    {
        $utente = Utente::factory()->superAdmin()->create([
            'email' => 'super@example.com',
            'password_hash' => 'password123',
        ]);

        $response = $this->post('/login', [
            'email' => 'super@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($utente);
        $this->assertNull(session('associazione_id'));
    }

    public function test_login_con_password_errata(): void
    {
        Utente::factory()->create([
            'email' => 'admin@example.com',
            'password_hash' => 'password123',
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'password-sbagliata',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_con_email_inesistente_da_lo_stesso_errore_generico(): void
    {
        $response = $this->from('/login')->post('/login', [
            'email' => 'nessuno@example.com',
            'password' => 'qualsiasi',
        ]);

        $response->assertSessionHasErrors(['email' => 'Credenziali non valide.']);
        $this->assertGuest();
    }

    public function test_login_account_disattivato(): void
    {
        Utente::factory()->disattivo()->create([
            'email' => 'admin@example.com',
            'password_hash' => 'password123',
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'password123',
        ]);

        $response->assertSessionHasErrors(['email' => 'Il tuo account è stato disattivato.']);
        $this->assertGuest();
    }

    public function test_rate_limiting_blocca_il_sesto_tentativo_nel_minuto(): void
    {
        RateLimiter::clear('login');

        Utente::factory()->create([
            'email' => 'admin@example.com',
            'password_hash' => 'password123',
        ]);

        foreach (range(1, 5) as $tentativo) {
            $this->post('/login', [
                'email' => 'admin@example.com',
                'password' => 'password-sbagliata',
            ])->assertRedirect();
        }

        $response = $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'password-sbagliata',
        ]);

        $response->assertStatus(429);
    }

    public function test_il_rate_limit_e_per_combinazione_email_e_ip(): void
    {
        foreach (range(1, 5) as $tentativo) {
            $this->post('/login', [
                'email' => 'primo@example.com',
                'password' => 'password-sbagliata',
            ]);
        }

        // Email diversa, stesso IP: non deve essere bloccata
        $response = $this->post('/login', [
            'email' => 'secondo@example.com',
            'password' => 'password-sbagliata',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
    }

    public function test_logout(): void
    {
        $utente = Utente::factory()->create();

        $response = $this->actingAs($utente)->post('/logout');

        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_utente_autenticato_viene_rediretto_dalla_login(): void
    {
        $utente = Utente::factory()->create();

        $this->actingAs($utente)
            ->get('/login')
            ->assertRedirect();
    }
}
