<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina sidebar: /dashboard (Dashboard\DashboardController@index).
 *
 * Nota: la rotta usa solo il middleware 'auth' (niente 'tenant'): il
 * super_admin senza tenant selezionato la vede in vista globale.
 */
class DashboardPageTest extends TestCase
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

    public function test_ospite_viene_rediretto_al_login_anche_dalla_root(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_operatore_autenticato_vede_la_dashboard(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $operatore->assignRole('operatore');

        $response = $this->actingAs($operatore)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewIs('dashboard.index');
        $response->assertSee('Dashboard');
    }

    public function test_isolamento_tenant_prossimi_eventi_solo_della_propria_associazione(): void
    {
        $altraAssociazione = Associazione::factory()->create();

        Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Assemblea Annuale Alfa',
            'data_evento' => now()->addDays(3),
        ]);
        Evento::factory()->create([
            'associazione_id' => $altraAssociazione->id,
            'titolo' => 'Torneo Riservato Beta',
            'data_evento' => now()->addDays(5),
        ]);

        $response = $this->actingAs($this->admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Assemblea Annuale Alfa');
        $response->assertDontSee('Torneo Riservato Beta');
        $this->assertCount(1, $response->viewData('prossimiEventi'));
    }

    public function test_statistica_eventi_mese_conta_solo_gli_eventi_del_mese_corrente(): void
    {
        Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now(),
        ]);
        Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->addMonths(2),
        ]);

        $response = $this->actingAs($this->admin)->get(route('dashboard'));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('stats')['eventi_mese']);
    }

    public function test_admin_associazione_non_ha_vista_globale_ne_card_di_cambio_associazione(): void
    {
        Associazione::factory()->create([
            'nome' => 'Associazione Selezionabile Gamma',
            'attiva' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('dashboard'));

        $response->assertOk();
        $this->assertFalse($response->viewData('vistaGlobale'));
        $this->assertCount(0, $response->viewData('associazioni'));
    }
}
