<?php

namespace Tests\Feature\Operativita;

use App\Models\Associazione;
use App\Models\NotaSocio;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.3 — Note interne sul socio: tab "Note" nella scheda con timeline,
 * pin opzionale, solo admin del tenant, isolamento multi-tenant.
 */
class NoteSocioTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    private Socio $socio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
    }

    public function test_crea_una_nota_con_autore(): void
    {
        $this->actingAs($this->admin)
            ->post("/soci/{$this->socio->id}/note", [
                'testo' => 'Ha chiesto informazioni sul rinnovo.',
                'fissata' => '1',
            ])
            ->assertRedirect("/soci/{$this->socio->id}?tab=note")
            ->assertSessionHas('success', 'Nota aggiunta.');

        $this->assertDatabaseHas('note_socio', [
            'socio_id' => $this->socio->id,
            'associazione_id' => $this->associazione->id,
            'autore_id' => $this->admin->id,
            'testo' => 'Ha chiesto informazioni sul rinnovo.',
            'fissata' => true,
        ]);
    }

    public function test_il_testo_e_obbligatorio(): void
    {
        $this->actingAs($this->admin)
            ->from("/soci/{$this->socio->id}")
            ->post("/soci/{$this->socio->id}/note", ['testo' => ''])
            ->assertSessionHasErrors('testo');
    }

    public function test_le_note_compaiono_nella_scheda_socio_con_le_fissate_in_cima(): void
    {
        NotaSocio::factory()->create([
            'socio_id' => $this->socio->id,
            'autore_id' => $this->admin->id,
            'testo' => 'Nota normale più recente',
        ]);
        NotaSocio::factory()->fissata()->create([
            'socio_id' => $this->socio->id,
            'autore_id' => $this->admin->id,
            'testo' => 'Nota fissata importante',
        ]);

        $response = $this->actingAs($this->admin)->get("/soci/{$this->socio->id}?tab=note");

        $response->assertOk();
        $response->assertSeeInOrder(['Nota fissata importante', 'Nota normale più recente']);
        $response->assertSee('Note'); // tab presente
    }

    public function test_toggle_pin(): void
    {
        $nota = NotaSocio::factory()->create([
            'socio_id' => $this->socio->id,
            'autore_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->patch("/soci/{$this->socio->id}/note/{$nota->id}/pin")
            ->assertRedirect("/soci/{$this->socio->id}?tab=note");

        $this->assertTrue($nota->fresh()->fissata);

        $this->actingAs($this->admin)
            ->patch("/soci/{$this->socio->id}/note/{$nota->id}/pin");

        $this->assertFalse($nota->fresh()->fissata);
    }

    public function test_elimina_una_nota(): void
    {
        $nota = NotaSocio::factory()->create([
            'socio_id' => $this->socio->id,
            'autore_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->delete("/soci/{$this->socio->id}/note/{$nota->id}")
            ->assertSessionHas('success', 'Nota eliminata.');

        $this->assertDatabaseMissing('note_socio', ['id' => $nota->id]);
    }

    public function test_socio_di_un_altro_tenant_non_raggiungibile(): void
    {
        $altra = Associazione::factory()->create();
        $estraneo = Socio::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->post("/soci/{$estraneo->id}/note", ['testo' => 'Tentativo cross-tenant'])
            ->assertNotFound();
    }

    public function test_nota_di_un_altro_socio_non_eliminabile_dal_percorso_sbagliato(): void
    {
        $altroSocio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $nota = NotaSocio::factory()->create([
            'socio_id' => $altroSocio->id,
            'autore_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->delete("/soci/{$this->socio->id}/note/{$nota->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('note_socio', ['id' => $nota->id]);
    }

    public function test_richiede_autenticazione(): void
    {
        $this->post("/soci/{$this->socio->id}/note", ['testo' => 'x'])->assertRedirect('/login');
    }
}
