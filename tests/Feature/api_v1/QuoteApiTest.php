<?php

namespace Tests\Feature\api_v1;

use App\Models\ApiKey;
use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Socio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteApiTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Associazione $altraAssociazione;

    private string $chiave;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->altraAssociazione = Associazione::factory()->create();
        $this->chiave = str_repeat('ee55', 16);

        ApiKey::create([
            'associazione_id' => $this->associazione->id,
            'api_key' => $this->chiave,
            'nome' => 'Chiave quote',
            'permessi' => ['quote:read'],
            'attiva' => true,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->chiave];
    }

    public function test_quote_di_un_socio_ordinate_per_anno_decrescente(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        Quota::factory()->create(['socio_id' => $socio->id, 'associazione_id' => $this->associazione->id, 'anno' => 2024]);
        Quota::factory()->create(['socio_id' => $socio->id, 'associazione_id' => $this->associazione->id, 'anno' => 2026]);

        $this->getJson('/api/v1/quote/'.$socio->id, $this->auth())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('socio.id', $socio->id)
            ->assertJsonCount(2, 'quote')
            ->assertJsonPath('quote.0.anno', 2026)
            ->assertJsonPath('quote.1.anno', 2024);
    }

    public function test_quote_di_socio_di_altro_tenant_restituisce_404(): void
    {
        $altrui = Socio::factory()->create(['associazione_id' => $this->altraAssociazione->id]);

        $this->getJson('/api/v1/quote/'.$altrui->id, $this->auth())
            ->assertStatus(404)
            ->assertJsonPath('code', 'socio_not_found');
    }

    public function test_index_paginata_con_dati_socio_e_isolamento_tenant(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        Quota::factory()->count(2)->create(['socio_id' => $socio->id, 'associazione_id' => $this->associazione->id]);

        $altrui = Socio::factory()->create(['associazione_id' => $this->altraAssociazione->id]);
        Quota::factory()->create(['socio_id' => $altrui->id, 'associazione_id' => $this->altraAssociazione->id]);

        $response = $this->getJson('/api/v1/quote?limit=10', $this->auth());

        $response->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'quote');

        $this->assertSame($socio->cognome, $response->json('quote.0.cognome'));
    }

    public function test_index_filtra_per_anno_e_stato(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        Quota::factory()->create(['socio_id' => $socio->id, 'associazione_id' => $this->associazione->id, 'anno' => 2024]);
        Quota::factory()->pagata()->create(['socio_id' => $socio->id, 'associazione_id' => $this->associazione->id, 'anno' => 2026]);

        $this->getJson('/api/v1/quote?anno=2024', $this->auth())
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->getJson('/api/v1/quote?stato=Pagata', $this->auth())
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('quote.0.stato', 'Pagata');
    }

    public function test_quote_richiede_il_permesso_quote_read(): void
    {
        $chiaveSenzaPermesso = str_repeat('ff66', 16);
        ApiKey::create([
            'associazione_id' => $this->associazione->id,
            'api_key' => $chiaveSenzaPermesso,
            'nome' => 'Solo tessere',
            'permessi' => ['tessere:read'],
            'attiva' => true,
        ]);

        $this->getJson('/api/v1/quote', ['Authorization' => 'Bearer '.$chiaveSenzaPermesso])
            ->assertStatus(403)
            ->assertJsonPath('code', 'permission_denied');
    }
}
