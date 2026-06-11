<?php

namespace Tests\Feature\DocumentiVerbaliScadenze;

use App\Models\Associazione;
use App\Models\Documento;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentiTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('documenti.index'))->assertRedirect('/login');
    }

    public function test_index_mostra_solo_i_documenti_del_proprio_tenant(): void
    {
        $altraAssociazione = Associazione::factory()->create();

        Documento::create([
            'associazione_id' => $this->associazione->id,
            'nome_file' => 'statuto-mio.pdf',
            'percorso_file' => 'x',
        ]);
        Documento::create([
            'associazione_id' => $altraAssociazione->id,
            'nome_file' => 'statuto-altrui.pdf',
            'percorso_file' => 'y',
        ]);

        $response = $this->actingAs($this->admin)->get(route('documenti.index'));

        $response->assertOk();
        $response->assertSee('statuto-mio.pdf');
        $response->assertDontSee('statuto-altrui.pdf');
    }

    public function test_upload_crea_documento_con_media_e_caricato_da(): void
    {
        $file = UploadedFile::fake()->create('regolamento.pdf', 200, 'application/pdf');

        $response = $this->actingAs($this->admin)->post(route('documenti.store'), [
            'document_file' => $file,
            'categoria' => 'Regolamenti',
            'descrizione' => 'Regolamento interno',
        ]);

        $response->assertRedirect(route('documenti.index'));
        $response->assertSessionHas('success');

        $documento = Documento::query()->first();
        $this->assertNotNull($documento);
        $this->assertSame('regolamento.pdf', $documento->nome_file);
        $this->assertSame('Regolamenti', $documento->categoria);
        $this->assertSame($this->admin->id, $documento->caricato_da);
        $this->assertNotSame('', $documento->percorso_file);

        $media = $documento->getFirstMedia(Documento::COLLEZIONE_FILE);
        $this->assertNotNull($media);
        $this->assertSame('local', $media->disk);
        // Nome fisico random, mai quello fornito dall'utente
        $this->assertStringNotContainsString('regolamento', $media->file_name);
    }

    public function test_upload_rifiuta_estensione_non_permessa(): void
    {
        $file = UploadedFile::fake()->create('script.php', 10, 'text/x-php');

        $response = $this->actingAs($this->admin)
            ->from(route('documenti.index'))
            ->post(route('documenti.store'), ['document_file' => $file]);

        $response->assertSessionHasErrors('document_file');
        $this->assertSame(0, Documento::query()->count());
    }

    public function test_upload_rifiuta_file_oltre_10mb(): void
    {
        $file = UploadedFile::fake()->create('enorme.pdf', 11000, 'application/pdf');

        $response = $this->actingAs($this->admin)
            ->from(route('documenti.index'))
            ->post(route('documenti.store'), ['document_file' => $file]);

        $response->assertSessionHasErrors('document_file');
    }

    public function test_download_autenticato_restituisce_il_file(): void
    {
        $documento = $this->caricaDocumento();

        $response = $this->actingAs($this->admin)->get(route('documenti.download', $documento));

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename=verbale.pdf');
    }

    public function test_download_di_documento_di_altro_tenant_restituisce_404(): void
    {
        $documento = $this->caricaDocumento();

        $altroAdmin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => Associazione::factory()->create()->id,
        ]);

        $this->actingAs($altroAdmin)
            ->get(route('documenti.download', ['documento' => $documento->id]))
            ->assertNotFound();
    }

    public function test_link_firmato_temporaneo_consente_il_download_senza_login(): void
    {
        $documento = $this->caricaDocumento();

        $response = $this->actingAs($this->admin)
            ->from(route('documenti.index'))
            ->post(route('documenti.link-temporaneo', $documento));

        $response->assertSessionHas('link_temporaneo');
        $url = session('link_temporaneo');

        // Da ospite (nuova "sessione"): il link firmato funziona
        $this->post('/logout');
        $this->get($url)->assertOk();
    }

    public function test_download_pubblico_senza_firma_valida_restituisce_403(): void
    {
        $documento = $this->caricaDocumento();

        $this->get(route('documenti.download-pubblico', ['id' => $documento->id]))
            ->assertForbidden();
    }

    public function test_destroy_sposta_nel_cestino_e_ripristina_recupera(): void
    {
        $documento = $this->caricaDocumento();

        $this->actingAs($this->admin)
            ->delete(route('documenti.destroy', $documento))
            ->assertRedirect(route('documenti.index'));

        $this->assertSoftDeleted('documenti', ['id' => $documento->id]);

        $this->actingAs($this->admin)
            ->post(route('documenti.ripristina', $documento->id))
            ->assertRedirect(route('documenti.index'));

        $this->assertDatabaseHas('documenti', ['id' => $documento->id, 'deleted_at' => null]);
    }

    public function test_eliminazione_definitiva_rimuove_record_e_file(): void
    {
        $documento = $this->caricaDocumento();
        $documento->delete();

        $this->actingAs($this->admin)
            ->delete(route('documenti.elimina-definitivamente', $documento->id))
            ->assertRedirect(route('documenti.cestino'));

        $this->assertDatabaseMissing('documenti', ['id' => $documento->id]);
        $this->assertDatabaseMissing('media', ['model_id' => $documento->id]);
    }

    public function test_operatore_non_puo_eliminare_documenti(): void
    {
        $documento = $this->caricaDocumento();

        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)
            ->delete(route('documenti.destroy', $documento))
            ->assertForbidden();
    }

    public function test_filtro_per_categoria_e_ricerca_unificati(): void
    {
        Documento::create([
            'associazione_id' => $this->associazione->id,
            'nome_file' => 'statuto.pdf',
            'percorso_file' => 'x',
            'categoria' => 'Statuti',
        ]);
        Documento::create([
            'associazione_id' => $this->associazione->id,
            'nome_file' => 'verbale-2024.pdf',
            'percorso_file' => 'y',
            'categoria' => 'Verbali',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('documenti.index', ['search' => 'statuto', 'category' => 'Statuti']));

        $response->assertOk();
        $response->assertSee('statuto.pdf');
        $response->assertDontSee('verbale-2024.pdf');
    }

    private function caricaDocumento(): Documento
    {
        $this->actingAs($this->admin)->post(route('documenti.store'), [
            'document_file' => UploadedFile::fake()->create('verbale.pdf', 50, 'application/pdf'),
        ]);

        return Documento::query()->latest('created_at')->firstOrFail();
    }
}
