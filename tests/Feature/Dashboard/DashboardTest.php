<?php

namespace Tests\Feature\Dashboard;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_utente_autenticato_accede_alla_dashboard(): void
    {
        $utente = Utente::factory()->create();

        $response = $this->actingAs($utente)->get('/dashboard');

        $response->assertOk();
        $response->assertViewIs('dashboard.index');
        $response->assertSee('Dashboard');
    }

    public function test_isolamento_tenant_l_admin_di_a_non_vede_i_dati_di_b(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();

        Socio::factory()->count(2)->create(['associazione_id' => $assocA->id, 'stato' => 'Attivo']);
        Socio::factory()->count(5)->create(['associazione_id' => $assocB->id, 'stato' => 'Attivo']);

        $adminA = Utente::factory()->adminAssociazione()->create(['associazione_id' => $assocA->id]);

        $response = $this->actingAs($adminA)->get('/dashboard');

        $response->assertOk();
        $stats = $response->viewData('stats');

        $this->assertSame(2, $stats['total_soci']);
        $this->assertSame(2, $stats['soci_attivi']);
    }

    public function test_super_admin_in_vista_globale_vede_tutti_i_tenant(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();

        Socio::factory()->count(2)->create(['associazione_id' => $assocA->id]);
        Socio::factory()->count(3)->create(['associazione_id' => $assocB->id]);

        $superAdmin = Utente::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->get('/dashboard');

        $response->assertOk();
        $this->assertSame(5, $response->viewData('stats')['total_soci']);
        $this->assertTrue($response->viewData('vistaGlobale'));
    }

    public function test_super_admin_con_tenant_selezionato_vede_solo_quel_tenant(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();

        Socio::factory()->count(2)->create(['associazione_id' => $assocA->id]);
        Socio::factory()->count(3)->create(['associazione_id' => $assocB->id]);

        $superAdmin = Utente::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)
            ->withSession(['associazione_id' => $assocA->id, 'associazione_nome' => $assocA->nome])
            ->get('/dashboard');

        $response->assertOk();
        $this->assertSame(2, $response->viewData('stats')['total_soci']);
        $this->assertFalse($response->viewData('vistaGlobale'));
    }

    public function test_le_risposte_web_hanno_i_security_headers(): void
    {
        $utente = Utente::factory()->create();

        $response = $this->actingAs($utente)->get('/dashboard');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotEmpty($response->headers->get('Content-Security-Policy'));
    }
}
