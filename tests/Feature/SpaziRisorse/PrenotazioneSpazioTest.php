<?php

namespace Tests\Feature\SpaziRisorse;

use App\Models\Associazione;
use App\Models\PrenotazioneSpazio;
use App\Models\Spazio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrenotazioneSpazioTest extends TestCase
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

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/spazi/prenotazioni')->assertRedirect('/login');
    }

    public function test_index_mostra_calendario_e_prossime_del_tenant(): void
    {
        $altra = Associazione::factory()->create();
        $spazioAltrui = Spazio::factory()->create(['associazione_id' => $altra->id]);

        PrenotazioneSpazio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'spazio_id' => $this->spazio->id,
            'titolo' => 'Assemblea soci',
            'dal' => now()->addDays(2)->setTime(10, 0),
            'al' => now()->addDays(2)->setTime(12, 0),
        ]);
        PrenotazioneSpazio::factory()->create([
            'associazione_id' => $altra->id,
            'spazio_id' => $spazioAltrui->id,
            'titolo' => 'Riunione altrui',
            'dal' => now()->addDays(2)->setTime(10, 0),
            'al' => now()->addDays(2)->setTime(12, 0),
        ]);

        $response = $this->actingAs($this->admin)->get('/spazi/prenotazioni');

        $response->assertOk();
        $response->assertViewIs('spazi-risorse.prenotazioni.index');
        $response->assertSee('Assemblea soci');
        $response->assertDontSee('Riunione altrui');
    }

    public function test_creazione_prenotazione(): void
    {
        $dal = now()->addDays(5)->setTime(9, 0)->format('Y-m-d\TH:i');
        $al = now()->addDays(5)->setTime(11, 0)->format('Y-m-d\TH:i');

        $response = $this->actingAs($this->admin)->post('/spazi/prenotazioni', [
            'spazio_id' => $this->spazio->id,
            'titolo' => 'Prove teatrali',
            'dal' => $dal,
            'al' => $al,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('prenotazioni_spazi', [
            'spazio_id' => $this->spazio->id,
            'titolo' => 'Prove teatrali',
            'associazione_id' => $this->associazione->id,
            'richiedente_id' => $this->admin->id,
        ]);
    }

    public function test_conflitto_overlap_blocca_la_prenotazione(): void
    {
        PrenotazioneSpazio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'spazio_id' => $this->spazio->id,
            'titolo' => 'Corso di pittura',
            'dal' => now()->addDays(3)->setTime(10, 0),
            'al' => now()->addDays(3)->setTime(12, 0),
        ]);

        // Overlap parziale (11:00–13:00 contro 10:00–12:00)
        $response = $this->actingAs($this->admin)
            ->from('/spazi/prenotazioni')
            ->post('/spazi/prenotazioni', [
                'spazio_id' => $this->spazio->id,
                'titolo' => 'Tentativo in conflitto',
                'dal' => now()->addDays(3)->setTime(11, 0)->format('Y-m-d\TH:i'),
                'al' => now()->addDays(3)->setTime(13, 0)->format('Y-m-d\TH:i'),
            ]);

        $response->assertSessionHasErrors('dal');
        $this->assertDatabaseMissing('prenotazioni_spazi', ['titolo' => 'Tentativo in conflitto']);
        // L'errore indica la prenotazione esistente
        $this->assertStringContainsString('Corso di pittura', session('errors')->first('dal'));
    }

    public function test_prenotazioni_adiacenti_non_sono_in_conflitto(): void
    {
        PrenotazioneSpazio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'spazio_id' => $this->spazio->id,
            'dal' => now()->addDays(3)->setTime(10, 0),
            'al' => now()->addDays(3)->setTime(12, 0),
        ]);

        // Inizia esattamente quando finisce l'altra (12:00) → nessun overlap
        $response = $this->actingAs($this->admin)->post('/spazi/prenotazioni', [
            'spazio_id' => $this->spazio->id,
            'titolo' => 'Subito dopo',
            'dal' => now()->addDays(3)->setTime(12, 0)->format('Y-m-d\TH:i'),
            'al' => now()->addDays(3)->setTime(14, 0)->format('Y-m-d\TH:i'),
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('prenotazioni_spazi', ['titolo' => 'Subito dopo']);
    }

    public function test_aggiornamento_non_va_in_conflitto_con_se_stessa(): void
    {
        $prenotazione = PrenotazioneSpazio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'spazio_id' => $this->spazio->id,
            'titolo' => 'Originale',
            'dal' => now()->addDays(4)->setTime(10, 0),
            'al' => now()->addDays(4)->setTime(12, 0),
        ]);

        $response = $this->actingAs($this->admin)->put("/spazi/prenotazioni/{$prenotazione->id}", [
            'spazio_id' => $this->spazio->id,
            'titolo' => 'Aggiornata',
            'dal' => now()->addDays(4)->setTime(10, 30)->format('Y-m-d\TH:i'),
            'al' => now()->addDays(4)->setTime(12, 30)->format('Y-m-d\TH:i'),
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();
        $this->assertSame('Aggiornata', $prenotazione->fresh()->titolo);
    }

    public function test_al_deve_essere_dopo_dal(): void
    {
        $response = $this->actingAs($this->admin)
            ->from('/spazi/prenotazioni')
            ->post('/spazi/prenotazioni', [
                'spazio_id' => $this->spazio->id,
                'titolo' => 'Invalida',
                'dal' => now()->addDays(2)->setTime(12, 0)->format('Y-m-d\TH:i'),
                'al' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i'),
            ]);

        $response->assertSessionHasErrors('al');
    }

    public function test_prenotazione_con_spazio_di_altro_tenant_fallisce(): void
    {
        $altra = Associazione::factory()->create();
        $spazioAltrui = Spazio::factory()->create(['associazione_id' => $altra->id]);

        $response = $this->actingAs($this->admin)
            ->from('/spazi/prenotazioni')
            ->post('/spazi/prenotazioni', [
                'spazio_id' => $spazioAltrui->id,
                'titolo' => 'Hack',
                'dal' => now()->addDays(2)->setTime(9, 0)->format('Y-m-d\TH:i'),
                'al' => now()->addDays(2)->setTime(11, 0)->format('Y-m-d\TH:i'),
            ]);

        $response->assertSessionHasErrors('spazio_id');
        $this->assertDatabaseMissing('prenotazioni_spazi', ['titolo' => 'Hack']);
    }

    public function test_aggiornamento_di_altro_tenant_restituisce_404(): void
    {
        $altrui = PrenotazioneSpazio::factory()->create(['titolo' => 'Riservata']);

        $this->actingAs($this->admin)->put("/spazi/prenotazioni/{$altrui->id}", [
            'spazio_id' => $this->spazio->id,
            'titolo' => 'Hack',
            'dal' => now()->addDays(2)->setTime(9, 0)->format('Y-m-d\TH:i'),
            'al' => now()->addDays(2)->setTime(11, 0)->format('Y-m-d\TH:i'),
        ])->assertNotFound();

        $this->assertSame('Riservata', $altrui->fresh()->titolo);
    }

    public function test_eliminazione_prenotazione(): void
    {
        $prenotazione = PrenotazioneSpazio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'spazio_id' => $this->spazio->id,
        ]);

        $this->actingAs($this->admin)->delete("/spazi/prenotazioni/{$prenotazione->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('prenotazioni_spazi', ['id' => $prenotazione->id]);
    }
}
