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

    public function test_attestato_di_socio_altro_tenant_da_404(): void
    {
        $altra = Associazione::factory()->create();
        $socio = Socio::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->get("/soci/{$socio->id}/attestato-volontariato")
            ->assertNotFound();
    }
}
