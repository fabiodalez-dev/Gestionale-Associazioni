<?php

namespace Tests\Feature\Struttura;

use App\Models\Associazione;
use App\Models\Carica;
use App\Models\DelegaRiunione;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Models\Verbale;
use App\Notifications\Struttura\DelegaAssembleaNotification;
use App\Services\Struttura\AssembleaService;
use App\Services\Struttura\DelegaNonValidaException;
use App\Services\Struttura\DelegheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Deleghe per l'assemblea dei soci (issue #7): registrazione dall'area soci
 * e dalla segreteria, validazioni (aventi diritto, limite, catene, cariche,
 * tempi), effetto su quorum e verbale, notifiche.
 */
class DelegheAssembleaTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    private Riunione $riunione;

    private DelegheService $service;

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
            'data_ora' => now()->addWeek(),
            'max_deleghe_per_socio' => 1,
        ]);
        $this->service = app(DelegheService::class);
    }

    /**
     * Socio avente diritto (in elenco) dell'assemblea di prova, con accesso
     * all'area soci.
     */
    private function avente(array $attributi = [], bool $voto = true): Socio
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create(array_merge(
            ['associazione_id' => $this->associazione->id],
            $attributi,
        ));

        RiunionePartecipante::factory()->create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $this->riunione->id,
            'socio_id' => $socio->id,
            'carica_ruolo' => null,
            'avente_diritto_voto' => $voto,
        ]);

        return $socio;
    }

    private function registra(Socio $delegante, Socio $delegato): DelegaRiunione
    {
        return $this->service->registra($this->riunione, $delegante, $delegato, DelegaRiunione::ORIGINE_SEGRETERIA);
    }

    private function assertDelegaRifiutata(Socio $delegante, Socio $delegato, string $messaggio): void
    {
        try {
            $this->registra($delegante, $delegato);
            $this->fail('La delega doveva essere rifiutata.');
        } catch (DelegaNonValidaException $e) {
            $this->assertStringContainsString($messaggio, $e->getMessage());
        }
    }

    private function segnaPresente(Socio $socio): void
    {
        RiunionePartecipante::query()
            ->where('riunione_id', $this->riunione->id)
            ->where('socio_id', $socio->id)
            ->update(['stato' => RiunionePartecipante::STATO_CONFERMATO]);
    }

    public function test_socio_delega_dall_area_soci_con_numero_socio_e_cognome(): void
    {
        $delegante = $this->avente();
        $delegato = $this->avente(['numero_socio' => 'S-100', 'cognome' => 'Verdi']);

        $this->actingAs($delegante, 'socio')
            ->post(route('area-soci.assemblee.delega', $this->riunione), [
                'numero_socio' => 'S-100',
                'cognome' => 'verdi',
            ])
            ->assertRedirect(route('area-soci.assemblee'))
            ->assertSessionHas('success');

        $delega = DelegaRiunione::query()->firstOrFail();
        $this->assertSame($delegante->id, $delega->delegante_socio_id);
        $this->assertSame($delegato->id, $delega->delegato_socio_id);
        $this->assertSame(DelegaRiunione::ORIGINE_AREA_SOCI, $delega->origine);
        $this->assertSame($this->associazione->id, $delega->associazione_id);

        Notification::assertSentTo($delegato, DelegaAssembleaNotification::class, fn ($n) => $n->destinatario === 'delegato');
        Notification::assertSentTo($delegante, DelegaAssembleaNotification::class, fn ($n) => $n->destinatario === 'delegante');
    }

    public function test_area_soci_rifiuta_cognome_non_corrispondente(): void
    {
        $delegante = $this->avente();
        $this->avente(['numero_socio' => 'S-100', 'cognome' => 'Verdi']);

        $this->actingAs($delegante, 'socio')
            ->post(route('area-soci.assemblee.delega', $this->riunione), [
                'numero_socio' => 'S-100',
                'cognome' => 'Rossi',
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, DelegaRiunione::query()->count());
    }

    public function test_pagina_assemblee_e_revoca_dall_area_soci(): void
    {
        $delegante = $this->avente();
        $delegato = $this->avente(['cognome' => 'Verdi']);
        $this->registra($delegante, $delegato);

        $this->actingAs($delegante, 'socio')
            ->get(route('area-soci.assemblee'))
            ->assertOk()
            ->assertSee($this->riunione->titolo)
            ->assertSee('Revoca la delega');

        $this->actingAs($delegato, 'socio')
            ->get(route('area-soci.assemblee'))
            ->assertOk()
            ->assertSee('Hai ricevuto la delega di');

        $this->actingAs($delegante, 'socio')
            ->delete(route('area-soci.assemblee.revoca', $this->riunione))
            ->assertSessionHas('success');

        $this->assertSame(0, DelegaRiunione::query()->count());
    }

    public function test_area_soci_non_delega_ad_assemblea_iniziata(): void
    {
        $this->riunione->update(['data_ora' => now()->subMinute()]);
        $delegante = $this->avente();
        $this->avente(['numero_socio' => 'S-100', 'cognome' => 'Verdi']);

        $this->actingAs($delegante, 'socio')
            ->post(route('area-soci.assemblee.delega', $this->riunione), [
                'numero_socio' => 'S-100',
                'cognome' => 'Verdi',
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, DelegaRiunione::query()->count());
    }

    public function test_segreteria_puo_registrare_durante_l_assemblea_ma_non_dopo(): void
    {
        $this->riunione->update(['data_ora' => now()->subMinute()]);
        $delegante = $this->avente();
        $delegato = $this->avente();

        $this->registra($delegante, $delegato);
        $this->assertSame(1, DelegaRiunione::query()->count());

        $this->riunione->update(['stato' => Riunione::STATO_SVOLTA]);
        $this->assertDelegaRifiutata($this->avente(), $delegato, 'prima dello svolgimento');
    }

    public function test_delegante_e_delegato_devono_avere_diritto_di_voto(): void
    {
        $avente = $this->avente();
        $fuoriElenco = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $senzaVoto = $this->avente([], voto: false);

        $this->assertDelegaRifiutata($avente, $fuoriElenco, 'non è tra gli aventi diritto');
        $this->assertDelegaRifiutata($senzaVoto, $avente, 'non è tra gli aventi diritto');
        $this->assertDelegaRifiutata($avente, $avente, 'sé stessi');
    }

    public function test_limite_di_deleghe_per_delegato(): void
    {
        $delegato = $this->avente();
        $this->registra($this->avente(), $delegato);

        $this->assertDelegaRifiutata($this->avente(), $delegato, 'numero massimo di deleghe (1)');

        $this->riunione->update(['max_deleghe_per_socio' => 2]);
        $this->registra($this->avente(), $delegato);

        $this->assertSame(2, DelegaRiunione::query()->where('delegato_socio_id', $delegato->id)->count());
    }

    public function test_una_delega_per_delegante_e_niente_catene(): void
    {
        $this->riunione->update(['max_deleghe_per_socio' => 3]);
        $a = $this->avente();
        $b = $this->avente();
        $c = $this->avente();

        $this->registra($a, $b);

        $this->assertDelegaRifiutata($a, $c, 'ha già una delega');
        // B ha ricevuto una delega: non può delegare
        $this->assertDelegaRifiutata($b, $c, 'ha ricevuto deleghe');
        // A ha delegato: non può ricevere
        $this->assertDelegaRifiutata($c, $a, 'non può ricevere deleghe');
    }

    public function test_divieto_di_delega_a_chi_ha_una_carica(): void
    {
        $this->riunione->update(['deleghe_vietate_cariche' => true]);
        $consigliere = $this->avente();
        Carica::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $consigliere->id,
            'ruolo' => 'Consigliere',
        ]);

        $this->assertDelegaRifiutata($this->avente(), $consigliere, 'ricopre una carica');
    }

    public function test_assemblea_senza_deleghe_e_direttivo(): void
    {
        $this->riunione->update(['max_deleghe_per_socio' => 0]);
        $this->assertDelegaRifiutata($this->avente(), $this->avente(), 'non ammette deleghe');

        $direttivo = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);
        $this->assertFalse($direttivo->ammetteDeleghe());
    }

    public function test_delega_conta_solo_se_il_delegato_e_presente_e_il_delegante_no(): void
    {
        $quorum = fn () => app(AssembleaService::class)->quorum($this->riunione);

        $delegante = $this->avente();
        $delegato = $this->avente();
        $this->avente();
        $this->registra($delegante, $delegato);

        $this->assertSame(0, $quorum()['presenti']);

        $this->segnaPresente($delegato);
        $this->assertSame(2, $quorum()['presenti']);
        $this->assertSame(1, $quorum()['presenti_in_persona']);
        $this->assertSame(1, $quorum()['deleghe']);

        // Il delegante arriva di persona: la delega decade, i presenti restano 2
        $this->segnaPresente($delegante);
        $this->assertSame(2, $quorum()['presenti']);
        $this->assertSame(0, $quorum()['deleghe']);
    }

    public function test_segreteria_registra_e_revoca_dalla_pagina_della_riunione(): void
    {
        $this->riunione->forceFill(['aventi_diritto_at' => now()])->save();
        $delegante = $this->avente(['numero_socio' => 'S-1']);
        $delegato = $this->avente(['numero_socio' => 'S-2']);

        $this->actingAs($this->admin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->post(route('riunioni.deleghe.store', $this->riunione), [
                'delegante' => 'S-1',
                'delegato' => 'S-2',
            ])
            ->assertRedirect(route('riunioni.show', $this->riunione))
            ->assertSessionHas('success');

        $delega = DelegaRiunione::query()->firstOrFail();
        $this->assertSame(DelegaRiunione::ORIGINE_SEGRETERIA, $delega->origine);
        $this->assertSame($this->admin->id, $delega->registrata_da);

        $this->actingAs($this->admin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('riunioni.show', $this->riunione))
            ->assertOk()
            ->assertSee($delegante->nome_completo)
            ->assertSee($delegato->nome_completo);

        $this->actingAs($this->admin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->delete(route('riunioni.deleghe.destroy', [$this->riunione, $delega]))
            ->assertSessionHas('success');

        $this->assertSame(0, DelegaRiunione::query()->count());
    }

    public function test_segreteria_con_codice_sconosciuto(): void
    {
        $this->avente(['numero_socio' => 'S-1']);

        $this->actingAs($this->admin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->post(route('riunioni.deleghe.store', $this->riunione), [
                'delegante' => 'S-1',
                'delegato' => 'X-99',
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, DelegaRiunione::query()->count());
    }

    public function test_chi_esce_dall_elenco_perde_le_deleghe(): void
    {
        $delegante = $this->avente();
        $delegato = $this->avente();
        $this->registra($delegante, $delegato);

        $delegante->update(['stato' => Socio::STATO_SOSPESO]);
        app(AssembleaService::class)->generaAventiDiritto($this->riunione);

        $this->assertSame(0, DelegaRiunione::query()->count());
    }

    public function test_verbale_riporta_presenti_per_delega(): void
    {
        $delegante = $this->avente();
        $delegato = $this->avente();
        $this->registra($delegante, $delegato);
        $this->segnaPresente($delegato);
        $this->riunione->update(['stato' => Riunione::STATO_SVOLTA]);

        $this->actingAs($this->admin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->post(route('riunioni.genera-verbale', $this->riunione));

        $verbale = Verbale::query()->firstOrFail();
        $this->assertStringContainsString('(1 di persona, 1 per delega)', $verbale->presenti);
        $this->assertStringContainsString("{$delegante->nome_completo} rappresentato/a da {$delegato->nome_completo}", $verbale->presenti);
    }

    public function test_codice_di_una_tessera_di_un_socio_cancellato(): void
    {
        $delegato = $this->avente(['numero_socio' => 'S-2']);
        $cancellato = $this->avente();
        Tessera::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $cancellato->id,
            'numero_tessera' => 'T-CANC-1',
        ]);
        $cancellato->delete();

        $this->actingAs($this->admin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->post(route('riunioni.deleghe.store', $this->riunione), [
                'delegante' => 'T-CANC-1',
                'delegato' => 'S-2',
            ])
            ->assertRedirect(route('riunioni.show', $this->riunione))
            ->assertSessionHas('error');

        $this->assertSame(0, DelegaRiunione::query()->count());
    }
}
