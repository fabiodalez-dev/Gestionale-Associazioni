<?php

namespace Tests\Feature\api_v1;

use App\Models\ApiKey;
use App\Models\Associazione;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina amministrativa "Chiavi API" + token personali Sanctum.
 */
class ApiKeysUiTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Associazione $altraAssociazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->altraAssociazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    private function creaChiave(?string $associazioneId, string $nome = 'Chiave'): ApiKey
    {
        return ApiKey::create([
            'associazione_id' => $associazioneId,
            // Senza associazione = chiave globale del super admin (flag esplicito)
            'globale' => $associazioneId === null,
            'api_key' => bin2hex(random_bytes(32)),
            'nome' => $nome,
            'permessi' => ['tessere:read'],
            'attiva' => true,
        ]);
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/api-keys')->assertRedirect('/login');
    }

    public function test_admin_vede_solo_le_chiavi_della_propria_associazione(): void
    {
        $this->creaChiave($this->associazione->id, 'Chiave Mia');
        $this->creaChiave($this->altraAssociazione->id, 'Chiave Altrui');
        $this->creaChiave(null, 'Chiave Globale Nascosta');

        $response = $this->actingAs($this->admin)->get('/api-keys');

        $response->assertOk()
            ->assertViewIs('api-v1.api-keys')
            ->assertSee('Chiave Mia')
            ->assertDontSee('Chiave Altrui')
            ->assertDontSee('Chiave Globale Nascosta');
    }

    public function test_super_admin_senza_tenant_vede_tutte_le_chiavi(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $this->creaChiave($this->associazione->id, 'Chiave Tenant A');
        $this->creaChiave($this->altraAssociazione->id, 'Chiave Tenant B');
        $this->creaChiave(null, 'Chiave Globale');

        $this->actingAs($superAdmin)->get('/api-keys')
            ->assertOk()
            ->assertSee('Chiave Tenant A')
            ->assertSee('Chiave Tenant B')
            ->assertSee('Chiave Globale')
            ->assertSee('tutte le associazioni');
    }

    public function test_creazione_chiave_mostra_il_segreto_una_sola_volta(): void
    {
        $response = $this->actingAs($this->admin)->post('/api-keys', [
            'nome' => 'Nuova chiave',
            'permessi' => ['soci:read', 'soci:write'],
        ]);

        $response->assertRedirect(route('api-keys.index'))
            ->assertSessionHas('nuova_chiave');

        $chiave = ApiKey::query()->where('nome', 'Nuova chiave')->first();
        $this->assertNotNull($chiave);
        $this->assertSame($this->associazione->id, $chiave->associazione_id);
        $this->assertSame(['soci:read', 'soci:write'], $chiave->permessi);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', session('nuova_chiave'));

        // In tabella solo hash e prefisso: la chiave in chiaro non è persistita
        $this->assertSame(hash('sha256', session('nuova_chiave')), $chiave->api_key_hash);
        $this->assertSame(substr(session('nuova_chiave'), 0, 8), $chiave->api_key_prefix);
        $this->assertDatabaseMissing('api_keys', ['api_key_hash' => session('nuova_chiave')]);
        $this->assertNull($chiave->api_key);
    }

    public function test_la_lista_mostra_solo_il_prefisso_della_chiave(): void
    {
        $chiave = $this->creaChiave($this->associazione->id, 'Chiave Prefisso');
        $inChiaro = $chiave->api_key;
        $this->assertNotNull($inChiaro);

        $this->actingAs($this->admin)->get('/api-keys')
            ->assertOk()
            ->assertSee(substr($inChiaro, 0, 8))
            ->assertDontSee($inChiaro)
            ->assertDontSee(hash('sha256', $inChiaro));
    }

    public function test_creazione_senza_permessi_usa_il_default_tessere_read(): void
    {
        $this->actingAs($this->admin)->post('/api-keys', ['nome' => 'Default permessi']);

        $this->assertSame(
            ['tessere:read'],
            ApiKey::query()->where('nome', 'Default permessi')->first()->permessi
        );
    }

    public function test_solo_super_admin_puo_creare_chiavi_globali(): void
    {
        // L'admin normale che invia globale=1 crea comunque una chiave scopata
        $this->actingAs($this->admin)->post('/api-keys', ['nome' => 'Finta globale', 'globale' => '1']);
        $this->assertSame(
            $this->associazione->id,
            ApiKey::query()->where('nome', 'Finta globale')->first()->associazione_id
        );

        $superAdmin = Utente::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)->post('/api-keys', ['nome' => 'Vera globale', 'globale' => '1']);
        $this->assertNull(ApiKey::query()->where('nome', 'Vera globale')->first()->associazione_id);
    }

    public function test_toggle_attiva_disattiva_la_chiave(): void
    {
        $chiave = $this->creaChiave($this->associazione->id);

        $this->actingAs($this->admin)->patch('/api-keys/'.$chiave->id.'/toggle')
            ->assertRedirect(route('api-keys.index'));

        $this->assertFalse($chiave->fresh()->attiva);
    }

    public function test_admin_non_puo_toccare_chiavi_di_altri_tenant_o_globali(): void
    {
        $altrui = $this->creaChiave($this->altraAssociazione->id);
        $globale = $this->creaChiave(null);

        $this->actingAs($this->admin)->patch('/api-keys/'.$altrui->id.'/toggle')->assertForbidden();
        $this->actingAs($this->admin)->delete('/api-keys/'.$globale->id)->assertForbidden();

        $this->assertNotNull($globale->fresh());
        $this->assertTrue($altrui->fresh()->attiva);
    }

    public function test_delete_elimina_la_chiave_del_proprio_tenant(): void
    {
        $chiave = $this->creaChiave($this->associazione->id);

        $this->actingAs($this->admin)->delete('/api-keys/'.$chiave->id)
            ->assertRedirect(route('api-keys.index'));

        $this->assertNull(ApiKey::find($chiave->id));
    }

    // ---- Chiavi globali esplicite e tenant senza fallback di sessione (audit run-2) ----

    public function test_la_chiave_globale_del_super_admin_ha_il_flag_esplicito(): void
    {
        $this->actingAs($this->admin)->post('/api-keys', ['nome' => 'Finta globale', 'globale' => '1']);
        $this->assertFalse(ApiKey::query()->where('nome', 'Finta globale')->sole()->globale);

        $superAdmin = Utente::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)->post('/api-keys', ['nome' => 'Vera globale', 'globale' => '1']);
        $vera = ApiKey::query()->where('nome', 'Vera globale')->sole();
        $this->assertTrue($vera->globale);
        $this->assertTrue($vera->isGlobale());

        // Super admin con tenant selezionato e senza opzione globale: chiave scopata
        $this->actingAs($superAdmin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->post('/api-keys', ['nome' => 'Scopata dal super admin']);
        $scopata = ApiKey::query()->where('nome', 'Scopata dal super admin')->sole();
        $this->assertSame($this->associazione->id, $scopata->associazione_id);
        $this->assertFalse($scopata->globale);
    }

    public function test_utente_senza_associazione_non_usa_il_tenant_rimasto_in_sessione(): void
    {
        // Admin scollegato: associazione_id NULL ma sessione con un tenant
        $scollegato = Utente::factory()->adminAssociazione()->create(['associazione_id' => null]);
        $chiave = $this->creaChiave($this->associazione->id, 'Chiave Del Vecchio Tenant');

        $this->actingAs($scollegato)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get('/api-keys')
            ->assertOk()
            ->assertDontSee('Chiave Del Vecchio Tenant');

        $this->actingAs($scollegato)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->post('/api-keys', ['nome' => 'Via sessione'])
            ->assertForbidden();
        $this->assertNull(ApiKey::query()->where('nome', 'Via sessione')->first());

        $this->actingAs($scollegato)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->patch('/api-keys/'.$chiave->id.'/toggle')
            ->assertForbidden();
        $this->actingAs($scollegato)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->delete('/api-keys/'.$chiave->id)
            ->assertForbidden();
        $this->assertTrue($chiave->fresh()->attiva);
    }

    public function test_la_lista_segnala_le_chiavi_senza_associazione_e_non_globali(): void
    {
        ApiKey::create([
            'associazione_id' => null,
            'api_key' => bin2hex(random_bytes(32)),
            'nome' => 'Chiave Orfana',
            'permessi' => ['*'],
            'attiva' => true,
        ]);

        $this->actingAs(Utente::factory()->superAdmin()->create())->get('/api-keys')
            ->assertOk()
            ->assertSee('Chiave Orfana')
            ->assertSee('Senza associazione (non valida)');
    }

    // ---- Token personali Sanctum ----

    public function test_creazione_token_sanctum_mostra_il_token_una_volta(): void
    {
        $response = $this->actingAs($this->admin)->post('/api-tokens', [
            'nome' => 'Token script',
            'abilities' => ['soci:read'],
        ]);

        $response->assertRedirect(route('api-keys.index'))->assertSessionHas('nuovo_token');

        $this->assertSame(1, $this->admin->tokens()->count());
        $this->assertSame(['soci:read'], $this->admin->tokens()->first()->abilities);
    }

    public function test_token_senza_scadenza_riceve_la_scadenza_massima_di_365_giorni(): void
    {
        $this->freezeTime();

        $this->actingAs($this->admin)->post('/api-tokens', [
            'nome' => 'Token senza scadenza',
            'abilities' => ['soci:read'],
        ])->assertSessionHas('nuovo_token');

        $token = $this->admin->tokens()->first();
        $this->assertNotNull($token->expires_at);
        $this->assertSame(
            now()->addDays(365)->endOfDay()->format('Y-m-d H:i:s'),
            $token->expires_at->format('Y-m-d H:i:s')
        );
    }

    public function test_token_con_scadenza_oltre_365_giorni_viene_rifiutato(): void
    {
        $this->actingAs($this->admin)
            ->from('/api-keys')
            ->post('/api-tokens', [
                'nome' => 'Token troppo lungo',
                'abilities' => ['soci:read'],
                'scadenza' => now()->addDays(366)->toDateString(),
            ])
            ->assertRedirect('/api-keys')
            ->assertSessionHasErrors('scadenza');

        $this->assertSame(0, $this->admin->tokens()->count());

        $this->actingAs($this->admin)->post('/api-tokens', [
            'nome' => 'Token un anno',
            'abilities' => ['soci:read'],
            'scadenza' => now()->addDays(365)->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->admin->tokens()->count());
    }

    public function test_revoca_token_elimina_solo_i_propri(): void
    {
        // Prima l'admin non poteva revocare nessun token altrui (redirect
        // silenzioso). Ora l'admin_associazione gestisce i token degli utenti
        // della propria associazione (PersonalAccessTokenPolicy): il limite
        // che resta è il tenant, quindi il test usa un utente di un'altra
        // associazione e si aspetta un 403 esplicito.
        $altroUtente = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->altraAssociazione->id,
        ]);
        $tokenAltrui = $altroUtente->createToken('altrui', ['soci:read']);

        $this->actingAs($this->admin)->delete('/api-tokens/'.$tokenAltrui->accessToken->id)->assertForbidden();

        $this->assertSame(1, $altroUtente->tokens()->count());
    }

    // ------------------------------------------------------------------
    // Revoca dei token altrui da parte degli amministratori
    // ------------------------------------------------------------------

    public function test_utente_revoca_i_propri_token_e_torna_alla_pagina_chiavi(): void
    {
        $token = $this->admin->createToken('mio', ['soci:read']);

        $this->actingAs($this->admin)->delete(route('api-tokens.destroy', $token->accessToken->id))
            ->assertRedirect(route('api-keys.index'))
            ->assertSessionHas('success', 'Token revocato.');

        $this->assertSame(0, $this->admin->tokens()->count());
    }

    public function test_token_inesistente_redirect_come_prima(): void
    {
        $this->actingAs($this->admin)->delete(route('api-tokens.destroy', 999999))
            ->assertRedirect(route('api-keys.index'));
    }

    public function test_super_admin_vede_e_revoca_i_token_di_qualsiasi_utente(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $altroSuper = Utente::factory()->superAdmin()->create();
        $utenteB = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->altraAssociazione->id]);
        $tokenB = $utenteB->createToken('Token tenant B', ['soci:read']);
        $tokenSuper = $altroSuper->createToken('Token altro super', ['*']);
        $this->admin->createToken('Token tenant A', ['soci:read']);

        $this->actingAs($superAdmin)->get(route('api-tokens.index'))
            ->assertOk()
            ->assertViewIs('api-v1.api-tokens')
            ->assertSee('Token tenant A')
            ->assertSee('Token tenant B')
            ->assertSee('Token altro super');

        $this->actingAs($superAdmin)->delete(route('api-tokens.destroy', $tokenB->accessToken->id))
            ->assertRedirect(route('api-tokens.index'))
            ->assertSessionHas('success', 'Token revocato.');
        $this->actingAs($superAdmin)->delete(route('api-tokens.destroy', $tokenSuper->accessToken->id))
            ->assertRedirect(route('api-tokens.index'));

        $this->assertSame(0, $utenteB->tokens()->count());
        $this->assertSame(0, $altroSuper->tokens()->count());
    }

    public function test_admin_associazione_vede_e_revoca_solo_token_della_propria_associazione(): void
    {
        $collega = Utente::factory()->create([
            'ruolo' => Utente::RUOLO_OPERATORE,
            'associazione_id' => $this->associazione->id,
        ]);
        $utenteB = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->altraAssociazione->id]);
        // Super admin agganciato alla stessa associazione: resta intoccabile
        $superAdmin = Utente::factory()->superAdmin()->create(['associazione_id' => $this->associazione->id]);

        $tokenCollega = $collega->createToken('Token collega', ['soci:read']);
        $tokenB = $utenteB->createToken('Token altro tenant', ['soci:read']);
        $tokenSuper = $superAdmin->createToken('Token del super admin', ['*']);

        $this->actingAs($this->admin)->get(route('api-tokens.index'))
            ->assertOk()
            ->assertSee('Token collega')
            ->assertDontSee('Token altro tenant')
            ->assertDontSee('Token del super admin');

        $this->actingAs($this->admin)->delete(route('api-tokens.destroy', $tokenB->accessToken->id))->assertForbidden();
        $this->actingAs($this->admin)->delete(route('api-tokens.destroy', $tokenSuper->accessToken->id))->assertForbidden();
        $this->assertSame(1, $utenteB->tokens()->count());
        $this->assertSame(1, $superAdmin->tokens()->count());

        $this->actingAs($this->admin)->delete(route('api-tokens.destroy', $tokenCollega->accessToken->id))
            ->assertRedirect(route('api-tokens.index'));
        $this->assertSame(0, $collega->tokens()->count());
    }

    public function test_utente_non_admin_non_vede_ne_revoca_token_altrui(): void
    {
        $operatore = Utente::factory()->create([
            'ruolo' => Utente::RUOLO_OPERATORE,
            'associazione_id' => $this->associazione->id,
        ]);
        $tokenAdmin = $this->admin->createToken('Token admin', ['soci:read']);

        $this->actingAs($operatore)->get(route('api-tokens.index'))->assertForbidden();
        $this->actingAs($operatore)->delete(route('api-tokens.destroy', $tokenAdmin->accessToken->id))->assertForbidden();

        $this->assertSame(1, $this->admin->tokens()->count());
    }

    public function test_token_revocato_dall_admin_smette_di_funzionare_sull_api(): void
    {
        $collega = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $token = $collega->createToken('Scanner', ['soci:read']);

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$token->plainTextToken])->assertOk();

        $this->actingAs($this->admin)->delete(route('api-tokens.destroy', $token->accessToken->id));

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$token->plainTextToken])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'auth_invalid');
    }

    public function test_link_alla_pagina_token_solo_per_chi_puo_gestirli(): void
    {
        $this->actingAs($this->admin)->get('/api-keys')->assertOk()->assertSee(route('api-tokens.index'));
    }

    public function test_pagina_documentazione_api_visibile_agli_admin(): void
    {
        $this->actingAs($this->admin)->get('/api-docs')
            ->assertOk()
            ->assertViewIs('api-v1.api-docs')
            ->assertSee('Documentazione API');
    }

    public function test_reference_scramble_accessibile_agli_admin_e_negata_agli_ospiti(): void
    {
        // Gate viewApiDocs: stessa regola della pagina Chiavi API (ApiKeyPolicy::viewAny)
        $this->get('/docs/api')->assertForbidden();

        $this->actingAs($this->admin)->get('/docs/api')->assertOk();

        $superAdmin = Utente::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)->get('/docs/api')->assertOk();
    }

    public function test_reference_scramble_negata_all_operatore(): void
    {
        // Hardening: il gate non si accontenta più di un utente loggato qualsiasi
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)->get('/docs/api')->assertForbidden();
    }

    public function test_la_rotta_di_default_api_user_non_esiste(): void
    {
        // routes/api.php non espone più /api/user (restituiva l'intero record utente)
        $this->actingAs($this->admin)->getJson('/api/user')->assertNotFound();
    }
}
