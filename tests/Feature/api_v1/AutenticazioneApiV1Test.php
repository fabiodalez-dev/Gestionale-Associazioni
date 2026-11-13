<?php

namespace Tests\Feature\api_v1;

use App\Http\Middleware\ApiV1\AuthenticateApiV1;
use App\Models\ApiKey;
use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Utente;
use App\Services\ApiV1\ApiClientContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Autenticazione doppia della REST API v1 (modulo api-v1):
 * chiavi legacy api_keys (Bearer 64-hex / X-API-Key) e token Sanctum con abilities.
 */
class AutenticazioneApiV1Test extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private string $chiave;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create(['attiva' => true]);
        $this->chiave = str_repeat('ab12', 16); // 64 hex

        ApiKey::create([
            'associazione_id' => $this->associazione->id,
            'api_key' => $this->chiave,
            'nome' => 'Chiave test',
            'permessi' => ['soci:read'],
            'attiva' => true,
        ]);
    }

    public function test_richiesta_senza_credenziali_restituisce_401_auth_missing(): void
    {
        $this->getJson('/api/v1/soci')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'auth_missing');
    }

    public function test_chiave_inesistente_restituisce_401_auth_invalid(): void
    {
        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.str_repeat('ff', 32)])
            ->assertStatus(401)
            ->assertJsonPath('code', 'auth_invalid');
    }

    public function test_chiave_disattivata_restituisce_403_key_disabled(): void
    {
        ApiKey::query()->perChiave($this->chiave)->update(['attiva' => false]);

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$this->chiave])
            ->assertStatus(403)
            ->assertJsonPath('code', 'key_disabled');
    }

    public function test_chiave_scaduta_restituisce_403_key_expired(): void
    {
        ApiKey::query()->perChiave($this->chiave)->update(['scadenza' => now()->subDay()->toDateString()]);

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$this->chiave])
            ->assertStatus(403)
            ->assertJsonPath('code', 'key_expired');
    }

    public function test_chiave_valida_il_giorno_stesso_della_scadenza(): void
    {
        ApiKey::query()->perChiave($this->chiave)->update(['scadenza' => now()->toDateString()]);

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$this->chiave])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_associazione_disattivata_restituisce_403_association_disabled(): void
    {
        $this->associazione->update(['attiva' => false]);

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$this->chiave])
            ->assertStatus(403)
            ->assertJsonPath('code', 'association_disabled');
    }

    public function test_header_x_api_key_funziona(): void
    {
        $this->getJson('/api/v1/soci', ['X-API-Key' => $this->chiave])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_permesso_mancante_restituisce_403_permission_denied(): void
    {
        // La chiave ha solo soci:read
        $this->getJson('/api/v1/tessere', ['Authorization' => 'Bearer '.$this->chiave])
            ->assertStatus(403)
            ->assertJsonPath('code', 'permission_denied');
    }

    public function test_la_chiave_aggiorna_ultimo_utilizzo(): void
    {
        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$this->chiave])->assertOk();

        $this->assertNotNull(ApiKey::query()->perChiave($this->chiave)->value('ultimo_utilizzo'));
    }

    // ---- Chiavi globali esplicite (audit run-2) ----

    /**
     * Regressione principale: una chiave '*' del tenant T1 non deve diventare
     * globale quando il super admin elimina T1 (prima la FK nullOnDelete la
     * trasformava in una chiave cross-tenant).
     */
    public function test_eliminare_l_associazione_non_trasforma_le_sue_chiavi_in_chiavi_globali(): void
    {
        $t1 = Associazione::factory()->create(['attiva' => true, 'nome' => 'Tenant Uno']);
        $t2 = Associazione::factory()->create(['attiva' => true]);
        $socioT2 = Socio::factory()->create(['associazione_id' => $t2->id, 'cognome' => 'AltroTenant']);
        $chiaveT1 = str_repeat('7e', 32);
        ApiKey::create([
            'associazione_id' => $t1->id,
            'api_key' => $chiaveT1,
            'nome' => 'Tutto T1',
            'permessi' => ['*'],
            'attiva' => true,
        ]);

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$chiaveT1])->assertOk();

        $this->actingAs(Utente::factory()->superAdmin()->create())
            ->delete('/associazioni/'.$t1->id, ['conferma_nome' => 'Tenant Uno'])
            ->assertRedirect(route('associazioni.index'));
        $this->app['auth']->forgetGuards();

        $this->assertDatabaseMissing('api_keys', ['api_key_hash' => ApiKey::hashChiave($chiaveT1)]);
        foreach (['/api/v1/soci', '/api/v1/soci/'.$socioT2->id, '/api/v1/associazioni'] as $url) {
            $this->getJson($url, ['Authorization' => 'Bearer '.$chiaveT1])
                ->assertStatus(401)
                ->assertJsonPath('code', 'auth_invalid')
                ->assertDontSee('AltroTenant');
        }
    }

    public function test_chiave_senza_associazione_e_senza_flag_globale_viene_rifiutata(): void
    {
        // Chiave orfana (es. sopravvissuta a un'associazione eliminata)
        $t2 = Associazione::factory()->create(['attiva' => true]);
        Socio::factory()->create(['associazione_id' => $t2->id, 'cognome' => 'AltroTenant']);
        $orfana = str_repeat('0f', 32);
        ApiKey::create([
            'associazione_id' => null,
            'api_key' => $orfana,
            'nome' => 'Orfana',
            'permessi' => ['*'],
            'attiva' => true,
        ]);

        foreach (['/api/v1/soci', '/api/v1/associazioni'] as $url) {
            $this->getJson($url, ['Authorization' => 'Bearer '.$orfana])
                ->assertStatus(403)
                ->assertJsonPath('code', 'association_disabled')
                ->assertDontSee('AltroTenant');
        }
    }

    public function test_chiave_con_flag_globale_resta_cross_tenant(): void
    {
        $t2 = Associazione::factory()->create(['attiva' => true]);
        Socio::factory()->create(['associazione_id' => $t2->id, 'cognome' => 'AltroTenant']);
        $globale = str_repeat('9a', 32);
        ApiKey::create([
            'associazione_id' => null,
            'globale' => true,
            'api_key' => $globale,
            'nome' => 'Globale',
            'permessi' => ['*'],
            'attiva' => true,
        ]);

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$globale])
            ->assertOk()
            ->assertSee('AltroTenant');
    }

    public function test_il_flag_globale_su_una_chiave_scopata_non_la_rende_globale(): void
    {
        $chiave = ApiKey::query()->perChiave($this->chiave)->firstOrFail();
        $chiave->update(['globale' => true]);

        $this->assertFalse($chiave->fresh()->isGlobale());
        $this->assertTrue($chiave->fresh()->hasPermesso('soci:read'));
        $this->assertFalse($chiave->fresh()->hasPermesso('soci:write'));
    }

    public function test_contesto_api_senza_associazione_e_non_globale_non_si_costruisce(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApiClientContext(associazioneId: null, permessi: ['*']);
    }

    public function test_permessi_della_chiave_e_del_contesto_seguono_la_stessa_regola(): void
    {
        $chiave = new ApiKey(['permessi' => ['soci:*', 'tessere:read']]);
        $contesto = new ApiClientContext(associazioneId: 'x', permessi: ['soci:*', 'tessere:read']);

        foreach (['soci:read', 'soci:write', 'tessere:read', 'tessere:write', 'quote:read'] as $permesso) {
            $this->assertSame($contesto->hasPermesso($permesso), $chiave->hasPermesso($permesso), $permesso);
        }
        $this->assertTrue((new ApiKey(['permessi' => ['*']]))->hasPermesso('quote:write'));
    }

    public function test_token_sanctum_di_super_admin_senza_associazione_resta_globale(): void
    {
        $t2 = Associazione::factory()->create(['attiva' => true]);
        Socio::factory()->create(['associazione_id' => $t2->id, 'cognome' => 'AltroTenant']);
        $token = Utente::factory()->superAdmin()->create(['associazione_id' => null])
            ->createToken('globale', ['soci:read'])->plainTextToken;

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertSee('AltroTenant');
    }

    // ---- Chiavi hashate (hardening audit run-1) ----

    public function test_in_tabella_resta_solo_l_hash_della_chiave_con_il_prefisso(): void
    {
        $this->assertDatabaseHas('api_keys', [
            'api_key_hash' => hash('sha256', $this->chiave),
            'api_key_prefix' => substr($this->chiave, 0, 8),
        ]);
        $this->assertDatabaseMissing('api_keys', ['api_key_hash' => $this->chiave]);

        // Un modello letto dal database non espone la chiave in chiaro
        $letta = ApiKey::query()->perChiave($this->chiave)->firstOrFail();
        $this->assertNull($letta->api_key);
        $this->assertArrayNotHasKey('api_key_hash', $letta->toArray());
    }

    public function test_l_hash_della_chiave_non_autentica(): void
    {
        // Chi legge il database non ottiene una credenziale spendibile
        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.hash('sha256', $this->chiave)])
            ->assertStatus(401)
            ->assertJsonPath('code', 'auth_invalid');
    }

    public function test_chiave_legacy_non_esadecimale_viene_cercata_per_hash(): void
    {
        // Il formato della chiave non è più un prerequisito: conta solo l'hash.
        // Solo il separatore "|" dei token Sanctum devia sull'altro meccanismo.
        ApiKey::create([
            'associazione_id' => $this->associazione->id,
            'api_key' => 'sk_formato_libero_0001',
            'nome' => 'Chiave formato libero',
            'permessi' => ['soci:read'],
            'attiva' => true,
        ]);

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer sk_formato_libero_0001'])->assertOk();
        $this->getJson('/api/v1/soci', ['X-API-Key' => 'sk_formato_libero_0001'])->assertOk();
    }

    // ---- Throttle prima dell'autenticazione ----

    public function test_i_tentativi_con_credenziale_errata_vengono_limitati(): void
    {
        $header = ['Authorization' => 'Bearer '.str_repeat('ff', 32)];

        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/soci', $header)->assertStatus(401);
        }

        $this->getJson('/api/v1/soci', $header)
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited');

        // Un'altra credenziale (valida) dallo stesso IP non è bloccata dal limite per credenziale
        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$this->chiave])->assertOk();
    }

    // ---- Token Sanctum (miglioramento 23) ----

    public function test_token_sanctum_con_ability_accede(): void
    {
        $utente = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $token = $utente->createToken('test', ['soci:read'])->plainTextToken;

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_token_sanctum_senza_ability_restituisce_permission_denied(): void
    {
        $utente = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $token = $utente->createToken('test', ['quote:read'])->plainTextToken;

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('code', 'permission_denied');
    }

    public function test_token_sanctum_con_wildcard_di_categoria(): void
    {
        $utente = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $token = $utente->createToken('test', ['soci:*'])->plainTextToken;

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$token])->assertOk();
    }

    public function test_token_di_operatore_senza_permessi_seedati_segue_il_fallback_per_ruolo_delle_policy(): void
    {
        // Nessun RolesSeeder: hasPermissionTo lancia PermissionDoesNotExist.
        // Come sul web, l'operatore ricade sui soci (SocioPolicy) ma non sulle
        // quote e sulle tessere (QuotaPolicy/TesseraPolicy senza fallback).
        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $token = $operatore->createToken('test', ['soci:read', 'quote:read', 'tessere:read'])->plainTextToken;
        $header = ['Authorization' => 'Bearer '.$token];

        $this->getJson('/api/v1/soci', $header)->assertOk();
        $this->getJson('/api/v1/quote', $header)
            ->assertStatus(403)
            ->assertJsonPath('code', 'permission_denied');
        $this->getJson('/api/v1/tessere', $header)
            ->assertStatus(403)
            ->assertJsonPath('code', 'permission_denied');
    }

    public function test_token_sanctum_invalido_restituisce_401(): void
    {
        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer 123|tokeninventato'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'auth_invalid');
    }

    public function test_token_sanctum_scaduto_restituisce_key_expired(): void
    {
        $utente = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $token = $utente->createToken('test', ['soci:read'], now()->subDay())->plainTextToken;

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('code', 'key_expired');
    }

    public function test_token_con_scadenza_propria_oltre_il_limite_di_sistema_resta_valido(): void
    {
        // Un token con expires_at esplicito (es. preesistente, backfillato a +365
        // giorni dalla migration) non viene tagliato dal limite calcolato su created_at
        $utente = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $token = $utente->createToken('vecchio', ['soci:read'], now()->addDays(400))->plainTextToken;
        PersonalAccessToken::query()->update(['created_at' => now()->subDays(370)]);

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$token])->assertOk();
    }

    public function test_token_sanctum_senza_scadenza_scade_con_il_limite_di_sistema(): void
    {
        // Limite di sistema 365 giorni per i token senza expires_at (quelli
        // preesistenti ricevono expires_at dalla migration di backfill).
        $this->assertSame(525600, config('sanctum.expiration'));

        $utente = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $token = $utente->createToken('eterno', ['soci:read'], null)->plainTextToken;

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$token])->assertOk();

        $this->travel(366)->days();

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('code', 'key_expired');
    }

    public function test_token_di_utente_disattivato_restituisce_key_disabled(): void
    {
        $utente = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
            'attivo' => false,
        ]);
        $token = $utente->createToken('test', ['soci:read'])->plainTextToken;

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('code', 'key_disabled');
    }

    // ---- Catch-all 405 ----

    public function test_metodo_non_supportato_restituisce_405_method_not_allowed(): void
    {
        $this->postJson('/api/v1/tessere', [], ['Authorization' => 'Bearer '.$this->chiave])
            ->assertStatus(405)
            ->assertJsonPath('code', 'method_not_allowed');

        $this->deleteJson('/api/v1/soci', [], ['Authorization' => 'Bearer '.$this->chiave])
            ->assertStatus(405)
            ->assertJsonPath('code', 'method_not_allowed');
    }

    // ------------------------------------------------------------------
    // API fuori dal gruppo web: niente sessione né cookie
    // ------------------------------------------------------------------

    public function test_risposte_api_v1_senza_set_cookie_e_senza_sessione(): void
    {
        $risposte = [
            $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$this->chiave])->assertOk(),
            $this->getJson('/api/v1/soci')->assertStatus(401),
            $this->deleteJson('/api/v1/soci', [], ['Authorization' => 'Bearer '.$this->chiave])->assertStatus(405),
            $this->getJson('/api/v1/tessere/verify?uuid=x'),
        ];

        // Controprova: una pagina web imposta i cookie di sessione e XSRF
        $this->assertNotSame([], $this->get('/login')->headers->getCookies());

        foreach ($risposte as $risposta) {
            $this->assertSame([], $risposta->headers->getCookies(), 'Le risposte /api/v1 non devono impostare cookie.');
            $this->assertFalse($risposta->headers->has('Set-Cookie'));
            // StartSession (gruppo web) aggancerebbe la sessione alla richiesta
            $this->assertFalse($risposta->baseRequest->hasSession(), 'Nessuna sessione per le richieste API.');
            // Header di sicurezza conservati, CSP assente come prima
            $risposta->assertHeader('X-Content-Type-Options', 'nosniff');
            $risposta->assertHeaderMissing('Content-Security-Policy');
        }

    }

    public function test_rotte_api_v1_nel_gruppo_api_con_url_e_nomi_invariati(): void
    {
        $attese = [
            'api.v1.ping' => 'api/v1',
            'api.v1.tessere.verify' => 'api/v1/tessere/verify',
            'api.v1.tessere.search' => 'api/v1/tessere/search',
            'api.v1.tessere.show' => 'api/v1/tessere/{id}',
            'api.v1.tessere.index' => 'api/v1/tessere',
            'api.v1.soci.search' => 'api/v1/soci/search',
            'api.v1.soci.show' => 'api/v1/soci/{id}',
            'api.v1.soci.index' => 'api/v1/soci',
            'api.v1.soci.update' => 'api/v1/soci/{id}',
            'api.v1.quote.perSocio' => 'api/v1/quote/{socio_id}',
            'api.v1.quote.index' => 'api/v1/quote',
            'api.v1.associazioni.index' => 'api/v1/associazioni',
            'api.v1.method-not-allowed' => 'api/v1/{path}',
            'api.v1.eventi.index' => 'api/v1/eventi',
            'api.v1.eventi.show' => 'api/v1/eventi/{id}',
            'api.v1.eventi.checkin' => 'api/v1/eventi/{id}/checkin',
        ];

        foreach ($attese as $nome => $uri) {
            $rotta = app('router')->getRoutes()->getByName($nome);
            $this->assertNotNull($rotta, "Rotta {$nome} mancante.");
            $this->assertSame($uri, $rotta->uri(), "URI di {$nome} cambiato.");

            $middleware = $rotta->gatherMiddleware();
            $this->assertContains('api', $middleware, "{$nome} deve stare nel gruppo api.");
            $this->assertNotContains('web', $middleware, "{$nome} non deve stare nel gruppo web.");

            // Throttle prima dell'autenticazione (anche i tentativi falliti contano)
            $throttle = collect($middleware)->search(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
            $auth = collect($middleware)->search(fn ($m) => is_string($m) && str_starts_with($m, AuthenticateApiV1::class));
            // Il catch-all 405 non ha mai avuto throttle né autenticazione
            if ($nome !== 'api.v1.method-not-allowed') {
                $this->assertNotFalse($throttle, "{$nome} senza throttle.");
            }
            if ($auth !== false) {
                $this->assertLessThan($auth, $throttle, "{$nome}: il throttle deve precedere AuthenticateApiV1.");
            }
        }

        // Le pagine amministrative restano nel gruppo web
        foreach (['api-keys.index', 'api-tokens.index', 'api-tokens.destroy', 'api-docs.index', 'eventi.checkin'] as $nome) {
            $this->assertContains('web', app('router')->getRoutes()->getByName($nome)->gatherMiddleware(), "{$nome} deve restare nel gruppo web.");
        }
    }

    public function test_api_con_app_non_installata_risponde_503_json_e_non_reindirizza(): void
    {
        $dir = sys_get_temp_dir().'/api-v1-installer-'.bin2hex(random_bytes(4));
        mkdir($dir);
        config([
            'installer.enabled' => true,
            'installer.enforce_in_tests' => true,
            'installer.lock_file' => $dir.'/installed.lock',
            'installer.in_progress_file' => $dir.'/installer-in-corso',
        ]);

        try {
            $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$this->chiave])
                ->assertStatus(503)
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 'not_installed');

            $this->assertFileDoesNotExist($dir.'/installed.lock');

            // Database già installato ma lock perso (deploy, installazione da
            // CLI): come EnsureInstalled riscrive il lock e l'API risponde
            Utente::factory()->superAdmin()->create();

            $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$this->chiave])->assertOk();
            $this->assertFileExists($dir.'/installed.lock');
        } finally {
            @unlink($dir.'/installed.lock');
            @rmdir($dir);
        }
    }
}
