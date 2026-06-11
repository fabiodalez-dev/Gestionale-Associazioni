<?php

namespace Tests\Feature\Email;

use App\Models\Associazione;
use App\Models\EmailLog;
use App\Models\EmailQueue;
use App\Services\Email\TenantMailer;

class EmailCodaTest extends EmailTestCase
{
    private function accoda(array $attributes = []): EmailQueue
    {
        return EmailQueue::create(array_merge([
            'associazione_id' => $this->associazione->id,
            'batch_id' => (string) \Illuminate\Support\Str::uuid(),
            'to_email' => 'dest@example.com',
            'to_name' => 'Destinatario',
            'subject' => 'Oggetto in coda',
            'body_html' => '<p>Corpo</p>',
            'template_codice' => 'comunicazione',
            'status' => EmailQueue::STATUS_PENDING,
        ], $attributes));
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('email-coda.index'))->assertRedirect('/login');
    }

    public function test_la_pagina_coda_mostra_statistiche_e_righe(): void
    {
        $this->accoda();
        $this->accoda(['status' => EmailQueue::STATUS_FAILED, 'attempts' => 3, 'error_message' => 'SMTP KO']);

        $response = $this->actingAs($this->admin)->get(route('email-coda.index'));

        $response->assertOk()->assertViewIs('email.coda');
        $this->assertSame(1, $response->viewData('stats')['pending']);
        $this->assertSame(1, $response->viewData('stats')['failed']);
        $response->assertSee('dest@example.com');
    }

    public function test_elaborazione_invia_le_pending_e_logga_gli_esiti(): void
    {
        $this->configuraSmtp();
        $item = $this->accoda();

        $this->mock(TenantMailer::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andReturn(['success' => true, 'error' => null]);
        });

        $response = $this->actingAs($this->admin)->postJson(route('email-coda.process'));

        $response->assertOk()->assertJson(['success' => true, 'sent' => 1, 'failed' => 0, 'remaining' => 0]);

        $item->refresh();
        $this->assertSame(EmailQueue::STATUS_SENT, $item->status);
        $this->assertNotNull($item->sent_at);

        $log = EmailLog::allTenants()->where('associazione_id', $this->associazione->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertSame($item->id, $log->queue_id);
        $this->assertSame('<p>Corpo</p>', $log->body_html);
        $this->assertSame($this->admin->id, $log->sent_by);
    }

    public function test_fallimento_non_definitivo_resta_pending_con_backoff_e_senza_log(): void
    {
        $this->configuraSmtp();
        $item = $this->accoda();

        $this->mock(TenantMailer::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andReturn(['success' => false, 'error' => 'Timeout']);
        });

        $this->actingAs($this->admin)->postJson(route('email-coda.process'))->assertOk();

        $item->refresh();
        $this->assertSame(EmailQueue::STATUS_PENDING, $item->status);
        $this->assertSame(1, $item->attempts);
        $this->assertSame('Timeout', $item->error_message);
        $this->assertNotNull($item->next_attempt_at);
        $this->assertTrue($item->next_attempt_at->isFuture());
        // In email_log finiscono solo gli esiti definitivi
        $this->assertSame(0, EmailLog::allTenants()->count());
    }

    public function test_fallimento_definitivo_marca_failed_e_logga(): void
    {
        $this->configuraSmtp();
        $item = $this->accoda(['attempts' => 2, 'max_attempts' => 3]);

        $this->mock(TenantMailer::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andReturn(['success' => false, 'error' => 'Mailbox unavailable']);
        });

        $this->actingAs($this->admin)->postJson(route('email-coda.process'))->assertOk();

        $item->refresh();
        $this->assertSame(EmailQueue::STATUS_FAILED, $item->status);
        $this->assertSame(3, $item->attempts);

        $log = EmailLog::allTenants()->first();
        $this->assertSame(EmailLog::STATUS_FAILED, $log->status);
        $this->assertSame('Mailbox unavailable', $log->error_message);
    }

    public function test_annulla_una_email_pending(): void
    {
        $item = $this->accoda();

        $this->actingAs($this->admin)
            ->post(route('email-coda.cancel', $item))
            ->assertRedirect(route('email-coda.index'));

        $this->assertSame(EmailQueue::STATUS_CANCELLED, $item->fresh()->status);
    }

    public function test_non_si_puo_annullare_una_email_gia_inviata(): void
    {
        $item = $this->accoda(['status' => EmailQueue::STATUS_SENT, 'sent_at' => now()]);

        $response = $this->actingAs($this->admin)->post(route('email-coda.cancel', $item));

        $response->assertSessionHas('warning');
        $this->assertSame(EmailQueue::STATUS_SENT, $item->fresh()->status);
    }

    public function test_riprova_resetta_i_tentativi_di_una_failed(): void
    {
        $item = $this->accoda([
            'status' => EmailQueue::STATUS_FAILED,
            'attempts' => 3,
            'error_message' => 'KO',
            'next_attempt_at' => now()->addHour(),
        ]);

        $this->actingAs($this->admin)->post(route('email-coda.retry', $item));

        $item->refresh();
        $this->assertSame(EmailQueue::STATUS_PENDING, $item->status);
        $this->assertSame(0, $item->attempts);
        $this->assertNull($item->error_message);
        $this->assertNull($item->next_attempt_at);
    }

    public function test_annulla_pendenti_in_blocco(): void
    {
        $this->accoda();
        $this->accoda(['to_email' => 'altro@example.com']);
        $sent = $this->accoda(['status' => EmailQueue::STATUS_SENT]);

        $this->actingAs($this->admin)->post(route('email-coda.cancel-pending'));

        $this->assertSame(2, EmailQueue::allTenants()->where('status', EmailQueue::STATUS_CANCELLED)->count());
        $this->assertSame(EmailQueue::STATUS_SENT, $sent->fresh()->status);
    }

    public function test_isolamento_tenant_non_si_annullano_email_di_altri_tenant(): void
    {
        $assocB = Associazione::factory()->create();
        $itemB = $this->accoda(['associazione_id' => $assocB->id]);

        $this->actingAs($this->admin)
            ->post(route('email-coda.cancel', $itemB->id))
            ->assertNotFound();

        $this->assertSame(EmailQueue::STATUS_PENDING, $itemB->fresh()->status);
    }

    public function test_operatore_puo_consultare_ma_non_elaborare(): void
    {
        $operatore = $this->creaOperatore();

        $this->actingAs($operatore)->get(route('email-coda.index'))->assertOk();
        $this->actingAs($operatore)->postJson(route('email-coda.process'))->assertForbidden();
    }
}
