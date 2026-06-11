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

    public function test_revoca_token_elimina_solo_i_propri(): void
    {
        $altroUtente = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $tokenAltrui = $altroUtente->createToken('altrui', ['soci:read']);

        $this->actingAs($this->admin)->delete('/api-tokens/'.$tokenAltrui->accessToken->id);

        $this->assertSame(1, $altroUtente->tokens()->count());
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
        // Gate viewApiDocs (miglioramento 22): solo utenti amministrativi loggati
        $this->get('/docs/api')->assertForbidden();

        $this->actingAs($this->admin)->get('/docs/api')->assertOk();
    }
}
