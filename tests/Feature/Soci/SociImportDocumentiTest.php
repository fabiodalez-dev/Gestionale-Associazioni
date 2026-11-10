<?php

namespace Tests\Feature\Soci;

use App\Models\Associazione;
use App\Models\DocumentoSocio;
use App\Models\Importazione;
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

    public function test_intestazioni_riconosciute_per_sinonimo(): void
    {
        $csv = implode("\n", [
            'Cognome;Nome;E-mail;C.F.;Cellulare;Data di nascita;Comune;Prov.',
            'Neri;Lia;lia.neri@example.com;RSSMRA85T10A562S;333 1234567;10/12/1985;Bologna;bo',
        ]);

        $this->actingAs($this->admin)->post('/soci/import', [
            'file' => UploadedFile::fake()->createWithContent('export.csv', $csv),
        ])->assertRedirect(route('soci.import'));

        $socio = Socio::query()->where('email', 'lia.neri@example.com')->firstOrFail();
        $this->assertSame(['Lia', 'Neri', 'RSSMRA85T10A562S', '333 1234567', 'Bologna', 'BO'], [
            $socio->nome, $socio->cognome, $socio->codice_fiscale, $socio->telefono, $socio->citta, $socio->provincia,
        ]);
        $this->assertSame('1985-12-10', $socio->data_nascita->toDateString());
    }

    public function test_reimportare_lo_stesso_file_aggiorna_invece_di_duplicare(): void
    {
        $prima = implode("\n", [
            'nome,cognome,email,telefono,citta',
            'Mario,Rossi,mario.dup@example.com,,Padova',
        ]);
        $this->actingAs($this->admin)->post('/soci/import', ['file' => UploadedFile::fake()->createWithContent('a.csv', $prima)]);

        // Stesso socio (email con maiuscole diverse), telefono aggiunto, città vuota
        $seconda = implode("\n", [
            'nome,cognome,email,telefono,citta',
            'Mario,Rossi,Mario.Dup@Example.com,3471112223,',
        ]);
        $this->actingAs($this->admin)->post('/soci/import', ['file' => UploadedFile::fake()->createWithContent('b.csv', $seconda)]);

        $report = session('import_report');
        $this->assertSame(0, $report['importati']);
        $this->assertSame(1, $report['aggiornati']);
        $this->assertSame([], $report['errori']);

        $this->assertSame(1, Socio::query()->where('cognome', 'Rossi')->count());
        $socio = Socio::query()->where('cognome', 'Rossi')->sole();
        $this->assertSame('3471112223', $socio->telefono);
        $this->assertSame('Padova', $socio->citta, 'Una cella vuota non cancella il dato');
        $this->assertSame('mario.dup@example.com', $socio->email);
    }

    public function test_persona_diversa_con_l_email_di_un_socio_non_lo_sovrascrive(): void
    {
        Socio::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Marco', 'cognome' => 'Esposito', 'email' => 'marco@example.com']);

        $csv = implode("\n", ['nome,cognome,email', 'Paolo,Conti,marco@example.com']);
        $this->actingAs($this->admin)->post('/soci/import', ['file' => UploadedFile::fake()->createWithContent('c.csv', $csv)]);

        $report = session('import_report');
        $this->assertSame([0, 0], [$report['importati'], $report['aggiornati']]);
        $this->assertCount(1, $report['errori']);
        $this->assertSame('Esposito', Socio::query()->where('email', 'marco@example.com')->value('cognome'));
    }

    public function test_storico_importazioni_con_report_errori_in_csv(): void
    {
        $csv = implode("\n", ['nome,cognome,email', 'Mario,Rossi,mario.storico@example.com', ',Senza,manca@example.com']);
        $this->actingAs($this->admin)->post('/soci/import', ['file' => UploadedFile::fake()->createWithContent('elenco-2026.csv', $csv)]);

        $importazione = Importazione::query()->sole();
        $this->assertSame(['elenco-2026.csv', 2, 1, 0, 1], [
            $importazione->nome_file, $importazione->righe_lette, $importazione->create, $importazione->aggiornate, $importazione->scartate,
        ]);
        $this->assertSame($this->admin->id, $importazione->utente_id);

        $this->actingAs($this->admin)->get(route('soci.importazioni.index'))
            ->assertOk()->assertSee('elenco-2026.csv')->assertSee(route('soci.importazioni.errori', $importazione->id), false);

        $report = $this->actingAs($this->admin)->get(route('soci.importazioni.errori', $importazione->id))
            ->assertOk()->assertDownload()->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}riga;errore", $report);
        $this->assertStringContainsString('3;', $report);

        // Dopo dodici mesi lo storico viene eliminato
        $importazione->forceFill(['created_at' => now()->subMonths(13)])->save();
        $this->artisan('model:prune', ['--model' => [Importazione::class]]);
        $this->assertSame(0, Importazione::query()->withoutGlobalScopes()->count());
    }

    public function test_storico_di_un_altra_associazione_non_visibile(): void
    {
        $altra = Importazione::query()->create([
            'associazione_id' => Associazione::factory()->create()->id,
            'tipo' => Importazione::TIPO_SOCI, 'nome_file' => 'altrui.csv', 'scartate' => 1,
            'errori' => [['riga' => 2, 'errori' => ['x']]],
        ]);

        $this->actingAs($this->admin)->get(route('soci.importazioni.index'))->assertDontSee('altrui.csv');
        $this->actingAs($this->admin)->get(route('soci.importazioni.errori', $altra->id))->assertNotFound();
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
