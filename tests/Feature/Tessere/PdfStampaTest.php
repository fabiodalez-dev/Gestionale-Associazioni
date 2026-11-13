<?php

namespace Tests\Feature\Tessere;

use App\Models\Associazione;
use App\Models\CategoriaSocio;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\TesseraTemplate;
use App\Models\TipoSocio;
use App\Services\Struttura\LogoAssociazione;
use App\Services\Tessere\TesseraPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PdfStampaTest extends TessereTestCase
{
    use RefreshDatabase;

    public function test_emissione_on_demand_crea_la_tessera_e_redirige_al_pdf(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);

        $response = $this->actingAs($admin)->post('/soci/'.$socio->id.'/tessere');

        $tessera = Tessera::allTenants()->where('socio_id', $socio->id)->firstOrFail();
        $response->assertRedirect(route('tessere.pdf', $tessera));

        $this->assertSame((int) now()->year, $tessera->anno_validita);
        $this->assertSame('Attiva', $tessera->stato);
        $this->assertSame((int) now()->year.'0001', $tessera->numero_tessera);
        $this->assertNotEmpty($tessera->qr_code_url);
        $this->assertSame('Default', $tessera->template_tessera);
    }

    public function test_emissione_on_demand_non_duplica_se_la_tessera_esiste(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);

        $esistente = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $assoc->id,
            'anno_validita' => (int) now()->year,
        ]);

        $this->actingAs($admin)
            ->post('/soci/'.$socio->id.'/tessere')
            ->assertRedirect(route('tessere.pdf', $esistente));

        $this->assertSame(1, Tessera::allTenants()->where('socio_id', $socio->id)->count());
    }

    public function test_download_pdf_della_tessera(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);

        $tipo = TipoSocio::create([
            'associazione_id' => $assoc->id,
            'nome' => 'Ordinario',
            'costo_tessera' => 25.00,
        ]);
        $socio = Socio::factory()->create([
            'associazione_id' => $assoc->id,
            'tipo_socio_id' => $tipo->id,
        ]);
        $tessera = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $assoc->id,
        ]);

        TesseraTemplate::create([
            'associazione_id' => $assoc->id,
            'tipo_socio_id' => $tipo->id,
            'titolo' => 'Template tipo',
            'contenuto' => 'Socio {NOME_COMPLETO} - tessera {NUMERO_TESSERA} valida fino al {DATA_SCADENZA}',
            'attivo' => true,
        ]);

        $response = $this->actingAs($admin)->get('/tessere/'.$tessera->id.'/pdf');

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_pdf_di_tessera_di_altra_associazione_404(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();
        $adminA = $this->creaAdmin($assocA);

        $tesseraB = Tessera::factory()->create([
            'socio_id' => Socio::factory()->create(['associazione_id' => $assocB->id])->id,
            'associazione_id' => $assocB->id,
        ]);

        $this->actingAs($adminA)->get('/tessere/'.$tesseraB->id.'/pdf')->assertNotFound();
    }

    public function test_stampa_card_html(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);
        $tessera = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $assoc->id,
        ]);

        $response = $this->actingAs($admin)->get('/tessere/'.$tessera->id.'/stampa');

        $response->assertOk();
        $response->assertSee($tessera->numero_tessera);
        $response->assertSee($socio->nome);
        $response->assertSee('Tessera Associativa Personale');
        $response->assertSee('data:image/png;base64', false); // QR data URI
    }

    public function test_template_attivo_specifico_ha_precedenza_e_il_disattivo_viene_ignorato(): void
    {
        $assoc = Associazione::factory()->create();
        $tipo = TipoSocio::create([
            'associazione_id' => $assoc->id,
            'nome' => 'Sostenitore',
        ]);
        $categoria = CategoriaSocio::create([
            'associazione_id' => $assoc->id,
            'nome' => 'Categoria Base',
            'testo_tessera' => 'Testo categoria di fallback',
        ]);
        $socio = Socio::factory()->create([
            'associazione_id' => $assoc->id,
            'tipo_socio_id' => $tipo->id,
            'categoria_socio_id' => $categoria->id,
        ]);
        $tessera = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $assoc->id,
        ]);

        // Template specifico ma DISATTIVO → si usa il fallback categoria (fix flag legacy)
        TesseraTemplate::create([
            'associazione_id' => $assoc->id,
            'tipo_socio_id' => $tipo->id,
            'titolo' => 'Disattivo',
            'contenuto' => 'NON DEVE APPARIRE',
            'attivo' => false,
        ]);

        $renderer = app(TesseraPdfRenderer::class);
        $testo = $renderer->testoTessera($tessera, $socio, $assoc, '', '');
        $this->assertStringContainsString('Testo categoria di fallback', $testo);
        $this->assertStringNotContainsString('NON DEVE APPARIRE', $testo);

        // Template specifico ATTIVO → vince su generico e categoria
        TesseraTemplate::create([
            'associazione_id' => $assoc->id,
            'tipo_socio_id' => null,
            'titolo' => 'Generico',
            'contenuto' => 'Testo generico',
            'attivo' => true,
        ]);
        TesseraTemplate::query()->where('titolo', 'Disattivo')->update(['attivo' => true, 'contenuto' => 'Testo specifico {NUMERO_TESSERA}']);

        $testo = $renderer->testoTessera($tessera->fresh(), $socio, $assoc, '', '');
        $this->assertStringContainsString('Testo specifico '.$tessera->numero_tessera, $testo);
    }

    /**
     * dompdf gira senza fetch remoto (isRemoteEnabled=false): il logo entra
     * nel PDF solo come data URI letto da un file locale. Un logo_url legacy
     * http(s) è usato solo se punta a un file di questa installazione.
     */
    public function test_il_pdf_usa_solo_loghi_locali_senza_fetch_remoto(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $logo = app(LogoAssociazione::class);

        Storage::disk('public')->put('uploads/logos/logo_pdf_test.png', UploadedFile::fake()->image('logo.png', 40, 40)->getContent());

        // Percorso locale legacy → data URI e PDF generato
        $assoc->update(['logo_url' => 'uploads/logos/logo_pdf_test.png']);
        $this->assertStringStartsWith('<img src="data:image/png;base64,', $logo->imgPdf($assoc));

        $tessera = Tessera::factory()->create([
            'socio_id' => Socio::factory()->create(['associazione_id' => $assoc->id])->id,
            'associazione_id' => $assoc->id,
        ]);
        $response = $this->actingAs($admin)->get('/tessere/'.$tessera->id.'/pdf');
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        // URL http(s) di questa installazione → convertito nello stesso file locale
        $assoc->update(['logo_url' => Storage::disk('public')->url('uploads/logos/logo_pdf_test.png')]);
        $this->assertStringStartsWith('<img src="data:image/png;base64,', $logo->imgPdf($assoc->fresh()));

        // URL esterno → nessun <img> remoto nel PDF
        $assoc->update(['logo_url' => 'https://cdn.example.com/logo.png']);
        $this->assertSame('', $logo->imgPdf($assoc->fresh()));

        Storage::disk('public')->delete('uploads/logos/logo_pdf_test.png');
    }
}
