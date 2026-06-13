<?php

namespace Tests\Feature\SpaziRisorse;

use App\Models\Associazione;
use App\Models\Attrezzatura;
use App\Models\PrestitoAttrezzatura;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrestitoAttrezzaturaTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    private Attrezzatura $attrezzatura;

    private Socio $socio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->attrezzatura = Attrezzatura::factory()->create([
            'associazione_id' => $this->associazione->id,
            'quantita' => 5,
        ]);
        $this->socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/prestiti')->assertRedirect('/login');
    }

    public function test_index_filtra_per_stato(): void
    {
        PrestitoAttrezzatura::factory()->create([
            'associazione_id' => $this->associazione->id,
            'attrezzatura_id' => $this->attrezzatura->id,
            'socio_id' => $this->socio->id,
        ]);
        PrestitoAttrezzatura::factory()->restituito()->create([
            'associazione_id' => $this->associazione->id,
            'attrezzatura_id' => $this->attrezzatura->id,
            'socio_id' => $this->socio->id,
        ]);

        $attivi = $this->actingAs($this->admin)->get('/prestiti?filtro=attivi');
        $attivi->assertOk();
        $this->assertCount(1, $attivi->viewData('prestiti'));

        $restituiti = $this->actingAs($this->admin)->get('/prestiti?filtro=restituiti');
        $this->assertCount(1, $restituiti->viewData('prestiti'));
    }

    public function test_creazione_prestito(): void
    {
        $response = $this->actingAs($this->admin)->post('/prestiti', [
            'attrezzatura_id' => $this->attrezzatura->id,
            'socio_id' => $this->socio->id,
            'quantita' => 2,
            'dal' => now()->toDateString(),
            'restituzione_prevista' => now()->addWeek()->toDateString(),
        ]);

        $response->assertRedirect(route('prestiti.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('prestiti_attrezzature', [
            'attrezzatura_id' => $this->attrezzatura->id,
            'socio_id' => $this->socio->id,
            'quantita' => 2,
            'restituito_at' => null,
        ]);
    }

    public function test_prestito_bloccato_se_disponibilita_insufficiente(): void
    {
        // 4 dei 5 pezzi già in prestito → disponibili 1
        PrestitoAttrezzatura::factory()->create([
            'associazione_id' => $this->associazione->id,
            'attrezzatura_id' => $this->attrezzatura->id,
            'socio_id' => $this->socio->id,
            'quantita' => 4,
        ]);

        $response = $this->actingAs($this->admin)
            ->from('/prestiti')
            ->post('/prestiti', [
                'attrezzatura_id' => $this->attrezzatura->id,
                'socio_id' => $this->socio->id,
                'quantita' => 2, // richiesti 2 ma 1 disponibile
                'dal' => now()->toDateString(),
                'restituzione_prevista' => now()->addWeek()->toDateString(),
            ]);

        $response->assertSessionHasErrors('quantita');
        // Solo il prestito originale esiste
        $this->assertDatabaseCount('prestiti_attrezzature', 1);
    }

    public function test_restituzione_riapre_la_disponibilita(): void
    {
        $prestito = PrestitoAttrezzatura::factory()->create([
            'associazione_id' => $this->associazione->id,
            'attrezzatura_id' => $this->attrezzatura->id,
            'socio_id' => $this->socio->id,
            'quantita' => 5,
        ]);

        $this->assertSame(0, $this->attrezzatura->fresh()->quantitaDisponibile());

        $response = $this->actingAs($this->admin)->post("/prestiti/{$prestito->id}/restituisci");

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertNotNull($prestito->fresh()->restituito_at);
        $this->assertSame(5, $this->attrezzatura->fresh()->quantitaDisponibile());
    }

    public function test_restituzione_di_altro_tenant_restituisce_404(): void
    {
        $altrui = PrestitoAttrezzatura::factory()->create();

        $this->actingAs($this->admin)->post("/prestiti/{$altrui->id}/restituisci")->assertNotFound();

        $this->assertNull($altrui->fresh()->restituito_at);
    }

    public function test_socio_di_altro_tenant_rifiutato(): void
    {
        $altra = Associazione::factory()->create();
        $socioAltrui = Socio::factory()->create(['associazione_id' => $altra->id]);

        $response = $this->actingAs($this->admin)
            ->from('/prestiti')
            ->post('/prestiti', [
                'attrezzatura_id' => $this->attrezzatura->id,
                'socio_id' => $socioAltrui->id,
                'quantita' => 1,
                'dal' => now()->toDateString(),
                'restituzione_prevista' => now()->addWeek()->toDateString(),
            ]);

        $response->assertSessionHasErrors('socio_id');
        $this->assertDatabaseCount('prestiti_attrezzature', 0);
    }

    public function test_eliminazione_prestito(): void
    {
        $prestito = PrestitoAttrezzatura::factory()->create([
            'associazione_id' => $this->associazione->id,
            'attrezzatura_id' => $this->attrezzatura->id,
            'socio_id' => $this->socio->id,
        ]);

        $this->actingAs($this->admin)->delete("/prestiti/{$prestito->id}")
            ->assertRedirect(route('prestiti.index'));

        $this->assertDatabaseMissing('prestiti_attrezzature', ['id' => $prestito->id]);
    }

    public function test_storico_prestiti_nella_scheda_socio(): void
    {
        PrestitoAttrezzatura::factory()->create([
            'associazione_id' => $this->associazione->id,
            'attrezzatura_id' => $this->attrezzatura->id,
            'socio_id' => $this->socio->id,
        ]);

        $response = $this->actingAs($this->admin)->get("/soci/{$this->socio->id}?tab=prestiti");

        $response->assertOk();
        $response->assertSee('Prestiti attrezzature');
        $response->assertSee($this->attrezzatura->nome);
    }
}
