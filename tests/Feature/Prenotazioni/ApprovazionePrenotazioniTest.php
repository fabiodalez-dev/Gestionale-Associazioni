<?php

namespace Tests\Feature\Prenotazioni;

use App\Models\Account;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Utente;
use App\Notifications\Prenotazioni\ConfermataNotification;
use App\Notifications\Prenotazioni\DaApprovareNotification;
use App\Notifications\Prenotazioni\InApprovazioneNotification;
use App\Notifications\Prenotazioni\InAttesaPagamentoNotification;
use App\Notifications\Prenotazioni\RifiutataNotification;
use App\Services\Prenotazioni\PrenotazioneException;
use App\Services\Prenotazioni\PrenotazioneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Eventi con richiede_approvazione (docs/PIANO-ISCRIZIONI.md):
 * creazione → in_approvazione (posti riservati, nessuna scadenza),
 * approva() → confermata o in_attesa_pagamento+30', rifiuta() →
 * annullata con motivo + promozione lista d'attesa, promossi dalla
 * coda → in_approvazione, chiusura manuale iscrizioni, isolamento
 * tenant, azioni HTTP del tab prenotazioni admin.
 */
class ApprovazionePrenotazioniTest extends TestCase
{
    use RefreshDatabase;

    protected PrenotazioneServiceSpia $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PrenotazioneServiceSpia;
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    protected function creaAccount(Evento $evento): Account
    {
        return Account::factory()->create(['associazione_id' => $evento->associazione_id]);
    }

    protected function creaAdmin(Evento $evento): Utente
    {
        return Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $evento->associazione_id,
        ]);
    }

    protected function fresca(Prenotazione $prenotazione): Prenotazione
    {
        return Prenotazione::allTenants()->findOrFail($prenotazione->id);
    }

    // ------------------------------------------------------------------
    // Creazione su evento con approvazione
    // ------------------------------------------------------------------

    public function test_crea_su_evento_gratuito_con_approvazione_va_in_approvazione(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->conApprovazione()->create();
        $account = $this->creaAccount($evento);

        $prenotazione = $this->service->crea($account, $evento, 2);

        $this->assertSame(Prenotazione::STATO_IN_APPROVAZIONE, $prenotazione->stato);
        $this->assertNull($prenotazione->scadenza_pagamento_at);
        $this->assertNull($prenotazione->approvata_at);
        // Notifiche: account (in approvazione) + admin (da approvare)
        $this->assertSame(['in_approvazione', 'da_approvare'], $this->service->tipiInviati());
    }

    public function test_crea_a_pagamento_con_approvazione_non_avvia_il_pagamento(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->aPagamento(15.0)->conApprovazione()->create();
        $account = $this->creaAccount($evento);

        $prenotazione = $this->service->crea($account, $evento, 2);

        $this->assertSame(Prenotazione::STATO_IN_APPROVAZIONE, $prenotazione->stato);
        // Il flusso di pagamento parte SOLO dopo l'approvazione
        $this->assertNull($prenotazione->scadenza_pagamento_at);
        $this->assertSame(30.0, (float) $prenotazione->importo_totale);
    }

    public function test_le_in_approvazione_consumano_posti(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(2)->conApprovazione()->create();
        $this->service->crea($this->creaAccount($evento), $evento, 2);

        // Evento pieno (i 2 posti sono riservati dall'in_approvazione)
        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 1);

        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $prenotazione->stato);
        $this->assertSame(2, Prenotazione::postiOccupati($evento->id));
    }

    public function test_doppia_richiesta_in_approvazione_bloccata(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->conApprovazione()->create();
        $account = $this->creaAccount($evento);
        $this->service->crea($account, $evento, 1);

        $this->expectException(PrenotazioneException::class);

        $this->service->crea($account, $evento, 1);
    }

    // ------------------------------------------------------------------
    // Chiusura manuale iscrizioni (toggle iscrizioni_aperte)
    // ------------------------------------------------------------------

    public function test_iscrizioni_chiuse_manualmente_evento_non_prenotabile(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->iscrizioniChiuse()->create();

        $this->assertFalse($evento->isPrenotabile());

        $this->expectException(PrenotazioneException::class);
        $this->expectExceptionMessage('Le prenotazioni per questo evento non sono aperte.');

        $this->service->crea($this->creaAccount($evento), $evento, 1);
    }

    // ------------------------------------------------------------------
    // Approvazione
    // ------------------------------------------------------------------

    public function test_approva_evento_gratuito_conferma_la_prenotazione(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->conApprovazione()->create();
        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 1);
        $admin = $this->creaAdmin($evento);

        $risultato = $this->service->approva($prenotazione, $admin);

        $this->assertSame(Prenotazione::STATO_CONFERMATA, $risultato->stato);
        $this->assertNotNull($risultato->approvata_at);
        $this->assertSame($admin->id, $risultato->approvata_da);
        $this->assertNull($risultato->scadenza_pagamento_at);
        $this->assertContains('confermata', $this->service->tipiInviati());
    }

    public function test_approva_evento_a_pagamento_apre_la_finestra_di_pagamento(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->aPagamento(10.0)->conApprovazione()->create();
        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 1);
        $admin = $this->creaAdmin($evento);

        $risultato = $this->service->approva($prenotazione, $admin);

        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $risultato->stato);
        $this->assertSame($admin->id, $risultato->approvata_da);
        $this->assertNotNull($risultato->scadenza_pagamento_at);
        $this->assertEqualsWithDelta(
            now()->addMinutes(30)->getTimestamp(),
            $risultato->scadenza_pagamento_at->getTimestamp(),
            5
        );
        $this->assertContains('in_attesa_pagamento', $this->service->tipiInviati());
    }

    public function test_pagamento_dopo_approvazione_conferma_la_prenotazione(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->aPagamento(10.0)->conApprovazione()->create();
        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 1);
        $this->service->approva($prenotazione, $this->creaAdmin($evento));

        $risultato = $this->service->onPagamentoCompletato(
            $prenotazione->id,
            $evento->associazione_id,
            'stripe',
            'tx-123',
        );

        $this->assertSame(Prenotazione::STATO_CONFERMATA, $risultato->stato);
        $this->assertNotNull($risultato->pagata_at);
        $this->assertSame('stripe', $risultato->gateway);
    }

    public function test_approva_prenotazione_non_in_approvazione_respinta(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create();
        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 1); // confermata

        $this->expectException(PrenotazioneException::class);
        $this->expectExceptionMessage('La prenotazione non è in attesa di approvazione.');

        $this->service->approva($prenotazione, $this->creaAdmin($evento));
    }

    public function test_approva_di_admin_di_altro_tenant_respinta(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->conApprovazione()->create();
        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 1);
        $estraneo = Utente::factory()->adminAssociazione()->create(); // altra associazione

        $this->expectException(PrenotazioneException::class);

        $this->service->approva($prenotazione, $estraneo);
    }

    public function test_super_admin_senza_associazione_puo_approvare(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->conApprovazione()->create();
        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 1);
        $superAdmin = Utente::factory()->superAdmin()->create(['associazione_id' => null]);

        $risultato = $this->service->approva($prenotazione, $superAdmin);

        $this->assertSame(Prenotazione::STATO_CONFERMATA, $risultato->stato);
    }

    // ------------------------------------------------------------------
    // Rifiuto
    // ------------------------------------------------------------------

    public function test_rifiuta_annulla_con_motivo_e_promuove_la_coda_in_approvazione(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(1)->conApprovazione()->create();
        $prima = $this->service->crea($this->creaAccount($evento), $evento, 1);   // in_approvazione
        $seconda = $this->service->crea($this->creaAccount($evento), $evento, 1); // lista_attesa
        $admin = $this->creaAdmin($evento);

        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $seconda->stato);

        $risultato = $this->service->rifiuta($prima, $admin, 'Posti riservati ai soci');

        $this->assertSame(Prenotazione::STATO_ANNULLATA, $risultato->stato);
        $this->assertSame('Posti riservati ai soci', $risultato->motivo_rifiuto);
        $this->assertNotNull($risultato->annullata_at);

        // Il promosso dalla coda su evento con approvazione NON salta la revisione
        $secondaFresca = $this->fresca($seconda);
        $this->assertSame(Prenotazione::STATO_IN_APPROVAZIONE, $secondaFresca->stato);
        $this->assertNotNull($secondaFresca->promossa_at);
        $this->assertNull($secondaFresca->scadenza_pagamento_at);

        $this->assertContains('rifiutata', $this->service->tipiInviati());
        $this->assertContains('in_approvazione', $this->service->tipiInviati());
        $this->assertContains('da_approvare', $this->service->tipiInviati());
    }

    public function test_rifiuta_prenotazione_non_in_approvazione_respinta(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create();
        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 1); // confermata

        $this->expectException(PrenotazioneException::class);

        $this->service->rifiuta($prenotazione, $this->creaAdmin($evento), 'No');
    }

    public function test_promozione_su_evento_senza_approvazione_resta_invariata(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(1)->create();
        $prima = $this->service->crea($this->creaAccount($evento), $evento, 1);   // confermata
        $seconda = $this->service->crea($this->creaAccount($evento), $evento, 1); // lista_attesa

        $this->service->annulla($prima, $this->creaAdmin($evento));

        // Senza richiede_approvazione il promosso gratuito va direttamente confermato
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $this->fresca($seconda)->stato);
    }

    public function test_scadi_pagamenti_ignora_le_in_approvazione(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->aPagamento(10.0)->conApprovazione()->create();
        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 1);

        $this->travel(2)->hours();

        $this->assertSame(0, $this->service->scadiPagamenti());
        $this->assertSame(Prenotazione::STATO_IN_APPROVAZIONE, $this->fresca($prenotazione)->stato);
    }

    // ------------------------------------------------------------------
    // Azioni HTTP del tab prenotazioni admin
    // ------------------------------------------------------------------

    public function test_admin_approva_via_http(): void
    {
        Notification::fake();

        $evento = Evento::factory()->pubblicato()->conCapienza(10)->conApprovazione()->create();
        $account = $this->creaAccount($evento);
        $prenotazione = app(PrenotazioneService::class)
            ->crea($account, $evento, 1);
        $admin = $this->creaAdmin($evento);

        $response = $this->actingAs($admin)
            ->post("/eventi/{$evento->id}/prenotazioni/{$prenotazione->id}/approva");

        $response->assertRedirect("/eventi/{$evento->id}/prenotazioni");
        $response->assertSessionHas('success');

        $this->assertSame(Prenotazione::STATO_CONFERMATA, $this->fresca($prenotazione)->stato);
        Notification::assertSentTo($account, ConfermataNotification::class);
    }

    public function test_admin_rifiuta_via_http_con_motivo_obbligatorio(): void
    {
        Notification::fake();

        $evento = Evento::factory()->pubblicato()->conCapienza(10)->conApprovazione()->create();
        $account = $this->creaAccount($evento);
        $prenotazione = app(PrenotazioneService::class)
            ->crea($account, $evento, 1);
        $admin = $this->creaAdmin($evento);

        // Senza motivo: errore di validazione, stato invariato
        $this->actingAs($admin)
            ->post("/eventi/{$evento->id}/prenotazioni/{$prenotazione->id}/rifiuta")
            ->assertSessionHasErrors('motivo');
        $this->assertSame(Prenotazione::STATO_IN_APPROVAZIONE, $this->fresca($prenotazione)->stato);

        // Con motivo: annullata + notifica con il motivo
        $this->actingAs($admin)
            ->post("/eventi/{$evento->id}/prenotazioni/{$prenotazione->id}/rifiuta", [
                'motivo' => 'Evento riservato ai soci',
            ])
            ->assertRedirect("/eventi/{$evento->id}/prenotazioni")
            ->assertSessionHas('success');

        $fresca = $this->fresca($prenotazione);
        $this->assertSame(Prenotazione::STATO_ANNULLATA, $fresca->stato);
        $this->assertSame('Evento riservato ai soci', $fresca->motivo_rifiuto);
        Notification::assertSentTo($account, RifiutataNotification::class);
    }

    public function test_admin_di_altro_tenant_riceve_404_via_http(): void
    {
        Notification::fake();

        $evento = Evento::factory()->pubblicato()->conCapienza(10)->conApprovazione()->create();
        $prenotazione = app(PrenotazioneService::class)
            ->crea($this->creaAccount($evento), $evento, 1);
        $estraneo = Utente::factory()->adminAssociazione()->create(); // altro tenant

        $this->actingAs($estraneo)
            ->post("/eventi/{$evento->id}/prenotazioni/{$prenotazione->id}/approva")
            ->assertNotFound();

        $this->assertSame(Prenotazione::STATO_IN_APPROVAZIONE, $this->fresca($prenotazione)->stato);
    }

    public function test_creazione_http_invia_notifiche_in_approvazione_e_da_approvare(): void
    {
        Notification::fake();

        $evento = Evento::factory()->pubblicato()->conCapienza(10)->conApprovazione()->create();
        $account = $this->creaAccount($evento);
        $admin = $this->creaAdmin($evento);

        app(PrenotazioneService::class)->crea($account, $evento, 1);

        Notification::assertSentTo($account, InApprovazioneNotification::class);
        Notification::assertSentTo($admin, DaApprovareNotification::class);
    }

    public function test_approvazione_a_pagamento_invia_notifica_con_finestra_di_pagamento(): void
    {
        Notification::fake();

        $evento = Evento::factory()->pubblicato()->conCapienza(10)->aPagamento(10.0)->conApprovazione()->create();
        $account = $this->creaAccount($evento);
        $service = app(PrenotazioneService::class);
        $prenotazione = $service->crea($account, $evento, 1);

        $service->approva($prenotazione, $this->creaAdmin($evento));

        Notification::assertSentTo($account, InAttesaPagamentoNotification::class);
    }

    // ------------------------------------------------------------------
    // Form evento admin: salvataggio dei nuovi toggle
    // ------------------------------------------------------------------

    public function test_form_evento_salva_i_toggle_iscrizioni_aperte_e_richiede_approvazione(): void
    {
        $evento = Evento::factory()->pubblicato()->create();
        $admin = $this->creaAdmin($evento);

        $response = $this->actingAs($admin)->put("/eventi/{$evento->id}", [
            'titolo' => $evento->titolo,
            'data_evento' => $evento->data_evento->format('Y-m-d H:i:s'),
            'iscrizioni_aperte' => '0',
            'richiede_approvazione' => '1',
        ]);

        $response->assertSessionHasNoErrors();

        $evento->refresh();
        $this->assertFalse($evento->iscrizioni_aperte);
        $this->assertTrue($evento->richiede_approvazione);
        $this->assertFalse($evento->isPrenotabile());
    }

    public function test_form_evento_senza_i_toggle_mantiene_i_valori_correnti(): void
    {
        $evento = Evento::factory()->pubblicato()->conApprovazione()->iscrizioniChiuse()->create();
        $admin = $this->creaAdmin($evento);

        // Client legacy: posta solo i campi base
        $this->actingAs($admin)->put("/eventi/{$evento->id}", [
            'titolo' => 'Titolo aggiornato',
            'data_evento' => $evento->data_evento->format('Y-m-d H:i:s'),
        ])->assertSessionHasNoErrors();

        $evento->refresh();
        $this->assertSame('Titolo aggiornato', $evento->titolo);
        $this->assertFalse($evento->iscrizioni_aperte);
        $this->assertTrue($evento->richiede_approvazione);
    }
}
