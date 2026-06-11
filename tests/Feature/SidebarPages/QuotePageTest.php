<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina sidebar: /quote (Quote\QuotaController@index).
 */
class QuotePageTest extends TestCase
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
        $this->get(route('quote.index'))->assertRedirect(route('login'));
    }

    public function test_admin_associazione_vede_la_pagina_quote_associative(): void
    {
        $response = $this->actingAs($this->admin)->get(route('quote.index'));

        $response->assertOk();
        $response->assertViewIs('quote.index');
        $response->assertSee('Quote Associative');
    }

    public function test_isolamento_tenant_le_quote_dell_altra_associazione_non_sono_visibili(): void
    {
        $altraAssociazione = Associazione::factory()->create();

        $socioA = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Fontanella',
        ]);
        $socioB = Socio::factory()->create([
            'associazione_id' => $altraAssociazione->id,
            'cognome' => 'Granchietti',
        ]);

        Quota::factory()->create(['socio_id' => $socioA->id]);
        Quota::factory()->create(['socio_id' => $socioB->id]);

        $response = $this->actingAs($this->admin)->get(route('quote.index'));

        $response->assertOk();
        $response->assertSee('Fontanella');
        $response->assertDontSee('Granchietti');
        $response->assertViewHas('quote', fn ($quote) => $quote->total() === 1);
    }

    public function test_filtro_per_anno_mostra_solo_le_quote_dell_anno_richiesto(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        Quota::factory()->create(['socio_id' => $socio->id]); // anno corrente
        Quota::factory()->scaduta()->create(['socio_id' => $socio->id]); // anno precedente

        $response = $this->actingAs($this->admin)
            ->get(route('quote.index', ['year' => (int) now()->year]));

        $response->assertOk();
        $response->assertViewHas('quote', fn ($quote) => $quote->total() === 1);
        $this->assertSame((string) now()->year, (string) $response->viewData('filtroAnno'));
    }

    public function test_super_admin_senza_tenant_selezionato_viene_rediretto_alla_dashboard(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $superAdmin->assignRole('super_admin');

        $response = $this->actingAs($superAdmin)->get(route('quote.index'));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('warning');
    }
}
