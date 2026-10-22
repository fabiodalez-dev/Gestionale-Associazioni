<?php

namespace Tests\Feature\Admin;

use App\Models\UpdateLog;
use App\Models\Utente;
use App\Services\Sistema\EnvEditor;
use App\Services\Update\UpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Pagina /aggiornamenti: accesso solo super_admin, controllo versione via
 * GitHub, requisiti, avvio dell'update (release o pacchetto caricato),
 * stato per il polling, sblocco manutenzione, token.
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
        $this->actingAs($admin)->getJson('/aggiornamenti/stato')->assertForbidden();
        $this->actingAs($admin)->post('/aggiornamenti/sblocca-manutenzione')->assertForbidden();
    }

    public function test_super_admin_vede_la_pagina_con_versione_requisiti_e_stato(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                'tag_name' => 'v0.1.0', 'body' => 'note', 'html_url' => 'https://github.com/x/y/releases/tag/v0.1.0', 'assets' => [],
            ], 200),
        ]);

        $super = Utente::factory()->superAdmin()->create();

        $this->actingAs($super)->get('/aggiornamenti')
            ->assertOk()
            ->assertSee('Aggiornamenti')
            ->assertSee('Versione installata')
            ->assertSee('Requisiti per aggiornare')
            ->assertSee('Prova di scrittura')
            ->assertSee('Aggiornamento da pacchetto')
            ->assertSee('aggiornamenti.js');
    }

    public function test_aggiornamento_disponibile_senza_pacchetto_non_mostra_il_pulsante(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(['tag_name' => 'v99.0.0', 'body' => '', 'html_url' => '', 'assets' => []], 200),
        ]);
        $super = Utente::factory()->superAdmin()->create();

        $this->actingAs($super)->get('/aggiornamenti')
            ->assertOk()
            ->assertSee('Aggiornamento disponibile: v99.0.0')
            ->assertSee('non ha il pacchetto installabile')
            ->assertDontSee('id="btnAggiorna"', false);
    }

    public function test_controlla_rileva_una_versione_piu_recente(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(['tag_name' => 'v99.0.0', 'body' => '', 'html_url' => '', 'assets' => [
                ['name' => 'gestionale-associazioni-v99.0.0.zip', 'browser_download_url' => 'https://github.com/x/p.zip', 'digest' => 'sha256:'.str_repeat('a', 64)],
            ]], 200),
        ]);

        $super = Utente::factory()->superAdmin()->create();

        $this->actingAs($super)->getJson('/aggiornamenti/controlla')
            ->assertOk()
            ->assertJson(['disponibile' => true, 'ultima' => '99.0.0', 'pacchetto' => true]);
    }

    public function test_stato_per_il_polling(): void
    {
        $super = Utente::factory()->superAdmin()->create();
        UpdateLog::create(['from_version' => '0.1.0', 'to_version' => '0.2.0', 'stato' => UpdateLog::STATO_FALLITO, 'messaggio_errore' => 'boom', 'completato_at' => now()]);

        $this->actingAs($super)->getJson('/aggiornamenti/stato')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson(['in_corso' => false, 'ultimo' => ['stato' => 'fallito', 'messaggio_errore' => 'boom']]);
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

    public function test_update_riuscito_risponde_in_json_e_registra_il_log(): void
    {
        $super = Utente::factory()->superAdmin()->create();

        $mock = Mockery::mock(UpdateService::class);
        $mock->shouldReceive('selfUpdateAbilitato')->andReturnTrue();
        $mock->shouldReceive('applicaAggiornamento')->once()
            ->withArgs(fn (string $v, ?Utente $u = null, ?string $p = null) => $v === '0.2.0' && $u?->is($super) && $p === null)
            ->andReturnUsing(function (string $v, Utente $u) {
                $log = UpdateLog::create([
                    'from_version' => '0.1.0', 'to_version' => $v,
                    'stato' => UpdateLog::STATO_COMPLETATO, 'eseguito_da' => $u->id,
                    'completato_at' => now(),
                ]);

                return ['success' => true, 'error' => null, 'log_id' => $log->id, 'versione' => $v];
            });
        $this->app->instance(UpdateService::class, $mock);

        $this->actingAs($super)
            ->postJson('/aggiornamenti/applica', ['versione' => '0.2.0'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('update_logs', ['to_version' => '0.2.0', 'stato' => 'completato']);
    }

    public function test_update_fallito_risponde_422_e_in_form_classico_redirige_con_errore(): void
    {
        $super = Utente::factory()->superAdmin()->create();

        $mock = Mockery::mock(UpdateService::class);
        $mock->shouldReceive('selfUpdateAbilitato')->andReturnTrue();
        $mock->shouldReceive('applicaAggiornamento')->twice()
            ->andReturn(['success' => false, 'error' => 'Requisiti non soddisfatti: PHP', 'log_id' => null, 'versione' => null]);
        $this->app->instance(UpdateService::class, $mock);

        $this->actingAs($super)->postJson('/aggiornamenti/applica', ['versione' => '0.2.0'])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Requisiti non soddisfatti: PHP']);

        $this->actingAs($super)->post('/aggiornamenti/applica', ['versione' => '0.2.0'])
            ->assertRedirect(route('aggiornamenti.index'))
            ->assertSessionHasErrors('update');
    }

    public function test_self_update_disattivato_blocca_la_pagina_web(): void
    {
        config(['update.self_update_enabled' => false]);
        $super = Utente::factory()->superAdmin()->create();

        $this->actingAs($super)->postJson('/aggiornamenti/applica', ['versione' => '0.2.0'])
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_pacchetto_caricato_viene_passato_all_updater_e_poi_cancellato(): void
    {
        $super = Utente::factory()->superAdmin()->create();
        $percorsoVisto = null;

        $mock = Mockery::mock(UpdateService::class);
        $mock->shouldReceive('selfUpdateAbilitato')->andReturnTrue();
        $mock->shouldReceive('applicaAggiornamento')->once()
            ->andReturnUsing(function (string $v, ?Utente $u = null, ?string $p = null) use (&$percorsoVisto) {
                $percorsoVisto = $p;
                $this->assertFileExists((string) $p);

                return ['success' => true, 'error' => null, 'log_id' => null, 'versione' => '0.2.0'];
            });
        $this->app->instance(UpdateService::class, $mock);

        $file = UploadedFile::fake()->createWithContent('gestionale-associazioni-v0.2.0.zip', "PK\x03\x04finto");

        $this->actingAs($super)->post('/aggiornamenti/carica', ['pacchetto' => $file], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonFragment(['success' => true]);

        $this->assertNotNull($percorsoVisto);
        $this->assertStringStartsWith(storage_path('app/updates'), (string) $percorsoVisto);
        $this->assertFileDoesNotExist((string) $percorsoVisto);
    }

    public function test_pacchetto_caricato_deve_essere_uno_zip(): void
    {
        $super = Utente::factory()->superAdmin()->create();

        $this->actingAs($super)->post('/aggiornamenti/carica', ['pacchetto' => UploadedFile::fake()->create('x.txt', 10)], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['pacchetto']]);

        // Senza AJAX: redirect con errore, come un form classico
        $this->actingAs($super)->post('/aggiornamenti/carica', ['pacchetto' => UploadedFile::fake()->create('x.txt', 10)])
            ->assertRedirect(route('aggiornamenti.index'))
            ->assertSessionHasErrors('pacchetto');
    }

    public function test_sblocco_manutenzione_scaduta(): void
    {
        $super = Utente::factory()->superAdmin()->create();

        $mock = Mockery::mock(UpdateService::class);
        $mock->shouldReceive('sbloccaManutenzione')->once()->andReturnTrue();
        $this->app->instance(UpdateService::class, $mock);

        $this->actingAs($super)->post('/aggiornamenti/sblocca-manutenzione')
            ->assertRedirect(route('aggiornamenti.index'))
            ->assertSessionHas('success');
    }

    public function test_token_github_viene_scritto_nel_env(): void
    {
        $super = Utente::factory()->superAdmin()->create();

        $env = Mockery::mock(EnvEditor::class);
        $env->shouldReceive('isWritable')->andReturnTrue();
        $env->shouldReceive('write')->once()->with(['UPDATE_GITHUB_TOKEN' => 'ghp_abc123']);
        $this->app->instance(EnvEditor::class, $env);

        $this->actingAs($super)->post('/aggiornamenti/token', ['token' => 'ghp_abc123'])
            ->assertRedirect(route('aggiornamenti.index'))
            ->assertSessionHas('success');

        $this->actingAs($super)->post('/aggiornamenti/token', ['token' => 'non valido!'])
            ->assertSessionHasErrors('token');
    }

    public function test_download_backup_solo_tra_quelli_elencati(): void
    {
        $super = Utente::factory()->superAdmin()->create();
        $file = sys_get_temp_dir().'/backup_prova_'.uniqid().'.sql.gz';
        File::put($file, 'x');

        $mock = Mockery::mock(UpdateService::class);
        $mock->shouldReceive('elencoBackup')->andReturn([['nome' => basename($file), 'percorso' => $file, 'byte' => 1, 'data' => time()]]);
        $this->app->instance(UpdateService::class, $mock);

        try {
            $this->actingAs($super)->get('/aggiornamenti/backup/'.basename($file))->assertOk();
            $this->actingAs($super)->get('/aggiornamenti/backup/altro.zip')->assertNotFound();
        } finally {
            @unlink($file);
        }
    }

    public function test_lock_impedisce_update_concorrenti(): void
    {
        $super = Utente::factory()->superAdmin()->create();
        $lock = base_path('storage/framework/aggiornamento.lock');
        File::ensureDirectoryExists(dirname($lock));
        $h = fopen($lock, 'c');
        flock($h, LOCK_EX | LOCK_NB);

        try {
            $esito = app(UpdateService::class)->applicaAggiornamento('0.2.0', $super);
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }

        $this->assertFalse($esito['success']);
        $this->assertStringContainsString('già in corso', (string) $esito['error']);
    }
}
