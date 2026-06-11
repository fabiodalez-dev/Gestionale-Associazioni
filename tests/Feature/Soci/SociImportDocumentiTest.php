<?php

namespace Tests\Feature\Soci;

use App\Models\Associazione;
use App\Models\DocumentoSocio;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SociImportDocumentiTest extends TestCase
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

    public function test_import_csv_con_report_errori_riga_per_riga(): void
    {
        $csv = implode("\n", [
            'nome,cognome,email,stato',
            'Mario,Rossi,mario.import@example.com,Attivo',
            ',SenzaNome,manca.nome@example.com,Attivo',          // riga invalida: nome mancante
            'Luigi,Verdi,email-non-valida,Attivo',               // riga invalida: email
            'Anna,Bianchi,anna.import@example.com,Attivo',
        ]);

        $file = UploadedFile::fake()->createWithContent('soci.csv', $csv);

        $response = $this->actingAs($this->admin)->post('/soci/import', ['file' => $file]);

        $response->assertRedirect(route('soci.import'));
        $report = session('import_report');

        $this->assertSame(2, $report['importati']);
        $this->assertCount(2, $report['errori']);
        $this->assertSame([3, 4], array_column($report['errori'], 'riga'));

        $this->assertDatabaseHas('soci', [
            'email' => 'mario.import@example.com',
            'associazione_id' => $this->associazione->id,
        ]);
        $this->assertDatabaseMissing('soci', ['email' => 'manca.nome@example.com']);

        // Numeri socio progressivi assegnati in import
        $this->assertSame('001', Socio::query()->where('email', 'mario.import@example.com')->value('numero_socio'));
        $this->assertSame('002', Socio::query()->where('email', 'anna.import@example.com')->value('numero_socio'));
    }

    public function test_upload_download_e_cancellazione_documento_socio(): void
    {
        Storage::fake('local');

        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->post("/soci/{$socio->id}/documenti", [
            'file' => UploadedFile::fake()->create('certificato.pdf', 100, 'application/pdf'),
            'descrizione' => 'Certificato medico',
            'data_scadenza' => now()->addYear()->toDateString(),
        ])->assertRedirect();

        $documento = DocumentoSocio::query()->where('socio_id', $socio->id)->first();

        $this->assertNotNull($documento);
        $this->assertSame('certificato.pdf', $documento->nome_file);
        Storage::disk('local')->assertExists($documento->percorso_file);

        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Caricamento Documento',
        ]);

        // Download protetto
        $this->actingAs($this->admin)
            ->get("/soci/{$socio->id}/documenti/{$documento->id}/download")
            ->assertOk();

        // Cross-tenant: 404
        $altroAdmin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => Associazione::factory()->create()->id,
        ]);
        $this->actingAs($altroAdmin)
            ->get("/soci/{$socio->id}/documenti/{$documento->id}/download")
            ->assertNotFound();

        // Cancellazione: soft delete del record (miglioramento 11),
        // il file resta sul disco per l'eventuale ripristino dal cestino documenti.
        $this->actingAs($this->admin)
            ->delete("/soci/{$socio->id}/documenti/{$documento->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('documenti_socio', ['id' => $documento->id]);
        Storage::disk('local')->assertExists($documento->percorso_file);
    }

    public function test_upload_di_un_tipo_file_non_ammesso_viene_rifiutato(): void
    {
        Storage::fake('local');

        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->post("/soci/{$socio->id}/documenti", [
            'file' => UploadedFile::fake()->create('malware.php', 10),
        ])->assertSessionHasErrors('file');
    }
}
