<?php

namespace Tests\Feature\DocumentiVerbaliScadenze;

use App\Models\Associazione;
use App\Models\Scadenza;
use App\Models\Utente;
use App\Notifications\PromemoriaScadenzaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ScadenzeTest extends TestCase
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
        $this->get(route('scadenze.index'))->assertRedirect('/login');
    }

    public function test_creazione_scadenza(): void
    {
        $response = $this->actingAs($this->admin)->post(route('scadenze.store'), [
            'titolo' => 'Rinnovo assicurazione',
            'descrizione' => 'Polizza RC',
            'data_scadenza' => now()->addMonth()->toDateString(),
            'tipo_scadenza' => 'Documenti',
            'priorita' => 'Alta',
            'stato' => 'Attiva',
            'assegnato_a' => '',
            'promemoria_giorni' => 7,
        ]);

        $response->assertRedirect(route('scadenze.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('scadenze', [
            'titolo' => 'Rinnovo assicurazione',
            'associazione_id' => $this->associazione->id,
            'assegnato_a' => null, // stringa vuota dal form → NULL
        ]);
    }

    public function test_validazione_server_side_dei_campi_chiusi(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('scadenze.index'))
            ->post(route('scadenze.store'), [
                'titolo' => 'X',
                'data_scadenza' => 'non-una-data',
                'tipo_scadenza' => 'Inventato',
                'priorita' => 'Massima',
                'stato' => 'Boh',
                'promemoria_giorni' => 0,
            ]);

        $response->assertSessionHasErrors(['data_scadenza', 'tipo_scadenza', 'priorita', 'stato', 'promemoria_giorni']);
        $this->assertSame(0, Scadenza::query()->count());
    }

    public function test_assegnatario_di_altro_tenant_viene_rifiutato(): void
    {
        $utenteAltroTenant = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => Associazione::factory()->create()->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->from(route('scadenze.index'))
            ->post(route('scadenze.store'), [
                'titolo' => 'Task',
                'data_scadenza' => now()->addWeek()->toDateString(),
                'tipo_scadenza' => 'Generale',
                'priorita' => 'Bassa',
                'stato' => 'Attiva',
                'assegnato_a' => $utenteAltroTenant->id,
                'promemoria_giorni' => 7,
            ]);

        $response->assertSessionHasErrors('assegnato_a');
    }

    public function test_completamento_rapido_traccia_chi_e_quando(): void
    {
        $scadenza = $this->creaScadenza();

        $this->actingAs($this->admin)
            ->post(route('scadenze.completa', $scadenza))
            ->assertRedirect(route('scadenze.index'));

        $scadenza->refresh();
        $this->assertSame(Scadenza::STATO_COMPLETATA, $scadenza->stato);
        $this->assertSame($this->admin->id, $scadenza->completata_da);
        $this->assertNotNull($scadenza->completata_at);
    }

    public function test_isolamento_tenant_sulle_scadenze(): void
    {
        $scadenza = $this->creaScadenza(['titolo' => 'Scadenza tenant A']);

        $adminB = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => Associazione::factory()->create()->id,
        ]);

        $this->actingAs($adminB)
            ->get(route('scadenze.index'))
            ->assertOk()
            ->assertDontSee('Scadenza tenant A');

        $this->actingAs($adminB)
            ->post(route('scadenze.completa', ['scadenza' => $scadenza->id]))
            ->assertNotFound();
    }

    public function test_ordinamento_composito_stato_priorita_data(): void
    {
        $this->creaScadenza(['titolo' => 'Completata vecchia', 'stato' => 'Completata', 'priorita' => 'Critica']);
        $this->creaScadenza(['titolo' => 'Attiva bassa', 'stato' => 'Attiva', 'priorita' => 'Bassa']);
        $this->creaScadenza(['titolo' => 'Attiva critica', 'stato' => 'Attiva', 'priorita' => 'Critica']);

        $response = $this->actingAs($this->admin)->get(route('scadenze.index'));

        $response->assertOk();
        $response->assertSeeInOrder(['Attiva critica', 'Attiva bassa', 'Completata vecchia']);
    }

    public function test_statistiche_sul_result_set_filtrato(): void
    {
        $this->creaScadenza(['priorita' => 'Critica', 'stato' => 'Attiva']);
        $this->creaScadenza(['stato' => 'Completata']);
        $this->creaScadenza(['stato' => 'Attiva', 'data_scadenza' => now()->addDays(3)->toDateString()]);

        $response = $this->actingAs($this->admin)->get(route('scadenze.index'));

        $response->assertOk();
        $stats = $response->viewData('stats');
        $this->assertSame(1, $stats['critiche']);
        $this->assertSame(2, $stats['attive']);
        $this->assertSame(1, $stats['completate']);
        $this->assertSame(1, $stats['in_scadenza']); // entro 7 giorni fissi (legacy)
    }

    public function test_export_csv_rispetta_i_filtri(): void
    {
        $this->creaScadenza(['titolo' => 'Quota annuale', 'tipo_scadenza' => 'Quote']);
        $this->creaScadenza(['titolo' => 'Evento estivo', 'tipo_scadenza' => 'Eventi']);

        $response = $this->actingAs($this->admin)
            ->get(route('scadenze.esporta-csv', ['type' => 'Quote']));

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Quota annuale', $csv);
        $this->assertStringNotContainsString('Evento estivo', $csv);
    }

    public function test_eliminazione_scadenza(): void
    {
        $scadenza = $this->creaScadenza();

        $this->actingAs($this->admin)
            ->delete(route('scadenze.destroy', $scadenza))
            ->assertRedirect(route('scadenze.index'));

        $this->assertDatabaseMissing('scadenze', ['id' => $scadenza->id]);
    }

    public function test_comando_promemoria_invia_notifica_e_traccia_l_invio(): void
    {
        Notification::fake();

        $assegnatario = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        // In finestra promemoria (scade tra 3 giorni, promemoria a 7)
        $inFinestra = $this->creaScadenza([
            'titolo' => 'Da notificare',
            'data_scadenza' => now()->addDays(3)->toDateString(),
            'promemoria_giorni' => 7,
            'assegnato_a' => $assegnatario->id,
        ]);

        // Fuori finestra (scade tra 30 giorni, promemoria a 7)
        $fuoriFinestra = $this->creaScadenza([
            'titolo' => 'Lontana',
            'data_scadenza' => now()->addDays(30)->toDateString(),
            'promemoria_giorni' => 7,
        ]);

        $this->artisan('scadenze:invia-promemoria')->assertSuccessful();

        Notification::assertSentTo($assegnatario, PromemoriaScadenzaNotification::class);
        $this->assertNotNull($inFinestra->refresh()->promemoria_inviato_at);
        $this->assertNull($fuoriFinestra->refresh()->promemoria_inviato_at);

        // Seconda esecuzione: nessun duplicato
        Notification::fake();
        $this->artisan('scadenze:invia-promemoria')->assertSuccessful();
        Notification::assertNothingSent();
    }

    private function creaScadenza(array $attributi = []): Scadenza
    {
        return Scadenza::create(array_merge([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Scadenza di prova',
            'data_scadenza' => now()->addMonth()->toDateString(),
            'tipo_scadenza' => 'Generale',
            'priorita' => 'Media',
            'stato' => 'Attiva',
            'promemoria_giorni' => 7,
        ], $attributi));
    }
}
