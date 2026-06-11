<?php

namespace Tests\Feature\Email;

use App\Models\EmailQueue;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\Email\DefaultEmailTemplates;

class EmailRemindersTest extends EmailTestCase
{
    private function preparaTenant(): Socio
    {
        $this->configuraSmtp();
        DefaultEmailTemplates::seedFor($this->associazione->id);

        return Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato' => 'Attivo',
            'email' => 'socio@example.com',
        ]);
    }

    public function test_accoda_promemoria_per_tessera_in_scadenza_con_link_rinnovo(): void
    {
        $socio = $this->preparaTenant();

        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'stato' => 'Attiva',
            'data_scadenza' => today()->addDays(10)->toDateString(),
        ]);

        $this->artisan('emails:send-reminders')->assertSuccessful();

        $item = EmailQueue::allTenants()
            ->where('associazione_id', $this->associazione->id)
            ->where('template_codice', 'scadenza_tessera')
            ->first();

        $this->assertNotNull($item);
        $this->assertSame('socio@example.com', $item->to_email);
        // Data formattata d/m/Y nel corpo (uniformata rispetto al legacy)
        $this->assertStringContainsString(today()->addDays(10)->format('d/m/Y'), $item->body_html);

        // Token di rinnovo generato con scadenza a 30 giorni
        $socio->refresh();
        $this->assertNotNull($socio->rinnovo_token);
        $this->assertNotNull($socio->rinnovo_token_expires);
    }

    public function test_non_accoda_duplicati_se_gia_in_coda_pending(): void
    {
        $socio = $this->preparaTenant();

        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'stato' => 'Attiva',
            'data_scadenza' => today()->addDays(10)->toDateString(),
        ]);

        $this->artisan('emails:send-reminders')->assertSuccessful();
        $this->artisan('emails:send-reminders')->assertSuccessful();

        $this->assertSame(
            1,
            EmailQueue::allTenants()
                ->where('associazione_id', $this->associazione->id)
                ->where('template_codice', 'scadenza_tessera')
                ->count()
        );
    }

    public function test_accoda_promemoria_per_quota_non_pagata(): void
    {
        $socio = $this->preparaTenant();

        Quota::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'data_pagamento' => null,
            'data_scadenza' => today()->addDays(5)->toDateString(),
            'importo' => 25.50,
        ]);

        $this->artisan('emails:send-reminders')->assertSuccessful();

        $item = EmailQueue::allTenants()
            ->where('associazione_id', $this->associazione->id)
            ->where('template_codice', 'scadenza_quota')
            ->first();

        $this->assertNotNull($item);
        $this->assertStringContainsString('25,50', $item->body_html);
    }

    public function test_socio_opt_out_non_riceve_promemoria(): void
    {
        $socio = $this->preparaTenant();
        $socio->forceFill(['email_opt_out' => true])->save();

        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'stato' => 'Attiva',
            'data_scadenza' => today()->addDays(10)->toDateString(),
        ]);

        $this->artisan('emails:send-reminders')->assertSuccessful();

        $this->assertSame(0, EmailQueue::allTenants()->count());
    }

    public function test_toggle_disattivato_non_accoda_nulla(): void
    {
        $socio = $this->preparaTenant();
        $this->associazione->smtpSetting->update(['auto_scadenza_tessera' => false]);

        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'stato' => 'Attiva',
            'data_scadenza' => today()->addDays(10)->toDateString(),
        ]);

        $this->artisan('emails:send-reminders')->assertSuccessful();

        $this->assertSame(0, EmailQueue::allTenants()->where('template_codice', 'scadenza_tessera')->count());
    }
}
