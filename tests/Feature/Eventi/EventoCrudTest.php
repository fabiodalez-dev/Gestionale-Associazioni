<?php

namespace Tests\Feature\Eventi;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventoCrudTest extends TestCase
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
        $this->get('/eventi')->assertRedirect('/login');
    }

    public function test_index_mostra_solo_gli_eventi_del_tenant(): void
    {
        $altraAssociazione = Associazione::factory()->create();

        $mio = Evento::factory()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Assemblea Annuale']);
        Evento::factory()->create(['associazione_id' => $altraAssociazione->id, 'titolo' => 'Evento Altrui']);

        $response = $this->actingAs($this->admin)->get('/eventi');

        $response->assertOk();
        $response->assertViewIs('eventi.index');
        $response->assertSee('Assemblea Annuale');
        $response->assertDontSee('Evento Altrui');
        $this->assertTrue($response->viewData('eventi')->contains('id', $mio->id));
    }

    public function test_index_filtra_per_ricerca_su_titolo_descrizione_luogo(): void
    {
        Evento::factory()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Cena Sociale', 'descrizione' => null, 'luogo' => 'Milano']);
        Evento::factory()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Torneo', 'descrizione' => null, 'luogo' => 'Roma']);

        $response = $this->actingAs($this->admin)->get('/eventi?search=Milano');

        $response->assertOk();
        $this->assertCount(1, $response->viewData('eventi'));
        $response->assertSee('Cena Sociale');
    }

    public function test_index_mostra_il_contatore_tessere_create(): void
    {
        $evento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        Tessera::factory()->count(2)->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'evento_creazione_id' => $evento->id,
        ]);

        $response = $this->actingAs($this->admin)->get('/eventi');

        $response->assertOk();
        $this->assertSame(2, $response->viewData('eventi')->firstWhere('id', $evento->id)->tessere_create_count);
    }

    public function test_creazione_evento(): void
    {
        $response = $this->actingAs($this->admin)->post('/eventi', [
            'titolo' => 'Festa di Primavera',
            'data_evento' => '2026-07-15T18:30',
            'luogo' => 'Sede sociale',
            'descrizione' => 'Festa annuale.',
        ]);

        $response->assertRedirect(route('eventi.index'));
        $response->assertSessionHas('success', 'Evento creato con successo.');

        $this->assertDatabaseHas('eventi', [
            'titolo' => 'Festa di Primavera',
            'luogo' => 'Sede sociale',
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_validazione_creazione_titolo_e_data_obbligatori(): void
    {
        $response = $this->actingAs($this->admin)
            ->from('/eventi')
            ->post('/eventi', [
                'titolo' => '',
                'data_evento' => 'non-una-data',
            ]);

        $response->assertRedirect('/eventi');
        $response->assertSessionHasErrors(['titolo', 'data_evento']);
        $this->assertDatabaseCount('eventi', 0);
    }

    public function test_aggiornamento_evento(): void
    {
        $evento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);

        $response = $this->actingAs($this->admin)->put("/eventi/{$evento->id}", [
            'titolo' => 'Titolo Aggiornato',
            'data_evento' => '2026-09-01T21:00',
            'luogo' => null,
            'descrizione' => null,
        ]);

        $response->assertRedirect(route('eventi.index'));
        $response->assertSessionHas('success', 'Evento aggiornato con successo.');
        $this->assertSame('Titolo Aggiornato', $evento->fresh()->titolo);
    }

    public function test_aggiornamento_evento_di_altro_tenant_restituisce_404(): void
    {
        $altrui = Evento::factory()->create();

        $this->actingAs($this->admin)->put("/eventi/{$altrui->id}", [
            'titolo' => 'Hack',
            'data_evento' => '2026-09-01T21:00',
        ])->assertNotFound();

        $this->assertNotSame('Hack', $altrui->fresh()->titolo);
    }

    public function test_eliminazione_evento(): void
    {
        $evento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);

        $response = $this->actingAs($this->admin)->delete("/eventi/{$evento->id}");

        $response->assertRedirect(route('eventi.index'));
        $response->assertSessionHas('success', 'Evento eliminato con successo.');
        $this->assertSoftDeleted('eventi', ['id' => $evento->id]);
    }

    public function test_eliminazione_evento_di_altro_tenant_restituisce_404(): void
    {
        $altrui = Evento::factory()->create();

        $this->actingAs($this->admin)->delete("/eventi/{$altrui->id}")->assertNotFound();

        $this->assertNull($altrui->fresh()->deleted_at);
    }

    public function test_edit_con_id_di_altro_tenant_non_precompila_il_form(): void
    {
        $altrui = Evento::factory()->create(['titolo' => 'Segretissimo']);

        $response = $this->actingAs($this->admin)->get("/eventi?edit={$altrui->id}");

        $response->assertOk();
        $this->assertNull($response->viewData('editEvento'));
        $response->assertDontSee('Segretissimo');
    }

    public function test_edit_precompila_il_form_per_evento_del_tenant(): void
    {
        $evento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);

        $response = $this->actingAs($this->admin)->get("/eventi?edit={$evento->id}");

        $response->assertOk();
        $this->assertSame($evento->id, $response->viewData('editEvento')->id);
    }
}
