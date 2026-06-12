<?php

namespace Tests\Feature\AnagraficaPro;

use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * f.25 PIANO-VITA-SOCIALE: export Excel multi-foglio (Soci, Tessere anno,
 * Quote anno, Libro soci) + import di aggiornamento con anteprima diff.
 */
class ExportImportTest extends TestCase
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

    private function csv(array $righe): UploadedFile
    {
        $contenuto = implode("\n", array_map(fn (array $r) => implode(',', $r), $righe));

        return UploadedFile::fake()->createWithContent('aggiornamento.csv', $contenuto);
    }

    public function test_pagina_export_import_accessibile_e_protetta(): void
    {
        // Guest prima di autenticarsi: actingAs persiste per tutto il test
        $this->get('/export-import')->assertRedirect();

        $this->actingAs($this->admin)->get('/export-import')->assertOk()
            ->assertSee('Export Excel multi-foglio')
            ->assertSee('Import di aggiornamento soci');
    }

    public function test_export_multifoglio_scarica_un_xlsx(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        Tessera::factory()->create(['socio_id' => $socio->id, 'associazione_id' => $this->associazione->id]);
        Quota::factory()->create(['socio_id' => $socio->id, 'associazione_id' => $this->associazione->id]);

        $response = $this->actingAs($this->admin)->get('/export-import/export?anno='.now()->year);

        $response->assertOk();
        $this->assertStringContainsString(
            '.xlsx',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    public function test_anteprima_mostra_il_diff_senza_scrivere(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'numero_socio' => '042',
            'telefono' => '3330000000',
            'citta' => 'Milano',
        ]);

        $response = $this->actingAs($this->admin)->post('/export-import/import/anteprima', [
            'file' => $this->csv([
                ['numero_socio', 'telefono', 'citta'],
                ['042', '3399998888', 'Torino'],
            ]),
        ]);

        $response->assertOk();
        $response->assertSee('Differenze da applicare');
        $response->assertSee('3330000000'); // valore attuale
        $response->assertSee('3399998888'); // nuovo valore
        $response->assertSee('Torino');

        // Anteprima = nessuna scrittura
        $socio->refresh();
        $this->assertSame('3330000000', $socio->telefono);
        $this->assertSame('Milano', $socio->citta);
    }

    public function test_applica_aggiorna_i_soci_con_match_per_numero_o_email(): void
    {
        $perNumero = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'numero_socio' => '001',
            'citta' => 'Roma',
        ]);
        $perEmail = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'numero_socio' => '002',
            'email' => 'via.email@example.com',
            'telefono' => null,
        ]);

        $file = $this->csv([
            ['numero_socio', 'email', 'telefono', 'citta'],
            ['001', '', '', 'Napoli'],
            ['', 'VIA.EMAIL@example.com', '3471234567', ''],
        ]);

        $anteprima = $this->actingAs($this->admin)->post('/export-import/import/anteprima', ['file' => $file]);
        $anteprima->assertOk();

        $token = session('anagrafica_pro_import')['token'];

        $applica = $this->actingAs($this->admin)->post('/export-import/import/applica', ['token' => $token]);
        $applica->assertRedirect(route('export-import.index'));
        $applica->assertSessionHas('success');

        $this->assertSame('Napoli', $perNumero->refresh()->citta);
        $this->assertSame('3471234567', $perEmail->refresh()->telefono);

        // L'aggiornamento è tracciato nello storico
        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $perNumero->id,
            'tipo_attivita' => 'Modifica Anagrafica',
        ]);
    }

    public function test_celle_vuote_non_svuotano_i_campi(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'numero_socio' => '007',
            'telefono' => '3215554433',
        ]);

        $this->actingAs($this->admin)->post('/export-import/import/anteprima', [
            'file' => $this->csv([
                ['numero_socio', 'telefono', 'citta'],
                ['007', '', 'Bari'],
            ]),
        ])->assertOk();

        $token = session('anagrafica_pro_import')['token'];
        $this->actingAs($this->admin)->post('/export-import/import/applica', ['token' => $token]);

        $socio->refresh();
        $this->assertSame('3215554433', $socio->telefono); // intatto
        $this->assertSame('Bari', $socio->citta);
    }

    public function test_righe_non_abbinate_e_isolamento_tenant(): void
    {
        $altra = Associazione::factory()->create();
        $estraneo = Socio::factory()->create([
            'associazione_id' => $altra->id,
            'numero_socio' => '999',
            'citta' => 'Estero',
        ]);

        $response = $this->actingAs($this->admin)->post('/export-import/import/anteprima', [
            'file' => $this->csv([
                ['numero_socio', 'citta'],
                ['999', 'Hackerata'], // socio di un ALTRO tenant: non deve matchare
            ]),
        ]);

        $response->assertOk();
        $response->assertSee('non abbinate');

        $token = session('anagrafica_pro_import')['token'];
        $this->actingAs($this->admin)->post('/export-import/import/applica', ['token' => $token]);

        $this->assertSame('Estero', $estraneo->refresh()->citta);
    }

    public function test_applica_con_token_non_valido_viene_rifiutato(): void
    {
        Socio::factory()->create(['associazione_id' => $this->associazione->id, 'numero_socio' => '010']);

        $this->actingAs($this->admin)->post('/export-import/import/anteprima', [
            'file' => $this->csv([
                ['numero_socio', 'citta'],
                ['010', 'Genova'],
            ]),
        ])->assertOk();

        $response = $this->actingAs($this->admin)->post('/export-import/import/applica', [
            'token' => '00000000-0000-0000-0000-000000000000',
        ]);

        $response->assertRedirect(route('export-import.index'));
        $response->assertSessionHas('error');
    }

    public function test_email_duplicata_nel_file_viene_segnalata_come_errore(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'occupata@example.com',
        ]);
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'numero_socio' => '020',
            'email' => 'libera@example.com',
        ]);

        $response = $this->actingAs($this->admin)->post('/export-import/import/anteprima', [
            'file' => $this->csv([
                ['numero_socio', 'email'],
                ['020', 'occupata@example.com'], // andrebbe in conflitto con un altro socio
            ]),
        ]);

        $response->assertOk();
        $response->assertSee('Righe con errori');
    }
}
