<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina sidebar: /soci (Soci\SocioController@index).
 *
 * Nota: il filtro di default della lista è tessera_stato=attive, quindi
 * i soci compaiono in lista solo se hanno una tessera attiva.
 */
class SociPageTest extends TestCase
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

    /** Crea un socio con tessera attiva (visibile nella lista di default). */
    private function creaSocioConTessera(string $associazioneId, string $cognome): Socio
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $associazioneId,
            'cognome' => $cognome,
        ]);

        Tessera::factory()->create(['socio_id' => $socio->id]);

        return $socio;
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('soci.index'))->assertRedirect(route('login'));
    }

    public function test_admin_associazione_vede_la_pagina_soci(): void
    {
        $response = $this->actingAs($this->admin)->get(route('soci.index'));

        $response->assertOk();
        $response->assertViewIs('soci.index');
        $response->assertSee('Soci');
    }

    public function test_isolamento_tenant_i_soci_dell_altra_associazione_non_sono_visibili(): void
    {
        $altraAssociazione = Associazione::factory()->create();

        $this->creaSocioConTessera($this->associazione->id, 'Verdellini');
        $this->creaSocioConTessera($altraAssociazione->id, 'Bianchessi');

        $response = $this->actingAs($this->admin)->get(route('soci.index'));

        $response->assertOk();
        $response->assertSee('Verdellini');
        $response->assertDontSee('Bianchessi');
    }

    public function test_ricerca_per_cognome_trova_solo_il_socio_corrispondente(): void
    {
        $this->creaSocioConTessera($this->associazione->id, 'Montalbano');
        $this->creaSocioConTessera($this->associazione->id, 'Quagliarella');

        $response = $this->actingAs($this->admin)
            ->get(route('soci.index', ['search' => 'Montalbano']));

        $response->assertOk();
        $response->assertSee('Montalbano');
        $response->assertDontSee('Quagliarella');
        $this->assertSame(1, $response->viewData('soci')->total());
    }

    public function test_utente_senza_permessi_riceve_403(): void
    {
        // Operatore senza ruolo spatie assegnato: il permesso soci.view
        // esiste (seedato) ma l'utente non lo possiede → 403
        $senzaPermessi = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($senzaPermessi)->get(route('soci.index'))->assertForbidden();
    }
}
