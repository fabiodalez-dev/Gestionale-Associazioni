<?php

namespace Tests\Feature\Struttura;

use App\Models\Associazione;
use App\Models\DelegaRiunione;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Socio;
use App\Models\Utente;
use App\Models\Verbale;
use App\Models\VotanteRiunione;
use App\Models\VotazioneRiunione;
use App\Models\VotoRiunione;
use App\Services\Struttura\VotoAssembleaService;
use App\Services\Struttura\VotoNonValidoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Votazioni dei soci in assemblea (issue #8): apertura e chiusura del voto
 * online, voto dall'area soci per sé e per i deleganti, anonimato del voto
 * segreto, doppio voto, voti in sala ed esito.
 */
class VotoOnlineAssembleaTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    private Riunione $riunione;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->riunione = Riunione::factory()->assemblea()->create([
            'associazione_id' => $this->associazione->id,
            'data_ora' => now()->subMinutes(10),
            'voto_online' => true,
            'odg' => [
                ['punto' => 'Bilancio', 'descrizione' => null],
                ['punto' => 'Statuto', 'descrizione' => null, 'maggioranza' => 'due_terzi'],
            ],
        ]);
    }

    private function actAsAdmin(): self
    {
        $this->actingAs($this->admin)->withSession(['associazione_id' => $this->associazione->id]);

        return $this;
    }

    private function socio(bool $presente = true): Socio
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create(['associazione_id' => $this->associazione->id]);

        RiunionePartecipante::factory()->create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $this->riunione->id,
            'socio_id' => $socio->id,
            'carica_ruolo' => null,
            'stato' => $presente ? RiunionePartecipante::STATO_CONFERMATO : RiunionePartecipante::STATO_INVITATO,
        ]);

        return $socio;
    }

    private function apri(int $punto = 0, bool $segreta = false): VotazioneRiunione
    {
        return app(VotoAssembleaService::class)->apri($this->riunione, $punto, $segreta);
    }

    public function test_segreteria_apre_e_chiude_il_voto_con_voti_in_sala(): void
    {
        $soci = collect(range(1, 4))->map(fn () => $this->socio());

        $this->actAsAdmin()
            ->post(route('riunioni.voto-online.apri', $this->riunione), ['punto_indice' => 0, 'segreta' => '0'])
            ->assertSessionHas('success');

        $votazione = VotazioneRiunione::query()->firstOrFail();
        $this->assertTrue($votazione->isAperta());

        $this->actAsAdmin()->get(route('riunioni.show', $this->riunione))
            ->assertOk()
            ->assertSee('Voto palese aperto')
            ->assertSee('Chiudi il voto');

        $this->actingAs($soci[0], 'socio')->post(route('area-soci.assemblee.vota', [$this->riunione, $votazione]), [
            'voti' => [$soci[0]->id => VotoRiunione::FAVOREVOLE],
        ])->assertSessionHas('success');
        $this->actingAs($soci[1], 'socio')->post(route('area-soci.assemblee.vota', [$this->riunione, $votazione]), [
            'voti' => [$soci[1]->id => VotoRiunione::CONTRARIO],
        ])->assertSessionHas('success');

        $this->actAsAdmin()
            ->post(route('riunioni.voto-online.chiudi', [$this->riunione, $votazione]), [
                'favorevoli' => 2,
                'contrari' => '',
                'astenuti' => '',
            ])
            ->assertSessionHas('success');

        $votazione->refresh();
        $this->assertFalse($votazione->isAperta());
        $this->assertSame(3, $votazione->favorevoli);
        $this->assertSame(1, $votazione->contrari);
        $this->assertSame(VotazioneRiunione::ESITO_APPROVATO, $votazione->esito);

        // Dopo la chiusura nessuno vota più
        $this->actingAs($soci[2], 'socio')->post(route('area-soci.assemblee.vota', [$this->riunione, $votazione]), [
            'voti' => [$soci[2]->id => VotoRiunione::FAVOREVOLE],
        ])->assertSessionHas('error');
    }

    public function test_voto_segreto_non_collega_il_voto_al_socio(): void
    {
        $socio = $this->socio();
        $this->socio();
        $votazione = $this->apri(0, segreta: true);

        app(VotoAssembleaService::class)->vota($votazione, $socio, [$socio->id => VotoRiunione::CONTRARIO]);

        $voto = VotoRiunione::query()->firstOrFail();
        $this->assertNull($voto->socio_id);
        $this->assertSame(VotoRiunione::CONTRARIO, $voto->opzione);
        $this->assertArrayNotHasKey('created_at', $voto->getAttributes());
        // Il registro dice solo che il socio ha votato
        $this->assertTrue(VotanteRiunione::query()->where('rappresentato_socio_id', $socio->id)->exists());
    }

    public function test_voto_palese_salva_il_socio_e_compare_nel_verbale(): void
    {
        $socio = $this->socio();
        $votazione = $this->apri();
        app(VotoAssembleaService::class)->vota($votazione, $socio, [$socio->id => VotoRiunione::FAVOREVOLE]);
        app(VotoAssembleaService::class)->chiudi($votazione);

        $this->assertSame($socio->id, VotoRiunione::query()->value('socio_id'));

        $this->riunione->update(['stato' => Riunione::STATO_SVOLTA]);
        $this->actAsAdmin()->post(route('riunioni.genera-verbale', $this->riunione));

        $verbale = Verbale::query()->firstOrFail();
        $this->assertStringContainsString("Favorevole: {$socio->nome_completo}", $verbale->deliberazioni);
    }

    public function test_doppio_voto_rifiutato(): void
    {
        $socio = $this->socio();
        $votazione = $this->apri();
        $service = app(VotoAssembleaService::class);

        $service->vota($votazione, $socio, [$socio->id => VotoRiunione::FAVOREVOLE]);

        $this->expectException(VotoNonValidoException::class);
        $service->vota($votazione, $socio, [$socio->id => VotoRiunione::CONTRARIO]);
    }

    public function test_il_delegato_vota_anche_per_il_delegante_con_voti_diversi(): void
    {
        $delegato = $this->socio();
        $delegante = $this->socio(presente: false);
        DelegaRiunione::create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $this->riunione->id,
            'delegante_socio_id' => $delegante->id,
            'delegato_socio_id' => $delegato->id,
            'origine' => DelegaRiunione::ORIGINE_SEGRETERIA,
        ]);

        $votazione = $this->apri();

        $this->actingAs($delegato, 'socio')
            ->get(route('area-soci.assemblee.show', $this->riunione))
            ->assertOk()
            ->assertSee('Il tuo voto')
            ->assertSee('Per delega di');

        $this->actingAs($delegato, 'socio')->post(route('area-soci.assemblee.vota', [$this->riunione, $votazione]), [
            'voti' => [
                $delegato->id => VotoRiunione::FAVOREVOLE,
                $delegante->id => VotoRiunione::ASTENUTO,
            ],
        ])->assertSessionHas('success', '2 voti registrati.');

        $this->assertSame(2, VotanteRiunione::query()->where('votante_socio_id', $delegato->id)->count());
        $this->assertEqualsCanonicalizing(
            [VotoRiunione::FAVOREVOLE, VotoRiunione::ASTENUTO],
            VotoRiunione::query()->pluck('opzione')->all(),
        );
    }

    public function test_non_si_vota_per_chi_non_si_rappresenta_ne_da_assenti(): void
    {
        $presente = $this->socio();
        $altro = $this->socio();
        $assente = $this->socio(presente: false);
        $votazione = $this->apri();

        $this->actingAs($presente, 'socio')->post(route('area-soci.assemblee.vota', [$this->riunione, $votazione]), [
            'voti' => [$altro->id => VotoRiunione::FAVOREVOLE],
        ])->assertSessionHas('error');

        $this->actingAs($assente, 'socio')->post(route('area-soci.assemblee.vota', [$this->riunione, $votazione]), [
            'voti' => [$assente->id => VotoRiunione::FAVOREVOLE],
        ])->assertSessionHas('error');

        $this->actingAs($presente, 'socio')->post(route('area-soci.assemblee.vota', [$this->riunione, $votazione]), [
            'voti' => [$presente->id => 'forse'],
        ])->assertSessionHas('error');

        $this->assertSame(0, VotoRiunione::query()->count());
    }

    public function test_apertura_richiede_quorum_voto_online_e_un_solo_voto_aperto(): void
    {
        // Nessun presente: quorum non raggiunto
        $this->socio(presente: false);
        $this->actAsAdmin()
            ->post(route('riunioni.voto-online.apri', $this->riunione), ['punto_indice' => 0])
            ->assertSessionHas('error');
        $this->assertSame(0, VotazioneRiunione::query()->count());

        $this->socio();
        $this->socio();
        $this->apri(0);

        $this->expectException(VotoNonValidoException::class);
        $this->apri(1);
    }

    public function test_apertura_negata_senza_voto_online(): void
    {
        $this->riunione->update(['voto_online' => false]);
        $this->socio();

        $this->expectException(VotoNonValidoException::class);
        $this->apri();
    }

    public function test_chiusura_rifiuta_voti_in_sala_oltre_i_presenti(): void
    {
        $socio = $this->socio();
        $votazione = $this->apri();
        app(VotoAssembleaService::class)->vota($votazione, $socio, [$socio->id => VotoRiunione::FAVOREVOLE]);

        $this->actAsAdmin()
            ->post(route('riunioni.voto-online.chiudi', [$this->riunione, $votazione]), ['favorevoli' => 1])
            ->assertSessionHas('error');

        $this->assertTrue($votazione->fresh()->isAperta());
    }

    public function test_maggioranza_dei_due_terzi_sul_voto_online(): void
    {
        $soci = collect(range(1, 3))->map(fn () => $this->socio());
        $votazione = $this->apri(1);
        $service = app(VotoAssembleaService::class);

        $service->vota($votazione, $soci[0], [$soci[0]->id => VotoRiunione::FAVOREVOLE]);
        $service->vota($votazione, $soci[1], [$soci[1]->id => VotoRiunione::ASTENUTO]);
        $service->vota($votazione, $soci[2], [$soci[2]->id => VotoRiunione::ASTENUTO]);

        $this->assertSame(VotazioneRiunione::ESITO_RESPINTO, $service->chiudi($votazione)->esito);
    }

    public function test_risultato_online_non_modificabile_a_mano(): void
    {
        $socio = $this->socio();
        $votazione = $this->apri();
        app(VotoAssembleaService::class)->vota($votazione, $socio, [$socio->id => VotoRiunione::FAVOREVOLE]);
        app(VotoAssembleaService::class)->chiudi($votazione);
        $this->riunione->update(['stato' => Riunione::STATO_SVOLTA]);

        $this->actAsAdmin()->post(route('riunioni.votazioni.store', $this->riunione), [
            'punto_indice' => 0,
            'esito' => VotazioneRiunione::ESITO_RESPINTO,
            'favorevoli' => 0,
            'contrari' => 1,
            'astenuti' => 0,
        ])->assertSessionHas('warning');

        $this->assertSame(1, $votazione->fresh()->favorevoli);
    }

    public function test_partecipazione_a_distanza_registra_la_presenza(): void
    {
        $this->riunione->update(['partecipazione_remota' => true]);
        $socio = $this->socio(presente: false);

        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.assemblee.presenza', $this->riunione))
            ->assertSessionHas('success');

        $partecipante = RiunionePartecipante::query()->where('socio_id', $socio->id)->firstOrFail();
        $this->assertTrue($partecipante->isPresente());
        $this->assertNotNull($partecipante->presente_at);
    }

    public function test_partecipazione_a_distanza_non_prima_della_convocazione_ne_se_non_prevista(): void
    {
        $socio = $this->socio(presente: false);

        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.assemblee.presenza', $this->riunione))
            ->assertSessionHas('error');

        $this->riunione->update(['partecipazione_remota' => true, 'data_ora' => now()->addHour()]);

        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.assemblee.presenza', $this->riunione))
            ->assertSessionHas('error');

        $this->assertFalse(RiunionePartecipante::query()->where('socio_id', $socio->id)->firstOrFail()->isPresente());
    }

    public function test_pagina_assemblea_solo_per_gli_aventi_diritto(): void
    {
        $estraneo = Socio::factory()->conAccessoAreaSoci()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($estraneo, 'socio')
            ->get(route('area-soci.assemblee.show', $this->riunione))
            ->assertRedirect(route('area-soci.assemblee'));

        $altraAssociazione = Riunione::factory()->assemblea()->create();
        $this->actingAs($estraneo, 'socio')
            ->get(route('area-soci.assemblee.show', $altraAssociazione))
            ->assertNotFound();
    }
}
