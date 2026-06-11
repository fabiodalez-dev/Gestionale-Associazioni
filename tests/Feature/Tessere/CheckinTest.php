<?php

namespace Tests\Feature\Tessere;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
