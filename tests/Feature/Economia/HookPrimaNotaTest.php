<?php

namespace Tests\Feature\Economia;

use App\Models\Evento;
use App\Models\Movimento;
use App\Models\Prenotazione;
use App\Services\Economia\PrimaNotaService;
use App\Services\Pagamenti\PaymentService;
use App\Services\Prenotazioni\PrenotazioneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * f.13 — Hook automatici della prima nota: quota pagata e prenotazione
 * incassata generano il movimento di entrata, in modo idempotente
 * (replay del webhook → nessun duplicato) e senza rompere i flussi
 * di pagamento esistenti.
 */
class HookPrimaNotaTest extends TestCase
{
    use ConfiguraTenantEconomia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
        Notification::fake();
    }

    public function test_quota_pagata_online_genera_il_movimento_automatico(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, ['importo' => 50, 'anno' => 2026]);

        $ok = app(PaymentService::class)
            ->onPaymentCompleted($quota->id, $this->associazione->id, 'stripe', 'pi_test_1');

        $this->assertTrue($ok);

        $movimento = Movimento::allTenants()
            ->where('origine_type', $quota->getMorphClass())
            ->where('origine_id', $quota->id)
            ->first();

        $this->assertNotNull($movimento);
        $this->assertSame(Movimento::TIPO_ENTRATA, $movimento->tipo);
        $this->assertSame($this->associazione->id, $movimento->associazione_id);
        $this->assertEqualsWithDelta(50.0, (float) $movimento->importo, 0.001);
        $this->assertSame(PrimaNotaService::CATEGORIA_QUOTE, $movimento->categoria?->nome);
        $this->assertStringContainsString('Quota 2026', (string) $movimento->descrizione);
    }

    public function test_replay_del_webhook_quota_non_duplica_il_movimento(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);

        $service = app(PaymentService::class);
        $service->onPaymentCompleted($quota->id, $this->associazione->id, 'stripe', 'pi_test_1');
        $service->onPaymentCompleted($quota->id, $this->associazione->id, 'stripe', 'pi_test_1');

        $this->assertSame(1, Movimento::allTenants()
            ->where('origine_type', $quota->getMorphClass())
            ->where('origine_id', $quota->id)
            ->count());
    }

    public function test_pagamento_offline_della_quota_genera_il_movimento(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, ['importo' => 25]);

        app(PaymentService::class)
            ->registerOfflinePayment($quota, 'contanti', now()->toDateString(), $this->admin);

        $movimento = Movimento::allTenants()
            ->where('origine_type', $quota->getMorphClass())
            ->where('origine_id', $quota->id)
            ->first();

        $this->assertNotNull($movimento);
        $this->assertSame('contanti', $movimento->metodo);
        $this->assertEqualsWithDelta(25.0, (float) $movimento->importo, 0.001);
    }

    public function test_prenotazione_pagata_genera_il_movimento_automatico(): void
    {
        $evento = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'prezzo' => 15,
        ]);

        $prenotazione = Prenotazione::factory()->inAttesaPagamento(15)->create([
            'evento_id' => $evento->id,
            'associazione_id' => $this->associazione->id,
        ]);

        app(PrenotazioneService::class)
            ->onPagamentoCompletato($prenotazione->id, $this->associazione->id, 'paypal', 'capture_1');

        $movimento = Movimento::allTenants()
            ->where('origine_type', $prenotazione->getMorphClass())
            ->where('origine_id', $prenotazione->id)
            ->first();

        $this->assertNotNull($movimento);
        $this->assertSame(Movimento::TIPO_ENTRATA, $movimento->tipo);
        $this->assertEqualsWithDelta(15.0, (float) $movimento->importo, 0.001);
        $this->assertSame(PrimaNotaService::CATEGORIA_EVENTI, $movimento->categoria?->nome);
        $this->assertSame('paypal', $movimento->metodo);
    }

    public function test_replay_del_webhook_prenotazione_non_duplica_il_movimento(): void
    {
        $evento = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'prezzo' => 15,
        ]);

        $prenotazione = Prenotazione::factory()->inAttesaPagamento(15)->create([
            'evento_id' => $evento->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $service = app(PrenotazioneService::class);
        $service->onPagamentoCompletato($prenotazione->id, $this->associazione->id, 'stripe', 'pi_1');
        $service->onPagamentoCompletato($prenotazione->id, $this->associazione->id, 'stripe', 'pi_1');

        $this->assertSame(1, Movimento::allTenants()
            ->where('origine_type', $prenotazione->getMorphClass())
            ->where('origine_id', $prenotazione->id)
            ->count());
    }

    public function test_prenotazione_gratuita_non_genera_movimenti(): void
    {
        $evento = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'prezzo' => 0,
        ]);

        $prenotazione = Prenotazione::factory()->create([
            'evento_id' => $evento->id,
            'associazione_id' => $this->associazione->id,
            'importo_totale' => 0,
        ]);

        app(PrimaNotaService::class)->registraIncassoPrenotazioneBestEffort($prenotazione);

        $this->assertSame(0, Movimento::allTenants()->count());
    }

    public function test_quota_non_pagata_non_genera_movimenti(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, ['data_pagamento' => null]);

        app(PrimaNotaService::class)->registraIncassoQuotaBestEffort($quota->id);

        $this->assertSame(0, Movimento::allTenants()->count());
    }
}
