<?php

namespace Tests\Feature\VolontariTurni;

use App\Models\Associazione;
use App\Models\Mansione;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.2 — Mansioni/competenze: CRUD, unicità per tenant, isolamento e permessi.
 */
class MansioniTest extends TestCase
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

    public function test_pagina_elenca_le_mansioni_del_tenant(): void
    {
        Mansione::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Accoglienza']);

        $altra = Associazione::factory()->create();
        Mansione::factory()->create(['associazione_id' => $altra->id, 'nome' => 'Segreteria altrui']);

        $response = $this->actingAs($this->admin)->get('/mansioni');

        $response->assertOk();
        $response->assertViewIs('volontari-turni.mansioni.index');
        $response->assertSee('Accoglienza');
        $response->assertDontSee('Segreteria altrui');
    }

    public function test_crea_una_mansione(): void
    {
        $this->actingAs($this->admin)
            ->post('/mansioni', ['nome' => 'Cucina', 'descrizione' => 'Preparazione pasti'])
            ->assertRedirect('/mansioni')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('mansioni', [
            'associazione_id' => $this->associazione->id,
            'nome' => 'Cucina',
        ]);
    }

    public function test_nome_mansione_unico_per_tenant(): void
    {
        Mansione::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Logistica']);

        $this->actingAs($this->admin)
            ->post('/mansioni', ['nome' => 'Logistica'])
            ->assertSessionHasErrors('nome');

        $this->assertSame(1, Mansione::where('nome', 'Logistica')->count());
    }

    public function test_aggiorna_ed_elimina_mansione(): void
    {
        $mansione = Mansione::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Vecchio']);

        $this->actingAs($this->admin)
            ->put("/mansioni/{$mansione->id}", ['nome' => 'Nuovo'])
            ->assertRedirect('/mansioni');
        $this->assertDatabaseHas('mansioni', ['id' => $mansione->id, 'nome' => 'Nuovo']);

        $this->actingAs($this->admin)
            ->delete("/mansioni/{$mansione->id}")
            ->assertRedirect('/mansioni');
        $this->assertDatabaseMissing('mansioni', ['id' => $mansione->id]);
    }

    public function test_non_puo_modificare_mansione_di_altro_tenant(): void
    {
        $altra = Associazione::factory()->create();
        $mansione = Mansione::factory()->create(['associazione_id' => $altra->id]);

        // Il global scope nasconde la mansione di altro tenant → 404 sul binding.
        $this->actingAs($this->admin)
            ->put("/mansioni/{$mansione->id}", ['nome' => 'Hijack'])
            ->assertNotFound();
    }

    public function test_accesso_richiede_autenticazione_admin(): void
    {
        // Guard 'web' (utenti) richiesta: senza login admin → redirect alla login.
        $this->get('/mansioni')->assertRedirect();
    }
}
