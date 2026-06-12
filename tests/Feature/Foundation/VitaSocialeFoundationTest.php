<?php

namespace Tests\Feature\Foundation;

use App\Models\Associazione;
use App\Models\Carica;
use App\Models\Cessazione;
use App\Models\DocumentoSocio;
use App\Models\Evento;
use App\Models\FeedbackEvento;
use App\Models\PromemoriaInviato;
use App\Models\Quota;
use App\Models\Ricevuta;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Models\Verbale;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Smoke test delle fondamenta "Vita sociale": schema additivo, model nuovi
 * (factory + tenancy) e campi/relazioni aggiunti ai model condivisi.
 */
class VitaSocialeFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creano_tutti_i_model_nuovi_con_tenant_coerente(): void
    {
        $promemoria = PromemoriaInviato::factory()->create();
        $feedback = FeedbackEvento::factory()->create();
        $cessazione = Cessazione::factory()->create();
        $carica = Carica::factory()->create();
        $ricevuta = Ricevuta::factory()->create();

        $this->assertSame($promemoria->socio->associazione_id, $promemoria->associazione_id);
        $this->assertSame($feedback->prenotazione->associazione_id, $feedback->associazione_id);
        $this->assertSame($feedback->evento->id, $feedback->prenotazione->evento_id);
        $this->assertSame($cessazione->socio->associazione_id, $cessazione->associazione_id);
        $this->assertSame($carica->socio->associazione_id, $carica->associazione_id);
        $this->assertSame($ricevuta->quota->associazione_id, $ricevuta->associazione_id);
    }

    public function test_i_model_nuovi_sono_scopati_per_tenant(): void
    {
        $cessazioneA = Cessazione::factory()->create();
        Cessazione::factory()->create(); // altro tenant

        $admin = Utente::factory()->adminAssociazione()
            ->create(['associazione_id' => $cessazioneA->associazione_id]);
        $this->actingAs($admin);

        $this->assertSame(1, Cessazione::count());
        $this->assertSame(2, Cessazione::allTenants()->count());
    }

    public function test_promemoria_inviati_unique_su_socio_tessera_tipo_step(): void
    {
        $socio = Socio::factory()->create();
        $tessera = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $socio->associazione_id,
        ]);

        $attributi = [
            'socio_id' => $socio->id,
            'associazione_id' => $socio->associazione_id,
            'tessera_id' => $tessera->id,
            'tipo' => PromemoriaInviato::TIPO_RINNOVO_TESSERA,
            'step' => 30,
        ];

        PromemoriaInviato::factory()->create($attributi);

        // Step diverso: consentito
        PromemoriaInviato::factory()->create(['step' => 15] + $attributi);

        $this->expectException(QueryException::class);

        PromemoriaInviato::factory()->create($attributi);
    }

    public function test_feedback_evento_unico_per_prenotazione_e_token_generato(): void
    {
        $this->assertSame(64, strlen(FeedbackEvento::generaToken()));

        $feedback = FeedbackEvento::factory()->create();
        $this->assertFalse($feedback->isCompilato());
        $this->assertSame(0, FeedbackEvento::query()->compilati()->count());

        FeedbackEvento::factory()->compilato()->create();
        $this->assertSame(1, FeedbackEvento::withoutGlobalScopes()->whereNotNull('compilato_at')->count());

        $this->expectException(QueryException::class);

        FeedbackEvento::factory()->create([
            'prenotazione_id' => $feedback->prenotazione_id,
            'evento_id' => $feedback->evento_id,
            'associazione_id' => $feedback->associazione_id,
        ]);
    }

    public function test_associazione_promemoria_rinnovo_steps_default_e_configurati(): void
    {
        $assoc = Associazione::factory()->create();
        $this->assertSame([30, 15, 3], $assoc->promemoriaRinnovoSteps());

        $assoc->update(['promemoria_rinnovo_config' => [7, 60, 7]]);
        $this->assertSame([60, 7], $assoc->fresh()->promemoriaRinnovoSteps());
    }

    public function test_evento_serie_e_solo_soci(): void
    {
        $serieId = (string) Str::uuid();
        $evento = Evento::factory()->create(['serie_id' => $serieId, 'solo_soci' => true]);
        Evento::factory()->create([
            'associazione_id' => $evento->associazione_id,
            'serie_id' => $serieId,
        ]);

        $this->assertTrue($evento->fresh()->isRiservatoAiSoci());
        $this->assertTrue($evento->isInSerie());
        $this->assertSame(2, Evento::withoutGlobalScopes()->dellaSerie($serieId)->count());
        $this->assertFalse(Evento::factory()->create()->isRiservatoAiSoci());
    }

    public function test_verbale_numerazione_per_anno_ed_evento_collegato(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $assoc->id]);
        $this->actingAs($admin);

        $evento = Evento::factory()->create(['associazione_id' => $assoc->id]);

        $verbale = Verbale::create([
            'associazione_id' => $assoc->id,
            'titolo' => 'Assemblea ordinaria',
            'numero' => Verbale::prossimoNumero(2026),
            'anno' => 2026,
            'data_riunione' => '2026-04-10',
            'tipo_riunione' => 'Assemblea Ordinaria',
            'evento_id' => $evento->id,
        ]);

        $this->assertSame('1/2026', $verbale->numero_completo);
        $this->assertSame(2, Verbale::prossimoNumero(2026));
        $this->assertSame(1, Verbale::prossimoNumero(2027));
        $this->assertTrue($verbale->evento->is($evento));
        $this->assertTrue($evento->verbali()->first()->is($verbale));
    }

    public function test_quota_ha_la_ricevuta_e_numero_completo(): void
    {
        $ricevuta = Ricevuta::factory()->create(['numero' => 12, 'anno' => 2026]);

        $this->assertSame('12/2026', $ricevuta->numero_completo);
        $this->assertTrue(
            Quota::withoutGlobalScopes()->find($ricevuta->quota_id)->ricevuta->is($ricevuta)
        );
    }

    public function test_richiesta_iscrizione_accetta_la_delibera(): void
    {
        $richiesta = RichiestaIscrizione::factory()->create([
            'delibera_numero' => '5/2026',
            'delibera_data' => '2026-05-01',
        ]);

        $this->assertSame('5/2026', $richiesta->fresh()->delibera_numero);
        $this->assertSame('2026-05-01', $richiesta->fresh()->delibera_data->toDateString());
    }

    public function test_documento_socio_tipo_e_stato_scadenza(): void
    {
        $socio = Socio::factory()->create();

        $documento = DocumentoSocio::create([
            'associazione_id' => $socio->associazione_id,
            'socio_id' => $socio->id,
            'nome_file' => 'certificato.pdf',
            'percorso_file' => 'documenti/certificato.pdf',
            'tipo_documento' => 'certificato_medico',
            'data_scadenza' => now()->addDays(10)->toDateString(),
        ]);

        $this->assertSame('certificato_medico', $documento->fresh()->tipo_documento);
        $this->assertFalse($documento->isScaduto());
        $this->assertTrue($documento->isInScadenza());

        $documento->update(['data_scadenza' => now()->subDay()->toDateString()]);
        $this->assertTrue($documento->fresh()->isScaduto());
    }

    public function test_carica_attiva_e_in_scadenza(): void
    {
        $attiva = Carica::factory()->create();
        Carica::factory()->conclusa()->create([
            'associazione_id' => $attiva->associazione_id,
        ]);
        $inScadenza = Carica::factory()->create([
            'associazione_id' => $attiva->associazione_id,
            'fine' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertTrue($attiva->isAttiva());
        $this->assertSame(2, Carica::withoutGlobalScopes()->attive()->count());
        $this->assertSame(
            [$inScadenza->id],
            Carica::withoutGlobalScopes()->inScadenza()->pluck('id')->all()
        );
    }

    public function test_cessazione_mappa_lo_stato_socio_coerente(): void
    {
        $this->assertSame(Socio::STATO_DECEDUTO, Cessazione::STATO_SOCIO_PER_TIPO[Cessazione::TIPO_DECESSO]);
        $this->assertSame(Socio::STATO_RADIATO, Cessazione::STATO_SOCIO_PER_TIPO[Cessazione::TIPO_DIMISSIONI]);

        $cessazione = Cessazione::factory()->create([
            'tipo' => Cessazione::TIPO_DIMISSIONI,
            'delibera_numero' => '7/2026',
            'delibera_data' => '2026-03-01',
        ]);

        $this->assertSame('7/2026', $cessazione->fresh()->delibera_numero);
        $this->assertNotNull($cessazione->socio);
    }
}
