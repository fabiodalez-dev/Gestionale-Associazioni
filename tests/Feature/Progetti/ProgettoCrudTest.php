<?php

namespace Tests\Feature\Progetti;

use App\Models\Associazione;
use App\Models\Progetto;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.12 — CRUD progetti con dettaglio: rotte protette, permessi struttura.*,
 * isolamento tenant e validazione.
 */
class ProgettoCrudTest extends TestCase
{
    use ConfiguraTenantProgetti;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
    }

    public function test_index_richiede_login(): void
    {
        $this->get(route('progetti.index'))->assertRedirect();
    }

    public function test_operatore_senza_permessi_struttura_riceve_403(): void
    {
        $this->actingAs($this->creaOperatore())
            ->get(route('progetti.index'))
            ->assertForbidden();
    }

    public function test_admin_vede_solo_i_progetti_del_proprio_tenant(): void
    {
        Progetto::factory()->count(2)->create(['associazione_id' => $this->associazione->id]);

        $altra = Associazione::factory()->create();
        Progetto::factory()->create(['associazione_id' => $altra->id]);

        $response = $this->actingAs($this->admin)->get(route('progetti.index'));

        $response->assertOk();
        $response->assertViewHas('progetti', fn ($progetti) => $progetti->total() === 2);
    }

    public function test_filtro_per_stato_e_ricerca_nome(): void
    {
        Progetto::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Festa Estate', 'stato' => Progetto::STATO_ATTIVO]);
        Progetto::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Archivio Storico', 'stato' => Progetto::STATO_CONCLUSO]);

        $this->actingAs($this->admin)
            ->get(route('progetti.index', ['stato' => Progetto::STATO_ATTIVO]))
            ->assertOk()
            ->assertSee('Festa Estate')
            ->assertDontSee('Archivio Storico');

        $this->actingAs($this->admin)
            ->get(route('progetti.index', ['search' => 'Archivio']))
            ->assertOk()
            ->assertSee('Archivio Storico')
            ->assertDontSee('Festa Estate');
    }

    public function test_pagine_create_ed_edit_si_renderizzano(): void
    {
        $this->actingAs($this->admin)->get(route('progetti.create'))
            ->assertOk()
            ->assertSee('Nuovo progetto');

        $progetto = Progetto::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->get(route('progetti.edit', $progetto))
            ->assertOk()
            ->assertSee('Modifica progetto');
    }

    public function test_store_crea_progetto_nel_tenant_corrente(): void
    {
        $response = $this->actingAs($this->admin)->post(route('progetti.store'), [
            'nome' => 'Progetto Solidale',
            'descrizione' => 'Raccolta fondi',
            'dal' => '2026-01-01',
            'al' => '2026-12-31',
            'stato' => Progetto::STATO_ATTIVO,
            'budget' => '1500.00',
        ]);

        $progetto = Progetto::query()->where('nome', 'Progetto Solidale')->first();
        $this->assertNotNull($progetto);
        $response->assertRedirect(route('progetti.show', $progetto));

        $this->assertDatabaseHas('progetti', [
            'associazione_id' => $this->associazione->id,
            'nome' => 'Progetto Solidale',
            'stato' => Progetto::STATO_ATTIVO,
        ]);
    }

    public function test_store_valida_nome_obbligatorio_e_date_coerenti(): void
    {
        $this->actingAs($this->admin)->post(route('progetti.store'), [
            'nome' => '',
            'stato' => Progetto::STATO_BOZZA,
        ])->assertSessionHasErrors('nome');

        $this->actingAs($this->admin)->post(route('progetti.store'), [
            'nome' => 'X',
            'stato' => Progetto::STATO_BOZZA,
            'dal' => '2026-06-01',
            'al' => '2026-05-01',
        ])->assertSessionHasErrors('al');
    }

    public function test_store_rifiuta_responsabile_di_altro_tenant(): void
    {
        $altra = Associazione::factory()->create();
        $responsabileAltrui = Utente::factory()->adminAssociazione()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)->post(route('progetti.store'), [
            'nome' => 'Progetto',
            'stato' => Progetto::STATO_BOZZA,
            'responsabile_id' => $responsabileAltrui->id,
        ])->assertSessionHasErrors('responsabile_id');
    }

    public function test_show_mostra_il_dettaglio(): void
    {
        $progetto = Progetto::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Dettaglio Progetto',
        ]);

        $this->actingAs($this->admin)
            ->get(route('progetti.show', $progetto))
            ->assertOk()
            ->assertSee('Dettaglio Progetto')
            ->assertViewHas('rendiconto')
            ->assertViewHas('oreVolontariato');
    }

    public function test_update_modifica_il_progetto(): void
    {
        $progetto = Progetto::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->put(route('progetti.update', $progetto), [
            'nome' => 'Nome aggiornato',
            'stato' => Progetto::STATO_CONCLUSO,
        ])->assertRedirect(route('progetti.show', $progetto));

        $this->assertDatabaseHas('progetti', [
            'id' => $progetto->id,
            'nome' => 'Nome aggiornato',
            'stato' => Progetto::STATO_CONCLUSO,
        ]);
    }

    public function test_destroy_elimina_il_progetto(): void
    {
        $progetto = Progetto::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->delete(route('progetti.destroy', $progetto))
            ->assertRedirect(route('progetti.index'));

        $this->assertDatabaseMissing('progetti', ['id' => $progetto->id]);
    }

    public function test_progetto_di_altro_tenant_non_raggiungibile(): void
    {
        $altra = Associazione::factory()->create();
        $progettoAltrui = Progetto::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)->get(route('progetti.show', $progettoAltrui->id))->assertNotFound();
        $this->actingAs($this->admin)->delete(route('progetti.destroy', $progettoAltrui->id))->assertNotFound();

        $this->assertDatabaseHas('progetti', ['id' => $progettoAltrui->id]);
    }

    public function test_operatore_non_puo_creare_progetti(): void
    {
        $this->actingAs($this->creaOperatore())->post(route('progetti.store'), [
            'nome' => 'Vietato',
            'stato' => Progetto::STATO_BOZZA,
        ])->assertForbidden();

        $this->assertDatabaseMissing('progetti', ['nome' => 'Vietato']);
    }
}
