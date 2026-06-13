<?php

namespace Tests\Feature\VolontariTurni;

use App\Models\Associazione;
use App\Models\Mansione;
use App\Models\Socio;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use App\Models\Utente;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.3/f.5 — Turni admin: CRUD, assegnazione volontari (suggeriti per
 * mansione/disponibilità), gestione candidature e registrazione presenze.
 */
class TurniAdminTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_lista_turni_isolata_per_tenant(): void
    {
        Turno::factory()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Turno mio']);
        $altra = Associazione::factory()->create();
        Turno::factory()->create(['associazione_id' => $altra->id, 'titolo' => 'Turno altrui']);

        $response = $this->actingAs($this->admin)->get('/turni');

        $response->assertOk();
        $response->assertSee('Turno mio');
        $response->assertDontSee('Turno altrui');
    }

    public function test_crea_turno(): void
    {
        $this->actingAs($this->admin)
            ->post('/turni', [
                'titolo' => 'Banchetto fiera',
                'data' => now()->addDays(5)->toDateString(),
                'ora_inizio' => '09:00',
                'ora_fine' => '13:00',
                'posti' => 3,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('turni', [
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Banchetto fiera',
            'posti' => 3,
            'stato' => Turno::STATO_APERTO,
        ]);
    }

    public function test_ora_fine_deve_seguire_ora_inizio(): void
    {
        $this->actingAs($this->admin)
            ->post('/turni', [
                'titolo' => 'Turno errato',
                'data' => now()->addDay()->toDateString(),
                'ora_inizio' => '14:00',
                'ora_fine' => '12:00',
                'posti' => 1,
            ])
            ->assertSessionHasErrors('ora_fine');
    }

    public function test_assegna_e_conferma_volontario(): void
    {
        $turno = Turno::factory()->create(['associazione_id' => $this->associazione->id, 'posti' => 2]);
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->post("/turni/{$turno->id}/assegna", ['socio_id' => $socio->id, 'conferma' => '1'])
            ->assertRedirect();

        $this->assertDatabaseHas('turni_assegnazioni', [
            'turno_id' => $turno->id,
            'socio_id' => $socio->id,
            'stato' => TurnoAssegnazione::STATO_CONFERMATO,
        ]);
    }

    public function test_non_assegna_se_posti_esauriti(): void
    {
        $turno = Turno::factory()->create(['associazione_id' => $this->associazione->id, 'posti' => 1]);
        TurnoAssegnazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'turno_id' => $turno->id,
        ]);
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->post("/turni/{$turno->id}/assegna", ['socio_id' => $socio->id])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('turni_assegnazioni', [
            'turno_id' => $turno->id,
            'socio_id' => $socio->id,
        ]);
    }

    public function test_suggerimenti_danno_priorita_a_mansione_e_disponibilita(): void
    {
        $mansione = Mansione::factory()->create(['associazione_id' => $this->associazione->id]);

        // Turno di lunedì mattina con mansione richiesta.
        $lunedi = now()->next(Carbon::MONDAY);
        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'mansione_id' => $mansione->id,
            'data' => $lunedi->toDateString(),
            'ora_inizio' => '09:00:00', 'ora_fine' => '12:00:00',
            'posti' => 5,
        ]);

        $perfetto = Socio::factory()->create([
            'associazione_id' => $this->associazione->id, 'cognome' => 'Perfetto',
            'volontario_attivo' => true, 'disponibilita' => ['lun' => ['mattina']],
        ]);
        $perfetto->mansioni()->attach($mansione->id);

        $genericoNonVolontario = Socio::factory()->create([
            'associazione_id' => $this->associazione->id, 'cognome' => 'Inattivo',
            'volontario_attivo' => false,
        ]);

        $response = $this->actingAs($this->admin)->get("/turni/{$turno->id}");
        $response->assertOk();

        $suggeriti = $response->viewData('suggeriti');
        // Il volontario inattivo non compare; il perfetto è primo con score massimo.
        $ids = $suggeriti->pluck('socio.id')->all();
        $this->assertContains($perfetto->id, $ids);
        $this->assertNotContains($genericoNonVolontario->id, $ids);
        $this->assertSame($perfetto->id, $suggeriti->first()['socio']->id);
        $this->assertTrue($suggeriti->first()['ha_mansione']);
        $this->assertTrue($suggeriti->first()['disponibile']);
    }

    public function test_registra_presenze_scrive_le_ore_e_chiude_il_turno(): void
    {
        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'ora_inizio' => '09:00:00', 'ora_fine' => '12:00:00', 'posti' => 2,
        ]);
        $assegnazione = TurnoAssegnazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'turno_id' => $turno->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/turni/{$turno->id}/presenze", [
                'ore' => [$assegnazione->id => '2.5'],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('turni_assegnazioni', [
            'id' => $assegnazione->id,
            'ore_effettive' => '2.50',
        ]);
        $this->assertDatabaseHas('turni', ['id' => $turno->id, 'stato' => Turno::STATO_CHIUSO]);
    }

    public function test_rimuovi_assegnazione_riapre_lo_slot(): void
    {
        $turno = Turno::factory()->create(['associazione_id' => $this->associazione->id, 'posti' => 1]);
        $assegnazione = TurnoAssegnazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'turno_id' => $turno->id,
        ]);

        $this->actingAs($this->admin)
            ->delete("/turni/{$turno->id}/assegnazioni/{$assegnazione->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('turni_assegnazioni', [
            'id' => $assegnazione->id,
            'stato' => TurnoAssegnazione::STATO_RINUNCIATO,
        ]);
        $this->assertSame(1, $turno->fresh()->postiDisponibili());
    }

    public function test_turno_di_altro_tenant_da_404(): void
    {
        $altra = Associazione::factory()->create();
        $turno = Turno::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)->get("/turni/{$turno->id}")->assertNotFound();
    }

    public function test_pianificazione_richiede_autenticazione_admin(): void
    {
        // Guard 'web' (utenti) richiesta: senza login admin → redirect alla login.
        $this->get('/turni')->assertRedirect();
    }
}
