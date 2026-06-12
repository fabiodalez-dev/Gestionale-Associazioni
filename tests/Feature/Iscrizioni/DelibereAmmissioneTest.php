<?php

namespace Tests\Feature\Iscrizioni;

use App\Models\Associazione;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Delibere di ammissione (f.14 del piano vita sociale): campi opzionali
 * delibera_numero/delibera_data all'approvazione di una richiesta di
 * iscrizione, salvati sulla richiesta e tracciati nello storico socio.
 */
class DelibereAmmissioneTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    private function creaRichiestaPendente(): RichiestaIscrizione
    {
        return RichiestaIscrizione::factory()
            ->inAttesa()
            ->conSocio()
            ->create(['associazione_id' => $this->associazione->id]);
    }

    public function test_approvazione_con_delibera_salva_numero_e_data_sulla_richiesta(): void
    {
        $richiesta = $this->creaRichiestaPendente();
        $socio = $richiesta->socio;

        $response = $this->actingAs($this->admin)->post(route('richieste-iscrizione.approva', $richiesta), [
            'delibera_numero' => '12/2026',
            'delibera_data' => '2026-06-10',
        ]);

        $response->assertRedirect(route('richieste-iscrizione.index'));
        $response->assertSessionHas('success');

        $richiesta->refresh();
        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $richiesta->stato);
        $this->assertSame('12/2026', $richiesta->delibera_numero);
        $this->assertSame('2026-06-10', $richiesta->delibera_data->toDateString());

        // Il socio è attivo e lo storico riporta la delibera
        $this->assertSame(Socio::STATO_ATTIVO, $socio->fresh()->stato);
        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Approvazione iscrizione',
        ]);

        $storico = $socio->storicoAttivita()
            ->withoutGlobalScope('associazione')
            ->where('tipo_attivita', 'Approvazione iscrizione')
            ->first();
        $this->assertStringContainsString('delibera di ammissione n. 12/2026', $storico->descrizione);
        $this->assertStringContainsString('10/06/2026', $storico->descrizione);
    }

    public function test_approvazione_senza_delibera_lascia_i_campi_vuoti(): void
    {
        $richiesta = $this->creaRichiestaPendente();

        $this->actingAs($this->admin)
            ->post(route('richieste-iscrizione.approva', $richiesta))
            ->assertRedirect(route('richieste-iscrizione.index'));

        $richiesta->refresh();
        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $richiesta->stato);
        $this->assertNull($richiesta->delibera_numero);
        $this->assertNull($richiesta->delibera_data);
    }

    public function test_delibera_data_non_valida_viene_rifiutata(): void
    {
        $richiesta = $this->creaRichiestaPendente();

        $this->actingAs($this->admin)
            ->from(route('richieste-iscrizione.index'))
            ->post(route('richieste-iscrizione.approva', $richiesta), [
                'delibera_numero' => '12/2026',
                'delibera_data' => 'non-una-data',
            ])
            ->assertSessionHasErrors('delibera_data');

        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->refresh()->stato);
    }

    public function test_la_delibera_compare_nello_storico_della_pagina_richieste(): void
    {
        $richiesta = $this->creaRichiestaPendente();

        $this->actingAs($this->admin)->post(route('richieste-iscrizione.approva', $richiesta), [
            'delibera_numero' => '3/2026',
            'delibera_data' => '2026-05-20',
        ]);

        $this->actingAs($this->admin)
            ->get(route('richieste-iscrizione.index'))
            ->assertOk()
            ->assertSee('Delibera n. 3/2026')
            ->assertSee('20/05/2026');
    }
}
