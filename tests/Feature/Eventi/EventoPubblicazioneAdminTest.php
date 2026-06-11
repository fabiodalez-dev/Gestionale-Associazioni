<?php

namespace Tests\Feature\Eventi;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Form evento esteso: sezione "Pubblicazione frontend"
 * (docs/PIANO-PRENOTAZIONI.md, sezione Admin).
 */
class EventoPubblicazioneAdminTest extends TestCase
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

    /** @return array<string, mixed> */
    private function payloadCompleto(array $override = []): array
    {
        return array_merge([
            'titolo' => 'Concerto di Primavera',
            'data_evento' => '2027-05-10T21:00',
            'data_fine' => '2027-05-10T23:30',
            'luogo' => 'Teatro Comunale',
            'descrizione' => 'Serata musicale.',
            'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
            'capienza' => 100,
            'prezzo' => '12,50',
            'iscrizioni_dal' => '2027-04-01T00:00',
            'iscrizioni_al' => '2027-05-09T23:59',
            'max_posti_per_prenotazione' => 6,
            'lista_attesa_attiva' => '1',
            'annullamento_entro_ore' => 48,
            'immagine_url' => 'https://example.org/locandina.jpg',
        ], $override);
    }

    public function test_creazione_evento_con_campi_di_pubblicazione(): void
    {
        $response = $this->actingAs($this->admin)->post('/eventi', $this->payloadCompleto());

        $response->assertRedirect(route('eventi.index'));
        $response->assertSessionHasNoErrors();

        $evento = Evento::query()->firstOrFail();
        $this->assertSame(Evento::STATO_PUBBLICATO, $evento->stato_pubblicazione);
        $this->assertSame(100, $evento->capienza);
        $this->assertSame('12.50', (string) $evento->prezzo); // virgola italiana normalizzata
        $this->assertSame(6, $evento->max_posti_per_prenotazione);
        $this->assertTrue($evento->lista_attesa_attiva);
        $this->assertSame(48, $evento->annullamento_entro_ore);
        $this->assertSame('https://example.org/locandina.jpg', $evento->immagine_url);
        $this->assertNotNull($evento->data_fine);
        $this->assertNotNull($evento->iscrizioni_dal);
        $this->assertNotNull($evento->iscrizioni_al);
    }

    public function test_creazione_senza_campi_di_pubblicazione_usa_i_default(): void
    {
        // Compatibilità legacy: solo i campi base
        $response = $this->actingAs($this->admin)->post('/eventi', [
            'titolo' => 'Evento Base',
            'data_evento' => '2027-07-15T18:30',
        ]);

        $response->assertSessionHasNoErrors();

        $evento = Evento::query()->firstOrFail();
        $this->assertSame(Evento::STATO_BOZZA, $evento->stato_pubblicazione);
        $this->assertNull($evento->capienza);
        $this->assertSame(0.0, (float) $evento->prezzo);
        $this->assertSame(4, $evento->max_posti_per_prenotazione);
        $this->assertTrue($evento->lista_attesa_attiva);
        $this->assertSame(24, $evento->annullamento_entro_ore);
    }

    public function test_update_parziale_non_resetta_lo_stato_di_pubblicazione(): void
    {
        $evento = Evento::factory()->pubblicato()->aPagamento(20)->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $response = $this->actingAs($this->admin)->put("/eventi/{$evento->id}", [
            'titolo' => 'Titolo Aggiornato',
            'data_evento' => '2027-09-01T21:00',
        ]);

        $response->assertSessionHasNoErrors();

        $evento->refresh();
        $this->assertSame('Titolo Aggiornato', $evento->titolo);
        $this->assertSame(Evento::STATO_PUBBLICATO, $evento->stato_pubblicazione);
        $this->assertSame(20.0, (float) $evento->prezzo);
    }

    public function test_stato_pubblicazione_non_valido_viene_rifiutato(): void
    {
        $response = $this->actingAs($this->admin)
            ->from('/eventi')
            ->post('/eventi', $this->payloadCompleto(['stato_pubblicazione' => 'online']));

        $response->assertSessionHasErrors(['stato_pubblicazione']);
    }

    public function test_chiusura_iscrizioni_non_puo_precedere_apertura(): void
    {
        $response = $this->actingAs($this->admin)
            ->from('/eventi')
            ->post('/eventi', $this->payloadCompleto([
                'iscrizioni_dal' => '2027-05-01T00:00',
                'iscrizioni_al' => '2027-04-01T00:00',
            ]));

        $response->assertSessionHasErrors(['iscrizioni_al']);
    }

    public function test_capienza_non_riducibile_sotto_i_posti_confermati_e_riservati(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'associazione_id' => $this->associazione->id,
        ]);

        // 2 posti confermati + 1 riservato (in attesa di pagamento) = 3 occupati
        Prenotazione::factory()->confermata()->create(['evento_id' => $evento->id, 'posti' => 2]);
        Prenotazione::factory()->inAttesaPagamento()->create(['evento_id' => $evento->id, 'posti' => 1]);
        // Annullate e in lista d'attesa NON contano
        Prenotazione::factory()->annullata()->create(['evento_id' => $evento->id, 'posti' => 5]);
        Prenotazione::factory()->listaAttesa()->create(['evento_id' => $evento->id, 'posti' => 5]);

        $response = $this->actingAs($this->admin)
            ->from('/eventi')
            ->put("/eventi/{$evento->id}", $this->payloadCompleto(['capienza' => 2]));

        $response->assertSessionHasErrors(['capienza']);
        $this->assertSame(10, $evento->fresh()->capienza);

        // Messaggio chiaro con il numero di posti occupati
        $messaggio = session('errors')->first('capienza');
        $this->assertStringContainsString('3 posti occupati', $messaggio);
    }

    public function test_capienza_riducibile_fino_ai_posti_occupati(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'associazione_id' => $this->associazione->id,
        ]);

        Prenotazione::factory()->confermata()->create(['evento_id' => $evento->id, 'posti' => 3]);

        $response = $this->actingAs($this->admin)
            ->put("/eventi/{$evento->id}", $this->payloadCompleto(['capienza' => 3]));

        $response->assertSessionHasNoErrors();
        $this->assertSame(3, $evento->fresh()->capienza);
    }

    public function test_lista_eventi_mostra_badge_stato_pubblicazione(): void
    {
        Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Evento Pubblicato',
        ]);
        Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Evento In Bozza',
        ]);

        $response = $this->actingAs($this->admin)->get('/eventi');

        $response->assertOk();
        $response->assertSee('Pubblicato');
        $response->assertSee('Bozza');
    }

    public function test_lista_eventi_mostra_link_anteprima_pubblica_per_i_pubblicati(): void
    {
        $evento = Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $response = $this->actingAs($this->admin)->get('/eventi');

        $response->assertOk();
        $response->assertSee(
            route('pubblico.eventi.show', [
                'associazione' => $this->associazione->fresh()->slug,
                'evento' => $evento->id,
            ]),
            false
        );
    }
}
