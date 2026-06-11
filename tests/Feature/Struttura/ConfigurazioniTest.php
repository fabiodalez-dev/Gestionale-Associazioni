<?php

namespace Tests\Feature\Struttura;

use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\PaymentGatewaySetting;
use App\Models\Sede;
use App\Models\TesseraTemplate;
use App\Models\TipoSocio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConfigurazioniTest extends TestCase
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

    public function test_pagina_configurazioni_visibile_all_admin_del_tenant(): void
    {
        $this->actingAs($this->admin)
            ->get('/configurazioni')
            ->assertOk()
            ->assertSee($this->associazione->nome);
    }

    public function test_operatore_non_accede_alle_configurazioni(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)->get('/configurazioni')->assertForbidden();
        $this->actingAs($operatore)->post('/configurazioni/info', ['nome' => 'Hack'])->assertForbidden();
    }

    public function test_super_admin_senza_tenant_viene_rediretto_alla_dashboard(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get('/configurazioni')
            ->assertRedirect(route('dashboard'));
    }

    public function test_update_info_aggiorna_solo_l_anagrafica(): void
    {
        $costoOriginale = $this->associazione->costo_tessera;

        $this->actingAs($this->admin)->post('/configurazioni/info', [
            'nome' => 'Nome Aggiornato',
            'email' => 'aggiornata@example.com',
            'provincia' => 'TO',
            'cap' => '10100',
        ])->assertRedirect(route('configurazioni.index', ['section' => 'info']));

        $this->associazione->refresh();
        $this->assertSame('Nome Aggiornato', $this->associazione->nome);
        $this->assertSame('aggiornata@example.com', $this->associazione->email);
        // costo_tessera e attiva non sono toccabili da qui
        $this->assertEquals($costoOriginale, $this->associazione->costo_tessera);
        $this->assertTrue($this->associazione->attiva);
    }

    public function test_upload_logo_valido(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->post('/configurazioni/logo', [
            'logo_file' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])->assertRedirect(route('configurazioni.index', ['section' => 'logo']));

        $logoUrl = $this->associazione->fresh()->logo_url;
        $this->assertNotNull($logoUrl);
        $this->assertStringStartsWith('uploads/logos/logo_', $logoUrl);
        Storage::disk('public')->assertExists($logoUrl);
    }

    public function test_upload_logo_estensione_non_valida_bloccante(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->post('/configurazioni/logo', [
            'logo_file' => UploadedFile::fake()->create('malware.php', 10),
        ])->assertSessionHasErrors('logo_file');

        $this->assertNull($this->associazione->fresh()->logo_url);
    }

    public function test_crud_sedi_con_isolamento_tenant(): void
    {
        // Crea
        $this->actingAs($this->admin)->post('/configurazioni/sedi', [
            'nome' => 'Sede Nord',
            'citta' => 'Milano',
            'provincia' => 'MI',
            'cap' => '20100',
        ])->assertRedirect(route('configurazioni.index', ['section' => 'sedi']));

        $sede = Sede::query()->forAssociazione($this->associazione->id)->where('nome', 'Sede Nord')->firstOrFail();

        // Aggiorna
        $this->actingAs($this->admin)->post('/configurazioni/sedi', [
            'id' => $sede->id,
            'nome' => 'Sede Nord-Ovest',
            'citta' => 'Milano',
        ]);
        $this->assertSame('Sede Nord-Ovest', $sede->fresh()->nome);

        // Isolamento: la sede di un ALTRO tenant non è né modificabile né eliminabile
        $altraAssociazione = Associazione::factory()->create();
        $sedeAltrui = Sede::create(['associazione_id' => $altraAssociazione->id, 'nome' => 'Sede Altrui']);

        $this->actingAs($this->admin)->post('/configurazioni/sedi', [
            'id' => $sedeAltrui->id,
            'nome' => 'Sede Rubata',
        ])->assertNotFound();
        $this->assertSame('Sede Altrui', $sedeAltrui->fresh()->nome);

        $this->actingAs($this->admin)->delete('/configurazioni/sedi/'.$sedeAltrui->id)
            ->assertSessionHas('warning');
        $this->assertDatabaseHas('sedi', ['id' => $sedeAltrui->id]);

        // Elimina la propria
        $this->actingAs($this->admin)->delete('/configurazioni/sedi/'.$sede->id)
            ->assertSessionHas('success');
        $this->assertDatabaseMissing('sedi', ['id' => $sede->id]);
    }

    public function test_rotta_legacy_sezioni_redirige_alle_sedi(): void
    {
        $this->actingAs($this->admin)
            ->get('/sezioni')
            ->assertRedirect(route('configurazioni.index', ['section' => 'sedi']));
    }

    public function test_privacy_policy_viene_sanitizzata(): void
    {
        $this->actingAs($this->admin)->post('/configurazioni/privacy-policy', [
            'privacy_policy_html' => '<h2>Informativa</h2><p>Testo <strong>importante</strong></p><script>alert("xss")</script>',
        ])->assertRedirect(route('configurazioni.index', ['section' => 'privacy']));

        $html = $this->associazione->fresh()->privacy_policy_html;
        $this->assertStringContainsString('<h2>Informativa</h2>', $html);
        $this->assertStringContainsString('<strong>importante</strong>', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_campi_obbligatori_salvati_con_privacy_forzata_gdpr(): void
    {
        $campo = CampoPersonalizzato::create([
            'associazione_id' => $this->associazione->id,
            'nome_campo' => 'Taglia maglietta',
            'tipo_campo' => 'text',
        ]);

        $this->actingAs($this->admin)->post('/configurazioni/campi-obbligatori', [
            'required_backend' => ['telefono' => '1'],
            'required_preiscrizione' => ['codice_fiscale' => '1'], // niente privacy_consenso nell'input
            'required_custom_preiscrizione' => [$campo->id],
        ])->assertRedirect(route('configurazioni.index', ['section' => 'campi-obbligatori']));

        $config = $this->associazione->fresh()->campi_obbligatori_config;
        $this->assertTrue($config['backend']['telefono']);
        $this->assertFalse($config['backend']['codice_fiscale']);
        $this->assertTrue($config['preiscrizione']['codice_fiscale']);
        // privacy_consenso SEMPRE true in preiscrizione anche se non inviato
        $this->assertTrue($config['preiscrizione']['privacy_consenso']);

        $campo->refresh();
        $this->assertFalse($campo->obbligatorio);
        $this->assertTrue($campo->obbligatorio_preiscrizione);
    }

    public function test_template_tessera_upsert_default_e_per_tipo(): void
    {
        $tipo = TipoSocio::create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Sostenitore',
        ]);

        // Template default (tipo_id vuoto)
        $this->actingAs($this->admin)->post('/configurazioni/tessera-template', [
            'tipo_id' => '',
            'contenuto' => 'Contenuto default v1',
        ]);
        // Secondo salvataggio: UPDATE, non un nuovo record
        $this->actingAs($this->admin)->post('/configurazioni/tessera-template', [
            'tipo_id' => '',
            'contenuto' => 'Contenuto default v2',
        ]);

        $default = TesseraTemplate::query()
            ->forAssociazione($this->associazione->id)
            ->whereNull('tipo_socio_id')
            ->get();
        $this->assertCount(1, $default);
        $this->assertSame('Contenuto default v2', $default->first()->contenuto);

        // Template specifico per tipo
        $this->actingAs($this->admin)->post('/configurazioni/tessera-template', [
            'tipo_id' => $tipo->id,
            'contenuto' => 'Contenuto sostenitore',
        ]);

        $this->assertDatabaseHas('tessera_templates', [
            'associazione_id' => $this->associazione->id,
            'tipo_socio_id' => $tipo->id,
            'contenuto' => 'Contenuto sostenitore',
        ]);
    }

    public function test_segreti_gateway_vuoti_mantengono_il_valore_esistente(): void
    {
        $settings = PaymentGatewaySetting::create([
            'associazione_id' => $this->associazione->id,
            'stripe_enabled' => true,
            'stripe_publishable_key' => 'pk_test_vecchia',
            'stripe_secret_key_encrypted' => 'sk_test_segreto_originale',
        ]);

        $this->actingAs($this->admin)->post('/configurazioni/pagamenti', [
            'stripe_enabled' => '1',
            'stripe_publishable_key' => 'pk_test_nuova',
            'stripe_secret_key' => '', // vuoto = mantieni
            'paypal_mode' => 'sandbox',
            'valuta' => 'EUR',
        ])->assertRedirect(route('configurazioni.index', ['section' => 'pagamenti']));

        $settings->refresh();
        $this->assertSame('pk_test_nuova', $settings->stripe_publishable_key);
        $this->assertSame('sk_test_segreto_originale', $settings->stripe_secret_key_encrypted);

        // Segreto valorizzato = sovrascritto
        $this->actingAs($this->admin)->post('/configurazioni/pagamenti', [
            'stripe_enabled' => '1',
            'stripe_publishable_key' => 'pk_test_nuova',
            'stripe_secret_key' => 'sk_test_nuovo_segreto',
            'paypal_mode' => 'sandbox',
            'valuta' => 'EUR',
        ]);

        $this->assertSame('sk_test_nuovo_segreto', $settings->fresh()->stripe_secret_key_encrypted);
    }
}
