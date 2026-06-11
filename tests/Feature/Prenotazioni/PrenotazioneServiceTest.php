<?php

namespace Tests\Feature\Prenotazioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Utente;
use App\Services\Prenotazioni\PrenotazioneException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Macchina a stati delle prenotazioni (PrenotazioneService):
 * esiti di creazione, capienza esatta, doppia prenotazione, annullamento,
 * scadenza pagamenti, promozione FIFO stretto, idempotenza webhook,
 * riconferma post-scadenza (overbooking accettato), promemoria eventi.
 */
class PrenotazioneServiceTest extends TestCase
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
        return Utente::factory()->create(['associazione_id' => $evento->associazione_id]);
    }

    protected function fresca(Prenotazione $prenotazione): Prenotazione
    {
        return Prenotazione::allTenants()->findOrFail($prenotazione->id);
    }

    // ------------------------------------------------------------------
    // Creazione: esiti
    // ------------------------------------------------------------------

    public function test_crea_gratuito_con_posti_disponibili_va_confermata(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create();
        $account = $this->creaAccount($evento);

        $prenotazione = $this->service->crea($account, $evento, 2);

        $this->assertSame(Prenotazione::STATO_CONFERMATA, $prenotazione->stato);
        $this->assertSame(2, $prenotazione->posti);
        $this->assertSame(0.0, (float) $prenotazione->importo_totale);
        $this->assertNull($prenotazione->scadenza_pagamento_at);
        $this->assertMatchesRegularExpression('/^PRN-[2-9A-HJKMNP-TV-Z]{6}$/', $prenotazione->codice);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $prenotazione->qr_token);
        $this->assertSame($evento->associazione_id, $prenotazione->associazione_id);
        $this->assertSame(['confermata'], $this->service->tipiInviati());
    }

    public function test_crea_a_pagamento_va_in_attesa_pagamento_con_scadenza_30_minuti(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->aPagamento(12.50)->create();
        $account = $this->creaAccount($evento);

        $prenotazione = $this->service->crea($account, $evento, 3);

        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $prenotazione->stato);
        // Snapshot del prezzo al momento della prenotazione
        $this->assertSame(12.50, (float) $prenotazione->prezzo_unitario);
        $this->assertSame(37.50, (float) $prenotazione->importo_totale);
        $this->assertNotNull($prenotazione->scadenza_pagamento_at);
        $this->assertEqualsWithDelta(
            now()->addMinutes(30)->getTimestamp(),
            $prenotazione->scadenza_pagamento_at->getTimestamp(),
            5
        );
        $this->assertSame(['in_attesa_pagamento'], $this->service->tipiInviati());
    }

    public function test_crea_su_evento_pieno_va_in_lista_attesa(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(2)->create();
        $this->service->crea($this->creaAccount($evento), $evento, 2);

        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 1);

        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $prenotazione->stato);
        $this->assertContains('lista_attesa', $this->service->tipiInviati());
    }

    public function test_crea_su_evento_pieno_senza_lista_attesa_rifiutata(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(1)->create([
            'lista_attesa_attiva' => false,
        ]);
        $this->service->crea($this->creaAccount($evento), $evento, 1);

        $this->expectException(PrenotazioneException::class);

        $this->service->crea($this->creaAccount($evento), $evento, 1);
    }

    public function test_capienza_illimitata_sempre_confermata(): void
    {
        $evento = Evento::factory()->pubblicato()->create(['capienza' => null]);

        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 4);

        $this->assertSame(Prenotazione::STATO_CONFERMATA, $prenotazione->stato);
    }

    // ------------------------------------------------------------------
    // Capienza esatta: niente oversell con posti multipli
    // ------------------------------------------------------------------

    public function test_capienza_esatta_no_oversell_con_posti_multipli(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(5)->create();

        $a = $this->service->crea($this->creaAccount($evento), $evento, 3); // 3/5
        $b = $this->service->crea($this->creaAccount($evento), $evento, 3); // non entra → coda
        $c = $this->service->crea($this->creaAccount($evento), $evento, 2); // 5/5 esatti
        $d = $this->service->crea($this->creaAccount($evento), $evento, 1); // pieno → coda

        $this->assertSame(Prenotazione::STATO_CONFERMATA, $a->stato);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $b->stato);
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $c->stato);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $d->stato);

        // Mai oltre la capienza: SUM(posti) degli stati attivi == 5
        $this->assertSame(5, Prenotazione::postiOccupati($evento->id));
    }

    // ------------------------------------------------------------------
    // Validazioni di creazione
    // ------------------------------------------------------------------

    public function test_doppia_prenotazione_attiva_bloccata(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create();
        $account = $this->creaAccount($evento);

        $this->service->crea($account, $evento, 1);

        $this->expectException(PrenotazioneException::class);
        $this->expectExceptionMessage('già una prenotazione attiva');

        $this->service->crea($account, $evento, 1);
    }

    public function test_anche_la_lista_attesa_blocca_la_doppia_prenotazione(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(1)->create();
        $this->service->crea($this->creaAccount($evento), $evento, 1);

        $account = $this->creaAccount($evento);
        $this->service->crea($account, $evento, 1); // → lista_attesa

        $this->expectException(PrenotazioneException::class);

        $this->service->crea($account, $evento, 1);
    }

    public function test_dopo_annullamento_si_puo_prenotare_di_nuovo(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create();
        $account = $this->creaAccount($evento);

        $prima = $this->service->crea($account, $evento, 1);
        $this->service->annulla($prima, $account);

        $seconda = $this->service->crea($account, $evento, 1);

        $this->assertSame(Prenotazione::STATO_CONFERMATA, $seconda->stato);
    }

    public function test_evento_non_pubblicato_non_prenotabile(): void
    {
        $evento = Evento::factory()->conCapienza(10)->create(); // bozza

        $this->expectException(PrenotazioneException::class);

        $this->service->crea($this->creaAccount($evento), $evento, 1);
    }

    public function test_finestra_iscrizioni_chiusa_non_prenotabile(): void
    {
        $evento = Evento::factory()->pubblicato()->create([
            'data_evento' => now()->addWeek(),
            'iscrizioni_al' => now()->subHour(),
        ]);

        $this->expectException(PrenotazioneException::class);

        $this->service->crea($this->creaAccount($evento), $evento, 1);
    }

    public function test_finestra_iscrizioni_non_ancora_aperta_non_prenotabile(): void
    {
        $evento = Evento::factory()->pubblicato()->create([
            'data_evento' => now()->addWeek(),
            'iscrizioni_dal' => now()->addDay(),
        ]);

        $this->expectException(PrenotazioneException::class);

        $this->service->crea($this->creaAccount($evento), $evento, 1);
    }

    public function test_evento_passato_non_prenotabile(): void
    {
        $evento = Evento::factory()->pubblicato()->passato()->create();

        $this->expectException(PrenotazioneException::class);

        $this->service->crea($this->creaAccount($evento), $evento, 1);
    }

    public function test_posti_oltre_il_massimo_per_prenotazione_rifiutati(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(100)->create([
            'max_posti_per_prenotazione' => 4,
        ]);

        $this->expectException(PrenotazioneException::class);
        $this->expectExceptionMessage('da 1 a 4 posti');

        $this->service->crea($this->creaAccount($evento), $evento, 5);
    }

    public function test_posti_zero_rifiutati(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(100)->create();

        $this->expectException(PrenotazioneException::class);

        $this->service->crea($this->creaAccount($evento), $evento, 0);
    }

    public function test_account_di_altro_tenant_rifiutato(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create();
        $altro = Account::factory()->create(); // altra associazione

        $this->expectException(PrenotazioneException::class);

        $this->service->crea($altro, $evento, 1);
    }

    // ------------------------------------------------------------------
    // Annullamento
    // ------------------------------------------------------------------

    public function test_utente_annulla_confermata_entro_il_termine(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'data_evento' => now()->addWeek(),
            'annullamento_entro_ore' => 24,
        ]);
        $account = $this->creaAccount($evento);
        $prenotazione = $this->service->crea($account, $evento, 2);

        $annullata = $this->service->annulla($prenotazione, $account);

        $this->assertSame(Prenotazione::STATO_ANNULLATA, $annullata->stato);
        $this->assertNotNull($annullata->annullata_at);
        $this->assertSame("Annullata dall'utente", $annullata->motivo_annullamento);
        $this->assertSame(0, Prenotazione::postiOccupati($evento->id));
        $this->assertContains('annullata', $this->service->tipiInviati());
    }

    public function test_annullo_oltre_il_termine_rifiutato_per_l_utente(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'data_evento' => now()->addHours(10),
            'annullamento_entro_ore' => 24, // termine già superato
        ]);
        $account = $this->creaAccount($evento);
        $prenotazione = $this->service->crea($account, $evento, 1);

        try {
            $this->service->annulla($prenotazione, $account);
            $this->fail('Attesa PrenotazioneException oltre il termine di annullamento.');
        } catch (PrenotazioneException $e) {
            $this->assertStringContainsString('termine', $e->getMessage());
        }

        $this->assertSame(Prenotazione::STATO_CONFERMATA, $this->fresca($prenotazione)->stato);
    }

    public function test_admin_annulla_anche_oltre_il_termine(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'data_evento' => now()->addHours(10),
            'annullamento_entro_ore' => 24,
        ]);
        $account = $this->creaAccount($evento);
        $prenotazione = $this->service->crea($account, $evento, 1);

        $annullata = $this->service->annulla($prenotazione, $this->creaAdmin($evento));

        $this->assertSame(Prenotazione::STATO_ANNULLATA, $annullata->stato);
        $this->assertSame("Annullata dall'amministrazione", $annullata->motivo_annullamento);
    }

    public function test_utente_non_annulla_dopo_il_checkin(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'data_evento' => now()->addWeek(),
        ]);
        $account = $this->creaAccount($evento);
        $prenotazione = $this->service->crea($account, $evento, 1);
        $prenotazione->forceFill(['checkin_at' => now()])->save();

        $this->expectException(PrenotazioneException::class);

        $this->service->annulla($prenotazione, $account);
    }

    public function test_account_non_proprietario_non_annulla(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create();
        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 1);

        $this->expectException(PrenotazioneException::class);

        $this->service->annulla($prenotazione, $this->creaAccount($evento));
    }

    public function test_utente_annulla_in_attesa_pagamento_in_ogni_momento(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->aPagamento()->create([
            'data_evento' => now()->addHours(2),
            'annullamento_entro_ore' => 24, // termine confermate superato: non si applica
        ]);
        $account = $this->creaAccount($evento);
        $prenotazione = $this->service->crea($account, $evento, 1);

        $annullata = $this->service->annulla($prenotazione, $account);

        $this->assertSame(Prenotazione::STATO_ANNULLATA, $annullata->stato);
    }

    public function test_utente_esce_dalla_lista_attesa_senza_promozioni(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(1)->create();
        $this->service->crea($this->creaAccount($evento), $evento, 1);

        $account = $this->creaAccount($evento);
        $inCoda = $this->service->crea($account, $evento, 1);

        $annullata = $this->service->annulla($inCoda, $account);

        $this->assertSame(Prenotazione::STATO_ANNULLATA, $annullata->stato);
        // Non consumava posti: nessuna promozione scatenata
        $this->assertNotContains('promozione', $this->service->tipiInviati());
    }

    public function test_annullata_o_scaduta_non_riannullabile(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create();
        $account = $this->creaAccount($evento);
        $prenotazione = $this->service->crea($account, $evento, 1);
        $this->service->annulla($prenotazione, $account);

        $this->expectException(PrenotazioneException::class);

        $this->service->annulla($this->fresca($prenotazione), $this->creaAdmin($evento));
    }

    // ------------------------------------------------------------------
    // Annullamento → rilascio posti → promozione
    // ------------------------------------------------------------------

    public function test_annullo_promuove_la_lista_attesa(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(2)->create([
            'data_evento' => now()->addWeek(),
        ]);
        $account = $this->creaAccount($evento);
        $confermata = $this->service->crea($account, $evento, 2);
        $inCoda = $this->service->crea($this->creaAccount($evento), $evento, 2);

        $this->service->annulla($confermata, $account);

        $promossa = $this->fresca($inCoda);
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $promossa->stato);
        $this->assertNotNull($promossa->promossa_at);
        $this->assertSame([$inCoda->id], $this->service->idPerTipo('promozione'));
    }

    public function test_promozione_fifo_stretto_si_ferma_al_primo_che_non_entra(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(4)->create([
            'data_evento' => now()->addWeek(),
        ]);
        $account = $this->creaAccount($evento);
        $piena = $this->service->crea($account, $evento, 4);

        $b = $this->service->crea($this->creaAccount($evento), $evento, 2);
        $c = $this->service->crea($this->creaAccount($evento), $evento, 3);
        $d = $this->service->crea($this->creaAccount($evento), $evento, 1);

        $this->service->annulla($piena, $account); // libera 4 posti

        // B (2 posti) entra; C (3 posti) NON entra → la coda si ferma lì:
        // D (1 posto) resta in attesa anche se entrerebbe (FIFO stretto)
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $this->fresca($b)->stato);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $this->fresca($c)->stato);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $this->fresca($d)->stato);
        $this->assertSame([$b->id], $this->service->idPerTipo('promozione'));
    }

    public function test_promozione_di_pagante_apre_finestra_24h(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(1)->aPagamento(15.0)->create([
            'data_evento' => now()->addWeek(),
        ]);
        $account = $this->creaAccount($evento);

        $prima = $this->service->crea($account, $evento, 1); // in_attesa_pagamento
        $inCoda = $this->service->crea($this->creaAccount($evento), $evento, 1); // lista_attesa

        $this->service->annulla($prima, $account);

        $promossa = $this->fresca($inCoda);
        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $promossa->stato);
        $this->assertNotNull($promossa->promossa_at);
        $this->assertEqualsWithDelta(
            now()->addHours(24)->getTimestamp(),
            $promossa->scadenza_pagamento_at->getTimestamp(),
            5
        );
        // Snapshot del prezzo preservato dalla creazione
        $this->assertSame(15.0, (float) $promossa->importo_totale);
    }

    public function test_promuovi_lista_attesa_standalone_dopo_aumento_capienza(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(1)->create([
            'data_evento' => now()->addWeek(),
        ]);
        $this->service->crea($this->creaAccount($evento), $evento, 1);
        $b = $this->service->crea($this->creaAccount($evento), $evento, 1);
        $c = $this->service->crea($this->creaAccount($evento), $evento, 1);

        $evento->forceFill(['capienza' => 3])->save();

        $promosse = $this->service->promuoviListaAttesa($evento->id);

        $this->assertCount(2, $promosse);
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $this->fresca($b)->stato);
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $this->fresca($c)->stato);
    }

    // ------------------------------------------------------------------
    // Scadenza pagamenti (scheduler)
    // ------------------------------------------------------------------

    public function test_scadi_pagamenti_marca_scadute_e_promuove(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(1)->aPagamento()->create([
            'data_evento' => now()->addWeek(),
        ]);
        $inAttesa = $this->service->crea($this->creaAccount($evento), $evento, 1);
        $inCoda = $this->service->crea($this->creaAccount($evento), $evento, 1);

        $this->travel(31)->minutes();

        $scadute = $this->service->scadiPagamenti();

        $this->assertSame(1, $scadute);
        $this->assertSame(Prenotazione::STATO_SCADUTA, $this->fresca($inAttesa)->stato);

        // La scadenza rilascia i posti e promuove il primo in coda (pagante → 24h)
        $promossa = $this->fresca($inCoda);
        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $promossa->stato);
        $this->assertNotNull($promossa->promossa_at);
        $this->assertContains('pagamento_scaduto', $this->service->tipiInviati());
        $this->assertContains('promozione', $this->service->tipiInviati());
    }

    public function test_scadi_pagamenti_ignora_le_finestre_ancora_aperte(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(5)->aPagamento()->create();
        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 1);

        $this->assertSame(0, $this->service->scadiPagamenti());
        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $this->fresca($prenotazione)->stato);
    }

    // ------------------------------------------------------------------
    // Pagamento completato (webhook)
    // ------------------------------------------------------------------

    public function test_pagamento_completato_conferma_la_prenotazione(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(5)->aPagamento()->create();
        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 2);

        $confermata = $this->service->onPagamentoCompletato(
            $prenotazione->id,
            $evento->associazione_id,
            'stripe',
            'pi_test_123',
        );

        $this->assertSame(Prenotazione::STATO_CONFERMATA, $confermata->stato);
        $this->assertSame('stripe', $confermata->gateway);
        $this->assertSame('pi_test_123', $confermata->gateway_transaction_id);
        $this->assertNotNull($confermata->pagata_at);
        $this->assertContains('confermata', $this->service->tipiInviati());
    }

    public function test_pagamento_completato_idempotente_su_replay_webhook(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(5)->aPagamento()->create();
        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 1);

        $prima = $this->service->onPagamentoCompletato($prenotazione->id, $evento->associazione_id, 'stripe', 'pi_primo');
        $replay = $this->service->onPagamentoCompletato($prenotazione->id, $evento->associazione_id, 'stripe', 'pi_replay');

        $this->assertNotNull($replay);
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $replay->stato);
        // Il replay non sovrascrive nulla: transaction id e pagata_at originali
        $this->assertSame('pi_primo', $replay->gateway_transaction_id);
        $this->assertEquals($prima->pagata_at, $replay->pagata_at);
        // Una sola notifica di conferma
        $this->assertSame([$prenotazione->id], $this->service->idPerTipo('confermata'));
    }

    public function test_riconferma_post_scadenza_accetta_overbooking(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(1)->aPagamento()->create([
            'data_evento' => now()->addWeek(),
        ]);
        $inAttesa = $this->service->crea($this->creaAccount($evento), $evento, 1);
        $inCoda = $this->service->crea($this->creaAccount($evento), $evento, 1);

        // Scade → il posto passa al promosso dalla coda
        $this->travel(31)->minutes();
        $this->service->scadiPagamenti();
        $this->assertSame(Prenotazione::STATO_SCADUTA, $this->fresca($inAttesa)->stato);
        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $this->fresca($inCoda)->stato);

        // Il pagamento della scaduta arriva comunque (era già in volo):
        // riconferma anche in overbooking, il denaro è stato incassato
        $riconfermata = $this->service->onPagamentoCompletato(
            $inAttesa->id,
            $evento->associazione_id,
            'paypal',
            'TX-RITARDATARIO',
        );

        $this->assertSame(Prenotazione::STATO_CONFERMATA, $riconfermata->stato);
        $this->assertNotNull($riconfermata->pagata_at);
        // Overbooking visibile: occupati (2) > capienza (1), nessuna retrocessione
        $this->assertSame(2, Prenotazione::postiOccupati($evento->id));
        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $this->fresca($inCoda)->stato);
    }

    public function test_pagamento_su_prenotazione_inesistente_o_tenant_errato_da_null(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(5)->aPagamento()->create();
        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 1);
        $altraAssociazione = Associazione::factory()->create();

        $this->assertNull($this->service->onPagamentoCompletato(
            '00000000-0000-0000-0000-000000000000',
            $evento->associazione_id,
            'stripe',
            'pi_x',
        ));

        $this->assertNull($this->service->onPagamentoCompletato(
            $prenotazione->id,
            $altraAssociazione->id, // tenant sbagliato
            'stripe',
            'pi_x',
        ));

        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $this->fresca($prenotazione)->stato);
    }

    public function test_pagamento_su_annullata_registra_incasso_senza_cambiare_stato(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(5)->aPagamento()->create([
            'data_evento' => now()->addWeek(),
        ]);
        $account = $this->creaAccount($evento);
        $prenotazione = $this->service->crea($account, $evento, 1);
        $this->service->annulla($prenotazione, $account);

        $esito = $this->service->onPagamentoCompletato($prenotazione->id, $evento->associazione_id, 'stripe', 'pi_tardivo');

        // Lo stato resta annullata (rimborso manuale in admin), l'incasso è tracciato
        $this->assertSame(Prenotazione::STATO_ANNULLATA, $esito->stato);
        $this->assertNotNull($esito->pagata_at);
        $this->assertSame('pi_tardivo', $esito->gateway_transaction_id);
    }

    // ------------------------------------------------------------------
    // Notifiche: sempre fuori dalla transazione
    // ------------------------------------------------------------------

    public function test_le_notifiche_partono_fuori_dalla_transazione(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(1)->aPagamento()->create([
            'data_evento' => now()->addWeek(),
        ]);
        $account = $this->creaAccount($evento);
        $prenotazione = $this->service->crea($account, $evento, 1);
        $this->service->crea($this->creaAccount($evento), $evento, 1); // coda
        $this->service->annulla($prenotazione, $account); // annullata + promozione

        $this->assertNotEmpty($this->service->livelliTransazione);

        // RefreshDatabase tiene il test al livello 1: dentro la transazione
        // di dominio il livello sarebbe 2+. Tutti gli hook devono vedere 1.
        foreach ($this->service->livelliTransazione as $livello) {
            $this->assertSame(1, $livello);
        }
    }

    // ------------------------------------------------------------------
    // Promemoria eventi (comando giornaliero)
    // ------------------------------------------------------------------

    public function test_promemoria_alle_confermate_con_evento_tra_24_e_48_ore(): void
    {
        $inFinestra = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'data_evento' => now()->addHours(30),
        ]);
        $troppoVicino = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'associazione_id' => $inFinestra->associazione_id,
            'data_evento' => now()->addHours(10),
        ]);
        $troppoLontano = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'associazione_id' => $inFinestra->associazione_id,
            'data_evento' => now()->addHours(60),
        ]);

        $attesa = $this->service->crea($this->creaAccount($inFinestra), $inFinestra, 1);
        $this->service->crea($this->creaAccount($troppoVicino), $troppoVicino, 1);
        $this->service->crea($this->creaAccount($troppoLontano), $troppoLontano, 1);

        $inviati = $this->service->inviaPromemoriaEventi();

        $this->assertSame(1, $inviati);
        $this->assertSame([$attesa->id], $this->service->idPerTipo('promemoria'));
    }

    public function test_promemoria_rispetta_preferenza_e_stato(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(1)->create([
            'data_evento' => now()->addHours(30),
        ]);

        // Confermata ma con preferenza promemoria disattivata
        $contrario = Account::factory()->create([
            'associazione_id' => $evento->associazione_id,
            'preferenze' => ['promemoria_eventi' => false],
        ]);
        $this->service->crea($contrario, $evento, 1);

        // In lista d'attesa (non confermata): nessun promemoria
        $this->service->crea($this->creaAccount($evento), $evento, 1);

        $this->assertSame(0, $this->service->inviaPromemoriaEventi());
        $this->assertSame([], $this->service->idPerTipo('promemoria'));
    }

    // ------------------------------------------------------------------
    // Comandi artisan + scheduler
    // ------------------------------------------------------------------

    public function test_comando_gestisci_scadenze(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(5)->aPagamento()->create();
        $prenotazione = $this->service->crea($this->creaAccount($evento), $evento, 1);

        $this->travel(31)->minutes();

        $this->artisan('prenotazioni:gestisci-scadenze')
            ->expectsOutputToContain('Prenotazioni scadute: 1')
            ->assertSuccessful();

        $this->assertSame(Prenotazione::STATO_SCADUTA, $this->fresca($prenotazione)->stato);
    }

    public function test_comando_promemoria_eventi(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(5)->create([
            'data_evento' => now()->addHours(30),
        ]);
        $this->service->crea($this->creaAccount($evento), $evento, 1);

        $this->artisan('prenotazioni:promemoria-eventi')
            ->expectsOutputToContain('Promemoria inviati: 1')
            ->assertSuccessful();
    }

    public function test_comandi_registrati_nello_scheduler(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('prenotazioni:gestisci-scadenze')
            ->expectsOutputToContain('prenotazioni:promemoria-eventi')
            ->assertSuccessful();
    }
}
