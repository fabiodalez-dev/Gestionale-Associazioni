<?php

namespace Tests\Feature\Dashboard;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KPI prenotazioni in dashboard (docs/PIANO-PRENOTAZIONI.md, sezione Admin):
 * visibili SOLO se il tenant ha frontend_pubblico_attivo.
 */
class DashboardKpiPrenotazioniTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create([
            'frontend_pubblico_attivo' => true,
        ]);
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_kpi_nascosti_se_frontend_non_attivo(): void
    {
        $this->associazione->update(['frontend_pubblico_attivo' => false]);

        $response = $this->actingAs($this->admin)->get('/dashboard');

        $response->assertOk();
        $this->assertNull($response->viewData('kpiPrenotazioni'));
        $response->assertDontSee('Prenotazioni frontend');
    }

    public function test_kpi_nascosti_in_vista_globale_super_admin(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->get('/dashboard');

        $response->assertOk();
        $this->assertNull($response->viewData('kpiPrenotazioni'));
    }

    public function test_kpi_calcolati_per_il_tenant_con_frontend_attivo(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->addDays(7),
        ]);

        // 2 confermate recenti (3 posti totali), 1 vecchia oltre i 30 giorni
        Prenotazione::factory()->confermata()->create(['evento_id' => $evento->id, 'posti' => 2]);
        Prenotazione::factory()->confermata()->create(['evento_id' => $evento->id, 'posti' => 1]);
        Prenotazione::factory()->confermata()->create([
            'evento_id' => $evento->id,
            'created_at' => now()->subDays(45),
        ]);

        // Incasso del mese: una pagata questo mese, una il mese scorso
        Prenotazione::factory()->pagata()->create([
            'evento_id' => $evento->id,
            'importo_totale' => 25.50,
            'pagata_at' => now(),
        ]);
        Prenotazione::factory()->pagata()->create([
            'evento_id' => $evento->id,
            'importo_totale' => 99,
            'pagata_at' => now()->subMonthsNoOverflow(1),
        ]);

        // Lista d'attesa
        Prenotazione::factory()->listaAttesa()->create(['evento_id' => $evento->id]);

        $response = $this->actingAs($this->admin)->get('/dashboard');

        $response->assertOk();
        $kpi = $response->viewData('kpiPrenotazioni');

        $this->assertNotNull($kpi);
        // 2 recenti + la vecchia esclusa; le "pagata" del factory sono confermate recenti anch'esse
        $this->assertSame(4, $kpi['confermate_30gg']);
        $this->assertSame(25.5, $kpi['incasso_mese']);
        $this->assertSame(1, $kpi['in_lista_attesa']);
        // Un account per ogni prenotazione creata dal factory (6)
        $this->assertSame(6, $kpi['account_registrati']);

        $response->assertSee('Prenotazioni frontend');
        $response->assertSee('Incasso eventi del mese');
    }

    public function test_riempimento_prossimi_eventi_pubblicati(): void
    {
        $pubblicato = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Evento Con Capienza',
            'data_evento' => now()->addDays(3),
        ]);
        // Bozza e passato: esclusi
        Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->addDays(5),
        ]);
        Evento::factory()->pubblicato()->passato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        Prenotazione::factory()->confermata()->create(['evento_id' => $pubblicato->id, 'posti' => 4]);
        // In attesa di pagamento: NON conta nel riempimento (solo confermati)
        Prenotazione::factory()->inAttesaPagamento()->create(['evento_id' => $pubblicato->id, 'posti' => 3]);

        $response = $this->actingAs($this->admin)->get('/dashboard');

        $kpi = $response->viewData('kpiPrenotazioni');
        $this->assertCount(1, $kpi['riempimento']);

        $riga = $kpi['riempimento'][0];
        $this->assertSame($pubblicato->id, $riga['evento']->id);
        $this->assertSame(4, $riga['confermati']);
        $this->assertSame(10, $riga['capienza']);
        $this->assertSame(40, $riga['percentuale']);

        $response->assertSee('Evento Con Capienza');
        $response->assertSee('4/10');
    }

    public function test_kpi_isolati_per_tenant(): void
    {
        // Dati di un altro tenant con frontend attivo
        $altraAssociazione = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        $eventoAltrui = Evento::factory()->pubblicato()->create([
            'associazione_id' => $altraAssociazione->id,
            'data_evento' => now()->addDays(2),
        ]);
        Prenotazione::factory()->confermata()->create(['evento_id' => $eventoAltrui->id]);
        Account::factory()->create(['associazione_id' => $altraAssociazione->id]);

        $response = $this->actingAs($this->admin)->get('/dashboard');

        $kpi = $response->viewData('kpiPrenotazioni');
        $this->assertSame(0, $kpi['confermate_30gg']);
        $this->assertSame(0, $kpi['in_lista_attesa']);
        // Solo l'account del factory altrui esiste, ma è di un altro tenant
        $this->assertSame(0, $kpi['account_registrati']);
        $this->assertCount(0, $kpi['riempimento']);
    }
}
