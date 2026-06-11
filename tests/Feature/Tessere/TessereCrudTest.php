<?php

namespace Tests\Feature\Tessere;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TessereCrudTest extends TessereTestCase
{
    use RefreshDatabase;

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/tessere')->assertRedirect('/login');
    }

    public function test_index_mostra_le_tessere_dell_anno_corrente(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);

        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);
        $tessera = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $assoc->id,
        ]);

        $response = $this->actingAs($admin)->get('/tessere');

        $response->assertOk();
        $response->assertViewIs('tessere.index');
        $response->assertSee($tessera->numero_tessera);
        $response->assertSee($socio->cognome);
    }

    public function test_index_in_modalita_ajax_risponde_json_con_count_e_html(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);

        Tessera::factory()->count(2)->create([
            'socio_id' => fn () => Socio::factory()->create(['associazione_id' => $assoc->id])->id,
            'associazione_id' => $assoc->id,
        ]);

        $response = $this->actingAs($admin)->get('/tessere?ajax=1');

        $response->assertOk();
        $response->assertJsonStructure(['count', 'html']);
        $this->assertSame(2, $response->json('count'));
    }

    public function test_creazione_tessera_singola(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);

        $response = $this->actingAs($admin)->post('/tessere', [
            'socio_id' => $socio->id,
            'numero_tessera' => '20260042',
            'anno_validita' => 2026,
            'data_emissione' => '2026-06-11',
            'data_scadenza' => '2026-12-31',
            'stato' => 'Attiva',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tessere', [
            'socio_id' => $socio->id,
            'associazione_id' => $assoc->id,
            'numero_tessera' => '20260042',
            'anno_validita' => 2026,
            'stato' => 'Attiva',
            // tipo_scadenza forzato dal default dell'associazione
            'tipo_scadenza' => 'solare',
        ]);

        // qr_code_url persistito all'emissione
        $tessera = Tessera::allTenants()->where('numero_tessera', '20260042')->first();
        $this->assertNotEmpty($tessera->qr_code_url);
        $this->assertStringContainsString('/verifica-tessera/'.$tessera->id, $tessera->qr_code_url);
    }

    public function test_data_scadenza_calcolata_se_assente_tipo_annuale(): void
    {
        $assoc = Associazione::factory()->scadenzaAnnuale()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);

        $this->actingAs($admin)->post('/tessere', [
            'socio_id' => $socio->id,
            'numero_tessera' => 'T-001',
            'anno_validita' => (int) now()->year,
            'data_emissione' => now()->toDateString(),
            'data_scadenza' => '',
            'stato' => 'Attiva',
        ])->assertRedirect();

        $tessera = Tessera::allTenants()->where('numero_tessera', 'T-001')->first();
        $this->assertSame('annuale', $tessera->tipo_scadenza);
        $this->assertSame(now()->addYear()->toDateString(), $tessera->data_scadenza->toDateString());
    }

    public function test_validazione_socio_di_altra_associazione_rifiutato(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();
        $admin = $this->creaAdmin($assocA);
        $socioB = Socio::factory()->create(['associazione_id' => $assocB->id]);

        $response = $this->actingAs($admin)->post('/tessere', [
            'socio_id' => $socioB->id,
            'numero_tessera' => '20260001',
            'anno_validita' => 2026,
            'data_emissione' => '2026-06-11',
            'stato' => 'Attiva',
        ]);

        $response->assertSessionHasErrors('socio_id');
        $this->assertDatabaseCount('tessere', 0);
    }

    public function test_validazione_numero_tessera_duplicato_nella_stessa_associazione(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);

        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $assoc->id,
            'numero_tessera' => '20260001',
        ]);

        $response = $this->actingAs($admin)->post('/tessere', [
            'socio_id' => $socio->id,
            'numero_tessera' => '20260001',
            'anno_validita' => 2026,
            'data_emissione' => '2026-06-11',
            'stato' => 'Attiva',
        ]);

        $response->assertSessionHasErrors('numero_tessera');
    }

    public function test_stato_scaduta_non_accettato_da_input_utente(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);

        $response = $this->actingAs($admin)->post('/tessere', [
            'socio_id' => $socio->id,
            'numero_tessera' => '20260001',
            'anno_validita' => 2026,
            'data_emissione' => '2026-06-11',
            'stato' => 'Scaduta',
        ]);

        $response->assertSessionHasErrors('stato');
    }

    public function test_modifica_tessera(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);
        $tessera = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $assoc->id,
        ]);

        $response = $this->actingAs($admin)->put('/tessere/'.$tessera->id, [
            'socio_id' => $socio->id,
            'numero_tessera' => 'NUOVO-99',
            'anno_validita' => $tessera->anno_validita,
            'data_emissione' => $tessera->data_emissione->toDateString(),
            'data_scadenza' => $tessera->data_scadenza->toDateString(),
            'stato' => 'Sospesa',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tessere', [
            'id' => $tessera->id,
            'numero_tessera' => 'NUOVO-99',
            'stato' => 'Sospesa',
        ]);
    }

    public function test_eliminazione_tessera(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $tessera = Tessera::factory()->create([
            'socio_id' => fn () => Socio::factory()->create(['associazione_id' => $assoc->id])->id,
            'associazione_id' => $assoc->id,
        ]);

        $this->actingAs($admin)
            ->delete('/tessere/'.$tessera->id)
            ->assertRedirect();

        $this->assertDatabaseMissing('tessere', ['id' => $tessera->id]);
    }

    public function test_isolamento_tenant_admin_di_a_non_vede_ne_modifica_tessere_di_b(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();
        $adminA = $this->creaAdmin($assocA);

        $socioB = Socio::factory()->create(['associazione_id' => $assocB->id]);
        $tesseraB = Tessera::factory()->create([
            'socio_id' => $socioB->id,
            'associazione_id' => $assocB->id,
        ]);

        // Non compare nella lista
        $this->actingAs($adminA)
            ->get('/tessere')
            ->assertOk()
            ->assertDontSee($tesseraB->numero_tessera);

        // Update e delete → 404 (global scope sul route binding)
        $this->actingAs($adminA)->put('/tessere/'.$tesseraB->id, [
            'socio_id' => $socioB->id,
            'numero_tessera' => 'HACK',
            'anno_validita' => 2026,
            'data_emissione' => '2026-01-01',
            'stato' => 'Attiva',
        ])->assertNotFound();

        $this->actingAs($adminA)->delete('/tessere/'.$tesseraB->id)->assertNotFound();
        $this->assertDatabaseHas('tessere', ['id' => $tesseraB->id, 'numero_tessera' => $tesseraB->numero_tessera]);
    }

    public function test_filtro_stato_attiva_esclude_le_tessere_scadute_di_fatto(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);

        $valida = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $assoc->id,
            'numero_tessera' => 'VALIDA-1',
        ]);

        // Attiva sul DB ma con data scadenza superata (stesso anno)
        Tessera::factory()->create([
            'socio_id' => Socio::factory()->create(['associazione_id' => $assoc->id])->id,
            'associazione_id' => $assoc->id,
            'numero_tessera' => 'SCADUTA-1',
            'stato' => 'Attiva',
            'data_emissione' => now()->subMonths(2)->toDateString(),
            'data_scadenza' => now()->subDay()->toDateString(),
        ]);

        $response = $this->actingAs($admin)->get('/tessere?stato=Attiva&ajax=1');

        $response->assertOk();
        $this->assertSame(1, $response->json('count'));
        $this->assertStringContainsString('VALIDA-1', $response->json('html'));

        $response = $this->actingAs($admin)->get('/tessere?stato=Scaduta&ajax=1');
        $this->assertSame(1, $response->json('count'));
        $this->assertStringContainsString('SCADUTA-1', $response->json('html'));
    }
}
