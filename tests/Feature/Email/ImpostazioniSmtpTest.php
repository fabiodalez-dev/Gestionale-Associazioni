<?php

namespace Tests\Feature\Email;

use App\Models\Associazione;
use App\Models\EmailTemplate;
use App\Models\SmtpSetting;
use App\Services\Email\TenantMailer;

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
