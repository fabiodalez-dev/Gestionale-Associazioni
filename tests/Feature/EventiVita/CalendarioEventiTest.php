<?php

namespace Tests\Feature\EventiVita;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.7 — Calendario eventi: vista mensile admin, vista mensile pubblica,
 * export .ics del singolo evento e feed calendario.ics dei pubblicati.
 */
class CalendarioEventiTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Calendario admin
    // ------------------------------------------------------------------

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/calendario-eventi')->assertRedirect('/login');
    }

    public function test_calendario_admin_mostra_gli_eventi_del_mese(): void
    {
        $evento = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Cena di primavera',
            'data_evento' => now()->startOfMonth()->addDays(9)->setTime(20, 30),
        ]);

        $response = $this->actingAs($this->admin)->get('/calendario-eventi');

        $response->assertOk();
        $response->assertViewIs('eventi-vita.calendario');
        $response->assertSee('Cena di primavera');
        $this->assertTrue(
            $response->viewData('eventiPerGiorno')
                ->get($evento->data_evento->format('Y-m-d'))
                ->contains('id', $evento->id)
        );
    }

    public function test_calendario_admin_non_mostra_eventi_di_altri_tenant(): void
    {
        $altra = Associazione::factory()->create();
        Evento::factory()->create([
            'associazione_id' => $altra->id,
            'titolo' => 'Evento Altrui Segreto',
            'data_evento' => now()->startOfMonth()->addDays(5),
        ]);

        $this->actingAs($this->admin)
            ->get('/calendario-eventi')
            ->assertOk()
            ->assertDontSee('Evento Altrui Segreto');
    }

    public function test_calendario_admin_naviga_su_un_altro_mese(): void
    {
        $prossimo = now()->addMonth()->startOfMonth();
        Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Evento del mese prossimo',
            'data_evento' => $prossimo->copy()->addDays(3)->setTime(18, 0),
        ]);

        // Mese corrente: l'evento non c'è
        $this->actingAs($this->admin)->get('/calendario-eventi')
            ->assertOk()
            ->assertDontSee('Evento del mese prossimo');

        // Mese successivo: c'è
        $this->actingAs($this->admin)->get('/calendario-eventi?mese='.$prossimo->format('Y-m'))
            ->assertOk()
            ->assertSee('Evento del mese prossimo');
    }

    public function test_mese_malformato_ricade_sul_corrente(): void
    {
        $response = $this->actingAs($this->admin)->get('/calendario-eventi?mese=banana');

        $response->assertOk();
        $this->assertSame(
            now()->startOfMonth()->format('Y-m'),
            $response->viewData('mese')->format('Y-m')
        );
    }

    // ------------------------------------------------------------------
    // ICS singolo evento (admin)
    // ------------------------------------------------------------------

    public function test_export_ics_admin_del_singolo_evento(): void
    {
        $evento = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Assemblea; annuale, ordinaria',
            'data_evento' => now()->addWeek()->setTime(21, 0),
        ]);

        $response = $this->actingAs($this->admin)->get("/eventi/{$evento->id}/evento.ics");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');

        $ics = $response->getContent();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('UID:'.$evento->id, $ics);
        // Escaping RFC 5545 di ; e ,
        $this->assertStringContainsString('Assemblea\;', $ics);
        $this->assertStringContainsString('annuale\,', $ics);
    }

    public function test_export_ics_di_un_evento_altrui_da_404(): void
    {
        $altra = Associazione::factory()->create();
        $eventoAltrui = Evento::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->get("/eventi/{$eventoAltrui->id}/evento.ics")
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Calendario pubblico + feed
    // ------------------------------------------------------------------

    public function test_calendario_pubblico_mostra_solo_i_pubblicati(): void
    {
        Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Concerto Aperto A Tutti',
            'data_evento' => now()->startOfMonth()->addDays(11)->setTime(21, 0),
        ]);
        Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Bozza Riservata Interna',
            'data_evento' => now()->startOfMonth()->addDays(12)->setTime(21, 0),
        ]);

        $this->get("/a/{$this->associazione->slug}/calendario")
            ->assertOk()
            ->assertSee('Concerto Aperto A Tutti')
            ->assertDontSee('Bozza Riservata Interna');
    }

    public function test_feed_calendario_ics_contiene_solo_i_pubblicati(): void
    {
        $pubblicato = Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Evento Pubblicato Feed',
            'data_evento' => now()->addWeek(),
        ]);
        Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Evento Bozza Feed',
            'data_evento' => now()->addWeek(),
        ]);

        // Pubblicato di un ALTRO tenant: mai nel feed
        $altra = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        Evento::factory()->pubblicato()->create([
            'associazione_id' => $altra->id,
            'titolo' => 'Evento Pubblicato Altrui',
            'data_evento' => now()->addWeek(),
        ]);

        $response = $this->get("/a/{$this->associazione->slug}/calendario.ics");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');

        $ics = $response->getContent();
        $this->assertStringContainsString('Evento Pubblicato Feed', $ics);
        $this->assertStringContainsString('UID:'.$pubblicato->id, $ics);
        $this->assertStringNotContainsString('Evento Bozza Feed', $ics);
        $this->assertStringNotContainsString('Evento Pubblicato Altrui', $ics);
    }

    public function test_ics_pubblico_del_singolo_evento_pubblicato(): void
    {
        $evento = Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->addWeek(),
        ]);

        $this->get("/a/{$this->associazione->slug}/eventi/{$evento->id}/evento.ics")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');
    }

    public function test_ics_pubblico_di_una_bozza_da_404(): void
    {
        $bozza = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->addWeek(),
        ]);

        $this->get("/a/{$this->associazione->slug}/eventi/{$bozza->id}/evento.ics")
            ->assertNotFound();
    }

    public function test_feed_di_slug_inesistente_da_404(): void
    {
        $this->get('/a/slug-che-non-esiste/calendario.ics')->assertNotFound();
    }
}
