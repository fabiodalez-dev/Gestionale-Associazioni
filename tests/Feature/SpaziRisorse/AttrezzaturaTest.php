<?php

namespace Tests\Feature\SpaziRisorse;

use App\Models\Associazione;
use App\Models\Attrezzatura;
use App\Models\PrestitoAttrezzatura;
use App\Models\Spazio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttrezzaturaTest extends TestCase
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
        $this->get('/attrezzature')->assertRedirect('/login');
    }

    public function test_index_mostra_solo_le_attrezzature_del_tenant(): void
    {
        $altra = Associazione::factory()->create();

        Attrezzatura::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Proiettore HD']);
        Attrezzatura::factory()->create(['associazione_id' => $altra->id, 'nome' => 'Casse Altrui']);

        $response = $this->actingAs($this->admin)->get('/attrezzature');

        $response->assertOk();
        $response->assertViewIs('spazi-risorse.attrezzature.index');
        $response->assertSee('Proiettore HD');
        $response->assertDontSee('Casse Altrui');
    }

    public function test_ricerca_per_nome_e_ubicazione(): void
    {
        Attrezzatura::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Tavolo pieghevole', 'descrizione' => null, 'ubicazione' => 'Cantina']);
        Attrezzatura::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Sedie', 'descrizione' => null, 'ubicazione' => 'Soffitta']);

        $response = $this->actingAs($this->admin)->get('/attrezzature?search=Cantina');
        $response->assertOk();
        $this->assertCount(1, $response->viewData('attrezzature'));
        $response->assertSee('Tavolo pieghevole');
    }

    public function test_filtro_per_stato(): void
    {
        Attrezzatura::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Buona', 'stato' => Attrezzatura::STATO_BUONO]);
        Attrezzatura::factory()->fuoriUso()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Rotta']);

        $response = $this->actingAs($this->admin)->get('/attrezzature?stato='.Attrezzatura::STATO_FUORI_USO);
        $response->assertOk();
        $this->assertCount(1, $response->viewData('attrezzature'));
        $response->assertSee('Rotta');
    }

    public function test_creazione_attrezzatura_con_virgola_decimale(): void
    {
        $response = $this->actingAs($this->admin)->post('/attrezzature', [
            'nome' => 'Microfono',
            'quantita' => 3,
            'valore_unitario' => '49,90',
            'stato' => Attrezzatura::STATO_BUONO,
        ]);

        $response->assertRedirect(route('attrezzature.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('attrezzature', [
            'nome' => 'Microfono',
            'quantita' => 3,
            'valore_unitario' => '49.90',
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_validazione_stato_non_valido(): void
    {
        $response = $this->actingAs($this->admin)
            ->from('/attrezzature')
            ->post('/attrezzature', [
                'nome' => 'X',
                'quantita' => 1,
                'stato' => 'distrutto',
            ]);

        $response->assertSessionHasErrors('stato');
        $this->assertDatabaseCount('attrezzature', 0);
    }

    public function test_spazio_di_altro_tenant_rifiutato(): void
    {
        $altra = Associazione::factory()->create();
        $spazioAltrui = Spazio::factory()->create(['associazione_id' => $altra->id]);

        $response = $this->actingAs($this->admin)
            ->from('/attrezzature')
            ->post('/attrezzature', [
                'nome' => 'Hack',
                'quantita' => 1,
                'stato' => Attrezzatura::STATO_BUONO,
                'spazio_id' => $spazioAltrui->id,
            ]);

        $response->assertSessionHasErrors('spazio_id');
        $this->assertDatabaseMissing('attrezzature', ['nome' => 'Hack']);
    }

    public function test_aggiornamento_non_riduce_sotto_il_prestato(): void
    {
        $attrezzatura = Attrezzatura::factory()->create([
            'associazione_id' => $this->associazione->id,
            'quantita' => 5,
        ]);
        // 3 pezzi attualmente in prestito
        PrestitoAttrezzatura::factory()->create([
            'associazione_id' => $this->associazione->id,
            'attrezzatura_id' => $attrezzatura->id,
            'quantita' => 3,
        ]);

        $response = $this->actingAs($this->admin)
            ->from('/attrezzature')
            ->put("/attrezzature/{$attrezzatura->id}", [
                'nome' => $attrezzatura->nome,
                'quantita' => 2,
                'stato' => Attrezzatura::STATO_BUONO,
            ]);

        $response->assertSessionHasErrors('quantita');
        $this->assertSame(5, $attrezzatura->fresh()->quantita);
    }

    public function test_aggiornamento_di_altro_tenant_restituisce_404(): void
    {
        $altrui = Attrezzatura::factory()->create(['nome' => 'Riservata']);

        $this->actingAs($this->admin)->put("/attrezzature/{$altrui->id}", [
            'nome' => 'Hack',
            'quantita' => 1,
            'stato' => Attrezzatura::STATO_BUONO,
        ])->assertNotFound();

        $this->assertSame('Riservata', $altrui->fresh()->nome);
    }

    public function test_eliminazione_bloccata_con_prestiti_attivi(): void
    {
        $attrezzatura = Attrezzatura::factory()->create([
            'associazione_id' => $this->associazione->id,
            'quantita' => 2,
        ]);
        PrestitoAttrezzatura::factory()->create([
            'associazione_id' => $this->associazione->id,
            'attrezzatura_id' => $attrezzatura->id,
            'quantita' => 1,
        ]);

        $response = $this->actingAs($this->admin)->delete("/attrezzature/{$attrezzatura->id}");

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('attrezzature', ['id' => $attrezzatura->id]);
    }

    public function test_eliminazione_senza_prestiti(): void
    {
        $attrezzatura = Attrezzatura::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->delete("/attrezzature/{$attrezzatura->id}")
            ->assertRedirect(route('attrezzature.index'));

        $this->assertDatabaseMissing('attrezzature', ['id' => $attrezzatura->id]);
    }
}
