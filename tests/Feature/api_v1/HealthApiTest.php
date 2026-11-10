<?php

namespace Tests\Feature\api_v1;

use App\Models\ApiKey;
use App\Models\Associazione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /api/v1/health (#75): stato pubblico senza dati sensibili, e con una
 * credenziale i suoi permessi effettivi e l'associazione a cui è vincolata.
 */
class HealthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_stato_pubblico_con_le_sole_versioni(): void
    {
        $risposta = $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('stato', 'ok')
            ->assertJsonPath('versione_api', 'v1')
            ->assertJsonPath('versione_app', trim((string) file_get_contents(base_path('VERSION'))));

        $this->assertSame(['success', 'stato', 'versione_app', 'versione_api', 'ora'], array_keys($risposta->json()));
    }

    public function test_con_la_chiave_mostra_permessi_e_associazione(): void
    {
        $associazione = Associazione::factory()->create(['nome' => 'APS Faro', 'attiva' => true]);
        $chiave = str_repeat('cd34', 16);
        ApiKey::create([
            'associazione_id' => $associazione->id,
            'api_key' => $chiave,
            'nome' => 'App esterna',
            'permessi' => ['soci:read', 'tessere:read'],
            'attiva' => true,
        ]);

        $this->getJson('/api/v1/health/credenziale', ['Authorization' => 'Bearer '.$chiave])
            ->assertOk()
            ->assertJsonPath('credenziale.tipo', 'api_key')
            ->assertJsonPath('credenziale.permessi', ['soci:read', 'tessere:read'])
            ->assertJsonPath('credenziale.globale', false)
            ->assertJsonPath('credenziale.associazione.nome', 'APS Faro');
    }

    public function test_senza_credenziale_il_dettaglio_risponde_401(): void
    {
        $this->getJson('/api/v1/health/credenziale')
            ->assertStatus(401)
            ->assertJsonPath('code', 'auth_missing');
    }
}
