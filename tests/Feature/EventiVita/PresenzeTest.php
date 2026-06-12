<?php

namespace Tests\Feature\EventiVita;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Prenotazione;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * f.9 — Foglio presenze PDF e registrazione presenze manuale in blocco
 * (checkbox multipli sulle prenotazioni confermate, oltre al check-in QR).
 */
class PresenzeTest extends TestCase
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
            'data_evento' => now()->addDay(),
        ]);
    }

    public function test_pagina_presenze_elenca_le_prenotazioni_confermate(): void
    {
        $confermata = Prenotazione::factory()->confermata()->create(['evento_id' => $this->evento->id]);
        Prenotazione::factory()->annullata()->create(['evento_id' => $this->evento->id]);

        $response = $this->actingAs($this->admin)->get("/eventi/{$this->evento->id}/presenze");

        $response->assertOk();
        $response->assertViewIs('eventi-vita.presenze');
        $this->assertCount(1, $response->viewData('prenotazioni'));
        $this->assertSame($confermata->id, $response->viewData('prenotazioni')->first()->id);
    }

    public function test_registrazione_presenze_in_blocco_marca_il_checkin(): void
    {
        $prima = Prenotazione::factory()->confermata()->create(['evento_id' => $this->evento->id]);
        $seconda = Prenotazione::factory()->confermata()->create(['evento_id' => $this->evento->id]);
        $esclusa = Prenotazione::factory()->confermata()->create(['evento_id' => $this->evento->id]);

        $this->actingAs($this->admin)
            ->post("/eventi/{$this->evento->id}/presenze", [
                'prenotazioni' => [$prima->id, $seconda->id],
            ])
            ->assertRedirect("/eventi/{$this->evento->id}/presenze")
            ->assertSessionHas('success', 'Presenze registrate: 2.');

        $this->assertNotNull($prima->fresh()->checkin_at);
        $this->assertSame($this->admin->id, $prima->fresh()->checkin_utente_id);
        $this->assertNotNull($seconda->fresh()->checkin_at);
        $this->assertNull($esclusa->fresh()->checkin_at);
    }

    public function test_le_prenotazioni_non_confermate_o_gia_arrivate_vengono_ignorate(): void
    {
        $inAttesa = Prenotazione::factory()->inAttesaPagamento()->create(['evento_id' => $this->evento->id]);
        $giaArrivata = Prenotazione::factory()->conCheckin()->create(['evento_id' => $this->evento->id]);
        $checkinOriginale = $giaArrivata->checkin_at;

        $this->travel(10)->minutes();

        $this->actingAs($this->admin)
            ->post("/eventi/{$this->evento->id}/presenze", [
                'prenotazioni' => [$inAttesa->id, $giaArrivata->id],
            ])
            ->assertSessionHas('success', 'Presenze registrate: 0.');

        $this->assertNull($inAttesa->fresh()->checkin_at);
        // Il check-in esistente NON viene riscritto
        $this->assertSame(
            $checkinOriginale->format('Y-m-d H:i:s'),
            $giaArrivata->fresh()->checkin_at->format('Y-m-d H:i:s')
        );
    }

    public function test_prenotazioni_di_un_altro_evento_vengono_ignorate(): void
    {
        $altroEvento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);
        $estranea = Prenotazione::factory()->confermata()->create(['evento_id' => $altroEvento->id]);

        $this->actingAs($this->admin)
            ->post("/eventi/{$this->evento->id}/presenze", [
                'prenotazioni' => [$estranea->id],
            ])
            ->assertSessionHas('success', 'Presenze registrate: 0.');

        $this->assertNull($estranea->fresh()->checkin_at);
    }

    public function test_senza_selezione_la_validazione_fallisce(): void
    {
        $this->actingAs($this->admin)
            ->post("/eventi/{$this->evento->id}/presenze", ['prenotazioni' => []])
            ->assertSessionHasErrors('prenotazioni');
    }

    public function test_evento_di_un_altro_tenant_da_404(): void
    {
        $altra = Associazione::factory()->create();
        $estraneo = Utente::factory()->adminAssociazione()->create(['associazione_id' => $altra->id]);

        $this->actingAs($estraneo)->get("/eventi/{$this->evento->id}/presenze")->assertNotFound();
        $this->actingAs($estraneo)
            ->post("/eventi/{$this->evento->id}/presenze", ['prenotazioni' => [(string) Str::uuid()]])
            ->assertNotFound();
    }

    public function test_foglio_presenze_pdf_viene_generato(): void
    {
        Prenotazione::factory()->confermata()->create(['evento_id' => $this->evento->id]);

        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        EventoPartecipante::create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $this->evento->id,
            'socio_id' => $socio->id,
            'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
            'data_conferma' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get("/eventi/{$this->evento->id}/foglio-presenze.pdf");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_foglio_presenze_di_un_altro_tenant_da_404(): void
    {
        $altra = Associazione::factory()->create();
        $estraneo = Utente::factory()->adminAssociazione()->create(['associazione_id' => $altra->id]);

        $this->actingAs($estraneo)
            ->get("/eventi/{$this->evento->id}/foglio-presenze.pdf")
            ->assertNotFound();
    }
}
