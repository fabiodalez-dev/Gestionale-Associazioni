<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureSuperAdmin2fa;
use App\Models\Utente;
use App\Services\Update\UpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * 2FA obbligatoria per il super admin (EnsureSuperAdmin2fa): senza 2FA
 * confermata raggiunge solo profilo, rotte 2FA, conferma password e logout.
 */
class SuperAdmin2faTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nei test il default è spento: qui si verifica il comportamento acceso
        config(['sicurezza.super_admin_2fa_obbligatoria' => true]);
    }

    private function superAdmin(bool $con2fa = false): Utente
    {
        $utente = Utente::factory()->superAdmin()->create(['password_hash' => 'password-attuale']);

        if ($con2fa) {
            $utente->forceFill([
                'two_factor_secret' => encrypt('SEGRETO2FA'),
                'two_factor_recovery_codes' => encrypt(json_encode(['codice-1'])),
                'two_factor_confirmed_at' => now(),
            ])->save();
        }

        return $utente;
    }

    public function test_super_admin_senza_2fa_viene_rediretto_al_profilo(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/dashboard')
            ->assertRedirect(route('profile.show'));
    }

    public function test_super_admin_senza_2fa_riceve_403_sulle_richieste_json(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/dashboard')
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'due fattori'));
    }

    public function test_2fa_avviata_ma_non_confermata_resta_bloccata(): void
    {
        $utente = $this->superAdmin();
        $utente->forceFill(['two_factor_secret' => encrypt('SEGRETO2FA')])->save();

        $this->actingAs($utente)->get('/dashboard')->assertRedirect(route('profile.show'));
    }

    public function test_super_admin_con_2fa_passa(): void
    {
        $risposta = $this->actingAs($this->superAdmin(con2fa: true))->get('/dashboard');

        $this->assertNotSame(route('profile.show'), $risposta->headers->get('Location'));
    }

    public function test_admin_associazione_senza_2fa_non_e_toccato(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create();

        $risposta = $this->actingAs($admin)->get('/dashboard');

        $this->assertNotSame(route('profile.show'), $risposta->headers->get('Location'));
    }

    public function test_flag_spento_disattiva_il_blocco(): void
    {
        config(['sicurezza.super_admin_2fa_obbligatoria' => false]);

        $risposta = $this->actingAs($this->superAdmin())->get('/dashboard');

        $this->assertNotSame(route('profile.show'), $risposta->headers->get('Location'));
    }

    public function test_pagina_di_attivazione_raggiungibile_con_avviso(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/profilo')
            ->assertOk()
            ->assertSee('Attiva 2FA')
            ->assertSee('obbligatoria', false);
    }

    public function test_avviso_assente_quando_la_2fa_e_attiva(): void
    {
        $this->actingAs($this->superAdmin(con2fa: true))
            ->get('/profilo')
            ->assertOk()
            ->assertDontSee('autenticazione a due fattori è obbligatoria');
    }

    public function test_attivazione_completa_dal_super_admin_bloccato(): void
    {
        $utente = $this->superAdmin();

        // Conferma password, avvio 2FA e conferma del codice restano raggiungibili
        $this->actingAs($utente)
            ->post('/user/confirm-password', ['password' => 'password-attuale'])
            ->assertSessionHasNoErrors();
        $this->actingAs($utente)
            ->post('/user/two-factor-authentication')
            ->assertRedirect();
        $this->actingAs($utente)->get('/user/two-factor-qr-code')->assertOk();

        $utente->refresh();
        $codice = app(Google2FA::class)->getCurrentOtp(decrypt($utente->two_factor_secret));
        $this->actingAs($utente)
            ->post('/user/confirmed-two-factor-authentication', ['code' => $codice])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($utente->fresh()->two_factor_confirmed_at);
        $risposta = $this->actingAs($utente->fresh())->get('/dashboard');
        $this->assertNotSame(route('profile.show'), $risposta->headers->get('Location'));
    }

    public function test_logout_raggiungibile(): void
    {
        $this->actingAs($this->superAdmin())->post('/logout')->assertRedirect();

        $this->assertGuest('web');
    }

    public function test_rotte_api_non_toccate(): void
    {
        // Le rotte REST (gruppo api, token Bearer) non passano dal blocco
        $router = app('router');
        $rotta = $router->getRoutes()->match(Request::create('/api/v1/associazioni', 'GET'));

        $this->assertNotContains(EnsureSuperAdmin2fa::class, $router->gatherRouteMiddleware($rotta));
        $this->assertContains(EnsureSuperAdmin2fa::class, $router->gatherRouteMiddleware(
            $router->getRoutes()->match(Request::create('/dashboard', 'GET')),
        ));
    }

    public function test_rotte_web_sotto_api_con_sessione_restano_bloccate(): void
    {
        // api/utenti-search è una rotta del gruppo web (sessione): non è un'API
        $this->actingAs($this->superAdmin())
            ->getJson('/api/utenti-search?q=mario')
            ->assertForbidden();
    }

    public function test_aggiornamenti_raggiungibili_durante_un_aggiornamento_in_corso(): void
    {
        $this->mock(UpdateService::class, function ($mock) {
            $mock->shouldReceive('inCorso')->andReturn(true);
            $mock->shouldReceive('inManutenzione')->andReturn(true);
            $mock->shouldIgnoreMissing();
        });

        $risposta = $this->actingAs($this->superAdmin())->getJson('/aggiornamenti/stato');

        $this->assertStringNotContainsString('due fattori', (string) $risposta->getContent());
    }

    public function test_aggiornamenti_bloccati_senza_aggiornamento_in_corso(): void
    {
        $this->mock(UpdateService::class, function ($mock) {
            $mock->shouldReceive('inCorso')->andReturn(false);
            $mock->shouldReceive('inManutenzione')->andReturn(false);
        });

        $this->actingAs($this->superAdmin())
            ->get('/aggiornamenti')
            ->assertRedirect(route('profile.show'));
    }

    public function test_installer_non_toccato(): void
    {
        // Nei test l'installer risponde 404 se l'app risulta installata: basta
        // che il blocco 2FA non intervenga prima con il redirect al profilo
        $risposta = $this->actingAs($this->superAdmin())->get('/install');

        $this->assertNotSame(route('profile.show'), $risposta->headers->get('Location'));
    }

    public function test_comando_azzera_la_2fa(): void
    {
        $utente = $this->superAdmin(con2fa: true);

        $this->artisan('admin:recupera-accesso', ['email' => $utente->email, '--azzera-2fa' => true])
            ->expectsOutputToContain('azzerata')
            ->assertSuccessful();

        $utente->refresh();
        $this->assertNull($utente->two_factor_secret);
        $this->assertNull($utente->two_factor_recovery_codes);
        $this->assertNull($utente->two_factor_confirmed_at);

        // Al login successivo torna alla pagina di attivazione, non resta fuori
        $this->actingAs($utente)->get('/dashboard')->assertRedirect(route('profile.show'));
        $this->actingAs($utente)->get('/profilo')->assertOk();
    }

    public function test_comando_azzera_2fa_senza_2fa_attiva(): void
    {
        $utente = $this->superAdmin();

        $this->artisan('admin:recupera-accesso', ['email' => $utente->email, '--azzera-2fa' => true])
            ->expectsOutputToContain('niente da azzerare')
            ->assertSuccessful();
    }
}
