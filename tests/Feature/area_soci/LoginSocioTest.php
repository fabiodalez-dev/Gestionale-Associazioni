<?php

namespace Tests\Feature\AreaSoci;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginSocioTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pagina_di_login_soci_viene_mostrata(): void
    {
        $response = $this->get(route('area-soci.login'));

        $response->assertOk();
        $response->assertSee('Accedi alla tua area riservata');
        $response->assertSee('Password dimenticata');
    }

    public function test_login_socio_con_credenziali_valide(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci('password123')->create([
            'email' => 'socio@example.com',
        ]);

        $response = $this->post(route('area-soci.login.attempt'), [
            'email' => 'socio@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('area-soci.dashboard'));
        $this->assertAuthenticatedAs($socio, 'socio');
    }

    public function test_login_con_password_errata_da_errore_generico(): void
    {
        Socio::factory()->conAccessoAreaSoci('password123')->create([
            'email' => 'socio@example.com',
        ]);

        $response = $this->from(route('area-soci.login'))->post(route('area-soci.login.attempt'), [
            'email' => 'socio@example.com',
            'password' => 'sbagliata',
        ]);

        $response->assertSessionHasErrors(['email' => 'Credenziali non valide.']);
        $this->assertGuest('socio');
    }

    public function test_socio_senza_password_impostata_non_puo_accedere(): void
    {
        Socio::factory()->create([
            'email' => 'socio@example.com',
            'password_hash' => null,
        ]);

        $response = $this->post(route('area-soci.login.attempt'), [
            'email' => 'socio@example.com',
            'password' => 'qualsiasi',
        ]);

        // Stesso errore generico: niente user-enumeration
        $response->assertSessionHasErrors(['email' => 'Credenziali non valide.']);
        $this->assertGuest('socio');
    }

    public function test_socio_cancellato_non_puo_accedere(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci('password123')->create([
            'email' => 'socio@example.com',
        ]);
        $socio->delete(); // soft delete

        $response = $this->post(route('area-soci.login.attempt'), [
            'email' => 'socio@example.com',
            'password' => 'password123',
        ]);

        $response->assertSessionHasErrors(['email' => 'Credenziali non valide.']);
        $this->assertGuest('socio');
    }

    public function test_rate_limiting_blocca_il_sesto_tentativo(): void
    {
        Socio::factory()->conAccessoAreaSoci('password123')->create([
            'email' => 'throttle@example.com',
        ]);

        foreach (range(1, 5) as $tentativo) {
            $this->post(route('area-soci.login.attempt'), [
                'email' => 'throttle@example.com',
                'password' => 'sbagliata',
            ])->assertRedirect();
        }

        $response = $this->post(route('area-soci.login.attempt'), [
            'email' => 'throttle@example.com',
            'password' => 'sbagliata',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Troppi tentativi',
            session('errors')->first('email')
        );
        $this->assertGuest('socio');
    }

    public function test_il_rate_limit_e_per_combinazione_email_e_ip(): void
    {
        foreach (range(1, 5) as $tentativo) {
            $this->post(route('area-soci.login.attempt'), [
                'email' => 'primo@example.com',
                'password' => 'sbagliata',
            ]);
        }

        // Email diversa, stesso IP: non bloccata
        $response = $this->post(route('area-soci.login.attempt'), [
            'email' => 'secondo@example.com',
            'password' => 'sbagliata',
        ]);

        $response->assertSessionHasErrors(['email' => 'Credenziali non valide.']);
    }

    public function test_login_riuscito_azzera_il_contatore_di_throttle(): void
    {
        Socio::factory()->conAccessoAreaSoci('password123')->create([
            'email' => 'socio@example.com',
        ]);

        $this->post(route('area-soci.login.attempt'), [
            'email' => 'socio@example.com',
            'password' => 'sbagliata',
        ]);

        $this->post(route('area-soci.login.attempt'), [
            'email' => 'socio@example.com',
            'password' => 'password123',
        ])->assertRedirect(route('area-soci.dashboard'));

        $this->assertSame(0, RateLimiter::attempts('area-soci-login|socio@example.com|127.0.0.1'));
    }

    public function test_logout_socio(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();

        $response = $this->actingAs($socio, 'socio')->post(route('area-soci.logout'));

        $response->assertRedirect(route('area-soci.login'));
        $this->assertGuest('socio');
    }

    public function test_socio_autenticato_viene_rediretto_dalla_login(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();

        $this->actingAs($socio, 'socio')
            ->get(route('area-soci.login'))
            ->assertRedirect(route('area-soci.dashboard'));
    }

    public function test_il_logout_socio_non_tocca_la_sessione_admin(): void
    {
        // Guard separate: il problema del legacy (sessione condivisa) non esiste
        $associazione = Associazione::factory()->create();
        $admin = Utente::factory()->create(['associazione_id' => $associazione->id]);
        $socio = Socio::factory()->conAccessoAreaSoci()->create(['associazione_id' => $associazione->id]);

        $this->actingAs($admin, 'web')
            ->actingAs($socio, 'socio')
            ->post(route('area-soci.logout'));

        $this->assertGuest('socio');
        $this->assertAuthenticatedAs($admin, 'web');
    }
}
