<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\SmtpSetting;
use App\Models\Socio;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina sidebar "Invia Comunicazione" (/comunicazioni).
 * Varianti non coperte da tests/Feature/Email/ComunicazioniTest.
 */
class ComunicazioniPageTest extends TestCase
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

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/comunicazioni')->assertRedirect('/login');
    }

    public function test_admin_vede_la_pagina_con_titolo_e_invii_recenti(): void
    {
        $this->actingAs($this->admin)
            ->get('/comunicazioni')
            ->assertOk()
            ->assertSee('Invia Comunicazione')
            ->assertSee('Invii recenti');
    }

    public function test_isolamento_tenant_il_conteggio_destinatari_esclude_i_soci_di_b(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato' => 'Attivo',
            'email' => 'a1@example.com',
        ]);
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato' => 'Attivo',
            'email' => 'a2@example.com',
        ]);

        $assocB = Associazione::factory()->create();
        Socio::factory()->count(3)->create([
            'associazione_id' => $assocB->id,
            'stato' => 'Attivo',
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('comunicazioni.count', ['recipient_type' => 'all']))
            ->assertOk()
            ->assertJson(['success' => true, 'count' => 2]);
    }

    public function test_la_pagina_segnala_quando_smtp_non_e_configurato(): void
    {
        $response = $this->actingAs($this->admin)->get('/comunicazioni');

        $response->assertOk();
        $this->assertFalse($response->viewData('isConfigured'));

        SmtpSetting::create([
            'associazione_id' => $this->associazione->id,
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_user' => 'utente',
            'smtp_pass_encrypted' => 'segretissima',
            'smtp_encryption' => 'tls',
            'from_email' => 'noreply@example.com',
            'from_name' => 'Associazione Test',
            'max_per_hour' => 100,
        ]);

        $response = $this->actingAs($this->admin)->get('/comunicazioni');

        $response->assertOk();
        $this->assertTrue($response->viewData('isConfigured'));
    }

    public function test_operatore_senza_permesso_email_view_riceve_403(): void
    {
        // Operatore creato SENZA assegnare il ruolo spatie: nessun permesso email.view
        $senzaPermessi = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($senzaPermessi)->get('/comunicazioni')->assertForbidden();
    }
}
