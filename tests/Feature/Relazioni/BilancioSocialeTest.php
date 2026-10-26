<?php

namespace Tests\Feature\Relazioni;

use App\Models\Associazione;
use App\Models\BilancioSociale;
use App\Models\Carica;
use App\Models\Cessazione;
use App\Models\Documento;
use App\Models\Movimento;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Socio;
use App\Models\Utente;
use App\Models\Verbale;
use App\Services\Relazioni\BilancioSocialeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bilancio sociale annuale (issue #15): dati calcolati dal gestionale per
 * sezione, testi conservati alla rigenerazione, approvazione con il verbale,
 * PDF e pubblicazione in Trasparenza.
 */
class BilancioSocialeTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->associazione = Associazione::factory()->create(['nome' => 'Amici del Parco']);
        $this->admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
    }

    private function actAsAdmin(): self
    {
        $this->actingAs($this->admin)->withSession(['associazione_id' => $this->associazione->id]);

        return $this;
    }

    public function test_crea_il_bilancio_con_i_dati_dell_anno(): void
    {
        $anno = (int) now()->year - 1;

        // Soci: due iscritti entro l'anno, uno cessato nell'anno, uno iscritto dopo
        $restato = Socio::factory()->create(['associazione_id' => $this->associazione->id, 'data_iscrizione' => "{$anno}-03-01"]);
        Socio::factory()->create(['associazione_id' => $this->associazione->id, 'data_iscrizione' => ($anno - 2).'-05-10']);
        $cessato = Socio::factory()->create(['associazione_id' => $this->associazione->id, 'data_iscrizione' => ($anno - 1).'-01-10']);
        Cessazione::factory()->create(['associazione_id' => $this->associazione->id, 'socio_id' => $cessato->id, 'data' => "{$anno}-06-30"]);
        Socio::factory()->create(['associazione_id' => $this->associazione->id, 'data_iscrizione' => ($anno + 1).'-01-15']);

        Carica::factory()->create(['associazione_id' => $this->associazione->id, 'socio_id' => $restato->id, 'ruolo' => 'Presidente', 'inizio' => "{$anno}-01-01"]);

        // Un'assemblea svolta con 1 presente su 2 aventi diritto
        $assemblea = Riunione::factory()->assemblea()->create(['associazione_id' => $this->associazione->id, 'data_ora' => "{$anno}-04-20 18:00", 'stato' => Riunione::STATO_SVOLTA]);
        foreach ([RiunionePartecipante::STATO_CONFERMATO, RiunionePartecipante::STATO_INVITATO] as $stato) {
            RiunionePartecipante::factory()->create(['associazione_id' => $this->associazione->id, 'riunione_id' => $assemblea->id, 'socio_id' => Socio::factory()->create(['associazione_id' => $this->associazione->id])->id, 'carica_ruolo' => null, 'stato' => $stato]);
        }

        Movimento::factory()->create(['associazione_id' => $this->associazione->id, 'tipo' => Movimento::TIPO_ENTRATA, 'importo' => 500, 'data' => "{$anno}-02-01"]);
        Movimento::factory()->create(['associazione_id' => $this->associazione->id, 'tipo' => Movimento::TIPO_USCITA, 'importo' => 200, 'data' => "{$anno}-09-01"]);

        $this->actAsAdmin()->post(route('bilancio-sociale.store'), ['anno' => $anno])->assertSessionHas('success');

        $bilancio = BilancioSociale::query()->firstOrFail();
        $dati = $bilancio->dati;

        $this->assertSame('Amici del Parco', $dati['ente']['nome']);
        $this->assertSame(1, $dati['governo']['assemblee']);
        $this->assertEquals(50.0, $dati['governo']['partecipazione_assemblee']);
        $this->assertSame([['ruolo' => 'Presidente', 'nome' => $restato->nome_completo]], $dati['governo']['cariche']);
        // Al 31/12: il socio iscritto nell'anno e quello di due anni prima (il cessato
        // no, i partecipanti all'assemblea sono iscritti oggi, dopo l'anno)
        $this->assertSame(2, $dati['persone']['soci_fine_anno']);
        $this->assertSame(1, $dati['persone']['cessazioni']);
        $this->assertEquals(500.0, $dati['economia']['entrate']);
        $this->assertEquals(300.0, $dati['economia']['saldo']);

        $this->actAsAdmin()->get(route('bilancio-sociale.show', $bilancio))
            ->assertOk()
            ->assertSee('Struttura, governo e amministrazione')
            ->assertSee('partecipazione media del 50');
    }

    public function test_un_bilancio_per_anno_e_anni_futuri_rifiutati(): void
    {
        $anno = (int) now()->year - 1;
        $this->actAsAdmin()->post(route('bilancio-sociale.store'), ['anno' => $anno]);
        $this->actAsAdmin()->post(route('bilancio-sociale.store'), ['anno' => $anno])->assertSessionHas('warning');
        $this->actAsAdmin()->post(route('bilancio-sociale.store'), ['anno' => now()->year + 1])->assertSessionHasErrors('anno');

        $this->assertSame(1, BilancioSociale::query()->count());
    }

    public function test_i_testi_restano_quando_si_rigenerano_i_dati(): void
    {
        $bilancio = app(BilancioSocialeService::class)->genera($this->associazione, (int) now()->year - 1);

        $this->actAsAdmin()->put(route('bilancio-sociale.update', $bilancio), [
            'testi' => ['ente' => 'La nostra missione è curare il parco.', 'sezione_inventata' => 'x'],
        ])->assertSessionHas('success');

        Socio::factory()->create(['associazione_id' => $this->associazione->id, 'data_iscrizione' => (now()->year - 1).'-02-02']);
        $this->actAsAdmin()->post(route('bilancio-sociale.rigenera', $bilancio))->assertSessionHas('success');

        $bilancio->refresh();
        $this->assertSame('La nostra missione è curare il parco.', $bilancio->testo('ente'));
        $this->assertArrayNotHasKey('sezione_inventata', $bilancio->testi);
        $this->assertSame(1, $bilancio->dati['persone']['soci_fine_anno']);
    }

    public function test_approvazione_pdf_e_pubblicazione_in_trasparenza(): void
    {
        $bilancio = app(BilancioSocialeService::class)->genera($this->associazione, (int) now()->year - 1);
        $verbale = new Verbale([
            'titolo' => 'Assemblea di bilancio',
            'data_riunione' => now()->toDateString(),
            'tipo_riunione' => 'Assemblea Ordinaria',
        ]);
        $verbale->associazione_id = $this->associazione->id;
        $verbale->anno = (int) now()->year;
        $verbale->numero = Verbale::prossimoNumero((int) now()->year);
        $verbale->save();

        // Non si pubblica una bozza
        $this->actAsAdmin()->post(route('bilancio-sociale.pubblica', $bilancio))->assertSessionHas('warning');

        $this->actAsAdmin()->post(route('bilancio-sociale.approva', $bilancio), ['verbale_id' => $verbale->id])->assertSessionHas('success');
        $bilancio->refresh();
        $this->assertSame(BilancioSociale::STATO_APPROVATO, $bilancio->stato);
        $this->assertSame($verbale->id, $bilancio->verbale_id);

        // Approvato: i dati non si rigenerano più
        $this->actAsAdmin()->post(route('bilancio-sociale.rigenera', $bilancio))->assertSessionHas('warning');

        $this->actAsAdmin()->get(route('bilancio-sociale.pdf', $bilancio))->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->actAsAdmin()->post(route('bilancio-sociale.pubblica', $bilancio))->assertSessionHas('success');
        $bilancio->refresh();
        $this->assertSame(BilancioSociale::STATO_PUBBLICATO, $bilancio->stato);

        $documento = Documento::query()->findOrFail($bilancio->documento_id);
        $this->assertTrue($documento->pubblico);
        $this->assertSame('bilancio', $documento->categoria_pubblica);
        $this->assertNotNull($documento->getFirstMedia(Documento::COLLEZIONE_FILE));

        // Pubblicato: non torna in bozza
        $this->actAsAdmin()->post(route('bilancio-sociale.riapri', $bilancio))->assertSessionHas('warning');
    }

    public function test_permessi_e_isolamento(): void
    {
        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($operatore)->withSession(['associazione_id' => $this->associazione->id])
            ->post(route('bilancio-sociale.store'), ['anno' => now()->year - 1])->assertForbidden();

        $altra = Associazione::factory()->create();
        $estraneo = BilancioSociale::withoutEvents(fn () => BilancioSociale::create(['associazione_id' => $altra->id, 'anno' => 2024]));
        $this->actAsAdmin()->get(route('bilancio-sociale.show', $estraneo))->assertNotFound();
    }
}
