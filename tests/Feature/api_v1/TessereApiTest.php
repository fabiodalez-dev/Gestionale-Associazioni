<?php

namespace Tests\Feature\api_v1;

use App\Models\ApiKey;
use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\Tessere\TesseraQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TessereApiTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Associazione $altraAssociazione;

    private string $chiave;

    private string $chiaveGlobale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->altraAssociazione = Associazione::factory()->create();

        $this->chiave = str_repeat('aa11', 16);
        $this->chiaveGlobale = str_repeat('bb22', 16);

        ApiKey::create([
            'associazione_id' => $this->associazione->id,
            'api_key' => $this->chiave,
            'nome' => 'Scopata',
            'permessi' => ['tessere:read'],
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

    /**
     * @return array<string, string>
     */
    private function auth(?string $chiave = null): array
    {
        return ['Authorization' => 'Bearer '.($chiave ?? $this->chiave)];
    }

    private function creaTessera(array $attrs = [], ?string $associazioneId = null): Tessera
    {
        $associazioneId ??= $this->associazione->id;
        $socio = Socio::factory()->create(['associazione_id' => $associazioneId]);

        return Tessera::factory()->create(array_merge([
            'associazione_id' => $associazioneId,
            'socio_id' => $socio->id,
        ], $attrs));
    }

    // ---- Verifica pubblica (QR) ----

    /** Query string di verify con la firma dell'URL stampato nel QR. */
    private function queryVerify(Tessera|string $tessera): string
    {
        $id = $tessera instanceof Tessera ? $tessera->id : $tessera;

        return http_build_query(['tessera_id' => $id, 'signature' => app(TesseraQrService::class)->signature($id)]);
    }

    public function test_verify_e_pubblico_e_riconosce_tessera_valida(): void
    {
        $tessera = $this->creaTessera();

        $this->getJson('/api/v1/tessere/verify?'.$this->queryVerify($tessera))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('valid', true)
            ->assertJsonPath('tessera.numero_tessera', $tessera->numero_tessera)
            ->assertJsonPath('socio.nome', $tessera->socio->nome)
            ->assertJsonPath('associazione', $this->associazione->nome)
            ->assertJsonMissingPath('tessera.associazione_id');
    }

    public function test_verify_tessera_scaduta_restituisce_valid_false(): void
    {
        $tessera = $this->creaTessera(['data_scadenza' => now()->subDay()->toDateString()]);

        $this->getJson('/api/v1/tessere/verify?'.$this->queryVerify($tessera))
            ->assertOk()
            ->assertJsonPath('valid', false);
    }

    public function test_verify_con_uuid_invalido_restituisce_400(): void
    {
        $this->getJson('/api/v1/tessere/verify?tessera_id=non-un-uuid')
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_tessera_id');
    }

    public function test_verify_tessera_inesistente_restituisce_404(): void
    {
        $this->getJson('/api/v1/tessere/verify?'.$this->queryVerify('00000000-0000-0000-0000-000000000000'))
            ->assertStatus(404)
            ->assertJsonPath('code', 'tessera_not_found');
    }

    /**
     * Hardening (audit run-1): prima bastava l'UUID; ora serve la firma
     * dell'URL del QR, controllata prima della lookup (403 sia per una
     * tessera esistente sia per una inesistente: non rivela nulla).
     */
    public function test_verify_senza_firma_valida_restituisce_403_senza_rivelare_la_tessera(): void
    {
        $tessera = $this->creaTessera();

        $this->getJson('/api/v1/tessere/verify?tessera_id='.$tessera->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'invalid_signature')
            ->assertJsonMissingPath('tessera');

        $this->getJson('/api/v1/tessere/verify?tessera_id='.$tessera->id.'&signature=manomessa')
            ->assertStatus(403)
            ->assertJsonPath('code', 'invalid_signature');

        $this->getJson('/api/v1/tessere/verify?tessera_id=00000000-0000-0000-0000-000000000000')
            ->assertStatus(403)
            ->assertJsonPath('code', 'invalid_signature');

        // La firma di un'altra tessera non vale
        $altra = $this->creaTessera();
        $this->getJson('/api/v1/tessere/verify?tessera_id='.$tessera->id.'&signature='.app(TesseraQrService::class)->signature($altra))
            ->assertStatus(403);
    }

    public function test_verify_accetta_la_firma_ricavata_dall_url_del_qr(): void
    {
        $tessera = $this->creaTessera();

        parse_str((string) parse_url(app(TesseraQrService::class)->verificationUrl($tessera), PHP_URL_QUERY), $query);

        $this->getJson('/api/v1/tessere/verify?tessera_id='.$tessera->id.'&signature='.$query['signature'])
            ->assertOk()
            ->assertJsonPath('valid', true);
    }

    // ---- Lista ----

    public function test_index_e_paginata_e_isolata_per_tenant(): void
    {
        $this->creaTessera();
        $this->creaTessera();
        $this->creaTessera([], $this->altraAssociazione->id);

        $response = $this->getJson('/api/v1/tessere?page=1&limit=1', $this->auth());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('page', 1)
            ->assertJsonPath('limit', 1)
            ->assertJsonCount(1, 'tessere');

        // La chiave scopata non vede le colonne associazione
        $this->assertArrayNotHasKey('associazione_nome', $response->json('tessere.0'));
    }

    public function test_index_filtra_per_anno_e_stato_legacy(): void
    {
        $this->creaTessera(['anno_validita' => 2024]);
        $this->creaTessera(['anno_validita' => 2025, 'stato' => Tessera::STATO_SOSPESA]);

        $this->getJson('/api/v1/tessere?anno=2024', $this->auth())
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->getJson('/api/v1/tessere?stato=Sospesa', $this->auth())
            ->assertOk()
            ->assertJsonPath('total', 1);

        // Stato fuori whitelist: ignorato
        $this->getJson('/api/v1/tessere?stato=Inventato', $this->auth())
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    public function test_index_supporta_filtri_query_builder(): void
    {
        $this->creaTessera(['anno_validita' => 2024]);
        $this->creaTessera(['anno_validita' => 2025]);

        $this->getJson('/api/v1/tessere?filter[anno]=2025', $this->auth())
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->getJson('/api/v1/tessere?filter[campo_inventato]=x', $this->auth())
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_filter');
    }

    public function test_chiave_globale_vede_tutti_i_tenant_con_colonne_associazione(): void
    {
        $this->creaTessera();
        $this->creaTessera([], $this->altraAssociazione->id);

        $response = $this->getJson('/api/v1/tessere', $this->auth($this->chiaveGlobale));

        $response->assertOk()->assertJsonPath('total', 2);
        $this->assertArrayHasKey('associazione_nome', $response->json('tessere.0'));

        // Filtro opzionale ?associazione_id= per le chiavi globali
        $this->getJson('/api/v1/tessere?associazione_id='.$this->altraAssociazione->id, $this->auth($this->chiaveGlobale))
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    // ---- Dettaglio ----

    public function test_show_restituisce_la_tessera_con_dati_socio(): void
    {
        $tessera = $this->creaTessera();

        $this->getJson('/api/v1/tessere/'.$tessera->id, $this->auth())
            ->assertOk()
            ->assertJsonPath('tessera.id', $tessera->id)
            ->assertJsonPath('tessera.numero_socio', $tessera->socio->numero_socio);
    }

    public function test_show_di_tessera_di_altro_tenant_restituisce_404(): void
    {
        $altrui = $this->creaTessera([], $this->altraAssociazione->id);

        $this->getJson('/api/v1/tessere/'.$altrui->id, $this->auth())
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');
    }

    // ---- Ricerca ----

    public function test_search_richiede_il_parametro_q(): void
    {
        $this->getJson('/api/v1/tessere/search', $this->auth())
            ->assertStatus(400)
            ->assertJsonPath('code', 'missing_query');
    }

    public function test_search_trova_per_numero_tessera_e_cognome_socio(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Verdellini',
        ]);
        Tessera::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
            'numero_tessera' => 'TESS-XYZ-001',
        ]);

        $this->getJson('/api/v1/tessere/search?q=TESS-XYZ', $this->auth())
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('tessere.0.numero_tessera', 'TESS-XYZ-001');

        $this->getJson('/api/v1/tessere/search?q=Verdellini', $this->auth())
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('tessere.0.socio_cognome', 'Verdellini');
    }
}
