<?php

namespace Tests\Feature\SpaziRisorse;

use App\Models\Associazione;
use App\Models\Sede;
use App\Models\Spazio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpazioCrudTest extends TestCase
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

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/spazi')->assertRedirect('/login');
    }

    public function test_index_mostra_solo_gli_spazi_del_tenant(): void
    {
        $altra = Associazione::factory()->create();

        Spazio::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Sala Conferenze']);
        Spazio::factory()->create(['associazione_id' => $altra->id, 'nome' => 'Sala Altrui']);

        $response = $this->actingAs($this->admin)->get('/spazi');

        $response->assertOk();
        $response->assertViewIs('spazi-risorse.spazi.index');
        $response->assertSee('Sala Conferenze');
        $response->assertDontSee('Sala Altrui');
    }

    public function test_index_filtra_per_ricerca(): void
    {
        Spazio::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Sala Verde', 'descrizione' => null]);
        Spazio::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Magazzino', 'descrizione' => null]);

        $response = $this->actingAs($this->admin)->get('/spazi?search=Verde');

        $response->assertOk();
        $this->assertCount(1, $response->viewData('spazi'));
        $response->assertSee('Sala Verde');
    }

    public function test_operatore_puo_creare_uno_spazio(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $response = $this->actingAs($operatore)->post('/spazi', [
            'nome' => 'Aula Magna',
            'capienza' => 120,
            'attivo' => '1',
        ]);

        $response->assertRedirect(route('spazi.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('spazi', [
            'nome' => 'Aula Magna',
            'capienza' => 120,
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_creazione_con_sede_del_tenant(): void
    {
        $sede = Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Sede centrale']);

        $this->actingAs($this->admin)->post('/spazi', [
            'nome' => 'Sala con sede',
            'sede_id' => $sede->id,
            'attivo' => '1',
        ])->assertRedirect(route('spazi.index'));

        $this->assertDatabaseHas('spazi', ['nome' => 'Sala con sede', 'sede_id' => $sede->id]);
    }

    public function test_creazione_con_sede_di_altro_tenant_fallisce(): void
    {
        $altra = Associazione::factory()->create();
        $sedeAltrui = Sede::create(['associazione_id' => $altra->id, 'nome' => 'Sede altrui']);

        $response = $this->actingAs($this->admin)
            ->from('/spazi')
            ->post('/spazi', [
                'nome' => 'Sala hack',
                'sede_id' => $sedeAltrui->id,
                'attivo' => '1',
            ]);

        $response->assertSessionHasErrors('sede_id');
        $this->assertDatabaseMissing('spazi', ['nome' => 'Sala hack']);
    }

    public function test_validazione_nome_obbligatorio(): void
    {
        $response = $this->actingAs($this->admin)
            ->from('/spazi')
            ->post('/spazi', ['nome' => '', 'attivo' => '1']);

        $response->assertSessionHasErrors('nome');
        $this->assertDatabaseCount('spazi', 0);
    }

    public function test_aggiornamento_spazio(): void
    {
        $spazio = Spazio::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Vecchio']);

        $this->actingAs($this->admin)->put("/spazi/{$spazio->id}", [
            'nome' => 'Nuovo nome',
            'attivo' => '0',
        ])->assertRedirect(route('spazi.index'));

        $fresh = $spazio->fresh();
        $this->assertSame('Nuovo nome', $fresh->nome);
        $this->assertFalse($fresh->attivo);
    }

    public function test_aggiornamento_di_altro_tenant_restituisce_404(): void
    {
        $altrui = Spazio::factory()->create(['nome' => 'Riservato']);

        $this->actingAs($this->admin)->put("/spazi/{$altrui->id}", [
            'nome' => 'Hack',
            'attivo' => '1',
        ])->assertNotFound();

        $this->assertSame('Riservato', $altrui->fresh()->nome);
    }

    public function test_eliminazione_spazio(): void
    {
        $spazio = Spazio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->delete("/spazi/{$spazio->id}")
            ->assertRedirect(route('spazi.index'));

        $this->assertDatabaseMissing('spazi', ['id' => $spazio->id]);
    }

    public function test_eliminazione_di_altro_tenant_restituisce_404(): void
    {
        $altrui = Spazio::factory()->create();

        $this->actingAs($this->admin)->delete("/spazi/{$altrui->id}")->assertNotFound();

        $this->assertDatabaseHas('spazi', ['id' => $altrui->id]);
    }
}
