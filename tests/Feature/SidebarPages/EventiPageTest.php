<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina sidebar: /eventi (Eventi\EventoController@index).
 */
class EventiPageTest extends TestCase
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
        $this->get(route('eventi.index'))->assertRedirect(route('login'));
    }

    public function test_admin_associazione_vede_la_pagina_eventi(): void
    {
        $response = $this->actingAs($this->admin)->get(route('eventi.index'));

        $response->assertOk();
        $response->assertViewIs('eventi.index');
        $response->assertSee('Eventi');
    }

    public function test_isolamento_tenant_gli_eventi_dell_altra_associazione_non_sono_visibili(): void
    {
        $altraAssociazione = Associazione::factory()->create();

        Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Cena Sociale Primaverile',
        ]);
        Evento::factory()->create([
            'associazione_id' => $altraAssociazione->id,
            'titolo' => 'Raduno Segreto Esterno',
        ]);

        $response = $this->actingAs($this->admin)->get(route('eventi.index'));

        $response->assertOk();
        $response->assertSee('Cena Sociale Primaverile');
        $response->assertDontSee('Raduno Segreto Esterno');
        $response->assertViewHas('eventi', fn ($eventi) => $eventi->total() === 1);
    }

    public function test_lista_ordinata_per_data_evento_decrescente(): void
    {
        Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Evento Vecchio',
            'data_evento' => now()->subMonth(),
        ]);
        Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Evento Recente',
            'data_evento' => now()->addMonth(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('eventi.index'));

        $response->assertOk();
        $this->assertSame('Evento Recente', $response->viewData('eventi')->first()->titolo);
        $response->assertSeeInOrder(['Evento Recente', 'Evento Vecchio']);
    }

    public function test_super_admin_senza_tenant_riceve_403_json_su_richiesta_ajax(): void
    {
        // La rotta non ha gate di permesso per l'operatore (EventoPolicy::viewAny
        // ritorna true per parità col legacy): si testa il comportamento reale
        // del middleware 'tenant' per il super_admin senza associazione selezionata.
        $superAdmin = Utente::factory()->superAdmin()->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin)
            ->getJson(route('eventi.index'))
            ->assertStatus(403)
            ->assertJsonPath('code', 'TENANT_NOT_SELECTED');
    }
}
