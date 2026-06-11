<?php

namespace Tests\Feature\DocumentiVerbaliScadenze;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Utente;
use App\Models\Verbale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerbaliTest extends TestCase
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
        $this->get(route('verbali.index'))->assertRedirect('/login');
    }

    public function test_creazione_verbale(): void
    {
        $response = $this->actingAs($this->admin)->post(route('verbali.store'), [
            'titolo' => 'Assemblea annuale 2026',
            'data_riunione' => '2026-05-20',
            'tipo_riunione' => 'Assemblea Ordinaria',
            'presenti' => "Mario Rossi\nLuigi Verdi",
            'ordine_del_giorno' => '1. Bilancio',
            'deliberazioni' => 'Approvato il bilancio.',
        ]);

        $response->assertRedirect(route('verbali.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('verbali', [
            'titolo' => 'Assemblea annuale 2026',
            'tipo_riunione' => 'Assemblea Ordinaria',
            'associazione_id' => $this->associazione->id,
            'approvato' => false,
        ]);
    }

    public function test_validazione_tipo_riunione_non_valido(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('verbali.index'))
            ->post(route('verbali.store'), [
                'titolo' => 'Riunione X',
                'data_riunione' => '2026-05-20',
                'tipo_riunione' => 'Tipo Inventato',
            ]);

        $response->assertSessionHasErrors('tipo_riunione');
        $this->assertSame(0, Verbale::query()->count());
    }

    public function test_approvazione_registra_chi_e_quando(): void
    {
        $verbale = $this->creaVerbale();

        $response = $this->actingAs($this->admin)->post(route('verbali.approva', $verbale));

        $response->assertRedirect(route('verbali.index'));

        $verbale->refresh();
        $this->assertTrue($verbale->approvato);
        $this->assertSame($this->admin->id, $verbale->approvato_da);
        $this->assertSame(now()->toDateString(), $verbale->data_approvazione->toDateString());
    }

    public function test_operatore_non_puo_approvare(): void
    {
        $verbale = $this->creaVerbale();
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)
            ->post(route('verbali.approva', $verbale))
            ->assertForbidden();

        $this->assertFalse($verbale->refresh()->approvato);
    }

    public function test_modifica_di_verbale_approvato_azzera_l_approvazione(): void
    {
        $verbale = $this->creaVerbale([
            'approvato' => true,
            'approvato_da' => $this->admin->id,
            'data_approvazione' => now()->toDateString(),
        ]);

        $this->actingAs($this->admin)->put(route('verbali.update', $verbale), [
            'titolo' => 'Titolo corretto',
            'data_riunione' => '2026-05-20',
            'tipo_riunione' => 'Consiglio Direttivo',
        ])->assertRedirect(route('verbali.index'));

        $verbale->refresh();
        $this->assertSame('Titolo corretto', $verbale->titolo);
        $this->assertFalse($verbale->approvato);
        $this->assertNull($verbale->approvato_da);
        $this->assertNull($verbale->data_approvazione);
    }

    public function test_operatore_non_puo_modificare_verbale_approvato(): void
    {
        $verbale = $this->creaVerbale([
            'approvato' => true,
            'approvato_da' => $this->admin->id,
        ]);
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)->put(route('verbali.update', $verbale), [
            'titolo' => 'Manomesso',
            'data_riunione' => '2026-05-20',
            'tipo_riunione' => 'Commissione',
        ])->assertForbidden();
    }

    public function test_isolamento_tenant_il_verbale_di_a_non_e_raggiungibile_da_b(): void
    {
        $verbale = $this->creaVerbale();

        $adminB = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => Associazione::factory()->create()->id,
        ]);

        $this->actingAs($adminB)->put(route('verbali.update', ['verbale' => $verbale->id]), [
            'titolo' => 'Hack',
            'data_riunione' => '2026-05-20',
            'tipo_riunione' => 'Commissione',
        ])->assertNotFound();

        $this->actingAs($adminB)
            ->get(route('verbali.index'))
            ->assertDontSee($verbale->titolo);
    }

    public function test_cestino_eliminazione_e_ripristino(): void
    {
        $verbale = $this->creaVerbale();

        $this->actingAs($this->admin)
            ->delete(route('verbali.destroy', $verbale))
            ->assertRedirect(route('verbali.index'));

        $this->assertSoftDeleted('verbali', ['id' => $verbale->id]);

        $this->actingAs($this->admin)
            ->get(route('verbali.cestino'))
            ->assertOk()
            ->assertSee($verbale->titolo);

        $this->actingAs($this->admin)
            ->post(route('verbali.ripristina', $verbale->id))
            ->assertRedirect(route('verbali.index'));

        $this->assertDatabaseHas('verbali', ['id' => $verbale->id, 'deleted_at' => null]);
    }

    public function test_aggiunta_firmatario_e_registrazione_firma(): void
    {
        $verbale = $this->creaVerbale();
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->post(route('verbali.firmatari.store', $verbale), [
                'socio_id' => $socio->id,
                'ruolo' => 'Presidente',
            ])
            ->assertRedirect(route('verbali.index'));

        $firmatario = $verbale->firmatari()->first();
        $this->assertNotNull($firmatario);
        $this->assertSame('Presidente', $firmatario->ruolo);
        $this->assertNull($firmatario->firmato_at);

        $this->actingAs($this->admin)
            ->post(route('verbali.firmatari.firma', [$verbale, $firmatario]))
            ->assertRedirect(route('verbali.index'));

        $this->assertNotNull($firmatario->refresh()->firmato_at);
    }

    public function test_firmatario_richiede_socio_utente_o_nominativo(): void
    {
        $verbale = $this->creaVerbale();

        $this->actingAs($this->admin)
            ->from(route('verbali.index'))
            ->post(route('verbali.firmatari.store', $verbale), ['ruolo' => 'Segretario'])
            ->assertSessionHasErrors('socio_id');
    }

    public function test_export_pdf_del_singolo_verbale(): void
    {
        $verbale = $this->creaVerbale();

        $response = $this->actingAs($this->admin)->get(route('verbali.pdf', $verbale));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_ricerca_e_filtro_tipo(): void
    {
        $this->creaVerbale(['titolo' => 'Assemblea soci', 'tipo_riunione' => 'Assemblea Ordinaria']);
        $this->creaVerbale(['titolo' => 'Direttivo di marzo', 'tipo_riunione' => 'Consiglio Direttivo']);

        $response = $this->actingAs($this->admin)
            ->get(route('verbali.index', ['search' => 'Assemblea', 'type' => 'Assemblea Ordinaria']));

        $response->assertOk();
        $response->assertSee('Assemblea soci');
        $response->assertDontSee('Direttivo di marzo');
    }

    private function creaVerbale(array $attributi = []): Verbale
    {
        return Verbale::create(array_merge([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Verbale di prova',
            'data_riunione' => '2026-05-20',
            'tipo_riunione' => 'Assemblea Ordinaria',
        ], $attributi));
    }
}
