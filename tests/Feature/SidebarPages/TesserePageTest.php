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
 * Pagina sidebar: /tessere (Tessere\TesseraController@index).
 *
 * La lista di default mostra le tessere dell'anno corrente (la factory
 * Tessera crea tessere dell'anno corrente).
 */
class TesserePageTest extends TestCase
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

    /** Crea una tessera (anno corrente) per un socio con il cognome dato. */
    private function creaTesseraPerCognome(string $associazioneId, string $cognome): Tessera
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $associazioneId,
            'cognome' => $cognome,
        ]);

        return Tessera::factory()->create(['socio_id' => $socio->id]);
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('tessere.index'))->assertRedirect(route('login'));
    }

    public function test_admin_associazione_vede_la_pagina_gestione_tessere(): void
    {
        $response = $this->actingAs($this->admin)->get(route('tessere.index'));

        $response->assertOk();
        $response->assertViewIs('tessere.index');
        $response->assertSee('Gestione Tessere');
    }

    public function test_isolamento_tenant_le_tessere_dell_altra_associazione_non_sono_visibili(): void
    {
        $altraAssociazione = Associazione::factory()->create();

        $this->creaTesseraPerCognome($this->associazione->id, 'Rossellini');
        $this->creaTesseraPerCognome($altraAssociazione->id, 'Neribaldi');

        $response = $this->actingAs($this->admin)->get(route('tessere.index'));

        $response->assertOk();
        $response->assertSee('Rossellini');
        $response->assertDontSee('Neribaldi');
        $this->assertCount(1, $response->viewData('tessere'));
    }

    public function test_ricerca_per_cognome_socio_filtra_le_tessere(): void
    {
        $this->creaTesseraPerCognome($this->associazione->id, 'Sparviero');
        $this->creaTesseraPerCognome($this->associazione->id, 'Colombaccio');

        $response = $this->actingAs($this->admin)
            ->get(route('tessere.index', ['search' => 'Sparviero']));

        $response->assertOk();
        // Nota: assertDontSee non è affidabile qui perché il modal "Nuova
        // Tessera" elenca comunque tutti i soci del tenant nel dropdown.
        $tessere = $response->viewData('tessere');
        $this->assertCount(1, $tessere);
        $this->assertSame('Sparviero', $tessere->first()->socio->cognome);
    }

    public function test_utente_senza_permessi_riceve_403(): void
    {
        // Operatore senza ruolo spatie assegnato: il permesso tessere.view
        // esiste (seedato) ma l'utente non lo possiede → 403
        $senzaPermessi = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($senzaPermessi)->get(route('tessere.index'))->assertForbidden();
    }
}
