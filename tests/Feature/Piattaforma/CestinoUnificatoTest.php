<?php

namespace Tests\Feature\Piattaforma;

use App\Models\Associazione;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\Socio;
use App\Models\Utente;
use App\Models\Verbale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.24 — Cestino unificato: soft-deleted di soci/eventi/documenti/verbali
 * in un'unica pagina con filtro per tipo, ripristino ed eliminazione
 * definitiva (permessi per modulo) e comando di svuotamento oltre 90 giorni.
 */
class CestinoUnificatoTest extends TestCase
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

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('cestino.index'))->assertRedirect('/login');
    }

    public function test_la_pagina_elenca_i_soft_deleted_di_tutti_i_tipi(): void
    {
        $socio = $this->creaSocioEliminato('Mario', 'Cestinato');
        $evento = $this->creaEventoEliminato('Evento cestinato');
        $documento = $this->creaDocumentoEliminato('statuto-cestinato.pdf');
        $verbale = $this->creaVerbaleEliminato('Verbale cestinato');

        $response = $this->actingAs($this->admin)->get(route('cestino.index'));

        $response->assertOk();
        $response->assertSee('Mario Cestinato');
        $response->assertSee('Evento cestinato');
        $response->assertSee('statuto-cestinato.pdf');
        $response->assertSee('Verbale cestinato');
        $response->assertSee('4 elementi nel cestino');
    }

    public function test_gli_elementi_di_un_altro_tenant_non_compaiono(): void
    {
        $altra = Associazione::factory()->create();
        $this->creaSocioEliminato('Tizio', 'Altrui', $altra->id);

        $response = $this->actingAs($this->admin)->get(route('cestino.index'));

        $response->assertOk();
        $response->assertDontSee('Tizio Altrui');
        $response->assertSee('0 elementi nel cestino');
    }

    public function test_il_filtro_per_tipo_limita_l_elenco(): void
    {
        $this->creaSocioEliminato('Mario', 'Cestinato');
        $this->creaEventoEliminato('Evento cestinato');

        $response = $this->actingAs($this->admin)->get(route('cestino.index', ['tipo' => 'soci']));

        $response->assertOk();
        $response->assertSee('Mario Cestinato');
        $response->assertDontSee('Evento cestinato');
    }

    public function test_un_elemento_attivo_non_compare_nel_cestino(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Attivo',
            'cognome' => 'NonCestinato',
        ]);

        $response = $this->actingAs($this->admin)->get(route('cestino.index'));

        $response->assertOk();
        $response->assertDontSee('Attivo NonCestinato');
    }

    public function test_ripristino_di_un_socio(): void
    {
        $socio = $this->creaSocioEliminato('Mario', 'Cestinato');

        $response = $this->actingAs($this->admin)
            ->post(route('cestino.ripristina', ['tipo' => 'soci', 'id' => $socio->id]));

        $response->assertRedirect(route('cestino.index'));
        $response->assertSessionHas('success');
        $this->assertNull($socio->fresh()->deleted_at);
    }

    public function test_ripristino_di_un_evento(): void
    {
        $evento = $this->creaEventoEliminato('Evento cestinato');

        $this->actingAs($this->admin)
            ->post(route('cestino.ripristina', ['tipo' => 'eventi', 'id' => $evento->id]))
            ->assertRedirect(route('cestino.index'));

        $this->assertNull($evento->fresh()->deleted_at);
    }

    public function test_eliminazione_definitiva_di_un_verbale(): void
    {
        $verbale = $this->creaVerbaleEliminato('Verbale cestinato');

        $response = $this->actingAs($this->admin)
            ->delete(route('cestino.elimina-definitivamente', ['tipo' => 'verbali', 'id' => $verbale->id]));

        $response->assertRedirect(route('cestino.index'));
        $this->assertDatabaseMissing('verbali', ['id' => $verbale->id]);
    }

    public function test_tipo_sconosciuto_produce_404(): void
    {
        $this->actingAs($this->admin)
            ->post(route('cestino.ripristina', ['tipo' => 'tessere', 'id' => 'x']))
            ->assertNotFound();
    }

    public function test_il_ripristino_cross_tenant_produce_404(): void
    {
        $altra = Associazione::factory()->create();
        $socio = $this->creaSocioEliminato('Tizio', 'Altrui', $altra->id);

        $this->actingAs($this->admin)
            ->post(route('cestino.ripristina', ['tipo' => 'soci', 'id' => $socio->id]))
            ->assertNotFound();

        $this->assertNotNull(Socio::withoutGlobalScope('associazione')->withTrashed()->find($socio->id)->deleted_at);
    }

    public function test_l_operatore_puo_ripristinare_un_socio_ma_non_un_verbale(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $socio = $this->creaSocioEliminato('Mario', 'Cestinato');
        $verbale = $this->creaVerbaleEliminato('Verbale cestinato');

        // soci: operatore ha soci.delete (fallback ruoli) → ripristino consentito
        $this->actingAs($operatore)
            ->post(route('cestino.ripristina', ['tipo' => 'soci', 'id' => $socio->id]))
            ->assertRedirect(route('cestino.index'));

        // verbali: restore/forceDelete riservati agli admin (VerbalePolicy)
        $this->actingAs($operatore)
            ->post(route('cestino.ripristina', ['tipo' => 'verbali', 'id' => $verbale->id]))
            ->assertForbidden();
    }

    public function test_comando_cestino_svuota_elimina_solo_gli_elementi_oltre_90_giorni(): void
    {
        $vecchio = $this->creaSocioEliminato('Vecchio', 'DaSvuotare');
        $recente = $this->creaSocioEliminato('Recente', 'DaTenere');
        $altraAssociazione = Associazione::factory()->create();
        $vecchioAltrui = $this->creaVerbaleEliminato('Verbale vecchio altrui', $altraAssociazione->id);

        $this->retrodataEliminazione($vecchio, 100);
        $this->retrodataEliminazione($vecchioAltrui, 120);

        $this->artisan('cestino:svuota')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('soci', ['id' => $vecchio->id]);
        $this->assertDatabaseMissing('verbali', ['id' => $vecchioAltrui->id]);
        $this->assertDatabaseHas('soci', ['id' => $recente->id]);
    }

    public function test_comando_cestino_svuota_rispetta_l_opzione_giorni(): void
    {
        $socio = $this->creaSocioEliminato('Mario', 'Cestinato');
        $this->retrodataEliminazione($socio, 10);

        $this->artisan('cestino:svuota', ['--giorni' => 5])->assertExitCode(0);

        $this->assertDatabaseMissing('soci', ['id' => $socio->id]);
    }

    // ----- helper -----

    private function creaSocioEliminato(string $nome, string $cognome, ?string $associazioneId = null): Socio
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $associazioneId ?? $this->associazione->id,
            'nome' => $nome,
            'cognome' => $cognome,
        ]);
        $socio->delete();

        return $socio;
    }

    private function creaEventoEliminato(string $titolo, ?string $associazioneId = null): Evento
    {
        $evento = Evento::factory()->create([
            'associazione_id' => $associazioneId ?? $this->associazione->id,
            'titolo' => $titolo,
        ]);
        $evento->delete();

        return $evento;
    }

    private function creaDocumentoEliminato(string $nomeFile, ?string $associazioneId = null): Documento
    {
        $documento = Documento::create([
            'associazione_id' => $associazioneId ?? $this->associazione->id,
            'nome_file' => $nomeFile,
            'percorso_file' => 'documenti/'.$nomeFile,
        ]);
        $documento->delete();

        return $documento;
    }

    private function creaVerbaleEliminato(string $titolo, ?string $associazioneId = null): Verbale
    {
        $verbale = Verbale::create([
            'associazione_id' => $associazioneId ?? $this->associazione->id,
            'titolo' => $titolo,
            'data_riunione' => '2026-05-20',
            'tipo_riunione' => 'Assemblea Ordinaria',
        ]);
        $verbale->delete();

        return $verbale;
    }

    private function retrodataEliminazione(Model $model, int $giorni): void
    {
        $model->newQueryWithoutScopes()
            ->getQuery()
            ->where('id', $model->getKey())
            ->update(['deleted_at' => now()->subDays($giorni)]);
    }
}
