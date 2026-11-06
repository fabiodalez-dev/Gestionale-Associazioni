<?php

namespace Tests\Feature\Struttura;

use App\Models\Associazione;
use App\Models\Candidatura;
use App\Models\Carica;
use App\Models\Elezione;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\SchedaElezione;
use App\Models\Socio;
use App\Models\Utente;
use App\Models\Verbale;
use App\Models\VotanteElezione;
use App\Services\Struttura\ElezioneNonValidaException;
use App\Services\Struttura\ElezioniService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Elezioni e rinnovo delle cariche (issue #9): candidature con requisiti,
 * scheda segreta con preferenze, scrutinio con schede cartacee e parità,
 * proclamazione che aggiorna le cariche.
 */
class ElezioniTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    private Riunione $riunione;

    private ElezioniService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $this->riunione = Riunione::factory()->assemblea()->create([
            'associazione_id' => $this->associazione->id,
            'data_ora' => now()->addWeek()->setTime(18, 0),
            'voto_online' => true,
        ]);
        $this->service = app(ElezioniService::class);
    }

    private function actAsAdmin(): self
    {
        $this->actingAs($this->admin)->withSession(['associazione_id' => $this->associazione->id]);

        return $this;
    }

    private function socio(array $attributi = [], bool $presente = false): Socio
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create(array_merge(
            ['associazione_id' => $this->associazione->id, 'data_iscrizione' => now()->subYears(2)->toDateString()],
            $attributi,
        ));

        RiunionePartecipante::factory()->create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $this->riunione->id,
            'socio_id' => $socio->id,
            'carica_ruolo' => null,
            'stato' => $presente ? RiunionePartecipante::STATO_CONFERMATO : RiunionePartecipante::STATO_INVITATO,
        ]);

        return $socio;
    }

    private function elezione(array $attributi = []): Elezione
    {
        return Elezione::create(array_merge([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $this->riunione->id,
            'organo' => 'direttivo',
            'posti' => 2,
            'max_preferenze' => 2,
            'durata_mandato_mesi' => 36,
            'candidature_dal' => now()->subDay()->toDateString(),
            'candidature_al' => now()->addDays(3)->toDateString(),
            'regola_parita' => 'anzianita_iscrizione',
        ], $attributi));
    }

    private function candidato(Elezione $elezione, Socio $socio): Candidatura
    {
        return $this->service->candida($elezione, $socio, 'segreteria', null, accettata: true);
    }

    /**
     * Elezione con il voto aperto, quorum raggiunto e i candidati indicati.
     *
     * @return array{0: Elezione, 1: list<Candidatura>}
     */
    private function elezioneAlVoto(int $candidati = 3): array
    {
        $elezione = $this->elezione();
        $candidature = [];
        for ($i = 0; $i < $candidati; $i++) {
            $candidature[] = $this->candidato($elezione, $this->socio(presente: true));
        }
        $this->service->apriVoto($elezione);

        return [$elezione->fresh(), $candidature];
    }

    public function test_admin_crea_l_elezione_dall_assemblea(): void
    {
        $this->actAsAdmin()->post(route('riunioni.elezioni.store', $this->riunione), [
            'organo' => 'direttivo',
            'posti' => 5,
            'max_preferenze' => 3,
            'durata_mandato_mesi' => 36,
            'candidature_dal' => now()->toDateString(),
            'candidature_al' => now()->addDays(5)->toDateString(),
            'anzianita_minima_mesi' => 6,
            'regola_parita' => 'eta',
        ])->assertSessionHasNoErrors();

        $elezione = Elezione::query()->firstOrFail();
        $this->assertSame(Elezione::STATO_CANDIDATURE, $elezione->stato);
        $this->assertSame(6, $elezione->anzianita_minima_mesi);

        $this->actAsAdmin()->get(route('elezioni.show', $elezione))->assertOk()->assertSee('Consiglio direttivo');
        $this->actAsAdmin()->get(route('riunioni.show', $this->riunione))->assertOk()->assertSee('Elezioni');
    }

    public function test_preferenze_non_oltre_i_posti_e_candidature_entro_l_assemblea(): void
    {
        $this->actAsAdmin()->post(route('riunioni.elezioni.store', $this->riunione), [
            'organo' => 'direttivo',
            'posti' => 2,
            'max_preferenze' => 3,
            'durata_mandato_mesi' => 36,
            'candidature_dal' => now()->toDateString(),
            'candidature_al' => now()->addMonth()->toDateString(),
            'regola_parita' => 'eta',
        ])->assertSessionHasErrors(['max_preferenze', 'candidature_al']);
    }

    public function test_autocandidatura_dall_area_soci_e_ritiro(): void
    {
        $elezione = $this->elezione();
        $socio = $this->socio();

        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.elezioni.candida', $elezione), ['presentazione' => 'Mi occupo di eventi.'])
            ->assertSessionHas('success');

        $candidatura = Candidatura::query()->firstOrFail();
        $this->assertTrue($candidatura->isValida());
        $this->assertSame('area_soci', $candidatura->origine);

        $this->actingAs($socio, 'socio')->get(route('area-soci.assemblee'))->assertOk()->assertSee('Sei candidato/a.');

        $this->actingAs($socio, 'socio')
            ->delete(route('area-soci.elezioni.ritira', $elezione))
            ->assertSessionHas('success');
        $this->assertFalse($candidatura->fresh()->isValida());

        // Ci si può ricandidare finché le candidature sono aperte
        $this->actingAs($socio, 'socio')->post(route('area-soci.elezioni.candida', $elezione))->assertSessionHas('success');
        $this->assertTrue($candidatura->fresh()->isValida());
        $this->assertSame(1, Candidatura::query()->count());
    }

    public function test_candidatura_della_segreteria_vale_dopo_l_accettazione(): void
    {
        $elezione = $this->elezione();
        $socio = $this->socio(['numero_socio' => 'C-1']);

        $this->actAsAdmin()
            ->post(route('elezioni.candidature.store', $elezione), ['codice' => 'C-1'])
            ->assertSessionHas('success');

        $candidatura = Candidatura::query()->firstOrFail();
        $this->assertFalse($candidatura->isValida());

        $this->actingAs($socio, 'socio')->get(route('area-soci.assemblee'))->assertSee('Accetto la candidatura');
        $this->actingAs($socio, 'socio')->post(route('area-soci.elezioni.accetta', $elezione))->assertSessionHas('success');

        $this->assertTrue($candidatura->fresh()->isValida());
    }

    public function test_requisiti_dei_candidati(): void
    {
        $elezione = $this->elezione(['anzianita_minima_mesi' => 12]);

        $requisiti = [
            'non è un socio attivo' => Socio::factory()->sospeso()->create(['associazione_id' => $this->associazione->id]),
            'minorenne' => $this->socio(['data_nascita' => now()->subYears(16)->toDateString()]),
            'mesi di iscrizione' => $this->socio(['data_iscrizione' => now()->subMonths(3)->toDateString()]),
        ];

        foreach ($requisiti as $messaggio => $socio) {
            try {
                $this->candidato($elezione, $socio);
                $this->fail("Candidatura accettata: {$messaggio}");
            } catch (ElezioneNonValidaException $e) {
                $this->assertStringContainsString($messaggio, $e->getMessage());
            }
        }

        // Con l'elenco calcolato serve essere tra gli aventi diritto
        $this->riunione->forceFill(['aventi_diritto_at' => now()])->save();
        $fuoriElenco = Socio::factory()->create(['associazione_id' => $this->associazione->id, 'data_iscrizione' => now()->subYears(3)->toDateString()]);
        $this->expectException(ElezioneNonValidaException::class);
        $this->candidato($elezione->fresh(), $fuoriElenco);
    }

    public function test_area_soci_non_si_candida_fuori_periodo(): void
    {
        $elezione = $this->elezione([
            'candidature_dal' => now()->addDay()->toDateString(),
            'candidature_al' => now()->addDays(3)->toDateString(),
        ]);

        $this->actingAs($this->socio(), 'socio')
            ->post(route('area-soci.elezioni.candida', $elezione))
            ->assertSessionHas('error');

        $this->assertSame(0, Candidatura::query()->count());
    }

    public function test_apertura_del_voto_richiede_candidati_e_quorum(): void
    {
        $elezione = $this->elezione();
        $this->socio();

        try {
            $this->service->apriVoto($elezione);
            $this->fail('Voto aperto senza candidati');
        } catch (ElezioneNonValidaException $e) {
            $this->assertStringContainsString('candidature valide', $e->getMessage());
        }

        $this->candidato($elezione, $this->socio());
        $this->expectException(ElezioneNonValidaException::class);
        $this->expectExceptionMessage('quorum');
        $this->service->apriVoto($elezione);
    }

    public function test_scheda_segreta_dall_area_soci_con_preferenze(): void
    {
        [$elezione, $candidature] = $this->elezioneAlVoto();
        $elettore = $this->socio(presente: true);

        $this->actingAs($elettore, 'socio')
            ->get(route('area-soci.assemblee.show', $this->riunione))
            ->assertOk()
            ->assertSee('Elezione: Consiglio direttivo');

        $this->actingAs($elettore, 'socio')
            ->post(route('area-soci.elezioni.vota', $elezione), [
                'schede' => [$elettore->id => ['bianca', $candidature[0]->id, $candidature[1]->id]],
            ])
            ->assertSessionHas('success');

        $scheda = SchedaElezione::query()->firstOrFail();
        $this->assertEqualsCanonicalizing([$candidature[0]->id, $candidature[1]->id], $scheda->preferenze);
        $this->assertArrayNotHasKey('created_at', $scheda->getAttributes());
        $this->assertTrue(VotanteElezione::query()->where('rappresentato_socio_id', $elettore->id)->exists());

        // Seconda scheda per lo stesso socio rifiutata
        $this->actingAs($elettore, 'socio')
            ->post(route('area-soci.elezioni.vota', $elezione), ['schede' => [$elettore->id => ['bianca']]])
            ->assertSessionHas('error');
        $this->assertSame(1, SchedaElezione::query()->count());
    }

    public function test_scheda_con_troppe_preferenze_o_candidati_estranei(): void
    {
        [$elezione, $candidature] = $this->elezioneAlVoto();
        $elettore = $this->socio(presente: true);

        foreach ([
            [$candidature[0]->id, $candidature[1]->id, $candidature[2]->id],
            [$this->socio()->id],
        ] as $preferenze) {
            try {
                $this->service->vota($elezione, $elettore, [$elettore->id => $preferenze]);
                $this->fail('Scheda non valida accettata');
            } catch (ElezioneNonValidaException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, SchedaElezione::query()->count());
    }

    public function test_scrutinio_con_schede_cartacee_e_parita_per_anzianita(): void
    {
        [$elezione, $candidature] = $this->elezioneAlVoto();
        // A e B a pari preferenze: prevale chi è iscritto da più tempo
        $candidature[0]->socio->update(['data_iscrizione' => now()->subYears(1)->toDateString()]);
        $candidature[1]->socio->update(['data_iscrizione' => now()->subYears(5)->toDateString()]);

        $elettore = $this->socio(presente: true);
        $this->service->vota($elezione, $elettore, [$elettore->id => [$candidature[0]->id, $candidature[2]->id]]);

        $this->actAsAdmin()->post(route('elezioni.scrutinio', $elezione), [
            'schede_valide' => 1,
            'schede_bianche' => 1,
            'schede_nulle' => 0,
            'preferenze' => [$candidature[1]->id => 1, $candidature[2]->id => 1],
        ])->assertSessionHas('success');

        $esito = Candidatura::query()->orderBy('posizione')->get();
        $this->assertSame(Elezione::STATO_SCRUTINIO, $elezione->fresh()->stato);
        // C ha 2 preferenze; A e B 1 ciascuno, B iscritto da più tempo
        $this->assertSame([$candidature[2]->id, $candidature[1]->id, $candidature[0]->id], $esito->pluck('id')->all());
        $this->assertSame([2, 1, 1], $esito->pluck('preferenze_totali')->all());
        $this->assertSame([true, true, false], $esito->pluck('eletta')->all());
    }

    public function test_scrutinio_rifiuta_numeri_incoerenti(): void
    {
        [$elezione, $candidature] = $this->elezioneAlVoto();

        // Preferenze in sala oltre schede valide × preferenze massime
        $this->actAsAdmin()->post(route('elezioni.scrutinio', $elezione), [
            'schede_valide' => 1,
            'preferenze' => [$candidature[0]->id => 2, $candidature[1]->id => 1],
        ])->assertSessionHas('error');

        // Schede oltre i presenti (3 presenti)
        $this->actAsAdmin()->post(route('elezioni.scrutinio', $elezione), [
            'schede_valide' => 2,
            'schede_bianche' => 2,
        ])->assertSessionHas('error');

        $this->assertSame(Elezione::STATO_VOTO, $elezione->fresh()->stato);
    }

    public function test_proclamazione_chiude_le_cariche_uscenti_e_crea_le_nuove(): void
    {
        $uscente = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $presidente = Carica::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $uscente->id,
            'ruolo' => 'Presidente',
            'inizio' => now()->subYears(3)->toDateString(),
        ]);
        $revisore = Carica::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $uscente->id,
            'ruolo' => 'Revisore dei Conti',
            'inizio' => now()->subYears(3)->toDateString(),
        ]);

        [$elezione, $candidature] = $this->elezioneAlVoto();
        $this->service->vota($elezione, $candidature[0]->socio, [$candidature[0]->socio_id => [$candidature[0]->id, $candidature[1]->id]]);
        $this->service->scrutina($elezione, [], 0, 0, 0);

        $this->actAsAdmin()->post(route('elezioni.proclama', $elezione))->assertSessionHas('success');

        $giornoElezione = $this->riunione->data_ora->copy()->startOfDay();
        $this->assertSame($giornoElezione->copy()->subDay()->toDateString(), $presidente->fresh()->fine->toDateString());
        $this->assertNull($revisore->fresh()->fine, 'Le cariche di altri organi restano');

        $nuove = Carica::query()->where('ruolo', 'Consigliere')->get();
        $this->assertEqualsCanonicalizing([$candidature[0]->socio_id, $candidature[1]->socio_id], $nuove->pluck('socio_id')->all());
        $this->assertSame($giornoElezione->toDateString(), $nuove[0]->inizio->toDateString());
        $this->assertSame($giornoElezione->copy()->addMonths(36)->subDay()->toDateString(), $nuove[0]->fine->toDateString());
        $this->assertSame(Elezione::STATO_PROCLAMATA, $elezione->fresh()->stato);

        // Proclamazione non ripetibile
        $this->expectException(ElezioneNonValidaException::class);
        $this->service->proclama($elezione->fresh());
    }

    public function test_risultati_nel_verbale(): void
    {
        [$elezione, $candidature] = $this->elezioneAlVoto();
        $this->service->vota($elezione, $candidature[0]->socio, [$candidature[0]->socio_id => [$candidature[0]->id]]);
        $this->service->scrutina($elezione, [], 0, 0, 0);
        $this->riunione->update(['stato' => Riunione::STATO_SVOLTA]);

        $this->actAsAdmin()->post(route('riunioni.genera-verbale', $this->riunione));

        $verbale = Verbale::query()->firstOrFail();
        $this->assertStringContainsString('Elezione Consiglio direttivo (2 posti, massimo 2 preferenze)', $verbale->deliberazioni);
        $this->assertStringContainsString("1. {$candidature[0]->socio->nome_completo}: 1 preferenze — eletto/a", $verbale->deliberazioni);
    }

    public function test_elezione_di_altra_associazione_non_accessibile(): void
    {
        $altra = Riunione::factory()->assemblea()->create();
        $elezione = Elezione::create([
            'associazione_id' => $altra->associazione_id,
            'riunione_id' => $altra->id,
            'organo' => 'direttivo',
            'posti' => 1,
            'max_preferenze' => 1,
            'candidature_dal' => now()->toDateString(),
            'candidature_al' => now()->toDateString(),
        ]);

        $this->actAsAdmin()->get(route('elezioni.show', $elezione))->assertNotFound();
    }
}
