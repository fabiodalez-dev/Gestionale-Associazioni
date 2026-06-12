<?php

namespace Tests\Feature\EmailConferme;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Ricevuta;
use App\Models\Socio;
use App\Notifications\Quote\RicevutaQuotaNotification;
use App\Services\Pagamenti\PaymentService;
use App\Services\Pagamenti\RicevutaService;
use Illuminate\Support\Facades\Notification;

/**
 * f.21 — Ricevute quote PDF numerate per anno: emissione alla conferma
 * del pagamento (offline e webhook via PaymentService), una sola ricevuta
 * per quota, numerazione per tenant+anno, email al socio, download da
 * admin e dalle aree riservate (area soci + account del mini-sito).
 */
class RicevuteQuoteTest extends EmailConfermeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    private function creaQuotaDaPagare(Socio $socio, array $attributes = []): Quota
    {
        return Quota::factory()->create(array_merge([
            'socio_id' => $socio->id,
            'associazione_id' => $socio->associazione_id,
            'anno' => (int) now()->year,
            'data_pagamento' => null,
            'gateway_transaction_id' => null,
        ], $attributes));
    }

    // ------------------------------------------------------------------
    // Emissione alla conferma del pagamento
    // ------------------------------------------------------------------

    public function test_pagamento_offline_emette_ricevuta_numerata_e_notifica_il_socio(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuotaDaPagare($socio);

        $this->actingAs($this->admin)->post(route('quote.pagamento', $quota), [
            'payment_method' => 'bonifico',
            'payment_date' => now()->toDateString(),
        ])->assertRedirect(route('quote.index'));

        $ricevuta = Ricevuta::allTenants()->where('quota_id', $quota->id)->sole();
        $this->assertSame(1, $ricevuta->numero);
        $this->assertSame((int) $quota->anno, $ricevuta->anno);
        $this->assertSame('1/'.$quota->anno, $ricevuta->numero_completo);

        Notification::assertSentTo(
            $socio,
            RicevutaQuotaNotification::class,
            fn (RicevutaQuotaNotification $n): bool => $n->ricevuta->quota_id === $quota->id
        );
    }

    public function test_on_payment_completed_emette_la_ricevuta_ed_e_idempotente_sul_replay(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuotaDaPagare($socio);

        /** @var PaymentService $payments */
        $payments = app(PaymentService::class);

        $this->assertTrue($payments->onPaymentCompleted($quota->id, $this->associazione->id, 'stripe', 'tx_123'));
        // Replay del webhook: ack senza nuova ricevuta né nuova email
        $this->assertTrue($payments->onPaymentCompleted($quota->id, $this->associazione->id, 'stripe', 'tx_123'));

        $this->assertSame(1, Ricevuta::allTenants()->where('quota_id', $quota->id)->count());

        Notification::assertSentToTimes($socio, RicevutaQuotaNotification::class, 1);
    }

    public function test_numerazione_progressiva_per_anno_e_per_tenant(): void
    {
        $socio = $this->creaSocio();

        /** @var RicevutaService $ricevute */
        $ricevute = app(RicevutaService::class);

        $prima = $ricevute->emettiPerQuota($this->creaQuotaPagata($socio));
        $seconda = $ricevute->emettiPerQuota($this->creaQuotaPagata($socio));
        $altroAnno = $ricevute->emettiPerQuota($this->creaQuotaPagata($socio, ['anno' => now()->year - 1]));

        $this->assertSame([1, 2], [$prima->numero, $seconda->numero]);
        // La numerazione riparte per ogni anno
        $this->assertSame(1, $altroAnno->numero);

        // ...e per ogni tenant
        $altraAssociazione = Associazione::factory()->create();
        $socioAltro = $this->creaSocio([], $altraAssociazione);
        $ricevutaAltro = $ricevute->emettiPerQuota($this->creaQuotaPagata($socioAltro));

        $this->assertSame(1, $ricevutaAltro->numero);
        $this->assertSame($altraAssociazione->id, $ricevutaAltro->associazione_id);
    }

    public function test_una_sola_ricevuta_per_quota(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuotaPagata($socio);

        /** @var RicevutaService $ricevute */
        $ricevute = app(RicevutaService::class);

        $prima = $ricevute->emettiPerQuota($quota);
        $replica = $ricevute->emettiPerQuota($quota->fresh());

        $this->assertSame($prima->id, $replica->id);
        $this->assertSame(1, Ricevuta::allTenants()->where('quota_id', $quota->id)->count());
    }

    public function test_quota_non_pagata_non_genera_ricevuta(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuotaDaPagare($socio);

        $this->assertNull(app(RicevutaService::class)->emettiPerQuota($quota));
        $this->assertDatabaseCount('ricevute', 0);
    }

    // ------------------------------------------------------------------
    // Download admin
    // ------------------------------------------------------------------

    public function test_admin_scarica_il_pdf_numerato_con_emissione_on_demand(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuotaPagata($socio);

        $response = $this->actingAs($this->admin)->get(route('quote.ricevuta', $quota));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('content-disposition', 'attachment; filename="ricevuta-1-'.$quota->anno.'.pdf"');

        $this->assertDatabaseHas('ricevute', [
            'quota_id' => $quota->id,
            'numero' => 1,
        ]);
    }

    public function test_admin_non_scarica_la_ricevuta_di_quota_non_pagata(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuotaDaPagare($socio);

        $this->actingAs($this->admin)
            ->get(route('quote.ricevuta', $quota))
            ->assertRedirect(route('quote.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('ricevute', 0);
    }

    public function test_admin_di_altro_tenant_non_accede_alla_quota(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuotaPagata($socio);

        $adminEstraneo = $this->creaAdminPer(Associazione::factory()->create());

        $this->actingAs($adminEstraneo)
            ->get(route('quote.ricevuta', $quota))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Download area soci
    // ------------------------------------------------------------------

    public function test_socio_scarica_la_propria_ricevuta_dall_area_soci(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $quota = $this->creaQuotaPagata($socio);

        $response = $this->actingAs($socio, 'socio')
            ->get(route('area-soci.quote.ricevuta', $quota));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_socio_non_scarica_la_ricevuta_di_un_altro_socio(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $altroSocio = $this->creaSocio();
        $quotaAltrui = $this->creaQuotaPagata($altroSocio);

        $this->actingAs($socio, 'socio')
            ->get(route('area-soci.quote.ricevuta', $quotaAltrui))
            ->assertNotFound();
    }

    public function test_socio_non_scarica_la_ricevuta_di_quota_non_pagata(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $quota = $this->creaQuotaDaPagare($socio);

        $this->actingAs($socio, 'socio')
            ->get(route('area-soci.quote.ricevuta', $quota))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Download area account (mini-sito pubblico)
    // ------------------------------------------------------------------

    public function test_account_collegato_scarica_la_ricevuta_del_proprio_socio(): void
    {
        $this->associazione->update(['frontend_pubblico_attivo' => true]);

        $socio = $this->creaSocio();
        $quota = $this->creaQuotaPagata($socio);

        $account = Account::factory()->verificato()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
        ]);

        $response = $this->actingAs($account, 'account')
            ->get('/a/'.$this->associazione->slug.'/account/quote/'.$quota->id.'/ricevuta');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_account_non_collegato_a_socio_riceve_404(): void
    {
        $this->associazione->update(['frontend_pubblico_attivo' => true]);

        $socio = $this->creaSocio();
        $quota = $this->creaQuotaPagata($socio);

        $account = Account::factory()->verificato()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => null,
        ]);

        $this->actingAs($account, 'account')
            ->get('/a/'.$this->associazione->slug.'/account/quote/'.$quota->id.'/ricevuta')
            ->assertNotFound();
    }

    public function test_account_non_scarica_ricevute_di_altri_soci(): void
    {
        $this->associazione->update(['frontend_pubblico_attivo' => true]);

        $socioCollegato = $this->creaSocio();
        $altroSocio = $this->creaSocio();
        $quotaAltrui = $this->creaQuotaPagata($altroSocio);

        $account = Account::factory()->verificato()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socioCollegato->id,
        ]);

        $this->actingAs($account, 'account')
            ->get('/a/'.$this->associazione->slug.'/account/quote/'.$quotaAltrui->id.'/ricevuta')
            ->assertNotFound();
    }
}
