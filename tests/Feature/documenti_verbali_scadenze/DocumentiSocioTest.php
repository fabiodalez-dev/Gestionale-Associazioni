<?php

namespace Tests\Feature\DocumentiVerbaliScadenze;

use App\Models\Associazione;
use App\Models\DocumentoSocio;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentiSocioTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    private Socio $socio;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('documenti-socio.index'))->assertRedirect('/login');
    }

    public function test_upload_documento_per_socio_del_tenant(): void
    {
        $response = $this->actingAs($this->admin)->post(route('documenti-socio.store'), [
            'socio_id' => $this->socio->id,
            'document_file' => UploadedFile::fake()->create('certificato.pdf', 80, 'application/pdf'),
            'descrizione' => 'Certificato medico',
            'data_scadenza' => now()->addYear()->toDateString(),
        ]);

        $response->assertRedirect(route('documenti-socio.index', ['socio_id' => $this->socio->id]));
        $response->assertSessionHas('success');

        $documento = DocumentoSocio::query()->first();
        $this->assertNotNull($documento);
        $this->assertSame($this->socio->id, $documento->socio_id);
        $this->assertSame($this->admin->id, $documento->caricato_da);
        $this->assertNotNull($documento->getFirstMedia(DocumentoSocio::COLLEZIONE_FILE));
    }

    public function test_upload_rifiuta_socio_di_altro_tenant(): void
    {
        $socioAltrui = Socio::factory()->create([
            'associazione_id' => Associazione::factory()->create()->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->from(route('documenti-socio.index'))
            ->post(route('documenti-socio.store'), [
                'socio_id' => $socioAltrui->id,
                'document_file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            ]);

        $response->assertSessionHasErrors('socio_id');
        $this->assertSame(0, DocumentoSocio::allTenants()->count());
    }

    public function test_index_filtra_per_socio(): void
    {
        $altroSocio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        DocumentoSocio::create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $this->socio->id,
            'nome_file' => 'certificato-rossi.pdf',
            'percorso_file' => 'a',
        ]);
        DocumentoSocio::create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $altroSocio->id,
            'nome_file' => 'certificato-verdi.pdf',
            'percorso_file' => 'b',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('documenti-socio.index', ['socio_id' => $this->socio->id]));

        $response->assertOk();
        $response->assertSee('certificato-rossi.pdf');
        $response->assertDontSee('certificato-verdi.pdf');
    }

    public function test_download_con_fallback_su_percorso_file(): void
    {
        // Record senza media (es. caricato dal modulo soci o migrato dal legacy)
        Storage::disk('local')->put('documenti-soci/test.pdf', 'contenuto-pdf');

        $documento = DocumentoSocio::create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $this->socio->id,
            'nome_file' => 'vecchio.pdf',
            'percorso_file' => 'documenti-soci/test.pdf',
        ]);

        $response = $this->actingAs($this->admin)->get(route('documenti-socio.download', $documento));

        $response->assertOk();
        $response->assertDownload('vecchio.pdf');
    }

    public function test_link_firmato_temporaneo_per_documento_socio(): void
    {
        $this->actingAs($this->admin)->post(route('documenti-socio.store'), [
            'socio_id' => $this->socio->id,
            'document_file' => UploadedFile::fake()->create('tessera.pdf', 10, 'application/pdf'),
        ]);

        $documento = DocumentoSocio::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->from(route('documenti-socio.index'))
            ->post(route('documenti-socio.link-temporaneo', $documento))
            ->assertSessionHas('link_temporaneo');

        $url = session('link_temporaneo');
        $this->post('/logout');
        $this->get($url)->assertOk();
    }

    public function test_destroy_soft_delete_e_ripristino(): void
    {
        $documento = DocumentoSocio::create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $this->socio->id,
            'nome_file' => 'doc.pdf',
            'percorso_file' => 'x',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('documenti-socio.destroy', $documento))
            ->assertRedirect();

        $this->assertSoftDeleted('documenti_socio', ['id' => $documento->id]);

        $this->actingAs($this->admin)
            ->post(route('documenti-socio.ripristina', $documento->id))
            ->assertRedirect();

        $this->assertDatabaseHas('documenti_socio', ['id' => $documento->id, 'deleted_at' => null]);
    }
}
