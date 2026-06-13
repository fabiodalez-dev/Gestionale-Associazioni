<?php

namespace Tests\Feature\Relazioni;

use App\Models\Associazione;
use App\Models\ContattoEsterno;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.21 — Mini-CRM contatti esterni: CRUD, ricerca testuale e filtro per tipo,
 * isolamento multi-tenant, permessi (struttura.*).
 */
class ContattiEsterniTest extends TestCase
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

    public function test_index_elenca_solo_i_contatti_del_tenant(): void
    {
        ContattoEsterno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Tipografia Rossi',
        ]);

        $altra = Associazione::factory()->create();
        ContattoEsterno::factory()->create([
            'associazione_id' => $altra->id,
            'nome' => 'Fornitore Estraneo',
        ]);

        $response = $this->actingAs($this->admin)->get('/contatti-esterni');

        $response->assertOk();
        $response->assertViewIs('relazioni.contatti-esterni.index');
        $response->assertSee('Tipografia Rossi');
        $response->assertDontSee('Fornitore Estraneo');
    }

    public function test_ricerca_per_nome_referente_email(): void
    {
        ContattoEsterno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Comune di Verona',
            'referente' => 'Dott. Bianchi',
        ]);
        ContattoEsterno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Altra Azienda',
            'referente' => 'Sig. Verdi',
        ]);

        $response = $this->actingAs($this->admin)->get('/contatti-esterni?q=Bianchi');

        $response->assertOk();
        $response->assertSee('Comune di Verona');
        $response->assertDontSee('Altra Azienda');
    }

    public function test_filtro_per_tipo(): void
    {
        ContattoEsterno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'tipo' => ContattoEsterno::TIPO_SPONSOR,
            'nome' => 'Sponsor Oro Spa',
        ]);
        ContattoEsterno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'tipo' => ContattoEsterno::TIPO_STAMPA,
            'nome' => 'Gazzetta Locale',
        ]);

        $response = $this->actingAs($this->admin)->get('/contatti-esterni?tipo='.ContattoEsterno::TIPO_SPONSOR);

        $response->assertOk();
        $response->assertSee('Sponsor Oro Spa');
        $response->assertDontSee('Gazzetta Locale');
    }

    public function test_crea_contatto_con_tag_normalizzati(): void
    {
        $this->actingAs($this->admin)
            ->post('/contatti-esterni', [
                'tipo' => ContattoEsterno::TIPO_ENTE,
                'nome' => 'Regione Veneto',
                'referente' => 'Ufficio bandi',
                'email' => 'bandi@regione.example',
                'telefono' => '045 1234567',
                'tag' => 'bando, contributi , bando, ',
            ])
            ->assertRedirect('/contatti-esterni')
            ->assertSessionHas('success');

        $contatto = ContattoEsterno::query()->where('nome', 'Regione Veneto')->firstOrFail();
        $this->assertSame($this->associazione->id, $contatto->associazione_id);
        $this->assertSame(['bando', 'contributi'], $contatto->tag);
    }

    public function test_crea_contatto_validazione_richiede_nome_e_tipo_valido(): void
    {
        $this->actingAs($this->admin)
            ->post('/contatti-esterni', [
                'tipo' => 'inesistente',
                'nome' => '',
            ])
            ->assertSessionHasErrors(['tipo', 'nome']);

        $this->assertDatabaseCount('contatti_esterni', 0);
    }

    public function test_aggiorna_contatto(): void
    {
        $contatto = ContattoEsterno::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Vecchio Nome',
        ]);

        $this->actingAs($this->admin)
            ->put('/contatti-esterni/'.$contatto->id, [
                'tipo' => ContattoEsterno::TIPO_FORNITORE,
                'nome' => 'Nuovo Nome',
                'tag' => 'urgente',
            ])
            ->assertRedirect('/contatti-esterni');

        $this->assertDatabaseHas('contatti_esterni', [
            'id' => $contatto->id,
            'nome' => 'Nuovo Nome',
            'tipo' => ContattoEsterno::TIPO_FORNITORE,
        ]);
    }

    public function test_elimina_contatto(): void
    {
        $contatto = ContattoEsterno::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->admin)
            ->delete('/contatti-esterni/'.$contatto->id)
            ->assertRedirect('/contatti-esterni');

        $this->assertDatabaseMissing('contatti_esterni', ['id' => $contatto->id]);
    }

    public function test_non_puo_modificare_contatto_di_altro_tenant(): void
    {
        $altra = Associazione::factory()->create();
        $contattoAltrui = ContattoEsterno::factory()->create([
            'associazione_id' => $altra->id,
        ]);

        // Il global scope rende il record invisibile → 404 sul binding
        $this->actingAs($this->admin)
            ->put('/contatti-esterni/'.$contattoAltrui->id, [
                'tipo' => ContattoEsterno::TIPO_ALTRO,
                'nome' => 'Hack',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('contatti_esterni', [
            'id' => $contattoAltrui->id,
            'associazione_id' => $altra->id,
        ]);
    }

    public function test_utente_senza_tenant_non_accede(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();

        // Nessuna associazione selezionata → middleware tenant respinge
        $this->actingAs($superAdmin)
            ->get('/contatti-esterni')
            ->assertRedirect(route('dashboard'));
    }
}
