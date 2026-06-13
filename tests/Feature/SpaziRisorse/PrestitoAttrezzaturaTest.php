<?php

namespace Tests\Feature\SpaziRisorse;

use App\Models\Associazione;
use App\Models\Attrezzatura;
use App\Models\PrestitoAttrezzatura;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

    /**
     * Regressione race TOCTOU (review round 3 #3): la validazione della
     * disponibilità nel Form Request è non atomica.
     *
     * Iniettiamo un prestito concorrente che consuma i pezzi residui DOPO la
     * validazione del Form Request ma PRIMA del persist del controller (hook
     * one-shot sulla rilettura dell'attrezzatura). Il hook è indipendente dal
     * fix: senza il ricontrollo in transazione il controller creerebbe comunque
     * i 4 pezzi richiesti (overbooking, 7/5 in prestito); col fix la disponibilità
     * viene ricalcolata e il secondo prestito è bloccato.
     */
    public function test_disponibilita_concorrente_bloccata_dal_ricontrollo_in_transazione(): void
    {
        // 5 pezzi totali, nessuno in prestito → la validazione iniziale vede 5 liberi
        $attrezzaturaId = $this->attrezzatura->id;
        $associazioneId = $this->associazione->id;
        $socioId = $this->socio->id;

        // La PRIMA rilettura è la validazione disponibilità del Form Request
        // (vede 5 liberi → passa). Il competitor viene iniettato alla SECONDA
        // rilettura, cioè quando il controller rilegge l'attrezzatura sotto lock
        // dentro la transazione: senza il fix questa seconda lettura non esiste
        // e il prestito da 4 verrebbe creato (overbooking 7/5).
        $letture = 0;
        Event::listen(
            'eloquent.retrieved: '.Attrezzatura::class,
            function (Attrezzatura $att) use ($attrezzaturaId, $associazioneId, $socioId, &$letture): void {
                if ($att->id !== $attrezzaturaId) {
                    return;
                }
                $letture++;
                if ($letture !== 2) {
                    return;
                }

                // Prestito parallelo: prende 3 dei 5 pezzi.
                PrestitoAttrezzatura::factory()->create([
                    'associazione_id' => $associazioneId,
                    'attrezzatura_id' => $attrezzaturaId,
                    'socio_id' => $socioId,
                    'quantita' => 3,
                ]);
            }
        );

        // Richiesti 4: validazione iniziale ok (5 liberi), ma il competitor ne
        // prende 3 → disponibili 2 < 4 al ricontrollo in transazione.
        $response = $this->actingAs($this->admin)
            ->from('/prestiti')
            ->post('/prestiti', [
                'attrezzatura_id' => $attrezzaturaId,
                'socio_id' => $socioId,
                'quantita' => 4,
                'dal' => now()->toDateString(),
                'restituzione_prevista' => now()->addWeek()->toDateString(),
            ]);

        $response->assertSessionHasErrors('quantita');

        // Il prestito da 4 pezzi NON è stato creato: il ricontrollo sotto lock
        // ha intercettato la disponibilità ridotta dal competitor, abortendo la
        // transazione (su sqlite, connessione singola, anche il competitor
        // inserito nella stessa transazione viene rimosso dal rollback). Senza il
        // fix il prestito da 4 verrebbe creato → overbooking 7/5.
        $this->assertDatabaseMissing('prestiti_attrezzature', ['quantita' => 4]);
        $this->assertLessThanOrEqual(
            (int) $this->attrezzatura->fresh()->quantita,
            $this->attrezzatura->fresh()->quantitaInPrestito(),
            'I pezzi in prestito non devono mai superare la quantità totale.'
        );
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
