<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\EmailTemplate;
use App\Models\Utente;
use App\Services\Email\DefaultEmailTemplates;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina sidebar "Template Email" (/email/templates).
 * Varianti non coperte da tests/Feature/Email/EmailTemplatesTest.
 */
class EmailTemplatesPageTest extends TestCase
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
        $this->get('/email/templates')->assertRedirect('/login');
    }

    public function test_admin_vede_la_pagina_con_titolo(): void
    {
        $this->actingAs($this->admin)
            ->get('/email/templates')
            ->assertOk()
            ->assertSee('Template Email');
    }

    public function test_isolamento_tenant_l_oggetto_personalizzato_di_b_non_compare_nella_pagina_di_a(): void
    {
        DefaultEmailTemplates::seedFor($this->associazione->id);

        $assocB = Associazione::factory()->create();
        DefaultEmailTemplates::seedFor($assocB->id);

        EmailTemplate::allTenants()
            ->where('associazione_id', $assocB->id)
            ->where('codice', 'benvenuto')
            ->update(['oggetto' => 'OGGETTO-SEGRETO-TENANT-B']);

        $response = $this->actingAs($this->admin)->get('/email/templates');

        $response->assertOk()->assertDontSee('OGGETTO-SEGRETO-TENANT-B');
        // Nella pagina di A compaiono solo gli 8 template del proprio tenant
        $this->assertCount(8, $response->viewData('templates'));
    }

    public function test_il_parametro_t_seleziona_il_template_corrente_con_fallback_sul_primo(): void
    {
        DefaultEmailTemplates::seedFor($this->associazione->id);

        $response = $this->actingAs($this->admin)
            ->get(route('email-templates.index', ['t' => 'rinnovo_tessera']));

        $response->assertOk();
        $this->assertSame('rinnovo_tessera', $response->viewData('current')->codice);

        // Codice inesistente: fallback sul primo template dell'ordinamento
        $response = $this->actingAs($this->admin)
            ->get(route('email-templates.index', ['t' => 'codice_inesistente']));

        $response->assertOk();
        $this->assertSame('benvenuto', $response->viewData('current')->codice);
    }

    public function test_operatore_senza_permesso_email_view_riceve_403(): void
    {
        // Operatore creato SENZA assegnare il ruolo spatie: nessun permesso email.view
        $senzaPermessi = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($senzaPermessi)->get('/email/templates')->assertForbidden();
    }
}
