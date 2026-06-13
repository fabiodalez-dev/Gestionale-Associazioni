<?php

namespace Tests\Feature\Admin;

use App\Models\UpdateLog;
use App\Models\Utente;
use App\Services\Update\UpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Pagina /aggiornamenti: accesso solo super_admin, controllo versione via
 * GitHub, validazione e gating dell'azione di update.
 */
class UpdatePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/aggiornamenti')->assertRedirect('/login');
    }

    public function test_admin_associazione_non_super_admin_riceve_403(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create();

        $this->actingAs($admin)->get('/aggiornamenti')->assertForbidden();
    }

    public function test_super_admin_vede_la_pagina_con_versione_e_stato(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                'tag_name' => 'v0.1.0',
                'body' => 'note',
                'html_url' => 'https://github.com/x/y/releases/tag/v0.1.0',
            ], 200),
        ]);

        $super = Utente::factory()->superAdmin()->create();

        $this->actingAs($super)->get('/aggiornamenti')
            ->assertOk()
            ->assertSee('Aggiornamenti')
            ->assertSee('Versione installata');
    }

    public function test_controlla_rileva_una_versione_piu_recente(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(['tag_name' => 'v99.0.0', 'body' => '', 'html_url' => ''], 200),
        ]);

        $super = Utente::factory()->superAdmin()->create();

        $this->actingAs($super)->getJson('/aggiornamenti/controlla')
            ->assertOk()
            ->assertJson(['disponibile' => true, 'ultima' => '99.0.0']);
    }

    public function test_versione_non_valida_viene_rifiutata(): void
    {
        $super = Utente::factory()->superAdmin()->create();

        $this->actingAs($super)
            ->post('/aggiornamenti/applica', ['versione' => 'non-semver'])
            ->assertSessionHasErrors('versione');
    }

    public function test_admin_non_super_non_puo_lanciare_l_update(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create();

        $this->actingAs($admin)
            ->post('/aggiornamenti/applica', ['versione' => '0.2.0'])
            ->assertForbidden();
    }

    public function test_update_riuscito_redirige_con_successo_e_registra_il_log(): void
    {
        $super = Utente::factory()->superAdmin()->create();

        $mock = Mockery::mock(UpdateService::class);
        $mock->shouldReceive('selfUpdateAbilitato')->andReturnTrue();
        $mock->shouldReceive('applicaAggiornamento')->once()
            ->andReturnUsing(function (string $v, Utente $u) {
                $log = UpdateLog::create([
                    'from_version' => '0.1.0', 'to_version' => $v,
                    'stato' => UpdateLog::STATO_COMPLETATO, 'eseguito_da' => $u->id,
                    'completato_at' => now(),
                ]);

                return ['success' => true, 'error' => null, 'log_id' => $log->id];
            });
        $this->app->instance(UpdateService::class, $mock);

        $this->actingAs($super)
            ->post('/aggiornamenti/applica', ['versione' => '0.2.0'])
            ->assertRedirect(route('aggiornamenti.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('update_logs', ['to_version' => '0.2.0', 'stato' => 'completato']);
    }

    public function test_lock_impedisce_update_concorrenti(): void
    {
        $super = Utente::factory()->superAdmin()->create();

        // Acquisisce il lock prima dell'update
        \Illuminate\Support\Facades\Cache::lock('app.update', 1800)->get();

        $esito = app(UpdateService::class)->applicaAggiornamento('0.2.0', $super);

        $this->assertFalse($esito['success']);
        $this->assertStringContainsString('già in corso', (string) $esito['error']);
    }
}
