<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\Sede;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SediPageTest extends TestCase
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
        $this->get('/configurazioni?section=sedi')->assertRedirect('/login');
    }

    public function test_admin_vede_la_sezione_sedi(): void
    {
        $response = $this->actingAs($this->admin)->get('/configurazioni?section=sedi');

        $response->assertOk();
        $response->assertSee('Nuova sede');
    }

    public function test_isolamento_tenant_la_sede_di_un_altro_tenant_non_compare(): void
    {
        Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Sede Casa Mia']);

        $altraAssociazione = Associazione::factory()->create();
        Sede::create(['associazione_id' => $altraAssociazione->id, 'nome' => 'Sede Estranea Riservata']);

        $response = $this->actingAs($this->admin)->get('/configurazioni?section=sedi');

        $response->assertOk();
        $response->assertSee('Sede Casa Mia');
        $response->assertDontSee('Sede Estranea Riservata');
    }

    public function test_la_sede_del_tenant_compare_in_lista_con_i_dettagli(): void
    {
        Sede::create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Sede Lago Maggiore',
            'citta' => 'Verbania',
            'responsabile' => 'Giulia Lariani',
        ]);

        $response = $this->actingAs($this->admin)->get('/configurazioni?section=sedi');

        $response->assertOk();
        $response->assertSee('Sede Lago Maggiore');
        $response->assertSee('Verbania');
        $response->assertSee('Giulia Lariani');
    }

    public function test_senza_sedi_viene_mostrato_lo_stato_vuoto(): void
    {
        $response = $this->actingAs($this->admin)->get('/configurazioni?section=sedi');

        $response->assertOk();
        $response->assertSee('Nessuna sede presente.');
    }
}
