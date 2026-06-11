<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina sidebar "Documentazione API" (/api-docs).
 *
 * Varianti rispetto a api_v1\ApiKeysUiTest (che copre admin→200 con viewIs
 * e /docs/api per ospite/admin): redirect ospite su /api-docs, titolo reale
 * della pagina, accesso super_admin senza tenant, contenuti attesi della
 * guida (autenticazione, errori, base URL), accesso dell'operatore
 * (la rotta non ha gate oltre ad auth: pagina informativa).
 */
class ApiDocsPageTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/api-docs')->assertRedirect('/login');
    }

    public function test_admin_vede_la_pagina_con_il_titolo_completo(): void
    {
        $this->actingAs($this->admin)
            ->get('/api-docs')
            ->assertOk()
            ->assertSee('Documentazione API v1');
    }

    public function test_super_admin_senza_tenant_selezionato_accede_alla_documentazione(): void
    {
        // La rotta non ha middleware 'tenant': la guida è globale e statica
        $superAdmin = Utente::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get('/api-docs')
            ->assertOk()
            ->assertSee('Documentazione API v1');
    }

    public function test_la_guida_contiene_base_url_autenticazione_permessi_ed_errori(): void
    {
        $this->actingAs($this->admin)
            ->get('/api-docs')
            ->assertOk()
            ->assertSee(rtrim(url('/api/v1'), '/'))
            ->assertSee('Autenticazione')
            ->assertSee('X-API-Key')
            ->assertSee('Formato errori')
            ->assertSee('method_not_allowed')
            ->assertSee('rate_limited');
    }

    public function test_anche_l_operatore_autenticato_vede_la_documentazione(): void
    {
        // Nessun gate dedicato sulla guida statica: basta essere autenticati
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)
            ->get('/api-docs')
            ->assertOk()
            ->assertSee('Documentazione API v1');
    }
}
