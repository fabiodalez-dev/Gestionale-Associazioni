<?php

namespace Tests\Feature\SidebarPages;

use App\Models\ApiKey;
use App\Models\Associazione;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina sidebar "Chiavi API" (/api-keys).
 *
 * Varianti rispetto a api_v1\ApiKeysUiTest (che copre ospite in GET,
 * visibilità per admin/super_admin senza tenant, store/toggle/delete):
 * ospite su rotta mutante, titolo reale, vista super_admin CON tenant
 * selezionato, chiave creata visibile in lista (nome + prefisso), accesso
 * dell'operatore limitato al proprio tenant.
 */
class ApiKeysPageTest extends TestCase
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

    private function creaChiave(?string $associazioneId, string $nome = 'Chiave'): ApiKey
    {
        return ApiKey::create([
            'associazione_id' => $associazioneId,
            'api_key' => bin2hex(random_bytes(32)),
            'nome' => $nome,
            'permessi' => ['tessere:read'],
            'attiva' => true,
        ]);
    }

    public function test_ospite_non_puo_creare_chiavi_e_viene_rediretto_al_login(): void
    {
        $this->post('/api-keys', ['nome' => 'Chiave Fantasma'])
            ->assertRedirect('/login');

        $this->assertDatabaseMissing('api_keys', ['nome' => 'Chiave Fantasma']);
    }

    public function test_admin_vede_la_pagina_con_il_titolo(): void
    {
        $this->actingAs($this->admin)
            ->get('/api-keys')
            ->assertOk()
            ->assertSee('Chiavi API');
    }

    public function test_super_admin_con_tenant_selezionato_vede_solo_chiavi_del_tenant_e_globali(): void
    {
        $altraAssociazione = Associazione::factory()->create();
        $this->creaChiave($this->associazione->id, 'Chiave Del Tenant Scelto');
        $this->creaChiave($altraAssociazione->id, 'Chiave Altro Tenant');
        $this->creaChiave(null, 'Chiave Globale Sempre Visibile');

        $superAdmin = Utente::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get('/api-keys')
            ->assertOk()
            ->assertSee('Chiave Del Tenant Scelto')
            ->assertSee('Chiave Globale Sempre Visibile')
            ->assertDontSee('Chiave Altro Tenant');
    }

    public function test_la_chiave_creata_appare_in_lista_con_il_prefisso_offuscato(): void
    {
        $chiave = $this->creaChiave($this->associazione->id, 'Chiave Gestionale Esterno');

        $this->actingAs($this->admin)
            ->get('/api-keys')
            ->assertOk()
            ->assertSee('Chiave Gestionale Esterno')
            // La pagina non espone mai la chiave intera: solo prefisso e suffisso
            ->assertSee(substr($chiave->api_key, 0, 8))
            ->assertDontSee($chiave->api_key);
    }

    public function test_l_operatore_non_accede_alle_chiavi_api(): void
    {
        // La pagina richiede il permesso api.view: l'operatore è escluso
        $this->creaChiave($this->associazione->id, 'Chiave Tenant Operatore');

        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)
            ->get('/api-keys')
            ->assertForbidden();
    }
}
