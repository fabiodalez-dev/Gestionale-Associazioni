<?php

namespace Tests\Feature\Piattaforma;

use App\Listeners\NotificaTentativiFallitiAccesso;
use App\Models\Accesso;
use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Utente;
use App\Notifications\Piattaforma\TentativiAccessoFallitiNotification;
use Illuminate\Auth\Events\Failed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * f.25 — Audit accessi: pagina admin con filtri sulla tabella `accessi`
 * (popolata dal listener RegistraAccesso di Fondamenta) e alert email agli
 * admin dopo 5 tentativi falliti in 10 minuti sulla stessa email (throttled).
 */
class AuditAccessiTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('audit-accessi.index'))->assertRedirect('/login');
    }

    public function test_l_operatore_non_accede_alla_pagina(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)
            ->get(route('audit-accessi.index'))
            ->assertForbidden();
    }

    public function test_l_admin_vede_solo_gli_accessi_del_proprio_tenant(): void
    {
        $altra = Associazione::factory()->create();

        Accesso::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email_tentata' => 'mio@tenant.test',
        ]);
        Accesso::factory()->create([
            'associazione_id' => $altra->id,
            'email_tentata' => 'altrui@tenant.test',
        ]);
        Accesso::factory()->fallito()->create([
            'associazione_id' => null,
            'email_tentata' => 'sconosciuta@nessuno.test',
        ]);

        $response = $this->actingAs($this->admin)->get(route('audit-accessi.index'));

        $response->assertOk();
        $response->assertSee('mio@tenant.test');
        $response->assertDontSee('altrui@tenant.test');
        $response->assertDontSee('sconosciuta@nessuno.test');
    }

    public function test_il_super_admin_in_vista_globale_vede_tutto_incluse_le_righe_senza_tenant(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();

        Accesso::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email_tentata' => 'mio@tenant.test',
        ]);
        Accesso::factory()->fallito()->create([
            'associazione_id' => null,
            'email_tentata' => 'sconosciuta@nessuno.test',
        ]);

        $response = $this->actingAs($superAdmin)->get(route('audit-accessi.index'));

        $response->assertOk();
        $response->assertSee('mio@tenant.test');
        $response->assertSee('sconosciuta@nessuno.test');
    }

    public function test_i_filtri_per_esito_guard_email_e_periodo_funzionano(): void
    {
        Accesso::factory()->create([
            'associazione_id' => $this->associazione->id,
            'guard' => 'web',
            'email_tentata' => 'login-ok@tenant.test',
        ]);
        Accesso::factory()->fallito()->create([
            'associazione_id' => $this->associazione->id,
            'guard' => 'socio',
            'email_tentata' => 'fallito@tenant.test',
        ]);
        $vecchio = Accesso::factory()->create([
            'associazione_id' => $this->associazione->id,
            'guard' => 'web',
            'email_tentata' => 'vecchio@tenant.test',
        ]);
        $vecchio->newQueryWithoutScopes()->getQuery()
            ->where('id', $vecchio->id)
            ->update(['created_at' => now()->subDays(30)]);

        // Filtro esito
        $this->actingAs($this->admin)
            ->get(route('audit-accessi.index', ['esito' => Accesso::ESITO_FALLITO]))
            ->assertOk()
            ->assertSee('fallito@tenant.test')
            ->assertDontSee('login-ok@tenant.test');

        // Filtro guard
        $this->actingAs($this->admin)
            ->get(route('audit-accessi.index', ['guard' => 'socio']))
            ->assertOk()
            ->assertSee('fallito@tenant.test')
            ->assertDontSee('login-ok@tenant.test');

        // Filtro email parziale
        $this->actingAs($this->admin)
            ->get(route('audit-accessi.index', ['email' => 'login-ok']))
            ->assertOk()
            ->assertSee('login-ok@tenant.test')
            ->assertDontSee('fallito@tenant.test');

        // Filtro periodo: esclude la riga di 30 giorni fa
        $this->actingAs($this->admin)
            ->get(route('audit-accessi.index', [
                'dal' => now()->subDay()->toDateString(),
                'al' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('login-ok@tenant.test')
            ->assertDontSee('vecchio@tenant.test');
    }

    public function test_filtri_non_validi_vengono_rifiutati(): void
    {
        $this->actingAs($this->admin)
            ->from(route('dashboard'))
            ->get(route('audit-accessi.index', ['esito' => 'inventato']))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('esito');
    }

    public function test_il_login_fallito_viene_registrato_nella_tabella_accessi(): void
    {
        $this->post('/login', [
            'email' => 'inesistente@nessuno.test',
            'password' => 'password-sbagliata',
        ]);

        $this->assertDatabaseHas('accessi', [
            'email_tentata' => 'inesistente@nessuno.test',
            'esito' => Accesso::ESITO_FALLITO,
            'guard' => 'web',
        ]);
    }

    public function test_cinque_login_falliti_inviano_un_solo_alert_ai_super_admin(): void
    {
        Notification::fake();

        $superAdmin = Utente::factory()->superAdmin()->create();

        foreach (range(1, 5) as $i) {
            $this->post('/login', [
                'email' => 'bersaglio@nessuno.test',
                'password' => 'password-sbagliata-'.$i,
            ]);
        }

        Notification::assertSentToTimes($superAdmin, TentativiAccessoFallitiNotification::class, 1);
    }

    public function test_l_alert_non_parte_sotto_la_soglia(): void
    {
        Notification::fake();

        Utente::factory()->superAdmin()->create();

        foreach (range(1, 3) as $i) {
            $this->post('/login', [
                'email' => 'sotto-soglia@nessuno.test',
                'password' => 'password-sbagliata',
            ]);
        }

        Notification::assertNothingSent();
    }

    public function test_il_listener_conta_solo_i_falliti_recenti_della_stessa_email(): void
    {
        Notification::fake();
        Utente::factory()->superAdmin()->create();

        // 3 recenti della stessa email + 1 vecchio + 1 di un'altra email: sotto soglia
        Accesso::factory()->fallito()->count(3)->create(['email_tentata' => 'mirato@nessuno.test']);
        $vecchio = Accesso::factory()->fallito()->create(['email_tentata' => 'mirato@nessuno.test']);
        $vecchio->newQueryWithoutScopes()->getQuery()
            ->where('id', $vecchio->id)
            ->update(['created_at' => now()->subMinutes(30)]);
        Accesso::factory()->fallito()->create(['email_tentata' => 'altro@nessuno.test']);

        $listener = app(NotificaTentativiFallitiAccesso::class);
        $listener->handle(new Failed('web', null, ['email' => 'mirato@nessuno.test', 'password' => 'x']));

        Notification::assertNothingSent();

        // Un quarto fallito recente: col tentativo corrente si arriva a 5 → alert
        Accesso::factory()->fallito()->create(['email_tentata' => 'mirato@nessuno.test']);
        $listener->handle(new Failed('web', null, ['email' => 'mirato@nessuno.test', 'password' => 'x']));

        Notification::assertSentTimes(TentativiAccessoFallitiNotification::class, 1);
    }

    public function test_l_alert_va_agli_admin_del_tenant_quando_l_utente_bersaglio_e_noto(): void
    {
        Notification::fake();

        $superAdmin = Utente::factory()->superAdmin()->create();
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'socio@tenant.test',
        ]);

        Accesso::factory()->fallito()->count(4)->create([
            'associazione_id' => $this->associazione->id,
            'email_tentata' => 'socio@tenant.test',
        ]);

        app(NotificaTentativiFallitiAccesso::class)
            ->handle(new Failed('socio', $socio, ['email' => 'socio@tenant.test', 'password' => 'x']));

        Notification::assertSentTo($this->admin, TentativiAccessoFallitiNotification::class);
        Notification::assertNotSentTo($superAdmin, TentativiAccessoFallitiNotification::class);
    }

    public function test_l_alert_e_throttled_per_email(): void
    {
        Notification::fake();
        Utente::factory()->superAdmin()->create();

        Accesso::factory()->fallito()->count(6)->create(['email_tentata' => 'martellata@nessuno.test']);

        $listener = app(NotificaTentativiFallitiAccesso::class);
        $evento = new Failed('web', null, ['email' => 'martellata@nessuno.test', 'password' => 'x']);

        $listener->handle($evento);
        $listener->handle($evento);

        Notification::assertSentTimes(TentativiAccessoFallitiNotification::class, 1);
    }
}
