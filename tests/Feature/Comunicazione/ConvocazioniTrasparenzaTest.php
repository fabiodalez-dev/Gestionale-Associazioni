<?php

namespace Tests\Feature\Comunicazione;

use App\Models\Annuncio;
use App\Models\Associazione;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\Socio;
use App\Notifications\Comunicazione\ConvocazioneAssembleaNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Addendum "Convocazioni assemblee + Trasparenza":
 * - annunci tipo 'convocazione' (data obbligatoria, evento collegato,
 *   dettagli sulla pagina pubblica Novità/Comunicazioni);
 * - invio convocazione in coda ai soci Attivi con email e senza opt-out;
 * - documenti pubblici sulla pagina /a/{slug}/trasparenza con download
 *   anonimo SOLO per pubblico=true e del tenant (404 altrimenti).
 */
class ConvocazioniTrasparenzaTest extends ComunicazioneTestCase
{
    // ── Convocazioni: form e pagina pubblica ─────────────────────────

    public function test_store_convocazione_richiede_data_assemblea(): void
    {
        $response = $this->actingAs($this->admin)->post(route('annunci.store'), [
            'titolo' => 'Assemblea ordinaria 2026',
            'tipo' => Annuncio::TIPO_CONVOCAZIONE,
            'corpo_html' => '<p>Convocazione</p>',
            'data_assemblea' => '',
            'in_evidenza' => '0',
            'solo_soci' => '0',
        ]);

        $response->assertSessionHasErrors('data_assemblea');
        $this->assertSame(0, Annuncio::allTenants()->count());
    }

    public function test_store_convocazione_salva_dettagli_ed_evento_collegato(): void
    {
        $evento = Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->addDays(20),
        ]);

        $response = $this->actingAs($this->admin)->post(route('annunci.store'), [
            'titolo' => 'Assemblea ordinaria 2026',
            'tipo' => Annuncio::TIPO_CONVOCAZIONE,
            'corpo_html' => '<p>Si convoca l\'assemblea dei soci.</p>',
            'data_assemblea' => now()->addDays(20)->format('Y-m-d\TH:i'),
            'luogo_assemblea' => 'Sede sociale, Via Roma 1',
            'ordine_del_giorno' => "1. Bilancio\n2. Rinnovo cariche",
            'evento_id' => $evento->id,
            'pubblicato_dal' => now()->subHour()->format('Y-m-d\TH:i'),
            'in_evidenza' => '0',
            'solo_soci' => '0',
        ]);

        $response->assertRedirect(route('annunci.index'));

        $annuncio = Annuncio::allTenants()->where('titolo', 'Assemblea ordinaria 2026')->firstOrFail();
        $this->assertTrue($annuncio->isConvocazione());
        $this->assertSame('Sede sociale, Via Roma 1', $annuncio->luogo_assemblea);
        $this->assertSame("1. Bilancio\n2. Rinnovo cariche", $annuncio->ordine_del_giorno);
        $this->assertSame($evento->id, $annuncio->evento_id);
        $this->assertNotNull($annuncio->data_assemblea);
    }

    public function test_evento_di_altro_tenant_non_collegabile(): void
    {
        $altra = Associazione::factory()->create();
        $eventoAltrui = Evento::factory()->pubblicato()->create([
            'associazione_id' => $altra->id,
            'data_evento' => now()->addDays(5),
        ]);

        $response = $this->actingAs($this->admin)->post(route('annunci.store'), [
            'titolo' => 'Assemblea con evento altrui',
            'tipo' => Annuncio::TIPO_CONVOCAZIONE,
            'corpo_html' => '<p>Test</p>',
            'data_assemblea' => now()->addDays(5)->format('Y-m-d\TH:i'),
            'evento_id' => $eventoAltrui->id,
            'in_evidenza' => '0',
            'solo_soci' => '0',
        ]);

        $response->assertSessionHasErrors('evento_id');
    }

    public function test_convocazione_pubblicata_visibile_su_novita_con_dettagli_e_conferma_presenza(): void
    {
        $evento = Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->addDays(15),
        ]);

        Annuncio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Assemblea straordinaria',
            'tipo' => Annuncio::TIPO_CONVOCAZIONE,
            'data_assemblea' => now()->addDays(15)->setTime(21, 0),
            'luogo_assemblea' => 'Aula magna',
            'ordine_del_giorno' => "1. Modifica statuto\n2. Varie ed eventuali",
            'evento_id' => $evento->id,
        ]);

        $this->get($this->urlTenant('novita'))
            ->assertOk()
            ->assertSee('Assemblea straordinaria')
            ->assertSee('Convocazione assemblea')
            ->assertSee('Aula magna')
            ->assertSee('Modifica statuto')
            ->assertSee('Conferma la tua presenza')
            ->assertSee(route('pubblico.eventi.show', [$this->associazione->slug, $evento->id]), false);
    }

    public function test_nav_pubblica_contiene_comunicazioni_e_trasparenza(): void
    {
        $this->get($this->urlTenant())
            ->assertOk()
            ->assertSee('Comunicazioni')
            ->assertSee('Trasparenza')
            ->assertSee(route('pubblico.trasparenza', $this->associazione->slug), false);
    }

    // ── Invio convocazione ai soci ───────────────────────────────────

    public function test_invio_convocazione_notifica_solo_i_soci_attivi_senza_opt_out(): void
    {
        Notification::fake();

        $annuncio = Annuncio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'tipo' => Annuncio::TIPO_CONVOCAZIONE,
            'data_assemblea' => now()->addDays(10),
        ]);

        $destinatario1 = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $destinatario2 = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $optOut = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email_opt_out' => true,
        ]);
        $sospeso = Socio::factory()->sospeso()->create(['associazione_id' => $this->associazione->id]);
        $senzaEmail = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => '',
        ]);

        $altra = Associazione::factory()->create();
        $socioAltrui = Socio::factory()->create(['associazione_id' => $altra->id]);

        $response = $this->actingAs($this->admin)
            ->post(route('annunci.invia-convocazione', $annuncio));

        $response->assertRedirect(route('annunci.index'));
        $response->assertSessionHas('success', 'Convocazione inviata a 2 soci.');

        Notification::assertSentTo([$destinatario1, $destinatario2], ConvocazioneAssembleaNotification::class);
        Notification::assertNotSentTo([$optOut, $sospeso, $senzaEmail, $socioAltrui], ConvocazioneAssembleaNotification::class);

        $this->assertNotNull($annuncio->fresh()->convocazione_inviata_at);
    }

    public function test_invio_convocazione_rifiutato_per_comunicazioni_o_bozze(): void
    {
        Notification::fake();

        $comunicazione = Annuncio::factory()->create(['associazione_id' => $this->associazione->id]);
        $bozza = Annuncio::factory()->bozza()->create([
            'associazione_id' => $this->associazione->id,
            'tipo' => Annuncio::TIPO_CONVOCAZIONE,
            'data_assemblea' => now()->addDays(10),
        ]);

        foreach ([$comunicazione, $bozza] as $annuncio) {
            $this->actingAs($this->admin)
                ->post(route('annunci.invia-convocazione', $annuncio))
                ->assertRedirect(route('annunci.index'))
                ->assertSessionHas('error');

            $this->assertNull($annuncio->fresh()->convocazione_inviata_at);
        }

        Notification::assertNothingSent();
    }

    // ── Trasparenza: toggle admin ────────────────────────────────────

    public function test_admin_pubblica_e_rimuove_documento_dalla_trasparenza(): void
    {
        Storage::fake('local');

        $documento = $this->creaDocumento('bilancio-2025.pdf');

        $this->actingAs($this->admin)
            ->post(route('documenti.trasparenza', $documento), ['categoria_pubblica' => 'bilancio'])
            ->assertRedirect();

        $documento->refresh();
        $this->assertTrue($documento->pubblico);
        $this->assertSame('bilancio', $documento->categoria_pubblica);

        // Secondo POST: rimozione dalla trasparenza
        $this->actingAs($this->admin)
            ->post(route('documenti.trasparenza', $documento))
            ->assertRedirect();

        $documento->refresh();
        $this->assertFalse($documento->pubblico);
        $this->assertNull($documento->categoria_pubblica);
    }

    public function test_pubblicazione_richiede_categoria_valida(): void
    {
        Storage::fake('local');

        $documento = $this->creaDocumento('doc.pdf');

        $this->actingAs($this->admin)
            ->post(route('documenti.trasparenza', $documento), ['categoria_pubblica' => 'inesistente'])
            ->assertSessionHasErrors('categoria_pubblica');

        $this->assertFalse($documento->fresh()->pubblico);
    }

    // ── Trasparenza: pagina pubblica e download anonimo ──────────────

    public function test_documento_pubblico_appare_su_trasparenza_e_si_scarica_senza_auth(): void
    {
        Storage::fake('local');

        $documento = $this->creaDocumento('bilancio-2025.pdf', 'CONTENUTO-BILANCIO-2025', [
            'pubblico' => true,
            'categoria_pubblica' => 'bilancio',
            'descrizione' => 'Bilancio consuntivo 2025',
        ]);

        $this->get($this->urlTenant('trasparenza'))
            ->assertOk()
            ->assertSee('bilancio-2025.pdf')
            ->assertSee('Bilanci')
            ->assertSee('Bilancio consuntivo 2025');

        $download = $this->get(route('pubblico.trasparenza.download', [$this->associazione->slug, $documento->id]));

        $download->assertOk();
        $download->assertDownload('bilancio-2025.pdf');
        $this->assertSame('CONTENUTO-BILANCIO-2025', $download->streamedContent());
    }

    public function test_documento_non_pubblico_non_appare_e_download_404(): void
    {
        Storage::fake('local');

        $documento = $this->creaDocumento('riservato.pdf', 'SEGRETO');

        $this->get($this->urlTenant('trasparenza'))
            ->assertOk()
            ->assertDontSee('riservato.pdf');

        $this->get(route('pubblico.trasparenza.download', [$this->associazione->slug, $documento->id]))
            ->assertNotFound();
    }

    public function test_isolamento_tenant_sulla_trasparenza(): void
    {
        Storage::fake('local');

        $altra = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        $documentoAltrui = $this->creaDocumento('bilancio-altrui.pdf', 'ALTRUI', [
            'pubblico' => true,
            'categoria_pubblica' => 'bilancio',
        ], $altra);

        // La pagina Trasparenza del tenant A non mostra il documento di B
        $this->get($this->urlTenant('trasparenza'))
            ->assertOk()
            ->assertDontSee('bilancio-altrui.pdf');

        // Il download via slug del tenant A → 404 uniforme
        $this->get(route('pubblico.trasparenza.download', [$this->associazione->slug, $documentoAltrui->id]))
            ->assertNotFound();

        // Sul tenant B invece è visibile e scaricabile
        $this->get($this->urlTenant('trasparenza', $altra))
            ->assertOk()
            ->assertSee('bilancio-altrui.pdf');
    }

    // ── Helper ───────────────────────────────────────────────────────

    /**
     * Crea un Documento con file fisico sul disk 'local' (fallback
     * percorso_file, come i record migrati dal legacy).
     *
     * @param  array<string, mixed>  $extra
     */
    private function creaDocumento(
        string $nomeFile,
        string $contenuto = 'contenuto',
        array $extra = [],
        ?Associazione $associazione = null,
    ): Documento {
        $associazione ??= $this->associazione;
        $percorso = 'documenti-test/'.uniqid().'-'.$nomeFile;

        Storage::disk('local')->put($percorso, $contenuto);

        return Documento::create(array_merge([
            'associazione_id' => $associazione->id,
            'nome_file' => $nomeFile,
            'percorso_file' => $percorso,
            'dimensione_kb' => 1,
        ], $extra));
    }
}
