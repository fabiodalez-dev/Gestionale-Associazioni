<?php

namespace Tests\Feature\Eventi;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Utente;
use App\Notifications\Prenotazioni\BigliettoPrenotazioneNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Tab admin "Prenotazioni" di un evento (docs/PIANO-PRENOTAZIONI.md,
 * sezione Admin): lista, conferma manuale pagamento offline, annullo
 * con promozione, promozione manuale, reinvio biglietto, export CSV.
 */
class PrenotazioniEventoAdminTest extends TestCase
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
        $this->evento = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/eventi/{$this->evento->id}/prenotazioni{$suffix}";
    }

    // ------------------------------------------------------------------
    // Lista e conteggi
    // ------------------------------------------------------------------

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get($this->url())->assertRedirect('/login');
    }

    public function test_index_mostra_prenotazioni_e_conteggi(): void
    {
        Prenotazione::factory()->confermata()->create(['evento_id' => $this->evento->id, 'posti' => 2]);
        Prenotazione::factory()->inAttesaPagamento()->create(['evento_id' => $this->evento->id, 'posti' => 1]);
        Prenotazione::factory()->listaAttesa()->create(['evento_id' => $this->evento->id, 'posti' => 3]);

        $response = $this->actingAs($this->admin)->get($this->url());

        $response->assertOk();
        $response->assertViewIs('eventi.prenotazioni');

        $conteggi = $response->viewData('conteggi');
        $this->assertSame(2, $conteggi['posti_confermati']);
        $this->assertSame(1, $conteggi['posti_riservati']);
        $this->assertSame(3, $conteggi['posti_in_coda']);
        $this->assertSame(10, $conteggi['capienza']);
        $this->assertCount(3, $response->viewData('prenotazioni'));
    }

    public function test_index_filtra_per_stato(): void
    {
        Prenotazione::factory()->confermata()->create(['evento_id' => $this->evento->id]);
        Prenotazione::factory()->listaAttesa()->create(['evento_id' => $this->evento->id]);

        $response = $this->actingAs($this->admin)->get($this->url('?stato=lista_attesa'));

        $response->assertOk();
        $this->assertCount(1, $response->viewData('prenotazioni'));
    }

    public function test_index_404_su_evento_di_altro_tenant(): void
    {
        $altroEvento = Evento::factory()->create(); // altra associazione

        $this->actingAs($this->admin)
            ->get("/eventi/{$altroEvento->id}/prenotazioni")
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Conferma manuale pagamento offline
    // ------------------------------------------------------------------

    public function test_conferma_manuale_pagamento_offline(): void
    {
        $prenotazione = Prenotazione::factory()->inAttesaPagamento(15.0)->create([
            'evento_id' => $this->evento->id,
            'posti' => 2,
        ]);

        $response = $this->actingAs($this->admin)
            ->post($this->url("/{$prenotazione->id}/conferma-pagamento"));

        $response->assertRedirect($this->url());
        $response->assertSessionHas('success');

        $prenotazione->refresh();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $prenotazione->stato);
        $this->assertSame('offline', $prenotazione->gateway);
        $this->assertNotNull($prenotazione->pagata_at);
    }

    public function test_conferma_manuale_rifiutata_su_prenotazione_gia_confermata(): void
    {
        $prenotazione = Prenotazione::factory()->pagata()->create([
            'evento_id' => $this->evento->id,
        ]);
        $gatewayOriginale = $prenotazione->gateway;

        $response = $this->actingAs($this->admin)
            ->post($this->url("/{$prenotazione->id}/conferma-pagamento"));

        $response->assertSessionHas('error');
        $this->assertSame($gatewayOriginale, $prenotazione->fresh()->gateway);
    }

    public function test_conferma_manuale_404_su_prenotazione_di_altro_evento(): void
    {
        $altroEvento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);
        $prenotazione = Prenotazione::factory()->inAttesaPagamento()->create([
            'evento_id' => $altroEvento->id,
        ]);

        $this->actingAs($this->admin)
            ->post($this->url("/{$prenotazione->id}/conferma-pagamento"))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Annullo admin (innesca la promozione della lista d'attesa)
    // ------------------------------------------------------------------

    public function test_annullo_admin_promuove_la_lista_attesa(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(1)->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $confermata = Prenotazione::factory()->confermata()->create([
            'evento_id' => $evento->id,
            'posti' => 1,
        ]);
        $inCoda = Prenotazione::factory()->listaAttesa()->create([
            'evento_id' => $evento->id,
            'posti' => 1,
            'prezzo_unitario' => 0,
            'importo_totale' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->post("/eventi/{$evento->id}/prenotazioni/{$confermata->id}/annulla", [
                'motivo' => 'Richiesta telefonica',
            ]);

        $response->assertSessionHas('success');

        $confermata->refresh();
        $this->assertSame(Prenotazione::STATO_ANNULLATA, $confermata->stato);
        $this->assertSame('Richiesta telefonica', $confermata->motivo_annullamento);

        // Il posto liberato promuove la prima in coda (gratuita → confermata)
        $inCoda->refresh();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $inCoda->stato);
        $this->assertNotNull($inCoda->promossa_at);
    }

    public function test_annullo_di_prenotazione_gia_annullata_segnala_errore(): void
    {
        $prenotazione = Prenotazione::factory()->annullata()->create([
            'evento_id' => $this->evento->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->post($this->url("/{$prenotazione->id}/annulla"));

        $response->assertSessionHas('error');
    }

    // ------------------------------------------------------------------
    // Promozione manuale
    // ------------------------------------------------------------------

    public function test_promozione_manuale_dopo_aumento_di_capienza(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(1)->create([
            'associazione_id' => $this->associazione->id,
        ]);

        Prenotazione::factory()->confermata()->create(['evento_id' => $evento->id, 'posti' => 1]);
        $inCoda = Prenotazione::factory()->listaAttesa()->create([
            'evento_id' => $evento->id,
            'posti' => 1,
            'prezzo_unitario' => 0,
            'importo_totale' => 0,
        ]);

        // Capienza aumentata dall'admin: la coda non si muove da sola
        $evento->update(['capienza' => 3]);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $inCoda->fresh()->stato);

        $response = $this->actingAs($this->admin)
            ->post("/eventi/{$evento->id}/prenotazioni/promuovi");

        $response->assertSessionHas('success');
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $inCoda->fresh()->stato);
    }

    public function test_promozione_manuale_a_pagamento_apre_finestra_di_24_ore(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(5)->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $inCoda = Prenotazione::factory()->listaAttesa()->create([
            'evento_id' => $evento->id,
            'posti' => 1,
            'prezzo_unitario' => 10,
            'importo_totale' => 10,
        ]);

        $this->actingAs($this->admin)->post("/eventi/{$evento->id}/prenotazioni/promuovi");

        $inCoda->refresh();
        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $inCoda->stato);
        $this->assertNotNull($inCoda->scadenza_pagamento_at);
        $this->assertTrue($inCoda->scadenza_pagamento_at->greaterThan(now()->addHours(23)));
    }

    public function test_promozione_manuale_senza_coda_segnala_warning(): void
    {
        $response = $this->actingAs($this->admin)
            ->post($this->url('/promuovi'));

        $response->assertSessionHas('warning');
    }

    // ------------------------------------------------------------------
    // Reinvio biglietto (best-effort)
    // ------------------------------------------------------------------

    public function test_reinvia_biglietto_notifica_l_account(): void
    {
        Notification::fake();

        $prenotazione = Prenotazione::factory()->confermata()->create([
            'evento_id' => $this->evento->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->post($this->url("/{$prenotazione->id}/reinvia-biglietto"));

        $response->assertSessionHas('success');

        Notification::assertSentTo(
            Account::withoutGlobalScopes()->findOrFail($prenotazione->account_id),
            BigliettoPrenotazioneNotification::class,
            fn ($notification) => $notification->prenotazione->is($prenotazione)
        );
    }

    public function test_reinvia_biglietto_rifiutato_su_prenotazione_non_confermata(): void
    {
        Notification::fake();

        $prenotazione = Prenotazione::factory()->listaAttesa()->create([
            'evento_id' => $this->evento->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->post($this->url("/{$prenotazione->id}/reinvia-biglietto"));

        $response->assertSessionHas('error');
        Notification::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Export CSV
    // ------------------------------------------------------------------

    public function test_export_csv_contiene_le_prenotazioni(): void
    {
        $prenotazione = Prenotazione::factory()->confermata()->create([
            'evento_id' => $this->evento->id,
            'posti' => 2,
        ]);
        $account = Account::withoutGlobalScopes()->findOrFail($prenotazione->account_id);

        $response = $this->actingAs($this->admin)->get($this->url('/export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Codice;Cognome;Nome', $csv);
        $this->assertStringContainsString($prenotazione->codice, $csv);
        $this->assertStringContainsString($account->cognome, $csv);
    }

    public function test_export_csv_rispetta_il_filtro_stato(): void
    {
        $confermata = Prenotazione::factory()->confermata()->create(['evento_id' => $this->evento->id]);
        $inCoda = Prenotazione::factory()->listaAttesa()->create(['evento_id' => $this->evento->id]);

        $csv = $this->actingAs($this->admin)
            ->get($this->url('/export?stato=lista_attesa'))
            ->streamedContent();

        $this->assertStringContainsString($inCoda->codice, $csv);
        $this->assertStringNotContainsString($confermata->codice, $csv);
    }
}
