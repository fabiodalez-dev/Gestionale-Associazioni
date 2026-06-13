<?php

namespace Tests\Feature\Struttura;

use App\Models\Associazione;
use App\Models\RichiestaIscrizione;
use App\Models\Riunione;
use App\Models\Socio;
use App\Models\Utente;
use App\Models\Verbale;
use App\Models\VotazioneRiunione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Archivio Decisioni (f.19): aggrega le votazioni approvate delle riunioni
 * e le delibere di ammissione (round 1), ricercabile per testo/anno con
 * link al verbale collegato. Permessi struttura.* e isolamento tenant.
 */
class DecisioniTest extends TestCase
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

    private function actAsAdmin(): self
    {
        $this->actingAs($this->admin)->withSession(['associazione_id' => $this->associazione->id]);

        return $this;
    }

    public function test_operatore_non_accede_alle_decisioni(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('decisioni.index'))
            ->assertForbidden();
    }

    public function test_decisioni_aggrega_votazioni_approvate_e_delibere(): void
    {
        $riunione = Riunione::factory()->svolta()->create([
            'associazione_id' => $this->associazione->id,
            'data_ora' => '2026-05-01 21:00:00',
            'odg' => [['punto' => 'Adesione a rete territoriale', 'descrizione' => null]],
        ]);

        // Votazione approvata → compare
        VotazioneRiunione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $riunione->id,
            'punto_indice' => 0,
            'esito' => VotazioneRiunione::ESITO_APPROVATO,
            'favorevoli' => 8, 'contrari' => 0, 'astenuti' => 0,
        ]);

        // Votazione respinta → NON compare
        VotazioneRiunione::factory()->respinta()->create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $riunione->id,
            'punto_indice' => 1,
        ]);

        // Delibera di ammissione → compare
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Marco', 'cognome' => 'Bianchi',
        ]);
        RichiestaIscrizione::create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
            'stato' => RichiestaIscrizione::STATO_APPROVATA,
            'dati_form' => [],
            'delibera_numero' => '12/2026',
            'delibera_data' => '2026-04-15',
        ]);

        $response = $this->actAsAdmin()->get(route('decisioni.index'))->assertOk();

        $decisioni = $response->viewData('decisioni');
        $this->assertCount(2, $decisioni);
        $response->assertSee('Adesione a rete territoriale');
        $response->assertSee('Marco Bianchi');
        $response->assertSee('12/2026');
    }

    public function test_link_al_verbale_collegato(): void
    {
        $verbale = new Verbale([
            'titolo' => 'Verbale collegato',
            'data_riunione' => '2026-03-01',
            'tipo_riunione' => 'Consiglio Direttivo',
        ]);
        $verbale->associazione_id = $this->associazione->id;
        $verbale->anno = 2026;
        $verbale->numero = 1;
        $verbale->save();
        $riunione = Riunione::factory()->svolta()->create([
            'associazione_id' => $this->associazione->id,
            'verbale_id' => $verbale->id,
            'odg' => [['punto' => 'Approvazione regolamento', 'descrizione' => null]],
        ]);
        VotazioneRiunione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $riunione->id,
            'punto_indice' => 0,
        ]);

        $this->actAsAdmin()
            ->get(route('decisioni.index'))
            ->assertOk()
            ->assertSee(route('verbali.index', ['edit' => $verbale->id]), false);
    }

    public function test_ricerca_per_testo(): void
    {
        $riunione = Riunione::factory()->svolta()->create([
            'associazione_id' => $this->associazione->id,
            'odg' => [
                ['punto' => 'Acquisto furgone', 'descrizione' => null],
                ['punto' => 'Modifica statuto', 'descrizione' => null],
            ],
        ]);
        VotazioneRiunione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $riunione->id, 'punto_indice' => 0,
        ]);
        VotazioneRiunione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $riunione->id, 'punto_indice' => 1,
        ]);

        $response = $this->actAsAdmin()->get(route('decisioni.index', ['search' => 'furgone']))->assertOk();

        $decisioni = $response->viewData('decisioni');
        $this->assertCount(1, $decisioni);
        $this->assertStringContainsString('furgone', mb_strtolower($decisioni[0]['titolo']));
    }

    public function test_filtro_per_anno(): void
    {
        $r2025 = Riunione::factory()->svolta()->create([
            'associazione_id' => $this->associazione->id,
            'data_ora' => '2025-03-01 21:00:00',
            'odg' => [['punto' => 'Punto 2025', 'descrizione' => null]],
        ]);
        $r2026 = Riunione::factory()->svolta()->create([
            'associazione_id' => $this->associazione->id,
            'data_ora' => '2026-03-01 21:00:00',
            'odg' => [['punto' => 'Punto 2026', 'descrizione' => null]],
        ]);
        VotazioneRiunione::factory()->create(['associazione_id' => $this->associazione->id, 'riunione_id' => $r2025->id, 'punto_indice' => 0]);
        VotazioneRiunione::factory()->create(['associazione_id' => $this->associazione->id, 'riunione_id' => $r2026->id, 'punto_indice' => 0]);

        $response = $this->actAsAdmin()->get(route('decisioni.index', ['anno' => 2026]))->assertOk();
        $decisioni = $response->viewData('decisioni');
        $this->assertCount(1, $decisioni);
        $this->assertStringContainsString('2026', $decisioni[0]['titolo']);
    }

    public function test_isolamento_tenant_nelle_decisioni(): void
    {
        $altra = Associazione::factory()->create();
        $riunioneAltrui = Riunione::factory()->svolta()->create([
            'associazione_id' => $altra->id,
            'odg' => [['punto' => 'Decisione segreta', 'descrizione' => null]],
        ]);
        VotazioneRiunione::factory()->create([
            'associazione_id' => $altra->id,
            'riunione_id' => $riunioneAltrui->id, 'punto_indice' => 0,
        ]);

        $response = $this->actAsAdmin()->get(route('decisioni.index'))->assertOk();
        $this->assertCount(0, $response->viewData('decisioni'));
        $response->assertDontSee('Decisione segreta');
    }
}
