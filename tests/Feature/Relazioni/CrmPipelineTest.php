<?php

namespace Tests\Feature\Relazioni;

use App\Models\Associazione;
use App\Models\OpportunitaCrm;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CRM a colonne: creazione, trascinamento tra le fasi, esito, isolamento.
 */
class CrmPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Marco', 'cognome' => 'Rossi']);
        $this->admin->assignRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE);
        $this->actingAs($this->admin)->withSession(['associazione_id' => $this->associazione->id]);
    }

    private function nuova(array $dati = []): OpportunitaCrm
    {
        return OpportunitaCrm::create(array_merge([
            'associazione_id' => $this->associazione->id,
            'pipeline' => 'adesioni',
            'fase' => 'contatto',
            'titolo' => 'Roberto Fabbri',
        ], $dati));
    }

    public function test_board_con_colonne_kpi_e_libreria_di_trascinamento(): void
    {
        $this->nuova(['descrizione' => 'Interessato a pallavolo amatori', 'origine' => 'modulo_web', 'valore' => 45, 'prossima_azione_il' => now()->subDay()->toDateString()]);

        $this->get(route('crm.index'))->assertOk()
            ->assertSee('Nuove adesioni')
            ->assertSee('Qualificato')->assertSee('In attesa di pagamento')
            ->assertSee('Roberto Fabbri')->assertSee('Interessato a pallavolo amatori')->assertSee('Modulo web')
            ->assertSee('Azioni in ritardo')
            ->assertSee('vendor/sortablejs/Sortable.min.js', false);

        $this->get(route('crm.index', ['pipeline' => 'sponsor']))->assertOk()
            ->assertSee('Sponsor e partner')->assertSee('Trattativa')->assertDontSee('Roberto Fabbri');
    }

    public function test_crea_modifica_e_elimina(): void
    {
        $this->post(route('crm.store'), [
            'pipeline' => 'sponsor', 'fase' => 'contatto', 'titolo' => 'Banca Emilia Credito',
            'descrizione' => 'Naming torneo primavera', 'origine' => 'contatto_diretto', 'valore' => '4000',
            'responsabile_id' => $this->admin->id,
        ])->assertRedirect(route('crm.index', ['pipeline' => 'sponsor']));

        $o = OpportunitaCrm::query()->sole();
        $this->assertSame($this->associazione->id, $o->associazione_id);
        $this->assertSame('4000.00', $o->valore);

        $this->put(route('crm.update', $o), ['fase' => 'incontro', 'titolo' => 'Banca Emilia Credito', 'valore' => '5000'])->assertRedirect();
        $this->assertSame('incontro', $o->refresh()->fase);

        // Fase non valida per la pipeline sponsor
        $this->put(route('crm.update', $o), ['fase' => 'qualificato', 'titolo' => 'X'])->assertSessionHasErrors('fase');

        $this->delete(route('crm.destroy', $o))->assertRedirect();
        $this->assertSoftDeleted($o);
    }

    public function test_trascinamento_aggiorna_fase_ordine_ed_esito(): void
    {
        $a = $this->nuova(['titolo' => 'A', 'fase' => 'qualificato', 'posizione' => 0]);
        $b = $this->nuova(['titolo' => 'B', 'fase' => 'qualificato', 'posizione' => 1]);
        $c = $this->nuova(['titolo' => 'C', 'fase' => 'contatto']);

        // C trascinata in "qualificato" in prima posizione
        $this->patchJson(route('crm.sposta', $c), ['fase' => 'qualificato', 'ordine' => [$c->id, $a->id, $b->id]])
            ->assertOk()->assertJson(['ok' => true, 'esito' => null]);
        $this->assertSame([0, 1, 2], [$c->refresh()->posizione, $a->refresh()->posizione, $b->refresh()->posizione]);
        $this->assertSame('qualificato', $c->fase);

        // Nell'ultima fase diventa vinta, tornando indietro si riapre
        $this->patchJson(route('crm.sposta', $c), ['fase' => 'iscritto', 'ordine' => [$c->id]])->assertJson(['esito' => 'vinta']);
        $this->assertNotNull($c->refresh()->chiusa_at);
        $this->patchJson(route('crm.sposta', $c), ['fase' => 'proposta', 'ordine' => [$c->id]])->assertJson(['esito' => null]);

        $this->patchJson(route('crm.sposta', $c), ['fase' => 'inesistente', 'ordine' => [$c->id]])->assertUnprocessable()->assertJsonPath('ok', false);
    }

    public function test_persa_esce_dalla_board_e_si_riapre(): void
    {
        $o = $this->nuova(['titolo' => 'Simone Bassi']);
        $this->post(route('crm.persa', $o))->assertRedirect();
        $this->assertSame('persa', $o->refresh()->esito);
        $this->get(route('crm.index'))->assertSee('Non andati a buon fine (1)');

        $this->post(route('crm.riapri', $o))->assertRedirect();
        $this->assertNull($o->refresh()->esito);
    }

    public function test_isolamento_tra_associazioni_e_permessi(): void
    {
        $altra = Associazione::factory()->create();
        $estranea = OpportunitaCrm::create(['associazione_id' => $altra->id, 'pipeline' => 'adesioni', 'fase' => 'contatto', 'titolo' => 'Contatto Estraneo']);
        $mia = $this->nuova();

        Utente::factory()->adminAssociazione()->create(['associazione_id' => $altra->id, 'nome' => 'Amministratore', 'cognome' => 'Forestiero']);
        $this->get(route('crm.index'))->assertDontSee('Contatto Estraneo')->assertDontSee('Forestiero')->assertSee('Marco Rossi');
        $this->patchJson(route('crm.sposta', $estranea), ['fase' => 'qualificato', 'ordine' => [$estranea->id]])->assertNotFound();
        // Un id di un'altra associazione nell'ordine viene ignorato
        $this->patchJson(route('crm.sposta', $mia), ['fase' => 'qualificato', 'ordine' => [$estranea->id, $mia->id]])->assertOk();
        $this->assertSame('contatto', $estranea->refresh()->fase);

        $soloEventi = Role::findOrCreate('solo_eventi_crm', 'web');
        $soloEventi->syncPermissions(['eventi.view']);
        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $operatore->syncRoles([$soloEventi]);
        $this->actingAs($operatore)->get(route('crm.index'))->assertForbidden();
        $this->actingAs($operatore)->patchJson(route('crm.sposta', $mia), ['fase' => 'proposta', 'ordine' => [$mia->id]])->assertForbidden();
    }

    public function test_iscrivi_come_socio_precompila_la_nuova_iscrizione(): void
    {
        $this->get(route('soci.create', ['nome' => 'Roberto', 'cognome' => 'Fabbri', 'email' => 'roberto@test.it']))->assertOk()
            ->assertSee('value="Roberto"', false)
            ->assertSee('value="roberto@test.it"', false);
    }
}
