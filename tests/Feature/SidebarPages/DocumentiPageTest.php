<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\Documento;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagina sidebar: /documenti (DocumentiVerbaliScadenze\DocumentoController@index).
 */
class DocumentiPageTest extends TestCase
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

    /** Il modello Documento non ha factory: creazione diretta come nei test esistenti. */
    private function creaDocumento(string $associazioneId, string $nomeFile, array $extra = []): Documento
    {
        return Documento::create(array_merge([
            'associazione_id' => $associazioneId,
            'nome_file' => $nomeFile,
            'percorso_file' => 'documenti/'.$nomeFile,
        ], $extra));
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('documenti.index'))->assertRedirect(route('login'));
    }

    public function test_admin_associazione_vede_la_pagina_documenti(): void
    {
        $response = $this->actingAs($this->admin)->get(route('documenti.index'));

        $response->assertOk();
        $response->assertViewIs('documenti-verbali-scadenze.documenti.index');
        $response->assertSee('Documenti');
    }

    public function test_isolamento_tenant_documenti_e_categorie_dell_altra_associazione_non_visibili(): void
    {
        $altraAssociazione = Associazione::factory()->create();

        $this->creaDocumento($this->associazione->id, 'bilancio-2026.pdf', [
            'categoria' => 'Bilanci',
        ]);
        $this->creaDocumento($altraAssociazione->id, 'verbale-riservato-beta.pdf', [
            'categoria' => 'CategoriaRiservataBeta',
        ]);

        $response = $this->actingAs($this->admin)->get(route('documenti.index'));

        $response->assertOk();
        $response->assertSee('bilancio-2026.pdf');
        $response->assertDontSee('verbale-riservato-beta.pdf');
        // Anche la folksonomy delle categorie è limitata al proprio tenant
        $response->assertViewHas(
            'categorie',
            fn ($categorie) => $categorie->contains('Bilanci')
                && ! $categorie->contains('CategoriaRiservataBeta')
        );
    }

    public function test_ricerca_sulla_descrizione_trova_solo_il_documento_corrispondente(): void
    {
        $this->creaDocumento($this->associazione->id, 'allegato-a.pdf', [
            'descrizione' => 'Convenzione palestra comunale',
        ]);
        $this->creaDocumento($this->associazione->id, 'allegato-b.pdf', [
            'descrizione' => 'Polizza assicurativa volontari',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('documenti.index', ['search' => 'palestra']));

        $response->assertOk();
        $response->assertSee('allegato-a.pdf');
        $response->assertDontSee('allegato-b.pdf');
        $response->assertViewHas('documenti', fn ($documenti) => $documenti->total() === 1);
    }

    public function test_super_admin_senza_tenant_selezionato_viene_rediretto_alla_dashboard(): void
    {
        // La rotta non ha gate di permesso per l'operatore (DocumentoPolicy::viewAny
        // ritorna true: tutti gli utenti del tenant vedono i documenti): si testa
        // il comportamento reale del middleware 'tenant' per il super_admin
        // senza associazione selezionata.
        $superAdmin = Utente::factory()->superAdmin()->create();
        $superAdmin->assignRole('super_admin');

        $response = $this->actingAs($superAdmin)->get(route('documenti.index'));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('warning');
    }
}
