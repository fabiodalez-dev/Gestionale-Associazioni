<?php

namespace Tests\Feature\Prenotazioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Area riservata account (/a/{slug}/account/*): accesso (guard account +
 * verificato), dashboard, elenco prenotazioni con filtri e posizione in
 * coda, dettaglio con QR, biglietto PDF (solo confermate), annullo,
 * storico con esito check-in, isolamento tra account e tra tenant.
 */
class AreaRiservataAccountTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create([
            'frontend_pubblico_attivo' => true,
        ]);

        $this->account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    private function urlTenant(string $path = '', ?Associazione $associazione = null): string
    {
        $slug = ($associazione ?? $this->associazione)->slug;

        return '/a/'.$slug.($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    private function eventoFuturo(array $attributes = []): Evento
    {
        return Evento::factory()->pubblicato()->create(array_merge([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->addDays(7),
        ], $attributes));
    }

    // ── Accesso ──────────────────────────────────────────────────────

    public function test_guest_viene_rediretto_alla_login_del_tenant(): void
    {
        $this->get($this->urlTenant('account'))
            ->assertRedirect($this->urlTenant('login'));
    }

    public function test_account_non_verificato_viene_rediretto_all_avviso_verifica(): void
    {
        $account = Account::factory()->nonVerificato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($account, 'account')
            ->get($this->urlTenant('account'))
            ->assertRedirect($this->urlTenant('verifica-email'));
    }

    public function test_account_disattivato_viene_sloggato(): void
    {
        $account = Account::factory()->disattivato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($account, 'account')
            ->get($this->urlTenant('account'))
            ->assertRedirect($this->urlTenant('login'));

        $this->assertGuest('account');
    }

    public function test_dashboard_mostra_prossime_prenotazioni_e_stato_socio(): void
    {
        $evento = $this->eventoFuturo(['titolo' => 'Concerto di primavera']);

        $prenotazione = Prenotazione::factory()->confermata()->create([
            'evento_id' => $evento->id,
            'account_id' => $this->account->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account'))
            ->assertOk()
            ->assertSee('Concerto di primavera')
            ->assertSee($prenotazione->codice)
            ->assertSee('Diventa socio');
    }

    // ── Elenco con filtri e posizione in coda ────────────────────────

    public function test_elenco_mostra_solo_le_proprie_prenotazioni_con_filtro_stato(): void
    {
        $evento = $this->eventoFuturo();

        $mia = Prenotazione::factory()->confermata()->create([
            'evento_id' => $evento->id,
            'account_id' => $this->account->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $miaAnnullata = Prenotazione::factory()->annullata()->create([
            'evento_id' => $evento->id,
            'account_id' => $this->account->id,
            'associazione_id' => $this->associazione->id,
        ]);

        // Prenotazione di un ALTRO account dello stesso tenant: mai visibile
        $altrui = Prenotazione::factory()->confermata()->create([
            'evento_id' => $evento->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/prenotazioni'))
            ->assertOk()
            ->assertSee($mia->codice)
            ->assertSee($miaAnnullata->codice)
            ->assertDontSee($altrui->codice);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/prenotazioni?stato=annullata'))
            ->assertOk()
            ->assertSee($miaAnnullata->codice)
            ->assertDontSee($mia->codice);
    }

    public function test_elenco_mostra_la_posizione_in_coda_delle_lista_attesa(): void
    {
        $evento = $this->eventoFuturo(['capienza' => 1]);

        Prenotazione::factory()->confermata()->create([
            'evento_id' => $evento->id,
            'associazione_id' => $this->associazione->id,
        ]);

        // Un altro account è in coda PRIMA di me → io sono 2°
        Prenotazione::factory()->listaAttesa()->create([
            'evento_id' => $evento->id,
            'associazione_id' => $this->associazione->id,
            'created_at' => now()->subMinutes(10),
        ]);

        Prenotazione::factory()->listaAttesa()->create([
            'evento_id' => $evento->id,
            'account_id' => $this->account->id,
            'associazione_id' => $this->associazione->id,
            'created_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/prenotazioni'))
            ->assertOk()
            ->assertSee('in coda: 2');
    }

    // ── Dettaglio con QR e isolamento ────────────────────────────────

    public function test_dettaglio_confermata_mostra_qr_e_dati_pagamento(): void
    {
        $evento = $this->eventoFuturo();

        $prenotazione = Prenotazione::factory()->pagata()->create([
            'evento_id' => $evento->id,
            'account_id' => $this->account->id,
            'associazione_id' => $this->associazione->id,
            'prezzo_unitario' => 15,
            'importo_totale' => 15,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/prenotazioni/'.$prenotazione->id))
            ->assertOk()
            ->assertSee($prenotazione->codice)
            ->assertSee('<svg', false) // QR inline SVG
            ->assertSee('Scarica biglietto PDF')
            ->assertSee($prenotazione->gateway_transaction_id);
    }

    public function test_dettaglio_di_prenotazione_altrui_e_vietato(): void
    {
        $evento = $this->eventoFuturo();

        $altrui = Prenotazione::factory()->confermata()->create([
            'evento_id' => $evento->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/prenotazioni/'.$altrui->id))
            ->assertForbidden();
    }

    public function test_prenotazione_di_altro_tenant_e_404_uniforme(): void
    {
        $altraAssociazione = Associazione::factory()->create([
            'frontend_pubblico_attivo' => true,
        ]);

        $altrui = Prenotazione::factory()->confermata()->create([
            'evento_id' => Evento::factory()->pubblicato()->create([
                'associazione_id' => $altraAssociazione->id,
                'data_evento' => now()->addDays(7),
            ])->id,
            'associazione_id' => $altraAssociazione->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/prenotazioni/'.$altrui->id))
            ->assertNotFound();
    }

    // ── Biglietto PDF ────────────────────────────────────────────────

    public function test_biglietto_pdf_scaricabile_solo_per_le_confermate(): void
    {
        $evento = $this->eventoFuturo();

        $confermata = Prenotazione::factory()->confermata()->create([
            'evento_id' => $evento->id,
            'account_id' => $this->account->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $response = $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/prenotazioni/'.$confermata->id.'/biglietto.pdf'));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        $inAttesa = Prenotazione::factory()->inAttesaPagamento()->create([
            'evento_id' => $evento->id,
            'account_id' => $this->account->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/prenotazioni/'.$inAttesa->id.'/biglietto.pdf'))
            ->assertNotFound();
    }

    public function test_biglietto_pdf_di_prenotazione_altrui_e_vietato(): void
    {
        $altrui = Prenotazione::factory()->confermata()->create([
            'evento_id' => $this->eventoFuturo()->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/prenotazioni/'.$altrui->id.'/biglietto.pdf'))
            ->assertForbidden();
    }

    // ── Annullo ──────────────────────────────────────────────────────

    public function test_annullo_di_una_confermata_entro_la_finestra(): void
    {
        $evento = $this->eventoFuturo(['data_evento' => now()->addDays(7)]);

        $prenotazione = Prenotazione::factory()->confermata()->create([
            'evento_id' => $evento->id,
            'account_id' => $this->account->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->post($this->urlTenant('account/prenotazioni/'.$prenotazione->id.'/annulla'))
            ->assertRedirect($this->urlTenant('account/prenotazioni/'.$prenotazione->id));

        $this->assertSame(
            Prenotazione::STATO_ANNULLATA,
            $prenotazione->fresh()->stato,
        );
    }

    public function test_annullo_oltre_il_termine_viene_rifiutato(): void
    {
        // Evento tra 2 ore, finestra di annullamento 24 ore → fuori termine
        $evento = $this->eventoFuturo([
            'data_evento' => now()->addHours(2),
            'annullamento_entro_ore' => 24,
        ]);

        $prenotazione = Prenotazione::factory()->confermata()->create([
            'evento_id' => $evento->id,
            'account_id' => $this->account->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->from($this->urlTenant('account/prenotazioni/'.$prenotazione->id))
            ->post($this->urlTenant('account/prenotazioni/'.$prenotazione->id.'/annulla'))
            ->assertSessionHasErrors('annulla');

        $this->assertSame(
            Prenotazione::STATO_CONFERMATA,
            $prenotazione->fresh()->stato,
        );
    }

    public function test_annullo_di_prenotazione_altrui_e_vietato(): void
    {
        $altrui = Prenotazione::factory()->confermata()->create([
            'evento_id' => $this->eventoFuturo()->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->post($this->urlTenant('account/prenotazioni/'.$altrui->id.'/annulla'))
            ->assertForbidden();

        $this->assertSame(Prenotazione::STATO_CONFERMATA, $altrui->fresh()->stato);
    }

    // ── Storico ──────────────────────────────────────────────────────

    public function test_storico_mostra_eventi_passati_con_esito_checkin(): void
    {
        $eventoPassato = Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->subDays(10),
            'titolo' => 'Cena sociale invernale',
        ]);

        $conCheckin = Prenotazione::factory()->conCheckin()->create([
            'evento_id' => $eventoPassato->id,
            'account_id' => $this->account->id,
            'associazione_id' => $this->associazione->id,
        ]);

        // Evento futuro: NON deve comparire nello storico
        $futura = Prenotazione::factory()->confermata()->create([
            'evento_id' => $this->eventoFuturo(['titolo' => 'Gita di ferragosto'])->id,
            'account_id' => $this->account->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/storico'))
            ->assertOk()
            ->assertSee('Cena sociale invernale')
            ->assertSee('Presente')
            ->assertDontSee('Gita di ferragosto');
    }
}
