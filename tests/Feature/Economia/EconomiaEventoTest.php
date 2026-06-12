<?php

namespace Tests\Feature\Economia;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\SpesaEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.17 — Economia evento: budget, spese vs incassi (prenotazioni pagate)
 * e margine, permessi e isolamento tenant.
 */
class EconomiaEventoTest extends TestCase
{
    use ConfiguraTenantEconomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
    }

    protected function creaEvento(array $attributes = []): Evento
    {
        return Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            ...$attributes,
        ]);
    }

    public function test_pagina_economia_richiede_login_e_permesso(): void
    {
        $evento = $this->creaEvento();

        $this->get(route('eventi.economia.show', $evento))->assertRedirect();

        $this->actingAs($this->creaUtenteSenzaPermessi())
            ->get(route('eventi.economia.show', $evento))
            ->assertForbidden();
    }

    public function test_pagina_economia_calcola_incassi_spese_e_margine(): void
    {
        $evento = $this->creaEvento(['prezzo' => 10, 'budget' => 100]);

        // Due prenotazioni pagate (10 + 20) e una non pagata (ignorata)
        Prenotazione::factory()->pagata()->create([
            'evento_id' => $evento->id,
            'associazione_id' => $this->associazione->id,
            'importo_totale' => 10,
        ]);
        Prenotazione::factory()->pagata()->create([
            'evento_id' => $evento->id,
            'associazione_id' => $this->associazione->id,
            'importo_totale' => 20,
        ]);
        Prenotazione::factory()->inAttesaPagamento(15)->create([
            'evento_id' => $evento->id,
            'associazione_id' => $this->associazione->id,
        ]);

        SpesaEvento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $evento->id,
            'importo' => 12.50,
        ]);

        $response = $this->actingAs($this->admin)->get(route('eventi.economia.show', $evento));

        $response->assertOk();
        $response->assertViewHas('incassi', 30.0);
        $response->assertViewHas('totaleSpese', 12.5);
        $response->assertViewHas('margine', 17.5);
    }

    public function test_aggiorna_budget(): void
    {
        $evento = $this->creaEvento(['budget' => null]);

        $this->actingAs($this->admin)
            ->put(route('eventi.economia.budget', $evento), ['budget' => '250.00'])
            ->assertRedirect(route('eventi.economia.show', $evento));

        $this->assertEqualsWithDelta(250.0, (float) $evento->fresh()->budget, 0.001);
    }

    public function test_store_spesa_nel_tenant_corrente(): void
    {
        $evento = $this->creaEvento();

        $this->actingAs($this->admin)->post(route('eventi.economia.spese.store', $evento), [
            'descrizione' => 'Noleggio service audio',
            'importo' => '80.00',
            'data' => '2026-06-10',
        ])->assertRedirect(route('eventi.economia.show', $evento));

        $this->assertDatabaseHas('spese_evento', [
            'evento_id' => $evento->id,
            'associazione_id' => $this->associazione->id,
            'descrizione' => 'Noleggio service audio',
        ]);
    }

    public function test_destroy_spesa(): void
    {
        $evento = $this->creaEvento();
        $spesa = SpesaEvento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $evento->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('eventi.economia.spese.destroy', $spesa))
            ->assertRedirect(route('eventi.economia.show', $evento));

        $this->assertDatabaseMissing('spese_evento', ['id' => $spesa->id]);
    }

    public function test_evento_di_altro_tenant_non_raggiungibile(): void
    {
        $altra = Associazione::factory()->create();
        $eventoAltrui = Evento::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->get(route('eventi.economia.show', $eventoAltrui->id))
            ->assertNotFound();
    }

    public function test_spesa_di_altro_tenant_non_eliminabile(): void
    {
        $altra = Associazione::factory()->create();
        $eventoAltrui = Evento::factory()->create(['associazione_id' => $altra->id]);
        $spesaAltrui = SpesaEvento::factory()->create([
            'associazione_id' => $altra->id,
            'evento_id' => $eventoAltrui->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('eventi.economia.spese.destroy', $spesaAltrui->id))
            ->assertNotFound();

        $this->assertDatabaseHas('spese_evento', ['id' => $spesaAltrui->id]);
    }
}
