<?php

namespace Tests\Feature\Soci;

use App\Models\Associazione;
use App\Models\GruppoDinamico;
use App\Models\SavedFilter;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SociFiltriTest extends TestCase
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

    private function socio(array $attributes = []): Socio
    {
        return Socio::factory()->create(array_merge(['associazione_id' => $this->associazione->id], $attributes));
    }

    public function test_default_lista_mostra_solo_soci_con_tessera_attiva(): void
    {
        $conTessera = $this->socio(['cognome' => 'Contessera']);
        Tessera::factory()->create(['socio_id' => $conTessera->id]);

        $this->socio(['cognome' => 'Senzatessera']);

        $scaduto = $this->socio(['cognome' => 'Scadutone']);
        Tessera::factory()->scaduta()->create(['socio_id' => $scaduto->id]);

        $this->actingAs($this->admin)->get('/soci')
            ->assertOk()
            ->assertSee('Contessera')
            ->assertDontSee('Senzatessera')
            ->assertDontSee('Scadutone');

        // Con tessera_stato=all compaiono tutti
        $this->actingAs($this->admin)->get('/soci?tessera_stato=all')
            ->assertOk()
            ->assertSee('Contessera')
            ->assertSee('Senzatessera')
            ->assertSee('Scadutone');
    }

    public function test_filtro_stato_pending_salta_il_filtro_tessera(): void
    {
        $this->socio(['cognome' => 'Pendolino', 'stato' => Socio::STATO_IN_ATTESA]);

        // Nessuna tessera, ma con status=In Attesa il filtro tessera viene saltato (spec §1.9)
        $this->actingAs($this->admin)->get('/soci?status=In Attesa')
            ->assertOk()
            ->assertSee('Pendolino');
    }

    public function test_ricerca_full_text_su_nome_email_e_numero_socio(): void
    {
        // Nomi fissati: con Faker it_IT un nome casuale può collidere con i
        // cognomi usati nelle assertDontSee (es. nome di battesimo "Neri")
        $target = $this->socio(['nome' => 'Carla', 'cognome' => 'Bianchessi', 'email' => 'unica@example.com', 'numero_socio' => '042']);
        Tessera::factory()->create(['socio_id' => $target->id]);

        $altro = $this->socio(['nome' => 'Ugo', 'cognome' => 'Neri']);
        Tessera::factory()->create(['socio_id' => $altro->id]);

        foreach (['Bianchessi', 'unica@example.com', '042'] as $termine) {
            $response = $this->actingAs($this->admin)->get('/soci?ajax=1&search='.urlencode($termine));

            $response->assertOk();
            $this->assertSame(1, $response->json('count'), "Ricerca '{$termine}'");
            $this->assertStringContainsString('Bianchessi', $response->json('html'));
            $this->assertStringNotContainsString('Neri', $response->json('html'));
        }
    }

    public function test_gruppo_dinamico_esclusivo_applica_solo_i_filtri_del_gruppo(): void
    {
        $milanese = $this->socio(['cognome' => 'Milanesi', 'citta' => 'Milano']);
        $romano = $this->socio(['cognome' => 'Romanetti', 'citta' => 'Roma']);
        Tessera::factory()->create(['socio_id' => $milanese->id]);
        Tessera::factory()->create(['socio_id' => $romano->id]);

        $gruppo = GruppoDinamico::create([
            'associazione_id' => $this->associazione->id,
            'nome_gruppo' => 'Milanesi',
            'filtri_json' => ['citta' => 'Milano'],
        ]);

        // Il gruppo ignora gli altri filtri (search inclusa) — spec §1.9.
        // Si ispeziona solo l'HTML della lista (via ajax) perché la pagina
        // intera riecheggia il valore di search nel campo del form.
        $response = $this->actingAs($this->admin)
            ->get('/soci?ajax=1&gruppo_id='.$gruppo->id.'&search=Romanetti');

        $response->assertOk();
        $this->assertSame(1, $response->json('count'));
        $this->assertStringContainsString('Milanesi', $response->json('html'));
        $this->assertStringNotContainsString('Romanetti', $response->json('html'));
    }

    public function test_risposta_ajax_restituisce_count_e_html(): void
    {
        $socio = $this->socio(['cognome' => 'Ajaxone']);
        Tessera::factory()->create(['socio_id' => $socio->id]);

        $response = $this->actingAs($this->admin)->get('/soci?ajax=1&tessera_stato=all');

        $response->assertOk()->assertJsonStructure(['count', 'html']);
        $this->assertSame(1, $response->json('count'));
        $this->assertStringContainsString('Ajaxone', $response->json('html'));
    }

    public function test_preset_filtri_upsert_caricamento_ed_eliminazione(): void
    {
        // Salvataggio
        $this->actingAs($this->admin)->post('/soci/filtri', [
            'preset_name' => 'Sospesi',
            'status' => 'Sospeso',
            'tessera_stato' => 'all',
        ])->assertRedirect();

        $preset = SavedFilter::query()->where('name', 'Sospesi')->first();
        $this->assertNotNull($preset);
        $this->assertSame('Sospeso', $preset->params_json['status']);

        // Stesso nome = upsert (spec §11.2)
        $this->actingAs($this->admin)->post('/soci/filtri', [
            'preset_name' => 'Sospesi',
            'status' => 'Attivo',
            'tessera_stato' => 'all',
        ]);

        $this->assertSame(1, SavedFilter::query()->where('name', 'Sospesi')->count());
        $this->assertSame('Attivo', $preset->refresh()->params_json['status']);

        // Caricamento: applica i filtri del preset
        $sospeso = $this->socio(['cognome' => 'Sospesini', 'stato' => Socio::STATO_SOSPESO]);
        Tessera::factory()->create(['socio_id' => $sospeso->id]);
        $attivo = $this->socio(['cognome' => 'Attivetti']);
        Tessera::factory()->create(['socio_id' => $attivo->id]);

        $this->actingAs($this->admin)->get('/soci?preset_id='.$preset->id)
            ->assertOk()
            ->assertSee('Attivetti')
            ->assertDontSee('Sospesini');

        // Eliminazione
        $this->actingAs($this->admin)
            ->delete('/soci/filtri/'.$preset->id)
            ->assertRedirect(route('soci.index'));

        $this->assertDatabaseMissing('saved_filters', ['id' => $preset->id]);
    }

    public function test_autocomplete_restituisce_solo_soci_attivi_del_tenant(): void
    {
        $this->socio(['nome' => 'Mario', 'cognome' => 'Rossini']);
        $this->socio(['nome' => 'Luigi', 'cognome' => 'Rossini', 'stato' => Socio::STATO_SOSPESO]);
        Socio::factory()->create(['nome' => 'Anna', 'cognome' => 'Rossini']); // altro tenant

        $response = $this->actingAs($this->admin)->getJson('/soci/search?q=Rossini');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('Rossini Mario', $response->json('0.nome_completo'));

        // Funziona anche con "Nome Cognome" (spec §12)
        $this->actingAs($this->admin)->getJson('/soci/search?q=Mario Rossini')
            ->assertOk()
            ->assertJsonCount(1);

        // q vuota → array vuoto
        $this->actingAs($this->admin)->getJson('/soci/search?q=')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_export_excel_e_csv(): void
    {
        $socio = $this->socio();
        Tessera::factory()->create(['socio_id' => $socio->id]);

        $this->actingAs($this->admin)->get('/soci/export?format=xlsx&tessera_stato=all')
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->actingAs($this->admin)->get('/soci/export?format=csv&tessera_stato=all')
            ->assertOk()
            ->assertHeader('content-disposition');
    }
}
