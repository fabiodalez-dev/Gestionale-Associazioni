<?php

namespace Tests\Feature\Rinnovi;

use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\Pagamenti\PaymentService;
use App\Services\Tessere\TesseraIssuer;
use Illuminate\Database\QueryException;
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

    public function test_errore_generico_di_emissione_non_invalida_il_pagamento(): void
    {
        Notification::fake();
        $this->configuraTenant();
        $socio = $this->creaSocio();
        $quota = Quota::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno' => (int) now()->year,
        ]);
        $this->mock(TesseraIssuer::class)
            ->shouldReceive('emettiSeAssente')
            ->andThrow(new \RuntimeException('template tessera mancante'));

        $this->pagaQuota($quota);

        // Best-effort: l'errore viene loggato, il pagamento resta registrato
        $this->assertNotNull($quota->fresh()->data_pagamento);
    }

    public function test_deadlock_in_emissione_fa_fallire_il_pagamento_per_il_retry_del_webhook(): void
    {
        Notification::fake();
        $this->configuraTenant();
        $socio = $this->creaSocio();
        $quota = Quota::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno' => (int) now()->year,
        ]);
        $this->mock(TesseraIssuer::class)
            ->shouldReceive('emettiSeAssente')
            ->andThrow(new QueryException('mysql', 'select 1', [], new \PDOException(
                'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'
            )));

        // Il deadlock ha già annullato la transazione sul server: non va
        // ingoiato, il webhook risponde errore e viene ritentato. (Qui il
        // deadlock è simulato, quindi il rollback lato server non avviene:
        // si verifica solo che l'errore si propaghi.)
        try {
            $this->pagaQuota($quota);
            $this->fail('Il deadlock deve propagarsi fuori da onPaymentCompleted.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('Deadlock found', $e->getMessage());
        }
    }
}
