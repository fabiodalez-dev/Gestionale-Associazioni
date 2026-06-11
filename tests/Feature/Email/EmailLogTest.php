<?php

namespace Tests\Feature\Email;

use App\Models\Associazione;
use App\Models\EmailLog;
use Illuminate\Support\Str;

class EmailLogTest extends EmailTestCase
{
    private function logga(array $attributes = []): EmailLog
    {
        return EmailLog::create(array_merge([
            'associazione_id' => $this->associazione->id,
            'batch_id' => (string) Str::uuid(),
            'to_email' => 'dest@example.com',
            'to_name' => 'Destinatario',
            'subject' => 'Oggetto loggato',
            'template_codice' => 'comunicazione',
            'body_html' => '<p>Corpo salvato nel log</p>',
            'status' => EmailLog::STATUS_SENT,
            'sent_at' => now(),
        ], $attributes));
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('email-log.index'))->assertRedirect('/login');
    }

    public function test_lo_storico_elenca_le_email_del_tenant(): void
    {
        $this->logga();
        $this->logga(['to_email' => 'fallita@example.com', 'status' => EmailLog::STATUS_FAILED, 'error_message' => 'KO']);

        $response = $this->actingAs($this->admin)->get(route('email-log.index'));

        $response->assertOk()
            ->assertViewIs('email.log')
            ->assertSee('dest@example.com')
            ->assertSee('fallita@example.com');
    }

    public function test_filtro_per_stato_e_ricerca_testo(): void
    {
        $this->logga(['to_email' => 'ok@example.com', 'subject' => 'Newsletter primavera']);
        $this->logga(['to_email' => 'ko@example.com', 'status' => EmailLog::STATUS_FAILED, 'subject' => 'Avviso']);

        $this->actingAs($this->admin)
            ->get(route('email-log.index', ['status' => 'failed']))
            ->assertSee('ko@example.com')
            ->assertDontSee('ok@example.com');

        $this->actingAs($this->admin)
            ->get(route('email-log.index', ['q' => 'primavera']))
            ->assertSee('ok@example.com')
            ->assertDontSee('ko@example.com');
    }

    public function test_uno_stato_fuori_whitelist_viene_ignorato(): void
    {
        $this->logga();

        $response = $this->actingAs($this->admin)->get(route('email-log.index', ['status' => 'sending']));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('logs')->total());
    }

    public function test_il_dettaglio_restituisce_metadati_e_body(): void
    {
        $log = $this->logga();

        $this->actingAs($this->admin)
            ->getJson(route('email-log.show', $log))
            ->assertOk()
            ->assertJsonPath('log.to_email', 'dest@example.com')
            ->assertJsonPath('log.sent_by', 'Sistema')
            ->assertJsonPath('log.body_html', '<p>Corpo salvato nel log</p>');
    }

    public function test_isolamento_tenant_il_log_di_b_non_e_accessibile_ad_a(): void
    {
        $assocB = Associazione::factory()->create();
        $logB = $this->logga(['associazione_id' => $assocB->id, 'to_email' => 'tenant-b@example.com']);

        $this->actingAs($this->admin)
            ->get(route('email-log.index'))
            ->assertDontSee('tenant-b@example.com');

        $this->actingAs($this->admin)
            ->getJson(route('email-log.show', $logB->id))
            ->assertNotFound();
    }
}
