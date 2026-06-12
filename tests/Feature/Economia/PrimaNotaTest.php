<?php

namespace Tests\Feature\Economia;

use App\Models\Associazione;
use App\Models\CategoriaMovimento;
use App\Models\Movimento;
use App\Services\Economia\PrimaNotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.13 — Prima nota: CRUD, saldo progressivo, categorie seedate,
 * permessi e isolamento tenant.
 */
class PrimaNotaTest extends TestCase
{
    use ConfiguraTenantEconomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
    }

    protected function categoriaEntrata(): CategoriaMovimento
    {
        return CategoriaMovimento::factory()->entrata()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_index_richiede_login(): void
    {
        $this->get(route('prima-nota.index'))->assertRedirect();
    }

    public function test_utente_senza_permessi_riceve_403(): void
    {
        $this->actingAs($this->creaUtenteSenzaPermessi())
            ->get(route('prima-nota.index'))
            ->assertForbidden();
    }

    public function test_al_primo_accesso_le_categorie_vengono_seedate_per_il_tenant(): void
    {
        $this->assertSame(0, CategoriaMovimento::allTenants()->count());

        $this->actingAs($this->admin)->get(route('prima-nota.index'))->assertOk();

        $this->assertSame(
            count(PrimaNotaService::CATEGORIE_DEFAULT),
            CategoriaMovimento::allTenants()->where('associazione_id', $this->associazione->id)->count()
        );

        // Secondo accesso: nessun duplicato
        $this->actingAs($this->admin)->get(route('prima-nota.index'))->assertOk();

        $this->assertSame(
            count(PrimaNotaService::CATEGORIE_DEFAULT),
            CategoriaMovimento::allTenants()->where('associazione_id', $this->associazione->id)->count()
        );
    }

    public function test_index_mostra_solo_i_movimenti_del_proprio_tenant(): void
    {
        Movimento::factory()->count(2)->create(['associazione_id' => $this->associazione->id]);

        $altra = Associazione::factory()->create();
        Movimento::factory()->create(['associazione_id' => $altra->id]);

        $response = $this->actingAs($this->admin)->get(route('prima-nota.index'));

        $response->assertOk();
        $response->assertViewHas('movimenti', fn ($movimenti) => $movimenti->total() === 2);
    }

    public function test_saldo_progressivo_calcolato_correttamente(): void
    {
        Movimento::factory()->entrata()->create([
            'associazione_id' => $this->associazione->id,
            'importo' => 100,
            'data' => '2026-01-10',
        ]);
        Movimento::factory()->uscita()->create([
            'associazione_id' => $this->associazione->id,
            'importo' => 30,
            'data' => '2026-01-20',
        ]);

        $response = $this->actingAs($this->admin)->get(route('prima-nota.index'));

        $response->assertOk();
        $response->assertViewHas('saldoTotale', 70.0);

        $saldi = $response->viewData('saldi');
        $movimenti = $response->viewData('movimenti');

        // Ordinati per data desc: la riga più recente porta il saldo totale
        $this->assertEqualsWithDelta(70.0, $saldi[$movimenti[0]->id], 0.001);
        $this->assertEqualsWithDelta(100.0, $saldi[$movimenti[1]->id], 0.001);
    }

    public function test_store_crea_movimento_manuale_nel_tenant_corrente(): void
    {
        $categoria = $this->categoriaEntrata();

        $response = $this->actingAs($this->admin)->post(route('prima-nota.store'), [
            'tipo' => Movimento::TIPO_ENTRATA,
            'importo' => '50.00',
            'data' => '2026-06-01',
            'categoria_id' => $categoria->id,
            'descrizione' => 'Contributo libero',
            'metodo' => 'contanti',
        ]);

        $response->assertRedirect(route('prima-nota.index'));

        $this->assertDatabaseHas('movimenti', [
            'associazione_id' => $this->associazione->id,
            'tipo' => Movimento::TIPO_ENTRATA,
            'descrizione' => 'Contributo libero',
            'registrato_da' => $this->admin->id,
        ]);
    }

    public function test_store_rifiuta_categoria_di_altro_tenant(): void
    {
        $altra = Associazione::factory()->create();
        $categoriaAltrui = CategoriaMovimento::factory()->entrata()->create([
            'associazione_id' => $altra->id,
        ]);

        $response = $this->actingAs($this->admin)->post(route('prima-nota.store'), [
            'tipo' => Movimento::TIPO_ENTRATA,
            'importo' => '50.00',
            'data' => '2026-06-01',
            'categoria_id' => $categoriaAltrui->id,
        ]);

        $response->assertSessionHasErrors('categoria_id');
        $this->assertSame(0, Movimento::allTenants()->count());
    }

    public function test_store_rifiuta_categoria_di_tipo_incoerente(): void
    {
        $categoriaUscita = CategoriaMovimento::factory()->uscita()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $response = $this->actingAs($this->admin)->post(route('prima-nota.store'), [
            'tipo' => Movimento::TIPO_ENTRATA,
            'importo' => '50.00',
            'data' => '2026-06-01',
            'categoria_id' => $categoriaUscita->id,
        ]);

        $response->assertSessionHasErrors('categoria_id');
    }

    public function test_update_movimento_manuale(): void
    {
        $categoria = $this->categoriaEntrata();
        $movimento = Movimento::factory()->entrata()->create([
            'associazione_id' => $this->associazione->id,
            'categoria_id' => $categoria->id,
        ]);

        $response = $this->actingAs($this->admin)->put(route('prima-nota.update', $movimento), [
            'tipo' => Movimento::TIPO_ENTRATA,
            'importo' => '99.50',
            'data' => '2026-06-02',
            'categoria_id' => $categoria->id,
            'descrizione' => 'Aggiornato',
        ]);

        $response->assertRedirect(route('prima-nota.index'));

        $this->assertDatabaseHas('movimenti', [
            'id' => $movimento->id,
            'descrizione' => 'Aggiornato',
            'importo' => 99.50,
        ]);
    }

    public function test_movimento_automatico_non_modificabile_ne_eliminabile(): void
    {
        $categoria = $this->categoriaEntrata();
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, ['data_pagamento' => now()->toDateString()]);

        $movimento = Movimento::factory()->entrata()->create([
            'associazione_id' => $this->associazione->id,
            'categoria_id' => $categoria->id,
            'origine_type' => $quota->getMorphClass(),
            'origine_id' => $quota->id,
        ]);

        $this->actingAs($this->admin)->put(route('prima-nota.update', $movimento), [
            'tipo' => Movimento::TIPO_ENTRATA,
            'importo' => '1.00',
            'data' => '2026-06-02',
            'categoria_id' => $categoria->id,
        ])->assertRedirect(route('prima-nota.index'));

        $this->assertDatabaseHas('movimenti', ['id' => $movimento->id, 'importo' => $movimento->importo]);

        $this->actingAs($this->admin)->delete(route('prima-nota.destroy', $movimento))
            ->assertRedirect(route('prima-nota.index'));

        $this->assertDatabaseHas('movimenti', ['id' => $movimento->id]);
    }

    public function test_destroy_elimina_movimento_manuale(): void
    {
        $movimento = Movimento::factory()->entrata()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->admin)->delete(route('prima-nota.destroy', $movimento))
            ->assertRedirect(route('prima-nota.index'));

        $this->assertDatabaseMissing('movimenti', ['id' => $movimento->id]);
    }

    public function test_movimento_di_altro_tenant_non_raggiungibile(): void
    {
        $altra = Associazione::factory()->create();
        $movimentoAltrui = Movimento::factory()->entrata()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->delete(route('prima-nota.destroy', $movimentoAltrui->id))
            ->assertNotFound();

        $this->assertDatabaseHas('movimenti', ['id' => $movimentoAltrui->id]);
    }
}
