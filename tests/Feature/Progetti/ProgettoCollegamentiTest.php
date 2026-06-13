<?php

namespace Tests\Feature\Progetti;

use App\Models\Associazione;
use App\Models\CategoriaMovimento;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Movimento;
use App\Models\Progetto;
use App\Models\Socio;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.13 — prima nota imputata al progetto + rendiconto;
 * f.14 — eventi e turni collegati elencati nel dettaglio;
 * f.15 — report progetto PDF intestato.
 */
class ProgettoCollegamentiTest extends TestCase
{
    use ConfiguraTenantProgetti;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
    }

    private function progetto(): Progetto
    {
        return Progetto::factory()->create([
            'associazione_id' => $this->associazione->id,
            'budget' => 1000,
        ]);
    }

    // ---- f.13 ----

    public function test_prima_nota_accetta_imputazione_a_progetto_del_tenant(): void
    {
        $progetto = $this->progetto();
        $categoria = CategoriaMovimento::factory()->entrata()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->admin)->post(route('prima-nota.store'), [
            'tipo' => Movimento::TIPO_ENTRATA,
            'importo' => '120.00',
            'data' => '2026-06-01',
            'categoria_id' => $categoria->id,
            'progetto_id' => $progetto->id,
        ])->assertRedirect(route('prima-nota.index'));

        $this->assertDatabaseHas('movimenti', [
            'associazione_id' => $this->associazione->id,
            'progetto_id' => $progetto->id,
            'importo' => 120.00,
        ]);
    }

    public function test_prima_nota_rifiuta_progetto_di_altro_tenant(): void
    {
        $categoria = CategoriaMovimento::factory()->entrata()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $altra = Associazione::factory()->create();
        $progettoAltrui = Progetto::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)->post(route('prima-nota.store'), [
            'tipo' => Movimento::TIPO_ENTRATA,
            'importo' => '10.00',
            'data' => '2026-06-01',
            'categoria_id' => $categoria->id,
            'progetto_id' => $progettoAltrui->id,
        ])->assertSessionHasErrors('progetto_id');
    }

    public function test_prima_nota_filtra_per_progetto(): void
    {
        $progetto = $this->progetto();
        $altro = $this->progetto();

        Movimento::factory()->entrata()->create([
            'associazione_id' => $this->associazione->id,
            'progetto_id' => $progetto->id,
        ]);
        Movimento::factory()->entrata()->create([
            'associazione_id' => $this->associazione->id,
            'progetto_id' => $altro->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('prima-nota.index', ['progetto_id' => $progetto->id]))
            ->assertOk()
            ->assertViewHas('movimenti', fn ($movimenti) => $movimenti->total() === 1);
    }

    public function test_rendiconto_nel_dettaglio_somma_entrate_e_uscite_del_progetto(): void
    {
        $progetto = $this->progetto();

        Movimento::factory()->entrata()->create([
            'associazione_id' => $this->associazione->id,
            'progetto_id' => $progetto->id,
            'importo' => 300,
        ]);
        Movimento::factory()->uscita()->create([
            'associazione_id' => $this->associazione->id,
            'progetto_id' => $progetto->id,
            'importo' => 120,
        ]);
        // Movimento NON imputato al progetto: non deve entrare nel rendiconto
        Movimento::factory()->entrata()->create([
            'associazione_id' => $this->associazione->id,
            'progetto_id' => null,
            'importo' => 999,
        ]);

        $response = $this->actingAs($this->admin)->get(route('progetti.show', $progetto));

        $response->assertOk();
        $response->assertViewHas('rendiconto', function (array $r): bool {
            return abs($r['entrate'] - 300.0) < 0.001
                && abs($r['uscite'] - 120.0) < 0.001
                && abs($r['saldo'] - 180.0) < 0.001;
        });
    }

    // ---- f.14 ----

    public function test_dettaglio_elenca_eventi_e_turni_collegati(): void
    {
        $progetto = $this->progetto();

        Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'progetto_id' => $progetto->id,
            'titolo' => 'Concerto Benefico',
        ]);
        Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'progetto_id' => $progetto->id,
            'titolo' => 'Allestimento Palco',
        ]);

        $this->actingAs($this->admin)->get(route('progetti.show', $progetto))
            ->assertOk()
            ->assertSee('Concerto Benefico')
            ->assertSee('Allestimento Palco');
    }

    public function test_evento_puo_essere_imputato_a_un_progetto(): void
    {
        $progetto = $this->progetto();
        $evento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->put(route('eventi.update', $evento), [
            'titolo' => $evento->titolo,
            'data_evento' => $evento->data_evento->format('Y-m-d\TH:i'),
            'stato_pubblicazione' => $evento->stato_pubblicazione,
            'prezzo' => '0',
            'max_posti_per_prenotazione' => 4,
            'lista_attesa_attiva' => 1,
            'iscrizioni_aperte' => 1,
            'richiede_approvazione' => 0,
            'annullamento_entro_ore' => 24,
            'solo_soci' => 0,
            'progetto_id' => $progetto->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('eventi', [
            'id' => $evento->id,
            'progetto_id' => $progetto->id,
        ]);
    }

    public function test_turno_puo_essere_imputato_a_un_progetto(): void
    {
        $progetto = $this->progetto();

        $this->actingAs($this->admin)->post(route('turni.store'), [
            'titolo' => 'Turno Progetto',
            'data' => '2026-07-01',
            'ora_inizio' => '09:00',
            'ora_fine' => '12:00',
            'posti' => 2,
            'progetto_id' => $progetto->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('turni', [
            'titolo' => 'Turno Progetto',
            'progetto_id' => $progetto->id,
        ]);
    }

    // ---- f.15 ----

    public function test_report_pdf_scaricabile(): void
    {
        $progetto = $this->progetto();

        $evento = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'progetto_id' => $progetto->id,
        ]);
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        EventoPartecipante::create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $evento->id,
            'socio_id' => $socio->id,
            'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
        ]);

        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'progetto_id' => $progetto->id,
        ]);
        TurnoAssegnazione::factory()->confermata()->create([
            'turno_id' => $turno->id,
            'associazione_id' => $this->associazione->id,
            'ore_effettive' => 5.5,
        ]);

        $response = $this->actingAs($this->admin)->get(route('progetti.report-pdf', $progetto));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_ore_volontariato_sommate_dai_turni_collegati(): void
    {
        $progetto = $this->progetto();
        $turno = Turno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'progetto_id' => $progetto->id,
        ]);
        TurnoAssegnazione::factory()->confermata()->create([
            'turno_id' => $turno->id,
            'associazione_id' => $this->associazione->id,
            'ore_effettive' => 3,
        ]);
        TurnoAssegnazione::factory()->confermata()->create([
            'turno_id' => $turno->id,
            'associazione_id' => $this->associazione->id,
            'ore_effettive' => 2,
        ]);

        $this->actingAs($this->admin)->get(route('progetti.show', $progetto))
            ->assertOk()
            ->assertViewHas('oreVolontariato', fn (array $o): bool => abs($o['totale'] - 5.0) < 0.001);
    }

    public function test_report_pdf_di_altro_tenant_non_raggiungibile(): void
    {
        $altra = Associazione::factory()->create();
        $progettoAltrui = Progetto::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)->get(route('progetti.report-pdf', $progettoAltrui->id))->assertNotFound();
    }
}
