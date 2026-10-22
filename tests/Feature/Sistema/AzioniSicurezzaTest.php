<?php

namespace Tests\Feature\Sistema;

use App\Models\Utente;
use App\Support\LimiteAccessi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Azioni di sicurezza delle impostazioni di sistema (come nella vecchia app):
 * revoca dei "Ricordami", azzeramento dei tentativi, durata del blocco.
 */
class AzioniSicurezzaTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): Utente
    {
        return Utente::factory()->create(['ruolo' => Utente::RUOLO_SUPER_ADMIN, 'associazione_id' => null]);
    }

    public function test_revoca_tutti_i_ricordami(): void
    {
        $altro = Utente::factory()->create(['remember_token' => 'token-ricordato']);

        $this->actingAs($this->superAdmin())
            ->post(route('impostazioni-sistema.revoca-accessi'))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertNull($altro->fresh()->remember_token);
    }

    public function test_azzera_i_tentativi_di_accesso(): void
    {
        $utente = Utente::factory()->create(['email' => 'bloccato@test.it', 'password_hash' => 'Lanterna-Velluto-42']);
        config(['auth.login_max_attempts' => 2]);

        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['email' => 'bloccato@test.it', 'password' => 'sbagliata']);
        }
        $this->post('/login', ['email' => 'bloccato@test.it', 'password' => 'Lanterna-Velluto-42'])->assertStatus(429);
        $this->assertGuest();

        $versione = LimiteAccessi::versione();
        $this->actingAs($this->superAdmin())->post(route('impostazioni-sistema.azzera-tentativi'))->assertSessionHas('success');
        $this->assertSame($versione + 1, LimiteAccessi::versione());

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post('/login', ['email' => 'bloccato@test.it', 'password' => 'Lanterna-Velluto-42'])->assertRedirect();
        $this->assertAuthenticatedAs($utente);
    }

    public function test_durata_del_blocco_configurabile(): void
    {
        config(['auth.login_lockout_minutes' => 15]);
        $this->assertSame(900, LimiteAccessi::secondiBlocco());

        config(['auth.login_lockout_minutes' => 0]);
        $this->assertSame(60, LimiteAccessi::secondiBlocco(), 'Mai meno di un minuto');

        $this->actingAs($this->superAdmin())->get(route('impostazioni-sistema.index', ['sezione' => 'sicurezza']))
            ->assertOk()->assertSee('AUTH_LOCKOUT_MINUTES')->assertSee('Revoca tutti gli accessi')->assertSee('Azzera i tentativi');
    }

    public function test_solo_il_super_admin(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create();
        $this->actingAs($admin)->post(route('impostazioni-sistema.revoca-accessi'))->assertForbidden();
        $this->actingAs($admin)->post(route('impostazioni-sistema.azzera-tentativi'))->assertForbidden();
        RateLimiter::clear('x');
    }
}
