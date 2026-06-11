<?php

namespace Tests\Feature\Eventi;

use App\Models\ApiKey;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckinApiTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Evento $evento;

    private string $chiaveScoped = 'sk_test_scoped_0000000000000000000000000000000000000001';

    private string $chiaveGlobale = 'sk_test_global_0000000000000000000000000000000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->evento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);

        ApiKey::create([
            'associazione_id' => $this->associazione->id,
            'api_key' => $this->chiaveScoped,
            'nome' => 'Chiave check-in test',
            'permessi' => ['eventi:read', 'eventi:write'],
            'attiva' => true,
        ]);

        ApiKey::create([
            'associazione_id' => null, // chiave globale super_admin
            'api_key' => $this->chiaveGlobale,
            'nome' => 'Chiave globale test',
            'permessi' => ['*'],
            'attiva' => true,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $key = null): array
    {
        return [
            'Authorization' => 'Bearer '.($key ?? $this->chiaveScoped),
            'Accept' => 'application/json',
        ];
    }

    private function creaSocioAttivo(array $attributes = []): Socio
    {
        return Socio::factory()->create(array_merge([
            'associazione_id' => $this->associazione->id,
        ], $attributes));
    }

    // ---- Autenticazione ----

    public function test_richiesta_senza_chiave_restituisce_401(): void
    {
        $this->getJson('/api/v1/eventi')->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'missing_api_key');
    }

    public function test_chiave_non_valida_restituisce_401(): void
    {
        $this->getJson('/api/v1/eventi', $this->headers('sk_inesistente'))
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_api_key');
    }

    public function test_chiave_disattivata_restituisce_401(): void
    {
        ApiKey::query()->where('api_key', $this->chiaveScoped)->update(['attiva' => false]);

        $this->getJson('/api/v1/eventi', $this->headers())
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_api_key');
    }

    public function test_permesso_mancante_restituisce_403(): void
    {
        ApiKey::create([
            'associazione_id' => $this->associazione->id,
            'api_key' => 'sk_solo_lettura_000000000000000000000000000000000000000',
            'nome' => 'Solo lettura',
            'permessi' => ['eventi:read'],
            'attiva' => true,
        ]);

        $this->postJson(
            "/api/v1/eventi/{$this->evento->id}/checkin",
            ['numero_socio' => '001'],
            $this->headers('sk_solo_lettura_000000000000000000000000000000000000000')
        )->assertStatus(403)->assertJsonPath('code', 'forbidden');
    }

    public function test_ping_verifica_la_chiave(): void
    {
        $this->getJson('/api/v1/', $this->headers())
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    // ---- GET /eventi ----

    public function test_lista_eventi_con_chiave_scoped_vede_solo_il_proprio_tenant(): void
    {
        Evento::factory()->create(); // altro tenant

        $response = $this->getJson('/api/v1/eventi?limit=100', $this->headers());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'eventi')
            ->assertJsonPath('eventi.0.id', $this->evento->id);
    }

    public function test_lista_eventi_con_chiave_globale_vede_tutti_i_tenant(): void
    {
        Evento::factory()->create();

        $response = $this->getJson('/api/v1/eventi?limit=100', $this->headers($this->chiaveGlobale));

        $response->assertOk()->assertJsonPath('total', 2);
        $this->assertArrayHasKey('associazione_nome', $response->json('eventi.0'));
    }

    // ---- GET /eventi/{id} ----

    public function test_dettaglio_evento_include_i_partecipanti(): void
    {
        $socio = $this->creaSocioAttivo(['nome' => 'Luca', 'cognome' => 'Verdi']);
        EventoPartecipante::create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $this->evento->id,
            'socio_id' => $socio->id,
            'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
            'data_conferma' => now(),
        ]);

        $response = $this->getJson("/api/v1/eventi/{$this->evento->id}", $this->headers());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('evento.id', $this->evento->id)
            ->assertJsonPath('totale_partecipanti', 1)
            ->assertJsonPath('partecipanti.0.cognome', 'Verdi');
    }

    // ---- POST /eventi/{id}/checkin ----

    public function test_uuid_malformato_restituisce_400(): void
    {
        $this->postJson('/api/v1/eventi/non-un-uuid/checkin', ['numero_socio' => '1'], $this->headers())
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_id');
    }

    public function test_evento_inesistente_restituisce_404(): void
    {
        $uuid = (string) Str::uuid();

        $this->postJson("/api/v1/eventi/{$uuid}/checkin", ['numero_socio' => '1'], $this->headers())
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');
    }

    public function test_evento_di_altro_tenant_con_chiave_scoped_restituisce_404(): void
    {
        $altrui = Evento::factory()->create();

        $this->postJson("/api/v1/eventi/{$altrui->id}/checkin", ['numero_socio' => '1'], $this->headers())
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');
    }

    public function test_checkin_con_socio_id(): void
    {
        $socio = $this->creaSocioAttivo();

        $response = $this->postJson(
            "/api/v1/eventi/{$this->evento->id}/checkin",
            ['socio_id' => $socio->id],
            $this->headers()
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('already_checked_in', false)
            ->assertJsonPath('socio.nome', $socio->nome)
            ->assertJsonPath('socio.cognome', $socio->cognome)
            ->assertJsonPath('socio.numero_socio', $socio->numero_socio)
            ->assertJsonPath('evento', $this->evento->titolo);

        $this->assertDatabaseHas('eventi_partecipanti', [
            'evento_id' => $this->evento->id,
            'socio_id' => $socio->id,
            'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
        ]);
    }

    public function test_checkin_con_tessera_id(): void
    {
        $socio = $this->creaSocioAttivo();
        $tessera = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->postJson(
            "/api/v1/eventi/{$this->evento->id}/checkin",
            ['tessera_id' => $tessera->id],
            $this->headers()
        )->assertOk()->assertJsonPath('socio.numero_socio', $socio->numero_socio);
    }

    public function test_checkin_con_numero_tessera(): void
    {
        // Nota: nel nuovo schema numero_tessera è UNIQUE per associazione, quindi
        // il caso legacy "stesso numero su più anni" non può più verificarsi nello
        // stesso tenant; l'ORDER BY anno_validita DESC resta comunque applicato.
        $socio = $this->creaSocioAttivo();
        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'numero_tessera' => 'T-100',
            'anno_validita' => (int) now()->subYear()->year,
        ]);

        // Una tessera con lo stesso numero in un ALTRO tenant non deve interferire
        $estraneo = Socio::factory()->create();
        Tessera::factory()->create([
            'socio_id' => $estraneo->id,
            'associazione_id' => $estraneo->associazione_id,
            'numero_tessera' => 'T-100',
            'anno_validita' => (int) now()->year,
        ]);

        $this->postJson(
            "/api/v1/eventi/{$this->evento->id}/checkin",
            ['numero_tessera' => 'T-100'],
            $this->headers()
        )->assertOk()->assertJsonPath('socio.numero_socio', $socio->numero_socio);
    }

    public function test_checkin_con_numero_socio(): void
    {
        $socio = $this->creaSocioAttivo(['numero_socio' => 'NS-042']);

        $this->postJson(
            "/api/v1/eventi/{$this->evento->id}/checkin",
            ['numero_socio' => 'NS-042'],
            $this->headers()
        )->assertOk()->assertJsonPath('already_checked_in', false);
    }

    public function test_body_vuoto_o_tessera_sconosciuta_restituisce_missing_socio(): void
    {
        $this->postJson("/api/v1/eventi/{$this->evento->id}/checkin", [], $this->headers())
            ->assertStatus(400)
            ->assertJsonPath('code', 'missing_socio');

        $this->postJson(
            "/api/v1/eventi/{$this->evento->id}/checkin",
            ['numero_tessera' => 'INESISTENTE'],
            $this->headers()
        )->assertStatus(400)->assertJsonPath('code', 'missing_socio');
    }

    public function test_socio_id_inesistente_restituisce_socio_not_found(): void
    {
        $this->postJson(
            "/api/v1/eventi/{$this->evento->id}/checkin",
            ['socio_id' => (string) Str::uuid()],
            $this->headers()
        )->assertStatus(404)->assertJsonPath('code', 'socio_not_found');
    }

    public function test_socio_di_altro_tenant_restituisce_socio_not_found(): void
    {
        $estraneo = Socio::factory()->create();

        $this->postJson(
            "/api/v1/eventi/{$this->evento->id}/checkin",
            ['socio_id' => $estraneo->id],
            $this->headers()
        )->assertStatus(404)->assertJsonPath('code', 'socio_not_found');
    }

    public function test_socio_non_attivo_viene_rifiutato_con_stato_nel_messaggio(): void
    {
        $sospeso = $this->creaSocioAttivo(['stato' => Socio::STATO_SOSPESO]);

        $response = $this->postJson(
            "/api/v1/eventi/{$this->evento->id}/checkin",
            ['socio_id' => $sospeso->id],
            $this->headers()
        );

        $response->assertStatus(400)->assertJsonPath('code', 'socio_not_active');
        $this->assertStringContainsString(Socio::STATO_SOSPESO, $response->json('error'));
    }

    public function test_doppio_checkin_e_idempotente_e_conserva_data_conferma(): void
    {
        $socio = $this->creaSocioAttivo();

        $this->postJson("/api/v1/eventi/{$this->evento->id}/checkin", ['socio_id' => $socio->id], $this->headers())
            ->assertOk()
            ->assertJsonPath('already_checked_in', false);

        $primaConferma = EventoPartecipante::allTenants()
            ->where('evento_id', $this->evento->id)
            ->where('socio_id', $socio->id)
            ->value('data_conferma');

        $this->travel(10)->minutes();

        $this->postJson("/api/v1/eventi/{$this->evento->id}/checkin", ['socio_id' => $socio->id], $this->headers())
            ->assertOk()
            ->assertJsonPath('already_checked_in', true)
            ->assertJsonPath('socio.numero_socio', $socio->numero_socio);

        $dopo = EventoPartecipante::allTenants()
            ->where('evento_id', $this->evento->id)
            ->where('socio_id', $socio->id)
            ->value('data_conferma');

        // L'orario del primo ingresso è conservato
        $this->assertEquals($primaConferma, $dopo);
        $this->assertSame(1, EventoPartecipante::allTenants()->where('evento_id', $this->evento->id)->count());
    }

    public function test_checkin_aggiorna_partecipazione_esistente_non_confermata(): void
    {
        $socio = $this->creaSocioAttivo();
        EventoPartecipante::create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $this->evento->id,
            'socio_id' => $socio->id,
            'stato_partecipazione' => EventoPartecipante::STATO_FORSE,
        ]);

        $this->postJson("/api/v1/eventi/{$this->evento->id}/checkin", ['socio_id' => $socio->id], $this->headers())
            ->assertOk()
            ->assertJsonPath('already_checked_in', false);

        $record = EventoPartecipante::allTenants()
            ->where('evento_id', $this->evento->id)
            ->where('socio_id', $socio->id)
            ->first();

        $this->assertSame(EventoPartecipante::STATO_CONFERMATO, $record->stato_partecipazione);
        $this->assertNotNull($record->data_conferma);
        $this->assertSame(1, EventoPartecipante::allTenants()->where('evento_id', $this->evento->id)->count());
    }

    public function test_chiave_globale_fa_checkin_su_evento_di_qualsiasi_tenant(): void
    {
        // Evento e socio di un ALTRO tenant: il tenant è derivato dall'evento
        $altraAssociazione = Associazione::factory()->create();
        $altroEvento = Evento::factory()->create(['associazione_id' => $altraAssociazione->id]);
        $altroSocio = Socio::factory()->create(['associazione_id' => $altraAssociazione->id]);

        $this->postJson(
            "/api/v1/eventi/{$altroEvento->id}/checkin",
            ['socio_id' => $altroSocio->id],
            $this->headers($this->chiaveGlobale)
        )->assertOk()->assertJsonPath('already_checked_in', false);

        $this->assertDatabaseHas('eventi_partecipanti', [
            'evento_id' => $altroEvento->id,
            'socio_id' => $altroSocio->id,
            'associazione_id' => $altraAssociazione->id,
            'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
        ]);
    }

    public function test_pagina_checkin_pubblica_con_header_di_sicurezza(): void
    {
        $response = $this->get('/checkin');

        $response->assertOk();
        $response->assertHeader('Permissions-Policy', 'camera=(self)');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("default-src 'self'", (string) $response->headers->get('Content-Security-Policy'));
        $response->assertSee('Check-in Evento');
        // @json escapa gli slash: nell'HTML l'URL base API compare come "api\/v1"
        $response->assertSee('api\/v1', false);
    }
}
