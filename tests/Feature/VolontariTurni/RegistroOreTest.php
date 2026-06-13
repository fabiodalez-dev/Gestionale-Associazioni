<?php

namespace Tests\Feature\VolontariTurni;

use App\Models\Associazione;
use App\Models\PromemoriaInviato;
use App\Models\Socio;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use App\Models\Utente;
use App\Notifications\VolontariTurni\PromemoriaTurnoNotification;
use App\Services\VolontariTurni\RegistroOreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * f.5/f.6 — Registro ore: ore manuali, totali, attestato PDF e comando
 * turni:promemoria (idempotente, solo ai confermati).
 */
class RegistroOreTest extends TestCase
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

    public function test_inserisce_ore_manuali_fuori_turno(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'volontario_attivo' => true,
        ]);

        $this->actingAs($this->admin)
            ->post('/registro-ore/manuali', [
                'socio_id' => $socio->id,
                'ore' => '4',
                'data' => now()->subDays(2)->toDateString(),
                'descrizione' => 'Allestimento sede',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('turni_assegnazioni', [
            'socio_id' => $socio->id,
            'stato' => TurnoAssegnazione::STATO_CONFERMATO,
            'ore_effettive' => '4.00',
        ]);

        $registro = app(RegistroOreService::class);
        $this->assertSame(4.0, $registro->totaleOreSocio($socio));
    }

    public function test_totale_ore_aggrega_turni_e_manuali(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data' => now()->startOfYear()->addDay()->toDateString(),
        ]);
        TurnoAssegnazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'turno_id' => $turno->id,
            'socio_id' => $socio->id,
            'ore_effettive' => 3,
        ]);

        $registro = app(RegistroOreService::class);
        $registro->aggiungiOreManuali($socio, 2.5, now(), 'Extra');

        $this->assertSame(5.5, $registro->totaleOreSocio($socio));
    }

    public function test_attestato_pdf_scaricabile(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Mario', 'cognome' => 'Rossi',
        ]);
        $anno = (int) now()->year;
        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data' => now()->toDateString(),
        ]);
        TurnoAssegnazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'turno_id' => $turno->id,
            'socio_id' => $socio->id,
            'ore_effettive' => 6,
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/soci/{$socio->id}/attestato-volontariato?anno={$anno}");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_comando_promemoria_invia_solo_ai_confermati_di_domani(): void
    {
        Notification::fake();

        $domani = now()->addDay()->toDateString();
        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data' => $domani, 'posti' => 3,
        ]);

        $confermato = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'conf@example.test', 'email_opt_out' => false,
        ]);
        TurnoAssegnazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'turno_id' => $turno->id, 'socio_id' => $confermato->id,
        ]);

        $proposto = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'prop@example.test',
        ]);
        TurnoAssegnazione::factory()->create([ // proposto, non confermato
            'associazione_id' => $this->associazione->id,
            'turno_id' => $turno->id, 'socio_id' => $proposto->id,
        ]);

        $this->artisan('turni:promemoria')->assertSuccessful();

        Notification::assertSentTo($confermato, PromemoriaTurnoNotification::class);
        Notification::assertNotSentTo($proposto, PromemoriaTurnoNotification::class);

        $this->assertDatabaseHas('promemoria_inviati', [
            'socio_id' => $confermato->id,
            'tipo' => 'turno',
        ]);
    }

    public function test_comando_promemoria_idempotente(): void
    {
        Notification::fake();

        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data' => now()->addDay()->toDateString(),
        ]);
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'x@example.test',
        ]);
        TurnoAssegnazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'turno_id' => $turno->id, 'socio_id' => $socio->id,
        ]);

        $this->artisan('turni:promemoria')->assertSuccessful();
        $this->artisan('turni:promemoria')->assertSuccessful();

        $this->assertSame(1, PromemoriaInviato::where('socio_id', $socio->id)->where('tipo', 'turno')->count());
        Notification::assertSentToTimes($socio, PromemoriaTurnoNotification::class, 1);
    }

    public function test_destroy_voce_con_socio_soft_deleted_passa_dalla_policy(): void
    {
        // Voce del registro su un turno reale, poi il socio viene soft-deleted:
        // socio() risolve null (il cascade DB non scatta sul soft delete).
        // La cancellazione deve passare dalla policy dedicata (delete →
        // soci.update) senza derivare il permesso dalla presenza della
        // relazione (#8).
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'volontario_attivo' => true,
        ]);
        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data' => now()->toDateString(),
        ]);
        $assegnazione = TurnoAssegnazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'turno_id' => $turno->id,
            'socio_id' => $socio->id,
            'ore_effettive' => 5,
        ]);

        $socio->delete();

        $this->assertNull($assegnazione->fresh()->socio);

        $this->actingAs($this->admin)
            ->delete("/registro-ore/{$assegnazione->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        // Turno reale (non manuale): la voce viene azzerata, non eliminata
        $this->assertNull($assegnazione->fresh()->ore_effettive);
    }

    public function test_destroy_voce_negato_agli_ospiti(): void
    {
        $assegnazione = TurnoAssegnazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'ore_effettive' => 3,
        ]);

        $this->delete("/registro-ore/{$assegnazione->id}")->assertRedirect('/login');
    }

    public function test_promemoria_dedup_per_turno_non_collide_tra_turni_diversi(): void
    {
        // Stesso socio confermato su DUE turni distinti domani: deve ricevere
        // un promemoria per ciascuno (dedup robusta su turno_id, non su hash
        // troncato che potrebbe collidere) — fix #15.
        $domani = now()->addDay()->toDateString();

        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'doppio@example.test', 'email_opt_out' => false,
        ]);

        $turnoA = Turno::factory()->create([
            'associazione_id' => $this->associazione->id, 'data' => $domani,
        ]);
        $turnoB = Turno::factory()->create([
            'associazione_id' => $this->associazione->id, 'data' => $domani,
        ]);

        foreach ([$turnoA, $turnoB] as $turno) {
            TurnoAssegnazione::factory()->confermata()->create([
                'associazione_id' => $this->associazione->id,
                'turno_id' => $turno->id, 'socio_id' => $socio->id,
            ]);
        }

        $this->artisan('turni:promemoria')->assertSuccessful();

        // Due record distinti, uno per turno (riferimento_id = turno_id)
        $this->assertSame(2, PromemoriaInviato::where('socio_id', $socio->id)
            ->where('tipo', 'turno')->count());
        $this->assertDatabaseHas('promemoria_inviati', [
            'socio_id' => $socio->id, 'tipo' => 'turno', 'riferimento_id' => $turnoA->id,
        ]);
        $this->assertDatabaseHas('promemoria_inviati', [
            'socio_id' => $socio->id, 'tipo' => 'turno', 'riferimento_id' => $turnoB->id,
        ]);

        // Re-run: nessun nuovo invio (idempotenza per turno)
        $this->artisan('turni:promemoria')->assertSuccessful();
        $this->assertSame(2, PromemoriaInviato::where('socio_id', $socio->id)
            ->where('tipo', 'turno')->count());
    }

    public function test_attestato_di_socio_altro_tenant_da_404(): void
    {
        $altra = Associazione::factory()->create();
        $socio = Socio::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->get("/soci/{$socio->id}/attestato-volontariato")
            ->assertNotFound();
    }
}
