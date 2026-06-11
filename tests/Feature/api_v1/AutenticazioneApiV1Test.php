<?php

namespace Tests\Feature\api_v1;

use App\Models\ApiKey;
use App\Models\Associazione;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ApiKey::query()->where('api_key', $this->chiave)->update(['attiva' => false]);

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$this->chiave])
            ->assertStatus(403)
            ->assertJsonPath('code', 'key_disabled');
    }

    public function test_chiave_scaduta_restituisce_403_key_expired(): void
    {
        ApiKey::query()->where('api_key', $this->chiave)->update(['scadenza' => now()->subDay()->toDateString()]);

        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$this->chiave])
            ->assertStatus(403)
            ->assertJsonPath('code', 'key_expired');
    }

    public function test_chiave_valida_il_giorno_stesso_della_scadenza(): void
    {
        ApiKey::query()->where('api_key', $this->chiave)->update(['scadenza' => now()->toDateString()]);

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

        $this->assertNotNull(ApiKey::query()->where('api_key', $this->chiave)->value('ultimo_utilizzo'));
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
}
