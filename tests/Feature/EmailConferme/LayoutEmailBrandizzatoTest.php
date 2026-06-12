<?php

namespace Tests\Feature\EmailConferme;

use App\Models\Associazione;
use App\Models\Socio;
use App\Notifications\Quote\PagamentoQuotaNotification;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * f.18 — Layout email brandizzato per tenant (vista condivisa emails.layout):
 * applicato da TenantNotificationMailer a tutte le notifiche, con logo/nome/
 * colore del tenant e fallback pulito senza logo / senza associazione.
 */
class LayoutEmailBrandizzatoTest extends EmailConfermeTestCase
{
    private function messaggio(): MailMessage
    {
        return (new MailMessage)
            ->subject('Oggetto di prova')
            ->greeting('Ciao Mario,')
            ->line('Questa è una riga di prova.')
            ->action('Apri il gestionale', 'https://example.com/azione')
            ->salutation('A presto!');
    }

    public function test_brandizza_applica_la_vista_condivisa_emails_layout(): void
    {
        $message = TenantNotificationMailer::brandizza($this->messaggio(), $this->associazione);

        $this->assertIsArray($message->view);
        $this->assertSame('emails.layout', $message->view['html']);
        $this->assertSame('emails.layout-text', $message->view['text']);
        $this->assertNull($message->markdown);
    }

    public function test_render_contiene_nome_associazione_colore_e_contenuti(): void
    {
        $message = TenantNotificationMailer::brandizza($this->messaggio(), $this->associazione);

        $html = $message->render();

        $this->assertStringContainsString(e($this->associazione->nome), $html);
        $this->assertStringContainsString(TenantNotificationMailer::COLORE_DEFAULT, $html);
        $this->assertStringContainsString('Ciao Mario,', $html);
        $this->assertStringContainsString('Questa è una riga di prova.', $html);
        $this->assertStringContainsString('Apri il gestionale', $html);
        $this->assertStringContainsString('https://example.com/azione', $html);
        $this->assertStringContainsString('A presto!', $html);
        $this->assertStringContainsString('&copy; '.now()->format('Y'), $html);
    }

    public function test_logo_presente_quando_configurato_e_fallback_pulito_senza(): void
    {
        // Fallback pulito: nessun tag <img> senza logo
        $senzaLogo = TenantNotificationMailer::brandizza($this->messaggio(), $this->associazione)
            ->render();
        $this->assertStringNotContainsString('<img', $senzaLogo);

        $this->associazione->update(['logo_url' => 'https://example.com/logo.png']);

        $conLogo = TenantNotificationMailer::brandizza($this->messaggio(), $this->associazione->fresh())
            ->render();
        $this->assertStringContainsString('https://example.com/logo.png', $conLogo);
    }

    public function test_senza_associazione_resta_il_markdown_di_default(): void
    {
        $message = TenantNotificationMailer::applica($this->messaggio(), null);

        $this->assertEmpty($message->view);
        $this->assertSame('notifications::email', $message->markdown);
    }

    public function test_link_disiscrizione_presente_solo_per_socio_persistito(): void
    {
        $socio = $this->creaSocio();

        $conSocio = TenantNotificationMailer::brandizza($this->messaggio(), $this->associazione, $socio)
            ->render();
        $this->assertStringContainsString('Disiscriviti dalle email', $conSocio);
        $this->assertNotEmpty($socio->fresh()->email_opt_out_token);

        // Socio in memoria (anteprime): nessun link e nessuna scrittura su DB
        $inMemoria = (new Socio)->forceFill(['nome' => 'Demo']);
        $senzaSocio = TenantNotificationMailer::brandizza($this->messaggio(), $this->associazione, $inMemoria)
            ->render();
        $this->assertStringNotContainsString('Disiscriviti dalle email', $senzaSocio);
    }

    public function test_non_sovrascrive_una_vista_gia_impostata(): void
    {
        $message = $this->messaggio()->view('emails.layout-text', ['brandNome' => 'X']);

        $branded = TenantNotificationMailer::brandizza($message, $this->associazione);

        $this->assertSame('emails.layout-text', $branded->view);
    }

    public function test_notifica_pagamento_quota_usa_il_layout_brandizzato(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuotaPagata($socio);

        $mail = (new PagamentoQuotaNotification($quota))->toMail($socio);

        $this->assertIsArray($mail->view);
        $this->assertSame('emails.layout', $mail->view['html']);

        $html = $mail->render();
        $this->assertStringContainsString(e($this->associazione->nome), $html);
        $this->assertStringContainsString('Conferma pagamento', (string) $mail->subject);
    }

    public function test_isolamento_tenant_il_layout_usa_il_brand_della_propria_associazione(): void
    {
        $altra = Associazione::factory()->create(['nome' => 'Altra Associazione XYZ']);

        $html = TenantNotificationMailer::brandizza($this->messaggio(), $this->associazione)
            ->render();

        $this->assertStringContainsString(e($this->associazione->nome), $html);
        $this->assertStringNotContainsString('Altra Associazione XYZ', $html);

        $htmlAltra = TenantNotificationMailer::brandizza($this->messaggio(), $altra)
            ->render();
        $this->assertStringContainsString('Altra Associazione XYZ', $htmlAltra);
    }
}
