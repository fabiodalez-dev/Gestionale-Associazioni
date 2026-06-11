<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\SmtpSetting;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina sidebar "Impostazioni Email" (/email/impostazioni).
 * Varianti non coperte da tests/Feature/Email/ImpostazioniSmtpTest.
 */
class EmailImpostazioniPageTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->admin->assignRole('admin_associazione');
    }

    private function creaSmtp(Associazione $associazione, string $host, string $fromEmail): SmtpSetting
    {
        return SmtpSetting::create([
            'associazione_id' => $associazione->id,
            'smtp_host' => $host,
            'smtp_port' => 587,
            'smtp_user' => 'utente',
            'smtp_pass_encrypted' => 'segretissima',
            'smtp_encryption' => 'tls',
            'from_email' => $fromEmail,
            'from_name' => 'Associazione Test',
            'max_per_hour' => 100,
        ]);
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/email/impostazioni')->assertRedirect('/login');
    }

    public function test_admin_vede_la_pagina_con_titolo_e_sezioni_del_form(): void
    {
        $this->actingAs($this->admin)
            ->get('/email/impostazioni')
            ->assertOk()
            ->assertSee('Impostazioni Email')
            ->assertSee('Server SMTP')
            ->assertSee('Mittente');
    }

    public function test_isolamento_tenant_l_host_smtp_di_b_non_compare_nella_pagina_di_a(): void
    {
        $assocB = Associazione::factory()->create();
        $this->creaSmtp($assocB, 'smtp.tenant-b.example', 'noreply-b@example.com');

        $response = $this->actingAs($this->admin)->get('/email/impostazioni');

        $response->assertOk()
            ->assertDontSee('smtp.tenant-b.example')
            ->assertDontSee('noreply-b@example.com');
        $this->assertNull($response->viewData('settings'));
    }

    public function test_il_form_e_precompilato_con_le_impostazioni_salvate(): void
    {
        $this->creaSmtp($this->associazione, 'smtp.precompilato.example', 'mittente@example.com');

        $this->actingAs($this->admin)
            ->get('/email/impostazioni')
            ->assertOk()
            ->assertSee('smtp.precompilato.example')
            ->assertSee('mittente@example.com');
    }

    public function test_operatore_con_solo_email_view_non_puo_salvare_le_impostazioni(): void
    {
        // L'operatore ha email.view ma non email.update: il salvataggio è negato
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $operatore->assignRole('operatore');

        $this->actingAs($operatore)->post('/email/impostazioni', [
            'smtp_host' => 'smtp.hack.example',
            'smtp_port' => 587,
            'smtp_pass' => 'x',
            'smtp_encryption' => 'tls',
            'from_email' => 'hack@example.com',
            'max_per_hour' => 100,
        ])->assertForbidden();

        $this->assertSame(0, SmtpSetting::allTenants()->count());
    }
}
