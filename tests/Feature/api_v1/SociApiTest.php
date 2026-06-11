<?php

namespace Tests\Feature\api_v1;

use App\Models\ApiKey;
use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Tag;
use App\Models\Tessera;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SociApiTest extends TestCase
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
        $this->chiave = str_repeat('cc33', 16);

        ApiKey::create([
            'associazione_id' => $this->associazione->id,
            'api_key' => $this->chiave,
            'nome' => 'Chiave soci',
            'permessi' => ['soci:read', 'soci:write'],
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

    // ---- Lista ----

    public function test_index_paginata_e_isolata_per_tenant(): void
    {
        Socio::factory()->count(3)->create(['associazione_id' => $this->associazione->id]);
        Socio::factory()->create(['associazione_id' => $this->altraAssociazione->id]);

        $this->getJson('/api/v1/soci?limit=2', $this->auth())
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('limit', 2)
            ->assertJsonCount(2, 'soci');
    }

    public function test_index_filtra_per_stato(): void
    {
        Socio::factory()->create(['associazione_id' => $this->associazione->id, 'stato' => Socio::STATO_ATTIVO]);
        Socio::factory()->create(['associazione_id' => $this->associazione->id, 'stato' => Socio::STATO_SOSPESO]);

        $this->getJson('/api/v1/soci?stato=Sospeso', $this->auth())
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('soci.0.stato', 'Sospeso');
    }

    // ---- Ricerca ----

    public function test_search_richiede_almeno_2_caratteri(): void
    {
        $this->getJson('/api/v1/soci/search?q=a', $this->auth())
            ->assertStatus(400)
            ->assertJsonPath('code', 'missing_query');
    }

    public function test_search_trova_per_cognome_email_e_codice_fiscale(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Brambillesco',
            'email' => 'brambillesco@example.test',
            'codice_fiscale' => 'BRMXXX80A01F205X',
        ]);

        $this->getJson('/api/v1/soci/search?q=Brambillesco', $this->auth())
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->getJson('/api/v1/soci/search?q=BRMXXX80', $this->auth())
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    // ---- Dettaglio ----

    public function test_show_include_tags_e_ultime_tessere(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $tag = Tag::create([
            'associazione_id' => $this->associazione->id,
            'nome_tag' => 'Volontario',
            'colore' => '#FF7B11',
        ]);
        $socio->tags()->attach($tag->id);
        Tessera::factory()->count(2)->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
        ]);

        $response = $this->getJson('/api/v1/soci/'.$socio->id, $this->auth());

        $response->assertOk()
            ->assertJsonPath('socio.id', $socio->id)
            ->assertJsonPath('socio.tags.0.nome_tag', 'Volontario')
            ->assertJsonPath('socio.tags.0.colore', '#FF7B11')
            ->assertJsonCount(2, 'socio.tessere');
    }

    public function test_show_di_socio_di_altro_tenant_restituisce_404(): void
    {
        $altrui = Socio::factory()->create(['associazione_id' => $this->altraAssociazione->id]);

        $this->getJson('/api/v1/soci/'.$altrui->id, $this->auth())
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');
    }

    // ---- Aggiornamento ----

    public function test_update_aggiorna_i_campi_in_whitelist(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->putJson('/api/v1/soci/'.$socio->id, [
            'telefono' => '3331234567',
            'citta' => 'Bologna',
            'campo_non_previsto' => 'ignorato',
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Socio aggiornato.');

        $socio->refresh();
        $this->assertSame('3331234567', $socio->telefono);
        $this->assertSame('Bologna', $socio->citta);
    }

    public function test_update_con_stato_invalido_restituisce_400_invalid_stato(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->putJson('/api/v1/soci/'.$socio->id, ['stato' => 'Marziano'], $this->auth())
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_stato');
    }

    public function test_update_senza_body_restituisce_400_empty_body(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->call(
            'PUT',
            '/api/v1/soci/'.$socio->id,
            [],
            [],
            [],
            $this->transformHeadersToServerVars($this->auth() + ['Content-Type' => 'application/json', 'Accept' => 'application/json']),
            ''
        )
            ->assertStatus(400)
            ->assertJsonPath('code', 'empty_body');
    }

    public function test_update_con_json_malformato_restituisce_400_invalid_json(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->call(
            'PUT',
            '/api/v1/soci/'.$socio->id,
            [],
            [],
            [],
            $this->transformHeadersToServerVars($this->auth() + ['Content-Type' => 'application/json', 'Accept' => 'application/json']),
            '{non-json'
        )
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_json');
    }

    public function test_update_senza_campi_validi_restituisce_400_no_valid_fields(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->putJson('/api/v1/soci/'.$socio->id, ['campo_inventato' => 'x'], $this->auth())
            ->assertStatus(400)
            ->assertJsonPath('code', 'no_valid_fields');
    }

    public function test_update_con_email_duplicata_restituisce_409(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'occupata@example.test',
        ]);
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->putJson('/api/v1/soci/'.$socio->id, ['email' => 'occupata@example.test'], $this->auth())
            ->assertStatus(409)
            ->assertJsonPath('code', 'duplicate_email');
    }

    public function test_update_di_socio_di_altro_tenant_restituisce_404(): void
    {
        $altrui = Socio::factory()->create(['associazione_id' => $this->altraAssociazione->id]);

        $this->putJson('/api/v1/soci/'.$altrui->id, ['citta' => 'Hackerville'], $this->auth())
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');

        $this->assertNotSame('Hackerville', $altrui->fresh()->citta);
    }

    public function test_update_richiede_il_permesso_soci_write(): void
    {
        $chiaveSolaLettura = str_repeat('dd44', 16);
        ApiKey::create([
            'associazione_id' => $this->associazione->id,
            'api_key' => $chiaveSolaLettura,
            'nome' => 'Sola lettura',
            'permessi' => ['soci:read'],
            'attiva' => true,
        ]);

        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->putJson('/api/v1/soci/'.$socio->id, ['citta' => 'Roma'], ['Authorization' => 'Bearer '.$chiaveSolaLettura])
            ->assertStatus(403)
            ->assertJsonPath('code', 'permission_denied');
    }
}
