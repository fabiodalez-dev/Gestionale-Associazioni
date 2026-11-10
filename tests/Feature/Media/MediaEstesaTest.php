<?php

namespace Tests\Feature\Media;

use App\Models\Associazione;
use App\Models\DocumentoSocio;
use App\Models\Progetto;
use App\Models\Riunione;
use App\Models\Socio;
use App\Models\Utente;
use App\Services\Privacy\ArchivioDatiPersonali;
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

    public function test_l_estensione_salvata_viene_dal_contenuto_non_dal_nome(): void
    {
        // Immagine valida con nome .html: sul disco pubblico non deve mai diventare HTML
        app(LogoAssociazione::class)->salva($this->associazione, UploadedFile::fake()->image('attacco.html', 200, 200));
        $this->assertStringEndsWith('.png', $this->associazione->fresh()->getFirstMedia('logo')->file_name);

        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($this->admin)->post(route('soci.documenti.store', $socio), [
            'file' => UploadedFile::fake()->image('documento.png', 100, 100),
            'tipo_documento' => 'altro',
        ]);
        $this->assertStringEndsWith('.png', DocumentoSocio::query()->firstOrFail()->getFirstMedia('file')->file_name);
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

        $archivio = app(ArchivioDatiPersonali::class);
        [$zip, $cartella] = $archivio->creaOra($socio->fresh());
        $z = new \ZipArchive;
        $z->open($zip);
        $this->assertNotFalse($z->locateName('foto/'.$media->file_name));
        $z->close();
        $cartella->delete();

        $this->actingAs($this->admin)->delete(route('soci.foto.destroy', $socio))->assertRedirect();
        $this->assertNull($socio->fresh()->getFirstMedia(Socio::COLLEZIONE_FOTO));
    }

    public function test_foto_dal_telefono_raddrizzata_ritagliata_in_4_5_e_senza_exif(): void
    {
        // Foto "di lato" come la salvano i telefoni: pixel orizzontali (sinistra
        // rossa, destra blu) con l'indicazione EXIF di ruotarla di 90° (Orientation 6)
        $img = imagecreatetruecolor(800, 600);
        imagefilledrectangle($img, 0, 0, 399, 599, (int) imagecolorallocate($img, 255, 0, 0));
        imagefilledrectangle($img, 400, 0, 799, 599, (int) imagecolorallocate($img, 0, 0, 255));
        ob_start();
        imagejpeg($img, null, 95);
        $jpeg = (string) ob_get_clean();
        // Segmento APP1 Exif minimo, big endian: un solo tag Orientation = 6
        $tiff = "MM\x00\x2a\x00\x00\x00\x08"."\x00\x01"."\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00"."\x00\x00\x00\x00";
        $app1 = "\xFF\xE1".pack('n', strlen("Exif\x00\x00".$tiff) + 2)."Exif\x00\x00".$tiff;
        $percorso = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
        file_put_contents($percorso, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));
        $this->assertSame(6, exif_read_data($percorso)['Orientation'] ?? null, 'Il file di prova ha l\'EXIF');

        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($this->admin)->post(route('soci.foto.store', $socio), [
            'foto' => new UploadedFile($percorso, 'IMG_0001.jpg', 'image/jpeg', null, true),
        ])->assertRedirect(route('soci.show', $socio));

        $originale = $socio->fresh()->getFirstMedia(Socio::COLLEZIONE_FOTO)->getPath();
        [$larghezza, $altezza] = getimagesize($originale);
        $this->assertSame([480, 600], [$larghezza, $altezza]);
        $this->assertArrayNotHasKey('Orientation', @exif_read_data($originale) ?: []);

        // Ruotata di 90° in senso orario: il rosso (prima a sinistra) ora è in alto
        $salvata = imagecreatefromjpeg($originale);
        $rgb = imagecolorat($salvata, 240, 20);
        $this->assertGreaterThan(200, ($rgb >> 16) & 0xFF, 'In alto c\'è il rosso');
        $this->assertLessThan(60, $rgb & 0xFF);
        @unlink($percorso);
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
