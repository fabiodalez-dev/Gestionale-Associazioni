<?php

namespace Tests\Feature\Rinnovi;

use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\Pagamenti\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

/**
 * f.1 — Hook post-pagamento esteso: un socio GIÀ attivo che paga la
 * quota dell'anno ottiene la tessera dell'anno emessa automaticamente
 * (idempotente: nessun duplicato se la tessera esiste già).
 */
class RinnovoHookPagamentoTest extends RinnoviTestCase
{
    use RefreshDatabase;

    private function pagaQuota(Quota $quota, string $tx = 'tx_test_1'): void
    {
        /** @var PaymentService $payments */
        $payments = app(PaymentService::class);
        $payments->onPaymentCompleted($quota->id, $quota->associazione_id, 'stripe', $tx);
    }

    public function test_pagamento_quota_di_socio_attivo_emette_la_tessera_dell_anno(): void
    {
        Notification::fake();
        $this->configuraTenant();
        $socio = $this->creaSocio();
        $quota = Quota::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno' => (int) now()->year,
        ]);

        $this->pagaQuota($quota);

        $quota->refresh();
        $this->assertNotNull($quota->data_pagamento);

        $tessera = Tessera::allTenants()
            ->where('socio_id', $socio->id)
            ->where('anno_validita', (int) now()->year)
            ->first();

        $this->assertNotNull($tessera, 'La tessera di rinnovo deve essere emessa al pagamento.');
        $this->assertSame(Tessera::STATO_ATTIVA, $tessera->stato);
        $this->assertNotEmpty($tessera->qr_code_url);
    }

    public function test_hook_idempotente_non_duplica_la_tessera_esistente(): void
    {
        Notification::fake();
        $this->configuraTenant();
        $socio = $this->creaSocio();
        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno_validita' => (int) now()->year,
        ]);
        $quota = Quota::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno' => (int) now()->year,
        ]);

        $this->pagaQuota($quota);

        $this->assertSame(1, Tessera::allTenants()
            ->where('socio_id', $socio->id)
            ->where('anno_validita', (int) now()->year)
            ->count());
    }

    public function test_webhook_duplicato_non_emette_seconda_tessera(): void
    {
        Notification::fake();
        $this->configuraTenant();
        $socio = $this->creaSocio();
        $quota = Quota::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno' => (int) now()->year,
        ]);

        $this->pagaQuota($quota, 'tx_1');
        $this->pagaQuota($quota, 'tx_1');

        $this->assertSame(1, Tessera::allTenants()->where('socio_id', $socio->id)->count());
    }

    public function test_pagamento_di_socio_non_attivo_non_emette_tessera(): void
    {
        Notification::fake();
        $this->configuraTenant();
        $socio = $this->creaSocio(['stato' => Socio::STATO_SOSPESO]);
        $quota = Quota::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno' => (int) now()->year,
        ]);

        $this->pagaQuota($quota);

        $quota->refresh();
        $this->assertNotNull($quota->data_pagamento);
        $this->assertSame(0, Tessera::allTenants()->where('socio_id', $socio->id)->count());
    }
}
