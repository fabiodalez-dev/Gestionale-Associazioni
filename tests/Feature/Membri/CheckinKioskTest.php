<?php

namespace Tests\Feature\Membri;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Prenotazione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.21 (piano "Gestione 2") — Check-in kiosk fullscreen per evento:
 * pagina dedicata senza sidebar con header scanner (camera consentita),
 * contatore presenze live, riuso dell'endpoint di check-in tessere,
 * isolamento tenant.
 */
class CheckinKioskTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    private Evento $evento;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->evento = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Assemblea annuale',
        ]);
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get("/checkin-kiosk/{$this->evento->id}")->assertRedirect('/login');
    }

    public function test_la_pagina_kiosk_e_fullscreen_con_scanner_e_contatore(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/checkin-kiosk/{$this->evento->id}");

        $response->assertOk()
            ->assertSee('Assemblea annuale')
            ->assertSee('html5-qrcode.min.js')
            ->assertSee('kioskCounter')
            ->assertSee('Esci dal kiosk');

        // Layout dedicato senza sidebar admin
        $response->assertDontSee('mainSidebar');

        // Header scanner: fotocamera consentita (self), no camera=() globale
        $this->assertStringContainsString(
            'camera=(self)',
            (string) $response->headers->get('Permissions-Policy')
        );
        $this->assertStringContainsString(
            'media-src',
            (string) $response->headers->get('Content-Security-Policy')
        );
    }

    public function test_il_contatore_live_somma_partecipanti_e_biglietti(): void
    {
        // 2 partecipanti soci confermati + 1 con stato diverso (escluso)
        $confermati = Socio::factory()->count(2)->create(['associazione_id' => $this->associazione->id]);
        foreach ($confermati as $socio) {
            EventoPartecipante::create([
                'associazione_id' => $this->associazione->id,
                'evento_id' => $this->evento->id,
                'socio_id' => $socio->id,
                'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
                'data_conferma' => now(),
            ]);
        }
        EventoPartecipante::create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $this->evento->id,
            'socio_id' => Socio::factory()->create(['associazione_id' => $this->associazione->id])->id,
            'stato_partecipazione' => EventoPartecipante::STATO_FORSE,
        ]);

        // Prenotazione con check-in da 3 posti + una senza check-in (esclusa)
        Prenotazione::factory()->confermata()->conCheckin()->create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $this->evento->id,
            'posti' => 3,
        ]);
        Prenotazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $this->evento->id,
            'posti' => 2,
        ]);

        $this->actingAs($this->admin)
            ->get("/checkin-kiosk/{$this->evento->id}/contatore")
            ->assertOk()
            ->assertJson(['presenze' => 5]);
    }

    public function test_il_checkin_via_endpoint_tessere_aggiorna_il_contatore(): void
    {
        // L'endpoint tessere richiede i permessi spatie (eventi.update/tessere.view)
        $this->seed(RolesSeeder::class);
        $this->admin->assignRole('admin_associazione');

        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $tessera = Tessera::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
        ]);

        // Stesso endpoint usato dal kiosk via fetch
        $this->actingAs($this->admin)
            ->postJson(route('tessere.checkin.store'), [
                'evento_id' => $this->evento->id,
                'tessera_id' => $tessera->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'already_checked_in' => false]);

        $this->actingAs($this->admin)
            ->get("/checkin-kiosk/{$this->evento->id}/contatore")
            ->assertOk()
            ->assertJson(['presenze' => 1]);
    }

    public function test_evento_di_un_altro_tenant_da_404(): void
    {
        $eventoEstraneo = Evento::factory()->create();

        $this->actingAs($this->admin)
            ->get("/checkin-kiosk/{$eventoEstraneo->id}")
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get("/checkin-kiosk/{$eventoEstraneo->id}/contatore")
            ->assertNotFound();
    }

    public function test_il_dettaglio_evento_ha_il_link_al_kiosk(): void
    {
        $this->actingAs($this->admin)
            ->get("/eventi/{$this->evento->id}/partecipanti")
            ->assertOk()
            ->assertSee('Kiosk check-in')
            ->assertSee(route('checkin-kiosk.show', $this->evento));
    }
}
