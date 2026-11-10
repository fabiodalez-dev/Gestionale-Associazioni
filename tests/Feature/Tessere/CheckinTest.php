<?php

namespace Tests\Feature\Tessere;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckinTest extends TessereTestCase
{
    use RefreshDatabase;

    public function test_pagina_checkin_richiede_login(): void
    {
        $this->get('/tessere/checkin')->assertRedirect('/login');
    }

    public function test_pagina_checkin_mostra_gli_eventi_del_tenant_con_headers_camera(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();
        $admin = $this->creaAdmin($assocA);

        $eventoA = Evento::factory()->create(['associazione_id' => $assocA->id]);
        $eventoB = Evento::factory()->create(['associazione_id' => $assocB->id]);

        $response = $this->actingAs($admin)->get('/tessere/checkin');

        $response->assertOk();
        $response->assertSee($eventoA->titolo);
        $response->assertDontSee($eventoB->titolo);
        $this->assertStringContainsString('camera=(self)', (string) $response->headers->get('Permissions-Policy'));
    }

    public function test_checkin_tramite_tessera_id(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);
        $tessera = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $assoc->id,
        ]);
        $evento = Evento::factory()->create(['associazione_id' => $assoc->id]);

        $response = $this->actingAs($admin)->postJson('/tessere/checkin', [
            'evento_id' => $evento->id,
            'tessera_id' => $tessera->id,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'already_checked_in' => false,
            'socio' => ['nome' => $socio->nome, 'cognome' => $socio->cognome],
        ]);

        $this->assertDatabaseHas('eventi_partecipanti', [
            'evento_id' => $evento->id,
            'socio_id' => $socio->id,
            'stato_partecipazione' => 'Confermato',
        ]);
    }

    public function test_socio_senza_tessera_trovato_per_nome_e_registrato(): void
    {
        $assoc = Associazione::factory()->create();
        $altra = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id, 'nome' => 'Giulia', 'cognome' => 'Ferraresi', 'numero_socio' => 'S-77']);
        Socio::factory()->create(['associazione_id' => $altra->id, 'nome' => 'Giulia', 'cognome' => 'Ferraresi']);
        $evento = Evento::factory()->create(['associazione_id' => $assoc->id]);

        // Nome e cognome in qualunque ordine; solo i soci del tenant
        foreach (['Ferraresi Giu', 'giulia ferr'] as $q) {
            $this->actingAs($admin)->getJson('/tessere/checkin/soci?q='.urlencode($q))
                ->assertOk()
                ->assertJsonCount(1, 'soci')
                ->assertJsonPath('soci.0.id', $socio->id)
                ->assertJsonPath('soci.0.nome', 'Ferraresi Giulia');
        }
        $this->actingAs($admin)->getJson('/tessere/checkin/soci?q=Gi')->assertJsonCount(0, 'soci');

        $this->actingAs($admin)->postJson('/tessere/checkin', ['evento_id' => $evento->id, 'socio_id' => $socio->id])
            ->assertOk()
            ->assertJson(['success' => true, 'already_checked_in' => false]);
        $this->assertDatabaseHas('eventi_partecipanti', ['evento_id' => $evento->id, 'socio_id' => $socio->id]);

        // Stesse verifiche della tessera: il secondo passaggio è un doppione
        $this->actingAs($admin)->postJson('/tessere/checkin', ['evento_id' => $evento->id, 'socio_id' => $socio->id])
            ->assertJson(['already_checked_in' => true]);
    }

    public function test_socio_di_un_altro_tenant_non_registrabile_per_id(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $estraneo = Socio::factory()->create(['associazione_id' => Associazione::factory()->create()->id]);
        $evento = Evento::factory()->create(['associazione_id' => $assoc->id]);

        $this->actingAs($admin)->postJson('/tessere/checkin', ['evento_id' => $evento->id, 'socio_id' => $estraneo->id])
            ->assertNotFound()
            ->assertJsonPath('code', 'socio_not_found');
    }

    public function test_ricerca_per_nome_richiede_login(): void
    {
        $this->get('/tessere/checkin/soci?q=Rossi')->assertRedirect('/login');
    }

    public function test_checkin_duplicato_segnalato(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);
        $tessera = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $assoc->id,
        ]);
        $evento = Evento::factory()->create(['associazione_id' => $assoc->id]);

        $payload = ['evento_id' => $evento->id, 'tessera_id' => $tessera->id];

        $this->actingAs($admin)->postJson('/tessere/checkin', $payload)->assertOk();
        $response = $this->actingAs($admin)->postJson('/tessere/checkin', $payload);

        $response->assertOk();
        $response->assertJson(['success' => true, 'already_checked_in' => true]);
        $this->assertSame(1, EventoPartecipante::allTenants()->where('evento_id', $evento->id)->count());
    }

    public function test_checkin_con_partecipante_creato_in_parallelo_lo_conferma_invece_di_fallire(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);
        $tessera = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $assoc->id,
        ]);
        $evento = Evento::factory()->create(['associazione_id' => $assoc->id]);

        // Un'operazione parallela inserisce la riga (unique evento+socio)
        // dopo la lettura iniziale del check-in e prima del suo create()
        EventoPartecipante::creating(function (EventoPartecipante $nuovo) use ($assoc, $evento, $socio): void {
            if ($nuovo->socio_id === $socio->id && ! EventoPartecipante::allTenants()->where('evento_id', $evento->id)->exists()) {
                DB::table('eventi_partecipanti')->insert([
                    'id' => (string) Str::uuid(),
                    'associazione_id' => $assoc->id,
                    'evento_id' => $evento->id,
                    'socio_id' => $socio->id,
                    'stato_partecipazione' => EventoPartecipante::STATO_NON_PARTECIPA,
                    'created_at' => now(),
                ]);
            }
        });

        $this->actingAs($admin)->postJson('/tessere/checkin', [
            'evento_id' => $evento->id,
            'tessera_id' => $tessera->id,
        ])->assertOk()->assertJson(['success' => true, 'already_checked_in' => false]);

        $partecipanti = EventoPartecipante::allTenants()->where('evento_id', $evento->id)->get();
        $this->assertCount(1, $partecipanti);
        $this->assertSame(EventoPartecipante::STATO_CONFERMATO, $partecipanti->first()->stato_partecipazione);
    }

    public function test_checkin_tramite_numero_tessera(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);
        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $assoc->id,
            'numero_tessera' => '20260077',
        ]);
        $evento = Evento::factory()->create(['associazione_id' => $assoc->id]);

        $this->actingAs($admin)->postJson('/tessere/checkin', [
            'evento_id' => $evento->id,
            'numero_tessera' => '20260077',
        ])->assertOk()->assertJson(['success' => true]);
    }

    public function test_checkin_socio_non_attivo_rifiutato(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->sospeso()->create(['associazione_id' => $assoc->id]);
        $tessera = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $assoc->id,
        ]);
        $evento = Evento::factory()->create(['associazione_id' => $assoc->id]);

        $response = $this->actingAs($admin)->postJson('/tessere/checkin', [
            'evento_id' => $evento->id,
            'tessera_id' => $tessera->id,
        ]);

        $response->assertStatus(400);
        $response->assertJson(['success' => false, 'code' => 'socio_not_active']);
    }

    public function test_checkin_isolamento_tenant_tessera_di_altra_associazione_non_trovata(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();
        $adminA = $this->creaAdmin($assocA);

        $tesseraB = Tessera::factory()->create([
            'socio_id' => Socio::factory()->create(['associazione_id' => $assocB->id])->id,
            'associazione_id' => $assocB->id,
        ]);
        $eventoA = Evento::factory()->create(['associazione_id' => $assocA->id]);

        $response = $this->actingAs($adminA)->postJson('/tessere/checkin', [
            'evento_id' => $eventoA->id,
            'tessera_id' => $tesseraB->id,
        ]);

        $response->assertStatus(404);
        $response->assertJson(['success' => false, 'code' => 'socio_not_found']);
    }

    public function test_checkin_evento_di_altra_associazione_rifiutato(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();
        $adminA = $this->creaAdmin($assocA);

        $socioA = Socio::factory()->create(['associazione_id' => $assocA->id]);
        $tesseraA = Tessera::factory()->create([
            'socio_id' => $socioA->id,
            'associazione_id' => $assocA->id,
        ]);
        $eventoB = Evento::factory()->create(['associazione_id' => $assocB->id]);

        $this->actingAs($adminA)->postJson('/tessere/checkin', [
            'evento_id' => $eventoB->id,
            'tessera_id' => $tesseraA->id,
        ])->assertStatus(422);
    }
}
