<?php

namespace Tests\Feature\api_v1;

use App\Models\ApiKey;
use App\Models\Associazione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssociazioniApiTest extends TestCase
{
    use RefreshDatabase;

    private string $chiaveScopata;

    private string $chiaveGlobale;

    private Associazione $associazione;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create(['attiva' => true]);
        $this->chiaveScopata = str_repeat('1a2b', 16);
        $this->chiaveGlobale = str_repeat('3c4d', 16);

        ApiKey::create([
            'associazione_id' => $this->associazione->id,
            'api_key' => $this->chiaveScopata,
            'nome' => 'Scopata',
            'permessi' => ['*'],
            'attiva' => true,
        ]);

        ApiKey::create([
            'associazione_id' => null,
            'api_key' => $this->chiaveGlobale,
            'nome' => 'Globale',
            'permessi' => ['*'],
            'attiva' => true,
        ]);
    }

    public function test_chiave_scopata_riceve_403_global_only(): void
    {
        $this->getJson('/api/v1/associazioni', ['Authorization' => 'Bearer '.$this->chiaveScopata])
            ->assertStatus(403)
            ->assertJsonPath('code', 'global_only');
    }

    public function test_chiave_globale_riceve_le_associazioni_attive_ordinate(): void
    {
        Associazione::factory()->create(['attiva' => true, 'nome' => 'AAA Prima']);
        Associazione::factory()->create(['attiva' => false, 'nome' => 'Disattivata']);

        $response = $this->getJson('/api/v1/associazioni', ['Authorization' => 'Bearer '.$this->chiaveGlobale]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 2)
            ->assertJsonPath('associazioni.0.nome', 'AAA Prima');

        $nomi = array_column($response->json('associazioni'), 'nome');
        $this->assertNotContains('Disattivata', $nomi);
    }

    public function test_endpoint_richiede_autenticazione(): void
    {
        $this->getJson('/api/v1/associazioni')
            ->assertStatus(401)
            ->assertJsonPath('code', 'auth_missing');
    }
}
