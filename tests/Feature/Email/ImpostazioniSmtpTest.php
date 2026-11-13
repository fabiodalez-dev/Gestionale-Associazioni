<?php

namespace Tests\Feature\Email;

use App\Models\Associazione;
use App\Models\EmailTemplate;
use App\Models\SmtpSetting;
use App\Services\Email\TenantMailer;
use App\Services\Email\TenantNotificationMailer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

class ImpostazioniSmtpTest extends EmailTestCase
{
    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('email-impostazioni.index'))->assertRedirect('/login');
    }

    public function test_admin_accede_alla_pagina_impostazioni(): void
    {
        $this->actingAs($this->admin)
            ->get(route('email-impostazioni.index'))
            ->assertOk()
            ->assertViewIs('email.impostazioni')
            ->assertSee('Impostazioni Email');
    }

    public function test_operatore_non_accede_alle_impostazioni(): void
    {
        $this->actingAs($this->creaOperatore())
            ->get(route('email-impostazioni.index'))
            ->assertForbidden();
    }

    public function test_host_smtp_di_rete_locale_o_privata_viene_rifiutato(): void
    {
        foreach (['127.0.0.1', '10.0.0.5', '192.168.1.20', '172.16.3.4', '169.254.169.254', '::1', '[::1]', 'fd00::1', 'localhost', 'mail.localhost', 'smtp.internal', 'posta.lan'] as $host) {
            $this->actingAs($this->admin)->from(route('email-impostazioni.index'))->post(route('email-impostazioni.store'), [
                'smtp_host' => $host,
                'smtp_port' => 25,
                'smtp_pass' => 'x',
                'smtp_encryption' => 'tls',
                'from_email' => 'noreply@example.com',
                'max_per_hour' => 100,
            ])->assertRedirect(route('email-impostazioni.index'))->assertSessionHasErrors('smtp_host');
        }

        $this->assertSame(0, SmtpSetting::allTenants()->count());
    }

    public function test_impostazioni_salvate_con_host_privato_non_aprono_connessioni_al_test(): void
    {
        // Riga salvata prima della regola (o nome DNS cambiato dopo il salvataggio):
        // il controllo vale alla connessione, non solo alla validazione del form
        $settings = $this->configuraSmtp();
        $settings->forceFill(['smtp_host' => '10.0.0.5'])->save(); // nessuna validazione: come una riga pre-esistente

        $this->actingAs($this->admin)->postJson(route('email-impostazioni.test'), ['test_email' => 'a@example.com'])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', fn (string $e) => str_contains($e, 'server di posta pubblico'));

        $this->expectException(\InvalidArgumentException::class);
        TenantMailer::mailerConfig($settings);
    }

    public function test_il_mailer_del_tenant_si_connette_all_ip_validato_con_il_nome_come_peer_tls(): void
    {
        $settings = $this->configuraSmtp();
        $settings->forceFill(['smtp_host' => '93.184.216.34'])->save();

        $config = TenantMailer::mailerConfig($settings);
        $this->assertSame('smtp-pinned', $config['transport']);
        $this->assertSame('93.184.216.34', $config['host']);
        $this->assertSame('93.184.216.34', $config['peer_name']);

        $transport = Mail::build($config)->getSymfonyTransport();
        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        /** @var SocketStream $stream */
        $stream = $transport->getStream();
        $this->assertSame('93.184.216.34', $stream->getStreamOptions()['ssl']['peer_name']);

        // Con un nome (risoluzione saltata nei test) il peer resta il nome
        $settings->forceFill(['smtp_host' => 'smtp.example.com'])->save();
        $config = TenantMailer::mailerConfig($settings);
        $this->assertSame('smtp.example.com', $config['host']);
        $this->assertSame('smtp.example.com', $config['peer_name']);
    }

    public function test_relay_privato_in_allowlist_dell_operatore_resta_ammesso(): void
    {
        config(['mail.smtp_tenant_host_privati' => 'localhost, 127.0.0.1, 10.1.2.3:2500']);

        // Host in allowlist ma porta non di posta: l'host locale non apre ogni porta
        $this->actingAs($this->admin)->from(route('email-impostazioni.index'))->post(route('email-impostazioni.store'), [
            'smtp_host' => '127.0.0.1', 'smtp_port' => 22, 'smtp_pass' => 'x', 'smtp_encryption' => 'none',
            'from_email' => 'noreply@example.com', 'max_per_hour' => 100,
        ])->assertSessionHasErrors('smtp_host');
        // Voce host:porta: vale solo quella porta
        $this->actingAs($this->admin)->from(route('email-impostazioni.index'))->post(route('email-impostazioni.store'), [
            'smtp_host' => '10.1.2.3', 'smtp_port' => 25, 'smtp_pass' => 'x', 'smtp_encryption' => 'none',
            'from_email' => 'noreply@example.com', 'max_per_hour' => 100,
        ])->assertSessionHasErrors('smtp_host');

        $this->actingAs($this->admin)->post(route('email-impostazioni.store'), [
            'smtp_host' => '127.0.0.1', 'smtp_port' => 25, 'smtp_pass' => 'x', 'smtp_encryption' => 'none',
            'from_email' => 'noreply@example.com', 'max_per_hour' => 100,
        ])->assertRedirect(route('email-impostazioni.index'))->assertSessionHasNoErrors();

        $settings = SmtpSetting::allTenants()->where('associazione_id', $this->associazione->id)->firstOrFail();
        $config = TenantMailer::mailerConfig($settings);
        $this->assertSame('127.0.0.1', $config['host']);

        // Un altro host privato, non in allowlist, resta rifiutato
        $settings->forceFill(['smtp_host' => '10.0.0.5'])->save();
        $this->expectException(\InvalidArgumentException::class);
        TenantMailer::mailerConfig($settings);
    }

    public function test_ipv6_viene_passato_al_transport_fra_parentesi_quadre(): void
    {
        $settings = $this->configuraSmtp();
        $settings->forceFill(['smtp_host' => '2606:4700:4700::1111'])->save();

        $config = TenantMailer::mailerConfig($settings);
        $transport = Mail::build($config)->getSymfonyTransport();
        $this->assertStringContainsString('[2606:4700:4700::1111]', (string) $transport);
    }

    public function test_notifica_con_smtp_del_tenant_non_ammesso_ripiega_sul_mailer_di_default(): void
    {
        $settings = $this->configuraSmtp();
        $settings->forceFill(['smtp_host' => '10.0.0.5'])->save();

        $message = TenantNotificationMailer::applica(new MailMessage, $this->associazione->fresh());
        $this->assertNull($message->mailer, 'con host non ammesso la notifica usa il mailer di default');
    }

    public function test_primo_salvataggio_crea_le_impostazioni_e_seedda_i_template(): void
    {
        $response = $this->actingAs($this->admin)->post(route('email-impostazioni.store'), [
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_user' => 'utente',
            'smtp_pass' => 'password-segreta',
            'smtp_encryption' => 'tls',
            'from_email' => 'noreply@example.com',
            'from_name' => 'Test',
            'max_per_hour' => 100,
            'auto_benvenuto' => 1,
        ]);

        $response->assertRedirect(route('email-impostazioni.index'));
        $response->assertSessionHas('success');

        $settings = SmtpSetting::allTenants()->where('associazione_id', $this->associazione->id)->first();
        $this->assertNotNull($settings);
        $this->assertSame('smtp.example.com', $settings->smtp_host);
        // Cifratura applicativa: il cast 'encrypted' decifra il valore originale
        $this->assertSame('password-segreta', $settings->smtp_pass_encrypted);
        $this->assertTrue($settings->auto_benvenuto);
        $this->assertFalse($settings->auto_scadenza_tessera);

        // Seed automatico degli 8 template di default al primo salvataggio
        $this->assertSame(8, EmailTemplate::allTenants()->where('associazione_id', $this->associazione->id)->count());
    }

    public function test_password_obbligatoria_al_primo_salvataggio(): void
    {
        $response = $this->actingAs($this->admin)->post(route('email-impostazioni.store'), [
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'from_email' => 'noreply@example.com',
            'max_per_hour' => 100,
        ]);

        $response->assertSessionHasErrors('smtp_pass');
    }

    public function test_validazione_email_mittente_non_valida(): void
    {
        $response = $this->actingAs($this->admin)->post(route('email-impostazioni.store'), [
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_pass' => 'x',
            'smtp_encryption' => 'tls',
            'from_email' => 'non-una-email',
            'max_per_hour' => 100,
        ]);

        $response->assertSessionHasErrors('from_email');
    }

    public function test_aggiornamento_con_password_vuota_mantiene_la_precedente(): void
    {
        $this->configuraSmtp();

        $this->actingAs($this->admin)->post(route('email-impostazioni.store'), [
            'smtp_host' => 'smtp.nuovo.com',
            'smtp_port' => 465,
            'smtp_pass' => '',
            'smtp_encryption' => 'ssl',
            'from_email' => 'altro@example.com',
            'max_per_hour' => 50,
        ])->assertSessionHasNoErrors();

        $settings = SmtpSetting::allTenants()->where('associazione_id', $this->associazione->id)->first();
        $this->assertSame('smtp.nuovo.com', $settings->smtp_host);
        $this->assertSame('segretissima', $settings->smtp_pass_encrypted);
    }

    public function test_isolamento_tenant_le_impostazioni_di_b_non_sono_visibili_ad_a(): void
    {
        $assocB = Associazione::factory()->create();
        $this->configuraSmtp($assocB);

        $response = $this->actingAs($this->admin)->get(route('email-impostazioni.index'));

        $response->assertOk();
        $this->assertNull($response->viewData('settings'));
    }

    public function test_email_di_test_riuscita_aggiorna_is_verified(): void
    {
        $this->configuraSmtp();

        $this->mock(TenantMailer::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andReturn(['success' => true, 'error' => null]);
        });

        $response = $this->actingAs($this->admin)->postJson(route('email-impostazioni.test'), [
            'test_email' => 'destinatario@example.com',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $settings = SmtpSetting::allTenants()->where('associazione_id', $this->associazione->id)->first();
        $this->assertTrue($settings->is_verified);
        $this->assertNotNull($settings->last_test_at);
    }

    public function test_email_di_test_fallita_riporta_l_errore_smtp(): void
    {
        $this->configuraSmtp();

        $this->mock(TenantMailer::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andReturn(['success' => false, 'error' => 'Connection refused']);
        });

        $response = $this->actingAs($this->admin)->postJson(route('email-impostazioni.test'), [
            'test_email' => 'destinatario@example.com',
        ]);

        $response->assertOk()->assertJson(['success' => false, 'error' => 'Connection refused']);
    }
}
