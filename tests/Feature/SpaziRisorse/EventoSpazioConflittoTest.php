<?php

namespace Tests\Feature\SpaziRisorse;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\PrenotazioneSpazio;
use App\Models\Spazio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.11 piano "Organizzazione 3": select spazio sul form evento e warning
 * conflitti NON bloccante (l'evento viene comunque salvato).
 */
class EventoSpazioConflittoTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    private Spazio $spazio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->spazio = Spazio::factory()->create(['associazione_id' => $this->associazione->id]);
    }

    public function test_il_form_evento_mostra_il_select_spazio(): void
    {
        $response = $this->actingAs($this->admin)->get('/eventi');

        $response->assertOk();
        $response->assertSee('name="spazio_id"', false);
        $response->assertSee($this->spazio->nome);
    }

    public function test_evento_salvato_con_spazio_senza_conflitti(): void
    {
        $response = $this->actingAs($this->admin)->post('/eventi', [
            'titolo' => 'Concerto',
            'data_evento' => now()->addDays(10)->setTime(20, 0)->format('Y-m-d\TH:i'),
            'data_fine' => now()->addDays(10)->setTime(23, 0)->format('Y-m-d\TH:i'),
            'spazio_id' => $this->spazio->id,
        ]);

        $response->assertRedirect(route('eventi.index'));
        $response->assertSessionHas('success');
        $response->assertSessionMissing('warning');
        $this->assertDatabaseHas('eventi', [
            'titolo' => 'Concerto',
            'spazio_id' => $this->spazio->id,
        ]);
    }

    public function test_warning_non_bloccante_su_conflitto_con_prenotazione(): void
    {
        PrenotazioneSpazio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'spazio_id' => $this->spazio->id,
            'titolo' => 'Corso yoga',
            'dal' => now()->addDays(10)->setTime(19, 0),
            'al' => now()->addDays(10)->setTime(21, 0),
        ]);

        $response = $this->actingAs($this->admin)->post('/eventi', [
            'titolo' => 'Concerto sovrapposto',
            'data_evento' => now()->addDays(10)->setTime(20, 0)->format('Y-m-d\TH:i'),
            'data_fine' => now()->addDays(10)->setTime(23, 0)->format('Y-m-d\TH:i'),
            'spazio_id' => $this->spazio->id,
        ]);

        // NON bloccante: evento salvato + redirect con warning
        $response->assertRedirect(route('eventi.index'));
        $response->assertSessionHas('warning');
        $this->assertStringContainsString('Corso yoga', session('warning'));
        $this->assertDatabaseHas('eventi', ['titolo' => 'Concerto sovrapposto', 'spazio_id' => $this->spazio->id]);
    }

    public function test_warning_su_conflitto_con_altro_evento_stesso_spazio(): void
    {
        Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Assemblea',
            'spazio_id' => $this->spazio->id,
            'data_evento' => now()->addDays(12)->setTime(18, 0),
            'data_fine' => now()->addDays(12)->setTime(20, 0),
        ]);

        $response = $this->actingAs($this->admin)->post('/eventi', [
            'titolo' => 'Festa accavallata',
            'data_evento' => now()->addDays(12)->setTime(19, 0)->format('Y-m-d\TH:i'),
            'data_fine' => now()->addDays(12)->setTime(22, 0)->format('Y-m-d\TH:i'),
            'spazio_id' => $this->spazio->id,
        ]);

        $response->assertRedirect(route('eventi.index'));
        $response->assertSessionHas('warning');
        $this->assertStringContainsString('Assemblea', session('warning'));
    }

    public function test_spazio_di_altro_tenant_rifiutato_dalla_validazione(): void
    {
        $altra = Associazione::factory()->create();
        $spazioAltrui = Spazio::factory()->create(['associazione_id' => $altra->id]);

        $response = $this->actingAs($this->admin)
            ->from('/eventi')
            ->post('/eventi', [
                'titolo' => 'Hack',
                'data_evento' => now()->addDays(5)->format('Y-m-d\TH:i'),
                'spazio_id' => $spazioAltrui->id,
            ]);

        $response->assertSessionHasErrors('spazio_id');
        $this->assertDatabaseMissing('eventi', ['titolo' => 'Hack']);
    }

    public function test_aggiornamento_evento_assegna_spazio_e_rileva_conflitti(): void
    {
        $evento = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'spazio_id' => null,
            'data_evento' => now()->addDays(15)->setTime(10, 0),
            'data_fine' => now()->addDays(15)->setTime(12, 0),
        ]);

        PrenotazioneSpazio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'spazio_id' => $this->spazio->id,
            'titolo' => 'Riunione direttivo',
            'dal' => now()->addDays(15)->setTime(11, 0),
            'al' => now()->addDays(15)->setTime(13, 0),
        ]);

        $response = $this->actingAs($this->admin)->put("/eventi/{$evento->id}", [
            'titolo' => $evento->titolo,
            'data_evento' => now()->addDays(15)->setTime(10, 0)->format('Y-m-d\TH:i'),
            'data_fine' => now()->addDays(15)->setTime(12, 0)->format('Y-m-d\TH:i'),
            'spazio_id' => $this->spazio->id,
        ]);

        $response->assertRedirect(route('eventi.index'));
        $response->assertSessionHas('warning');
        $this->assertSame($this->spazio->id, $evento->fresh()->spazio_id);
    }
}
