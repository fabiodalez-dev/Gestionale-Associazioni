<?php

namespace Tests\Feature\Struttura;

use App\Models\Associazione;
use App\Models\Carica;
use App\Models\ConflittoRiunione;
use App\Models\DelegaRiunione;
use App\Models\DichiarazioneInteressi;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Socio;
use App\Models\Utente;
use App\Models\Verbale;
use App\Models\VotazioneRiunione;
use App\Models\VotoRiunione;
use App\Notifications\Struttura\DichiarazioneInteressiNotification;
use App\Services\Struttura\ConflittiService;
use App\Services\Struttura\ConflittoNonValidoException;
use App\Services\Struttura\VotoAssembleaService;
use App\Services\Struttura\VotoNonValidoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Conflitti di interesse (issue #11): dichiarazioni annuali dei titolari di
 * cariche con raccolta e promemoria, conflitti per punto che escludono dal
 * voto, verbale e registro.
 */
class ConflittiInteresseTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
    }

    private function actAsAdmin(): self
    {
        $this->actingAs($this->admin)->withSession(['associazione_id' => $this->associazione->id]);

        return $this;
    }

    private function titolare(string $ruolo = 'Consigliere'): Socio
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create(['associazione_id' => $this->associazione->id]);
        Carica::factory()->create(['associazione_id' => $this->associazione->id, 'socio_id' => $socio->id, 'ruolo' => $ruolo]);

        return $socio;
    }

    private function partecipante(Riunione $riunione, Socio $socio, bool $presente = true): void
    {
        RiunionePartecipante::factory()->create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $riunione->id,
            'socio_id' => $socio->id,
            'carica_ruolo' => null,
            'stato' => $presente ? RiunionePartecipante::STATO_CONFERMATO : RiunionePartecipante::STATO_INVITATO,
        ]);
    }

    public function test_titolare_dichiara_dall_area_soci_e_aggiorna(): void
    {
        $socio = $this->titolare();

        $this->actingAs($socio, 'socio')->get(route('area-soci.dichiarazione-interessi'))->assertOk()->assertSee('Invia la dichiarazione');

        $this->actingAs($socio, 'socio')->post(route('area-soci.dichiarazione-interessi.store'), ['nessun_conflitto' => '1'])->assertSessionHas('success');
        $dichiarazione = DichiarazioneInteressi::query()->firstOrFail();
        $this->assertTrue($dichiarazione->nessun_conflitto);
        $this->assertSame((int) now()->year, $dichiarazione->anno);
        $this->assertNull($dichiarazione->registrata_da);

        $this->actingAs($socio, 'socio')->post(route('area-soci.dichiarazione-interessi.store'), [
            'nessun_conflitto' => '0',
            'interessi' => 'Socio della ditta fornitrice Alfa srl.',
        ])->assertSessionHas('success');

        $this->assertSame(1, DichiarazioneInteressi::query()->count());
        $this->assertSame('Socio della ditta fornitrice Alfa srl.', $dichiarazione->fresh()->interessi);
        $this->assertFalse($dichiarazione->fresh()->nessun_conflitto);
    }

    public function test_dichiarazione_vuota_o_di_chi_non_ha_cariche_rifiutata(): void
    {
        $titolare = $this->titolare();
        $this->actingAs($titolare, 'socio')->post(route('area-soci.dichiarazione-interessi.store'), ['nessun_conflitto' => '0'])->assertSessionHas('error');

        $socio = Socio::factory()->conAccessoAreaSoci()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($socio, 'socio')->get(route('area-soci.dichiarazione-interessi'))->assertOk()->assertSee('non risultano cariche');
        $this->actingAs($socio, 'socio')->post(route('area-soci.dichiarazione-interessi.store'), ['nessun_conflitto' => '1'])->assertSessionHas('error');

        $this->assertSame(0, DichiarazioneInteressi::query()->count());
    }

    public function test_raccolta_avvisa_chi_manca_e_il_promemoria_e_mensile(): void
    {
        $daAvvisare = $this->titolare('Presidente');
        $giaDichiarato = $this->titolare('Tesoriere');
        app(ConflittiService::class)->dichiara($giaDichiarato, (int) now()->year, true, null);
        // Carica di un'altra associazione: mai coinvolta
        $estraneo = Socio::factory()->create();
        Carica::factory()->create(['associazione_id' => $estraneo->associazione_id, 'socio_id' => $estraneo->id]);

        $this->actAsAdmin()->post(route('conflitti.raccolta'), ['anno' => now()->year])->assertSessionHas('success');

        Notification::assertSentToTimes($daAvvisare, DichiarazioneInteressiNotification::class, 1);
        Notification::assertNotSentTo($giaDichiarato, DichiarazioneInteressiNotification::class);
        Notification::assertNotSentTo($estraneo, DichiarazioneInteressiNotification::class);

        // Il comando mensile non reinvia nello stesso mese
        $this->artisan('conflitti:promemoria-dichiarazioni')->assertSuccessful();
        Notification::assertSentToTimes($daAvvisare, DichiarazioneInteressiNotification::class, 1);

        // Il mese dopo sì
        $this->travel(1)->months();
        $this->artisan('conflitti:promemoria-dichiarazioni')->assertSuccessful();
        Notification::assertSentToTimes($daAvvisare, DichiarazioneInteressiNotification::class, 2);

        // Raccolta fermata: niente promemoria
        $this->actAsAdmin()->delete(route('conflitti.raccolta.ferma'));
        $this->travel(1)->months();
        $this->artisan('conflitti:promemoria-dichiarazioni')->assertSuccessful();
        Notification::assertSentToTimes($daAvvisare, DichiarazioneInteressiNotification::class, 2);
    }

    public function test_registro_e_pdf_per_la_segreteria(): void
    {
        $socio = $this->titolare('Presidente');
        app(ConflittiService::class)->dichiara($socio, (int) now()->year, false, 'Consigliere di altra associazione.');

        $this->actAsAdmin()->get(route('conflitti.index'))
            ->assertOk()
            ->assertSee($socio->nome_completo)
            ->assertSee('Consigliere di altra associazione.');

        $this->actAsAdmin()->get(route('conflitti.pdf'))->assertOk()->assertHeader('content-type', 'application/pdf');

        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($operatore)->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('conflitti.index'))->assertForbidden();
    }

    public function test_segreteria_registra_la_dichiarazione_cartacea(): void
    {
        $socio = $this->titolare();

        $this->actAsAdmin()->post(route('conflitti.dichiarazioni.store'), [
            'socio_id' => $socio->id,
            'anno' => now()->year,
            'nessun_conflitto' => '1',
        ])->assertSessionHas('success');

        $this->assertSame($this->admin->id, DichiarazioneInteressi::query()->value('registrata_da'));
    }

    public function test_conflitto_nel_direttivo_limita_i_voti_e_compare_nel_verbale(): void
    {
        $riunione = Riunione::factory()->svolta()->create(['associazione_id' => $this->associazione->id]);
        $soci = collect(range(1, 3))->map(fn () => $this->titolare());
        $soci->each(fn (Socio $s) => $this->partecipante($riunione, $s));

        $this->actAsAdmin()->post(route('riunioni.conflitti.store', $riunione), [
            'punto_indice' => 0,
            'socio' => $soci[0]->id,
            'motivazione' => 'Fornitore coinvolto',
        ])->assertSessionHas('success');

        // 3 presenti, 1 in conflitto: al massimo 2 voti
        $this->actAsAdmin()->post(route('riunioni.votazioni.store', $riunione), [
            'punto_indice' => 0, 'esito' => 'approvato', 'favorevoli' => 3, 'contrari' => 0, 'astenuti' => 0,
        ])->assertSessionHas('error');

        $this->actAsAdmin()->post(route('riunioni.votazioni.store', $riunione), [
            'punto_indice' => 0, 'esito' => 'approvato', 'favorevoli' => 2, 'contrari' => 0, 'astenuti' => 0,
        ])->assertSessionHas('success');

        $this->actAsAdmin()->post(route('riunioni.genera-verbale', $riunione));
        $this->assertStringContainsString(
            "{$soci[0]->nome_completo} dichiara un conflitto di interesse e non partecipa al voto (Fornitore coinvolto).",
            Verbale::query()->firstOrFail()->deliberazioni,
        );
    }

    public function test_conflitto_solo_per_partecipanti_e_punti_esistenti(): void
    {
        $riunione = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);
        $service = app(ConflittiService::class);
        $socio = $this->titolare();

        try {
            $service->dichiaraConflitto($riunione, 0, $socio, null);
            $this->fail('Conflitto di un non partecipante accettato');
        } catch (ConflittoNonValidoException) {
            $this->addToAssertionCount(1);
        }

        $this->partecipante($riunione, $socio);
        $this->expectException(ConflittoNonValidoException::class);
        $service->dichiaraConflitto($riunione, 99, $socio, null);
    }

    public function test_in_assemblea_chi_e_in_conflitto_non_vota_online_ne_per_delega_e_esce_dalla_base(): void
    {
        $riunione = Riunione::factory()->assemblea()->create([
            'associazione_id' => $this->associazione->id,
            'data_ora' => now()->subMinutes(5),
            'voto_online' => true,
        ]);
        $inConflitto = $this->titolare();
        $altri = collect(range(1, 3))->map(fn () => $this->titolare());
        $this->partecipante($riunione, $inConflitto);
        $altri->each(fn (Socio $s) => $this->partecipante($riunione, $s));

        app(ConflittiService::class)->dichiaraConflitto($riunione, 0, $inConflitto, null);
        $voto = app(VotoAssembleaService::class);

        $this->assertTrue($voto->rappresentati($riunione, $inConflitto, 0)->isEmpty());
        $this->assertTrue($voto->rappresentati($riunione, $inConflitto, 1)->has($inConflitto->id));

        $votazione = $voto->apri($riunione, 0, false);

        try {
            $voto->vota($votazione, $inConflitto, [$inConflitto->id => VotoRiunione::FAVOREVOLE]);
            $this->fail('Voto di chi è in conflitto accettato');
        } catch (VotoNonValidoException) {
            $this->addToAssertionCount(1);
        }

        // 4 presenti, 1 in conflitto: base 3; 2 favorevoli su 3 = maggioranza assoluta raggiunta
        $riunione->update(['odg' => [['punto' => 'Contratto', 'descrizione' => null, 'maggioranza' => 'assoluta'], ['punto' => 'Varie', 'descrizione' => null]]]);
        $voto->vota($votazione, $altri[0], [$altri[0]->id => VotoRiunione::FAVOREVOLE]);
        $voto->vota($votazione, $altri[1], [$altri[1]->id => VotoRiunione::FAVOREVOLE]);
        $voto->vota($votazione, $altri[2], [$altri[2]->id => VotoRiunione::CONTRARIO]);

        $this->assertSame(VotazioneRiunione::ESITO_APPROVATO, $voto->chiudi($votazione->fresh())->esito);
        $this->assertSame(1, ConflittoRiunione::query()->count());
    }

    public function test_le_deleghe_affidate_a_chi_e_in_conflitto_escono_dalla_base(): void
    {
        $riunione = Riunione::factory()->assemblea()->create([
            'associazione_id' => $this->associazione->id,
            'data_ora' => now()->subMinutes(5),
        ]);
        $delegato = $this->titolare();
        $this->partecipante($riunione, $delegato);

        foreach (range(1, 2) as $i) {
            $delegante = $this->titolare();
            $this->partecipante($riunione, $delegante, presente: false);
            DelegaRiunione::create([
                'associazione_id' => $this->associazione->id,
                'riunione_id' => $riunione->id,
                'delegante_socio_id' => $delegante->id,
                'delegato_socio_id' => $delegato->id,
                'origine' => DelegaRiunione::ORIGINE_SEGRETERIA,
            ]);
        }

        $service = app(ConflittiService::class);
        $service->dichiaraConflitto($riunione, 0, $delegato, null);

        // Il delegato in conflitto non vota né per sé né per i deleganti: tre voti fuori dalla base
        $this->assertTrue(app(VotoAssembleaService::class)->rappresentati($riunione, $delegato, 0)->isEmpty());
        $this->assertSame(3, $service->presentiInConflitto($riunione, 0));
        $this->assertSame(0, $service->presentiInConflitto($riunione, 1));
    }

    public function test_i_soci_cancellati_non_ricevono_la_richiesta_di_dichiarazione(): void
    {
        $attivo = $this->titolare('Presidente');
        $cancellato = $this->titolare('Tesoriere');
        $cancellato->delete();

        $titolari = app(ConflittiService::class)->titolari($this->associazione, (int) now()->year);

        $this->assertSame([$attivo->id], $titolari->pluck('id')->all());
    }
}
