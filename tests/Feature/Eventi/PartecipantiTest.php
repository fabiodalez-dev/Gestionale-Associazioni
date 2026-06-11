<?php

namespace Tests\Feature\Eventi;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartecipantiTest extends TestCase
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
        $this->evento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);
    }

    private function creaSocio(array $attributes = []): Socio
    {
        return Socio::factory()->create(array_merge([
            'associazione_id' => $this->associazione->id,
        ], $attributes));
    }

    private function iscrivi(Socio $socio, string $stato = EventoPartecipante::STATO_NON_PARTECIPA): EventoPartecipante
    {
        return EventoPartecipante::create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $this->evento->id,
            'socio_id' => $socio->id,
            'stato_partecipazione' => $stato,
        ]);
    }

    public function test_index_lista_i_partecipanti_dell_evento(): void
    {
        $socio = $this->creaSocio(['nome' => 'Mario', 'cognome' => 'Rossi']);
        $this->iscrivi($socio, EventoPartecipante::STATO_CONFERMATO);

        $response = $this->actingAs($this->admin)
            ->get("/eventi/{$this->evento->id}/partecipanti");

        $response->assertOk();
        $response->assertViewIs('eventi.partecipanti');
        $response->assertSee('Mario');
        $response->assertSee('Rossi');
    }

    public function test_evento_di_altro_tenant_restituisce_404(): void
    {
        $altrui = Evento::factory()->create();

        $this->actingAs($this->admin)
            ->get("/eventi/{$altrui->id}/partecipanti")
            ->assertNotFound();
    }

    public function test_filtro_per_stato_non_altera_le_statistiche_globali(): void
    {
        $this->iscrivi($this->creaSocio(), EventoPartecipante::STATO_CONFERMATO);
        $this->iscrivi($this->creaSocio(), EventoPartecipante::STATO_FORSE);
        $this->iscrivi($this->creaSocio(), EventoPartecipante::STATO_NON_PARTECIPA);

        $response = $this->actingAs($this->admin)
            ->get("/eventi/{$this->evento->id}/partecipanti?status=Confermato");

        $response->assertOk();
        $this->assertCount(1, $response->viewData('partecipanti'));

        // Fix del bug legacy: le card statistiche restano calcolate sull'intero evento
        $stats = $response->viewData('stats');
        $this->assertSame(1, $stats['confermati']);
        $this->assertSame(1, $stats['forse']);
        $this->assertSame(1, $stats['non_partecipa']);
        $this->assertSame(3, $stats['totale']);
    }

    public function test_aggiunta_singolo_socio(): void
    {
        $socio = $this->creaSocio();

        $response = $this->actingAs($this->admin)
            ->post("/eventi/{$this->evento->id}/partecipanti", [
                'socio_id' => $socio->id,
                'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
                'note' => 'Arriva tardi',
            ]);

        $response->assertRedirect(route('eventi.partecipanti.index', $this->evento));
        $response->assertSessionHas('success', 'Socio aggiunto all\'evento!');

        $this->assertDatabaseHas('eventi_partecipanti', [
            'evento_id' => $this->evento->id,
            'socio_id' => $socio->id,
            'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
            'note' => 'Arriva tardi',
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_aggiunta_socio_gia_iscritto_fa_upsert(): void
    {
        $socio = $this->creaSocio();
        $this->iscrivi($socio, EventoPartecipante::STATO_FORSE);

        $this->actingAs($this->admin)
            ->post("/eventi/{$this->evento->id}/partecipanti", [
                'socio_id' => $socio->id,
                'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
                'note' => 'Aggiornato',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, EventoPartecipante::query()->where('evento_id', $this->evento->id)->count());
        $record = EventoPartecipante::query()->where('evento_id', $this->evento->id)->first();
        $this->assertSame(EventoPartecipante::STATO_CONFERMATO, $record->stato_partecipazione);
        $this->assertSame('Aggiornato', $record->note);
        $this->assertNotNull($record->data_conferma);
    }

    public function test_aggiunta_socio_non_attivo_viene_rifiutata(): void
    {
        $sospeso = $this->creaSocio(['stato' => Socio::STATO_SOSPESO]);

        $response = $this->actingAs($this->admin)
            ->from("/eventi/{$this->evento->id}/partecipanti")
            ->post("/eventi/{$this->evento->id}/partecipanti", [
                'socio_id' => $sospeso->id,
                'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
            ]);

        $response->assertSessionHasErrors('socio_id');
        $this->assertDatabaseCount('eventi_partecipanti', 0);
    }

    public function test_aggiunta_socio_di_altro_tenant_viene_rifiutata(): void
    {
        $estraneo = Socio::factory()->create();

        $response = $this->actingAs($this->admin)
            ->from("/eventi/{$this->evento->id}/partecipanti")
            ->post("/eventi/{$this->evento->id}/partecipanti", [
                'socio_id' => $estraneo->id,
                'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
            ]);

        $response->assertSessionHasErrors('socio_id');
        $this->assertDatabaseCount('eventi_partecipanti', 0);
    }

    public function test_stato_partecipazione_fuori_whitelist_viene_rifiutato(): void
    {
        $socio = $this->creaSocio();

        $response = $this->actingAs($this->admin)
            ->from("/eventi/{$this->evento->id}/partecipanti")
            ->post("/eventi/{$this->evento->id}/partecipanti", [
                'socio_id' => $socio->id,
                'stato_partecipazione' => 'Presente',
            ]);

        $response->assertSessionHasErrors('stato_partecipazione');
    }

    public function test_aggiunta_massiva_inserisce_solo_i_mancanti_con_conteggio_reale(): void
    {
        $giaIscritto = $this->creaSocio();
        $this->iscrivi($giaIscritto, EventoPartecipante::STATO_CONFERMATO);

        $this->creaSocio();
        $this->creaSocio();
        $this->creaSocio(['stato' => Socio::STATO_SOSPESO]); // non attivo: escluso
        Socio::factory()->create(); // altro tenant: escluso

        $response = $this->actingAs($this->admin)
            ->post("/eventi/{$this->evento->id}/partecipanti/tutti");

        $response->assertRedirect(route('eventi.partecipanti.index', $this->evento));
        $response->assertSessionHas('success', "Aggiunti 2 soci all'evento!");

        $this->assertSame(3, EventoPartecipante::query()->where('evento_id', $this->evento->id)->count());

        // I nuovi iscritti hanno stato default e data_conferma NULL
        $nuovi = EventoPartecipante::query()
            ->where('evento_id', $this->evento->id)
            ->where('socio_id', '!=', $giaIscritto->id)
            ->get();
        foreach ($nuovi as $p) {
            $this->assertSame(EventoPartecipante::STATO_NON_PARTECIPA, $p->stato_partecipazione);
            $this->assertNull($p->data_conferma);
        }

        // Ripetuta: idempotente, 0 inserimenti
        $this->actingAs($this->admin)
            ->post("/eventi/{$this->evento->id}/partecipanti/tutti")
            ->assertSessionHas('success', "Aggiunti 0 soci all'evento!");
        $this->assertSame(3, EventoPartecipante::query()->where('evento_id', $this->evento->id)->count());
    }

    public function test_aggiornamento_partecipazione_riscrive_data_conferma(): void
    {
        $socio = $this->creaSocio();
        $record = $this->iscrivi($socio);
        $this->assertNull($record->data_conferma);

        $response = $this->actingAs($this->admin)
            ->put("/eventi/{$this->evento->id}/partecipanti/{$record->id}", [
                'stato_partecipazione' => EventoPartecipante::STATO_FORSE,
                'note' => 'Da richiamare',
            ]);

        $response->assertSessionHas('success', 'Partecipazione aggiornata con successo!');

        $record->refresh();
        $this->assertSame(EventoPartecipante::STATO_FORSE, $record->stato_partecipazione);
        $this->assertSame('Da richiamare', $record->note);
        $this->assertNotNull($record->data_conferma);
    }

    public function test_aggiornamento_partecipante_di_altro_evento_restituisce_404(): void
    {
        $altroEvento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);
        $socio = $this->creaSocio();
        $record = EventoPartecipante::create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $altroEvento->id,
            'socio_id' => $socio->id,
            'stato_partecipazione' => EventoPartecipante::STATO_FORSE,
        ]);

        $this->actingAs($this->admin)
            ->put("/eventi/{$this->evento->id}/partecipanti/{$record->id}", [
                'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
            ])
            ->assertNotFound();
    }

    public function test_rimozione_partecipante(): void
    {
        $socio = $this->creaSocio();
        $record = $this->iscrivi($socio);

        $response = $this->actingAs($this->admin)
            ->delete("/eventi/{$this->evento->id}/partecipanti/{$record->id}");

        $response->assertSessionHas('success', 'Partecipante rimosso dall\'evento!');
        $this->assertDatabaseMissing('eventi_partecipanti', ['id' => $record->id]);

        // Idempotente: la seconda rimozione non produce errori
        $this->actingAs($this->admin)
            ->delete("/eventi/{$this->evento->id}/partecipanti/{$record->id}")
            ->assertSessionHas('success', 'Partecipante rimosso dall\'evento!');
    }

    public function test_export_csv_dei_partecipanti(): void
    {
        $socio = $this->creaSocio(['nome' => 'Anna', 'cognome' => 'Bianchi']);
        $this->iscrivi($socio, EventoPartecipante::STATO_CONFERMATO);

        $response = $this->actingAs($this->admin)
            ->get("/eventi/{$this->evento->id}/partecipanti/export");

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Bianchi', $csv);
        $this->assertStringContainsString('Anna', $csv);
        $this->assertStringContainsString(EventoPartecipante::STATO_CONFERMATO, $csv);
    }
}
