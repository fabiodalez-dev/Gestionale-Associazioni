<?php

namespace Tests\Feature\AnagraficaPro;

use App\Models\Associazione;
use App\Models\DocumentoSocio;
use App\Models\PromemoriaInviato;
use App\Models\Socio;
use App\Models\Utente;
use App\Notifications\AnagraficaPro\DocumentiInScadenzaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * f.23 PIANO-VITA-SOCIALE: documenti socio con scadenza e tipo,
 * promemoria via comando documenti:promemoria-scadenze, badge in scheda.
 */
class DocumentiScadenzaTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    private Socio $socio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    private function creaDocumento(array $override = []): DocumentoSocio
    {
        return DocumentoSocio::create(array_merge([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $this->socio->id,
            'nome_file' => 'certificato.pdf',
            'percorso_file' => 'documenti-soci/test/certificato.pdf',
            'tipo_documento' => 'certificato_medico',
            'data_scadenza' => now()->addDays(10)->toDateString(),
        ], $override));
    }

    public function test_upload_documento_con_tipo_e_scadenza(): void
    {
        Storage::fake('local');

        $response = $this->actingAs($this->admin)->post("/soci/{$this->socio->id}/documenti", [
            'file' => UploadedFile::fake()->create('certificato.pdf', 100, 'application/pdf'),
            'descrizione' => 'Certificato agonistico',
            'tipo_documento' => 'certificato_medico',
            'data_scadenza' => now()->addMonths(6)->toDateString(),
        ]);

        $response->assertRedirect(route('soci.show', [$this->socio, 'tab' => 'documenti']));

        $this->assertDatabaseHas('documenti_socio', [
            'socio_id' => $this->socio->id,
            'nome_file' => 'certificato.pdf',
            'tipo_documento' => 'certificato_medico',
        ]);
    }

    public function test_badge_scaduto_e_in_scadenza_nella_scheda_socio(): void
    {
        $this->creaDocumento(['data_scadenza' => now()->subDay()->toDateString(), 'nome_file' => 'scaduto.pdf']);
        $this->creaDocumento(['data_scadenza' => now()->addDays(5)->toDateString(), 'nome_file' => 'quasi.pdf']);

        $response = $this->actingAs($this->admin)->get("/soci/{$this->socio->id}");

        $response->assertOk();
        $response->assertSee('documento scaduto');
        $response->assertSee('in scadenza');
        $response->assertSee('Certificato medico');
    }

    public function test_comando_invia_promemoria_ad_admin_e_socio_e_non_duplica(): void
    {
        Notification::fake();

        $this->creaDocumento();

        $this->artisan('documenti:promemoria-scadenze')
            ->expectsOutputToContain('Promemoria documenti inviati: 1')
            ->assertSuccessful();

        Notification::assertSentTo($this->admin, DocumentiInScadenzaNotification::class);
        Notification::assertSentTo($this->socio, DocumentiInScadenzaNotification::class);

        $this->assertDatabaseHas('promemoria_inviati', [
            'socio_id' => $this->socio->id,
            'tipo' => PromemoriaInviato::TIPO_DOCUMENTO_SCADENZA,
            'step' => DocumentoSocio::GIORNI_PREAVVISO_SCADENZA,
        ]);

        // Secondo run: nessun nuovo invio (dedup su promemoria_inviati)
        $this->artisan('documenti:promemoria-scadenze')
            ->expectsOutputToContain('Promemoria documenti inviati: 0')
            ->assertSuccessful();

        Notification::assertSentToTimes($this->admin, DocumentiInScadenzaNotification::class, 1);
        $this->assertSame(1, PromemoriaInviato::allTenants()->count());
    }

    public function test_documento_fuori_finestra_non_genera_promemoria(): void
    {
        Notification::fake();

        $this->creaDocumento(['data_scadenza' => now()->addDays(60)->toDateString()]);
        $this->creaDocumento(['data_scadenza' => null, 'nome_file' => 'senza-scadenza.pdf']);

        $this->artisan('documenti:promemoria-scadenze')
            ->expectsOutputToContain('Promemoria documenti inviati: 0')
            ->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame(0, PromemoriaInviato::allTenants()->count());
    }

    public function test_socio_senza_email_o_opt_out_non_riceve_il_promemoria(): void
    {
        Notification::fake();

        $this->socio->update(['email_opt_out' => true]);
        $this->creaDocumento();

        $this->artisan('documenti:promemoria-scadenze')->assertSuccessful();

        Notification::assertSentTo($this->admin, DocumentiInScadenzaNotification::class);
        Notification::assertNotSentTo($this->socio, DocumentiInScadenzaNotification::class);
    }

    public function test_isolamento_tenant_dei_promemoria(): void
    {
        Notification::fake();

        $altraAssociazione = Associazione::factory()->create();
        $altroAdmin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $altraAssociazione->id,
        ]);

        $this->creaDocumento();

        $this->artisan('documenti:promemoria-scadenze')->assertSuccessful();

        Notification::assertSentTo($this->admin, DocumentiInScadenzaNotification::class);
        Notification::assertNotSentTo($altroAdmin, DocumentiInScadenzaNotification::class);
    }

    public function test_nuovo_documento_dopo_un_digest_riceve_il_suo_promemoria(): void
    {
        Notification::fake();

        $this->creaDocumento();
        $this->artisan('documenti:promemoria-scadenze')->assertSuccessful();

        // Documento caricato DOPO il primo digest, già dentro la finestra
        $this->travel(2)->days();
        $this->creaDocumento(['nome_file' => 'nuovo.pdf', 'data_scadenza' => now()->addDays(7)->toDateString()]);

        $this->artisan('documenti:promemoria-scadenze')
            ->expectsOutputToContain('Promemoria documenti inviati: 1')
            ->assertSuccessful();

        Notification::assertSentToTimes($this->admin, DocumentiInScadenzaNotification::class, 2);
        $this->assertSame(2, PromemoriaInviato::allTenants()->count());
    }
}
