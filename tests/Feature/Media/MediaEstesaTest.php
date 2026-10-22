<?php

namespace Tests\Feature\Media;

use App\Models\Associazione;
use App\Models\DocumentoSocio;
use App\Models\Progetto;
use App\Models\Riunione;
use App\Models\Socio;
use App\Models\Utente;
use App\Services\Struttura\LogoAssociazione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaEstesaTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Storage::fake('media_private');

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
    }

    private function adminAltraAssociazione(): Utente
    {
        return Utente::factory()->adminAssociazione()->create(['associazione_id' => Associazione::factory()->create()->id]);
    }

    // ── Logo ─────────────────────────────────────────────────────────

    public function test_logo_caricato_ha_url_assoluto_e_va_nei_pdf(): void
    {
        $logo = app(LogoAssociazione::class);
        $logo->salva($this->associazione, UploadedFile::fake()->image('logo.png', 600, 300));

        $url = (string) $this->associazione->fresh()->logoUrl('miniatura');
        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/').'/storage/', $url);
        $this->assertStringContainsString('conversions', $url);
        $this->assertStringStartsWith('<img src="data:image/', $logo->imgPdf($this->associazione->fresh()));

        // Un nuovo logo sostituisce il precedente
        $logo->salva($this->associazione->fresh(), UploadedFile::fake()->image('nuovo.png', 300, 300));
        $this->assertCount(1, $this->associazione->fresh()->getMedia('logo'));
    }

    public function test_logo_precedente_senza_media_resta_leggibile_e_confinato(): void
    {
        Storage::disk('public')->put('uploads/logos/logo_vecchio.png', UploadedFile::fake()->image('x.png')->getContent());
        $this->associazione->update(['logo_url' => 'uploads/logos/logo_vecchio.png']);
        $logo = app(LogoAssociazione::class);

        $this->assertSame(Storage::disk('public')->url('uploads/logos/logo_vecchio.png'), $this->associazione->logoUrl());
        $this->assertStringStartsWith('<img src="data:image/png', $logo->imgPdf($this->associazione));

        $this->associazione->update(['logo_url' => '../../.env']);
        $this->assertNull($this->associazione->fresh()->logoUrl());
        $this->assertSame('', $logo->imgPdf($this->associazione->fresh()));

        $this->associazione->update(['logo_url' => 'https://cdn.example.com/logo.png']);
        $this->assertSame('https://cdn.example.com/logo.png', $this->associazione->fresh()->logoUrl());
    }

    // ── Comando di migrazione ────────────────────────────────────────

    public function test_migrazione_dei_file_esistenti_idempotente_e_con_prova(): void
    {
        Storage::disk('public')->put('uploads/logos/logo_vecchio.png', UploadedFile::fake()->image('x.png')->getContent());
        $this->associazione->update(['logo_url' => 'uploads/logos/logo_vecchio.png']);

        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        Storage::disk('local')->put('documenti-soci/vecchio.pdf', 'contenuto');
        $documento = DocumentoSocio::create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
            'nome_file' => 'certificato.pdf',
            'percorso_file' => 'documenti-soci/vecchio.pdf',
        ]);
        $orfano = DocumentoSocio::create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
            'nome_file' => 'perso.pdf',
            'percorso_file' => 'documenti-soci/non-esiste.pdf',
        ]);

        $this->artisan('app:migra-media', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('media', 0);

        $this->artisan('app:migra-media')->assertSuccessful();

        $associazione = $this->associazione->fresh();
        $this->assertNotNull($associazione->getFirstMedia('logo'));
        $this->assertSame($associazione->getFirstMedia('logo')->getPathRelativeToRoot(), $associazione->logo_url);
        Storage::disk('public')->assertExists('uploads/logos/logo_vecchio.png'); // logo copiato, non spostato

        $documento->refresh();
        $media = $documento->getFirstMedia(DocumentoSocio::COLLEZIONE_FILE);
        $this->assertNotNull($media);
        $this->assertSame($media->getPathRelativeToRoot(), $documento->percorso_file);
        $this->assertSame('contenuto', Storage::disk('local')->get($documento->percorso_file));
        Storage::disk('local')->assertMissing('documenti-soci/vecchio.pdf'); // documento spostato
        $this->assertNull($orfano->fresh()->getFirstMedia(DocumentoSocio::COLLEZIONE_FILE));

        $this->artisan('app:migra-media')->assertSuccessful();
        $this->assertDatabaseCount('media', 2);
    }

    // ── Documenti del socio ──────────────────────────────────────────

    public function test_il_documento_del_socio_viene_salvato_su_medialibrary(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->post(route('soci.documenti.store', $socio), [
            'file' => UploadedFile::fake()->create('carta.pdf', 50, 'application/pdf'),
            'tipo_documento' => 'altro',
        ])->assertRedirect();

        $documento = DocumentoSocio::query()->firstOrFail();
        $this->assertNotNull($documento->getFirstMedia(DocumentoSocio::COLLEZIONE_FILE));
        Storage::disk('local')->assertExists($documento->percorso_file);

        $this->actingAs($this->admin)->get(route('soci.documenti.download', [$socio, $documento]))->assertOk();
    }

    // ── Foto del socio ───────────────────────────────────────────────

    public function test_foto_del_socio_quadrata_privata_e_nell_archivio_gdpr(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->post(route('soci.foto.store', $socio), [
            'foto' => UploadedFile::fake()->image('ritratto.jpg', 800, 600),
        ])->assertRedirect(route('soci.show', $socio));

        $media = $socio->fresh()->getFirstMedia(Socio::COLLEZIONE_FOTO);
        $this->assertNotNull($media);
        $this->assertSame('media_private', $media->disk);
        [$larghezza, $altezza] = getimagesize($media->getPath('tessera'));
        $this->assertSame([300, 300], [$larghezza, $altezza]);

        $this->actingAs($this->admin)->get(route('soci.foto.show', $socio))->assertOk();
        $this->actingAs($this->adminAltraAssociazione())->get(route('soci.foto.show', $socio))->assertNotFound();
        auth()->forgetGuards();
        $this->get(route('soci.foto.show', $socio))->assertRedirect();

        $archivio = app(\App\Services\Privacy\ArchivioDatiPersonali::class);
        [$zip, $cartella] = $archivio->creaOra($socio->fresh());
        $z = new \ZipArchive;
        $z->open($zip);
        $this->assertNotFalse($z->locateName('foto/'.$media->file_name));
        $z->close();
        $cartella->delete();

        $this->actingAs($this->admin)->delete(route('soci.foto.destroy', $socio))->assertRedirect();
        $this->assertNull($socio->fresh()->getFirstMedia(Socio::COLLEZIONE_FOTO));
    }

    public function test_la_foto_deve_essere_un_immagine(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->post(route('soci.foto.store', $socio), [
            'foto' => UploadedFile::fake()->create('script.php', 5, 'text/x-php'),
        ])->assertSessionHasErrors('foto');
    }

    // ── Allegati ─────────────────────────────────────────────────────

    public function test_allegati_di_riunioni_e_progetti(): void
    {
        $riunione = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);
        $progetto = Progetto::factory()->create(['associazione_id' => $this->associazione->id]);

        foreach (['riunioni' => $riunione, 'progetti' => $progetto] as $tipo => $modello) {
            $this->actingAs($this->admin)->post(route('allegati.store', [$tipo, $modello->id]), [
                'file' => UploadedFile::fake()->create('rendiconto.pdf', 100, 'application/pdf'),
            ])->assertRedirect()->assertSessionHas('success');

            $media = $modello->fresh()->getFirstMedia('allegati');
            $this->assertNotNull($media);
            $this->assertSame('media_private', $media->disk);

            $this->actingAs($this->admin)->get(route('allegati.index', [$tipo, $modello->id]))->assertOk()->assertSee('rendiconto.pdf');
            $this->actingAs($this->admin)->get(route('allegati.download', [$tipo, $modello->id, $media->id]))
                ->assertOk()->assertDownload('rendiconto.pdf');
        }

        $this->actingAs($this->admin)->get(route('riunioni.show', $riunione))->assertOk()->assertSee('rendiconto.pdf');
        $this->actingAs($this->admin)->get(route('progetti.show', $progetto))->assertOk()->assertSee('rendiconto.pdf');
    }

    public function test_allegati_isolati_per_associazione_e_tipo_di_file(): void
    {
        $riunione = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);
        $progetto = Progetto::factory()->create(['associazione_id' => $this->associazione->id]);
        $riunione->addMedia(UploadedFile::fake()->create('verbale.pdf', 10, 'application/pdf'))->toMediaCollection('allegati');
        $media = $riunione->getFirstMedia('allegati');

        $altro = $this->adminAltraAssociazione();
        $this->actingAs($altro)->get(route('allegati.download', ['riunioni', $riunione->id, $media->id]))->assertNotFound();
        $this->actingAs($altro)->post(route('allegati.store', ['riunioni', $riunione->id]), [
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertNotFound();

        // Media di un altro modello non raggiungibile cambiando l'URL
        $this->actingAs($this->admin)->get(route('allegati.download', ['progetti', $progetto->id, $media->id]))->assertNotFound();

        // Tipo non previsto e file non ammesso
        $this->actingAs($this->admin)->get('/allegati/utenti/'.$this->admin->id)->assertNotFound();
        $this->actingAs($this->admin)->post(route('allegati.store', ['riunioni', $riunione->id]), [
            'file' => UploadedFile::fake()->create('shell.php', 1, 'text/x-php'),
        ])->assertSessionHasErrors('file');

        $this->actingAs($this->admin)->delete(route('allegati.destroy', ['riunioni', $riunione->id, $media->id]))->assertRedirect();
        $this->assertNull($riunione->fresh()->getFirstMedia('allegati'));
    }
}
