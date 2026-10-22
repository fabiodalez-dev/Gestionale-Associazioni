<?php

namespace Tests\Feature\quote_pagamenti;

use App\Models\Associazione;
use App\Models\Quota;
use App\Models\SmtpSetting;
use App\Models\Socio;
use App\Notifications\Quote\PagamentoQuotaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Opzione "Conferma pagamento quota" (Email → Impostazioni): come nella vecchia
 * app, se l'associazione la spegne il socio non riceve l'email di conferma.
 */
class EmailPagamentoQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function canali(?bool $opzione): array
    {
        $associazione = Associazione::factory()->create();
        if ($opzione !== null) {
            SmtpSetting::query()->withoutGlobalScopes()->create(['associazione_id' => $associazione->id, 'auto_pagamento_quota' => $opzione, 'smtp_pass_encrypted' => '']);
        }
        $socio = Socio::factory()->create(['associazione_id' => $associazione->id]);
        $quota = Quota::create(['associazione_id' => $associazione->id, 'socio_id' => $socio->id, 'anno' => 2026, 'importo' => 25, 'data_scadenza' => '2026-12-31', 'stato' => 'Pagata', 'tipo' => 'Quota Associativa']);

        return (new PagamentoQuotaNotification($quota))->via($socio);
    }

    public function test_opzione_spenta_niente_email(): void
    {
        $this->assertSame(['database'], $this->canali(false));
    }

    public function test_opzione_accesa_email_inviata(): void
    {
        $this->assertSame(['database', 'mail'], $this->canali(true));
    }

    public function test_senza_impostazioni_email_vale_il_predefinito(): void
    {
        $this->assertSame(['database', 'mail'], $this->canali(null));
    }
}
