<?php

namespace Tests\Feature\EventiVita;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Socio;
use App\Models\Utente;
use App\Services\EventiVita\VitaSocialeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.12 — Statistiche di partecipazione: tab "Vita sociale" nella scheda
 * socio e top partecipanti nella dashboard.
 */
class VitaSocialeSocioTest extends TestCase
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

    /**
     * @return array{0: Socio, 1: Account}
     */
    private function socioConAccount(): array
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $account = Account::factory()->verificato()->collegatoASocio($socio)->create([
            'associazione_id' => $this->associazione->id,
        ]);

        return [$socio, $account->fresh()];
    }

    public function test_scheda_socio_mostra_la_tab_vita_sociale(): void
    {
        [$socio, $account] = $this->socioConAccount();

        $eventoPassato = Evento::factory()->passato()->create(['associazione_id' => $this->associazione->id]);
        Prenotazione::factory()->conCheckin()->create([
            'evento_id' => $eventoPassato->id,
            'account_id' => $account->id,
        ]);

        $response = $this->actingAs($this->admin)->get("/soci/{$socio->id}");

        $response->assertOk();
        $response->assertSee('Vita sociale');
        $response->assertSee('Eventi prenotati');
        $response->assertSee('Partecipazioni (check-in)');
        $response->assertSee($eventoPassato->titolo);
    }

    public function test_statistiche_socio_contano_prenotazioni_e_checkin(): void
    {
        [$socio, $account] = $this->socioConAccount();

        $passato = Evento::factory()->passato()->create(['associazione_id' => $this->associazione->id]);
        $futuro = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->addWeek(),
        ]);

        // Partecipato (check-in) a un evento passato
        Prenotazione::factory()->conCheckin()->create([
            'evento_id' => $passato->id,
            'account_id' => $account->id,
            'checkin_at' => now()->subWeek(),
        ]);
        // Prenotazione futura confermata (non concorre al tasso)
        Prenotazione::factory()->confermata()->create([
            'evento_id' => $futuro->id,
            'account_id' => $account->id,
        ]);
        // Annullata: non conta
        Prenotazione::factory()->annullata()->create([
            'evento_id' => $futuro->id,
            'account_id' => $account->id,
        ]);

        $this->actingAs($this->admin);
        $stats = app(VitaSocialeService::class)->statisticheSocio($socio);

        $this->assertSame(2, $stats['prenotazioni_totali']);
        $this->assertSame(1, $stats['prenotazioni_con_checkin']);
        $this->assertSame(100, $stats['tasso_partecipazione']);
        $this->assertNotNull($stats['ultima_presenza']);
        $this->assertSame(
            now()->subWeek()->format('Y-m-d'),
            $stats['ultima_presenza']->format('Y-m-d')
        );
    }

    public function test_statistiche_socio_senza_account_collegato(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin);
        $stats = app(VitaSocialeService::class)->statisticheSocio($socio);

        $this->assertNull($stats['account']);
        $this->assertSame(0, $stats['prenotazioni_totali']);
        $this->assertNull($stats['tasso_partecipazione']);
        $this->assertNull($stats['ultima_presenza']);
    }

    public function test_dashboard_mostra_i_top_partecipanti_del_tenant(): void
    {
        $evento = Evento::factory()->passato()->create(['associazione_id' => $this->associazione->id]);

        $assiduo = Account::factory()->verificato()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Maria',
            'cognome' => 'Assidua',
        ]);
        $altroEvento = Evento::factory()->passato()->create(['associazione_id' => $this->associazione->id]);
        Prenotazione::factory()->conCheckin()->create(['evento_id' => $evento->id, 'account_id' => $assiduo->id]);
        Prenotazione::factory()->conCheckin()->create(['evento_id' => $altroEvento->id, 'account_id' => $assiduo->id]);

        // Check-in di un ALTRO tenant: mai in classifica
        $altra = Associazione::factory()->create();
        $eventoAltrui = Evento::factory()->passato()->create(['associazione_id' => $altra->id]);
        $estraneo = Account::factory()->create([
            'associazione_id' => $altra->id,
            'nome' => 'Carlo',
            'cognome' => 'Estraneo',
        ]);
        Prenotazione::factory()->conCheckin()->create(['evento_id' => $eventoAltrui->id, 'account_id' => $estraneo->id]);

        $response = $this->actingAs($this->admin)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Top partecipanti');
        $response->assertSee('Maria Assidua');
        $response->assertSee('2 presenze');
        $response->assertDontSee('Carlo Estraneo');
    }

    public function test_top_partecipanti_ordina_per_numero_di_presenze(): void
    {
        $eventi = Evento::factory()->count(3)->passato()->create(['associazione_id' => $this->associazione->id]);

        $primo = Account::factory()->create(['associazione_id' => $this->associazione->id]);
        $secondo = Account::factory()->create(['associazione_id' => $this->associazione->id]);

        foreach ($eventi as $evento) {
            Prenotazione::factory()->conCheckin()->create(['evento_id' => $evento->id, 'account_id' => $primo->id]);
        }
        Prenotazione::factory()->conCheckin()->create(['evento_id' => $eventi[0]->id, 'account_id' => $secondo->id]);

        $top = app(VitaSocialeService::class)->topPartecipanti($this->associazione->id);

        $this->assertCount(2, $top);
        $this->assertSame($primo->id, $top[0]['account']->id);
        $this->assertSame(3, $top[0]['presenze']);
        $this->assertSame($secondo->id, $top[1]['account']->id);
        $this->assertSame(1, $top[1]['presenze']);
    }
}
