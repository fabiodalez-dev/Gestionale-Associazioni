<?php

namespace Tests\Feature\Struttura;

use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Models\Verbale;
use App\Models\VotazioneRiunione;
use App\Notifications\Struttura\ConvocazioneRiunioneNotification;
use App\Services\Struttura\AssembleaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Assemblea dei soci (issue #6): elenco aventi diritto con i requisiti
 * (quota pagata, minorenni senza voto), appello con lettura della tessera,
 * quorum costitutivo per convocazione, esiti calcolati dalla maggioranza,
 * convocazione agli aventi diritto e bozza di verbale.
 */
class AssembleaSociTest extends TestCase
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

    private function actAsAdmin(): self
    {
        $this->actingAs($this->admin)
            ->withSession(['associazione_id' => $this->associazione->id]);

        return $this;
    }

    private function socio(array $attributi = []): Socio
    {
        return Socio::factory()->create(array_merge(
            ['associazione_id' => $this->associazione->id],
            $attributi,
        ));
    }

    private function assemblea(array $attributi = []): Riunione
    {
        return Riunione::factory()->assemblea()->create(array_merge(
            ['associazione_id' => $this->associazione->id],
            $attributi,
        ));
    }

    /**
     * Assemblea svolta con $aventi soci aventi diritto, di cui $presenti presenti.
     */
    private function assembleaConPresenti(int $aventi, int $presenti, array $attributi = []): Riunione
    {
        $riunione = $this->assemblea(array_merge(['stato' => Riunione::STATO_SVOLTA], $attributi));

        for ($i = 0; $i < $aventi; $i++) {
            RiunionePartecipante::factory()->create([
                'associazione_id' => $this->associazione->id,
                'riunione_id' => $riunione->id,
                'socio_id' => $this->socio()->id,
                'carica_ruolo' => null,
                'stato' => $i < $presenti ? RiunionePartecipante::STATO_CONFERMATO : RiunionePartecipante::STATO_INVITATO,
            ]);
        }

        return $riunione;
    }

    public function test_admin_crea_un_assemblea_con_seconda_convocazione_e_maggioranze(): void
    {
        $this->actAsAdmin()->post(route('riunioni.store'), [
            'titolo' => 'Assemblea di bilancio',
            'organo' => Riunione::ORGANO_ASSEMBLEA_ORDINARIA,
            'data_ora' => '2026-11-20T08:00',
            'seconda_convocazione_at' => '2026-11-21T18:00',
            'quorum_prima' => '',
            'quorum_seconda' => 'un_terzo',
            'requisito_quota_pagata' => '1',
            'escludi_minorenni_voto' => '0',
            'stato' => Riunione::STATO_PIANIFICATA,
            'odg' => [
                ['punto' => 'Bilancio consuntivo', 'descrizione' => '', 'maggioranza' => 'semplice'],
                ['punto' => 'Modifica regolamento', 'descrizione' => '', 'maggioranza' => 'due_terzi'],
            ],
        ])->assertSessionHasNoErrors();

        $riunione = Riunione::query()->firstOrFail();

        $this->assertTrue($riunione->isAssemblea());
        $this->assertSame('Assemblea Ordinaria', $riunione->organoLabel());
        $this->assertSame('2026-11-21 18:00', $riunione->seconda_convocazione_at->format('Y-m-d H:i'));
        $this->assertNull($riunione->quorum_prima);
        $this->assertSame('maggioranza_assoluta', $riunione->regolaQuorum());
        $this->assertSame('un_terzo', $riunione->regolaQuorum(seconda: true));
        $this->assertTrue($riunione->requisito_quota_pagata);
        $this->assertFalse($riunione->escludi_minorenni_voto);
        // La maggioranza semplice non si salva: è il predefinito
        $this->assertArrayNotHasKey('maggioranza', $riunione->odg[0]);
        $this->assertSame('semplice', $riunione->maggioranzaPunto(0));
        $this->assertSame('due_terzi', $riunione->maggioranzaPunto(1));
    }

    public function test_seconda_convocazione_deve_seguire_la_prima(): void
    {
        $this->actAsAdmin()->post(route('riunioni.store'), [
            'titolo' => 'Assemblea',
            'organo' => Riunione::ORGANO_ASSEMBLEA_ORDINARIA,
            'data_ora' => '2026-11-20T18:00',
            'seconda_convocazione_at' => '2026-11-20T08:00',
            'stato' => Riunione::STATO_PIANIFICATA,
        ])->assertSessionHasErrors('seconda_convocazione_at');
    }

    public function test_riunione_senza_organo_resta_del_direttivo(): void
    {
        $this->actAsAdmin()->post(route('riunioni.store'), [
            'titolo' => 'Direttivo',
            'data_ora' => '2026-11-20T21:00',
            'stato' => Riunione::STATO_PIANIFICATA,
        ])->assertSessionHasNoErrors();

        $riunione = Riunione::query()->firstOrFail();
        $this->assertSame(Riunione::ORGANO_DIRETTIVO, $riunione->organo);
        $this->assertFalse($riunione->isAssemblea());
        $this->assertNull($riunione->regolaQuorum());
    }

    public function test_aventi_diritto_soci_attivi_con_quota_pagata_e_minorenni_senza_voto(): void
    {
        $riunione = $this->assemblea([
            'data_ora' => now()->addWeek(),
            'requisito_quota_pagata' => true,
            'escludi_minorenni_voto' => true,
        ]);
        $anno = (int) $riunione->data_ora->year;

        $inRegola = $this->socio();
        Quota::factory()->pagata()->create(['socio_id' => $inRegola->id, 'anno' => $anno]);

        $minorenne = $this->socio(['data_nascita' => now()->subYears(15)->toDateString()]);
        Quota::factory()->pagata()->create(['socio_id' => $minorenne->id, 'anno' => $anno]);

        $moroso = $this->socio();
        Quota::factory()->create(['socio_id' => $moroso->id, 'anno' => $anno]);

        $quotaVecchia = $this->socio();
        Quota::factory()->pagata()->create(['socio_id' => $quotaVecchia->id, 'anno' => $anno - 1]);

        $sospeso = Socio::factory()->sospeso()->create(['associazione_id' => $this->associazione->id]);
        Quota::factory()->pagata()->create(['socio_id' => $sospeso->id, 'anno' => $anno]);

        // Socio di un'altra associazione: mai in elenco
        $altro = Socio::factory()->create();
        Quota::factory()->pagata()->create(['socio_id' => $altro->id, 'anno' => $anno]);

        $this->actAsAdmin()
            ->post(route('riunioni.aventi-diritto', $riunione))
            ->assertRedirect(route('riunioni.show', $riunione))
            ->assertSessionHas('success');

        $partecipanti = RiunionePartecipante::query()
            ->where('riunione_id', $riunione->id)
            ->get()
            ->keyBy('socio_id');

        $this->assertEqualsCanonicalizing([$inRegola->id, $minorenne->id], $partecipanti->keys()->all());
        $this->assertTrue($partecipanti[$inRegola->id]->avente_diritto_voto);
        $this->assertFalse($partecipanti[$minorenne->id]->avente_diritto_voto);
        $this->assertSame($this->associazione->id, $partecipanti[$inRegola->id]->associazione_id);
        $this->assertNotNull($riunione->fresh()->aventi_diritto_at);

        $quorum = app(AssembleaService::class)->quorum($riunione);
        $this->assertSame(1, $quorum['aventi_diritto']);
    }

    public function test_senza_requisito_quota_hanno_diritto_tutti_i_soci_attivi(): void
    {
        $riunione = $this->assemblea(['requisito_quota_pagata' => false]);
        $this->socio();
        $this->socio();
        Socio::factory()->inAttesa()->create(['associazione_id' => $this->associazione->id]);

        $this->actAsAdmin()->post(route('riunioni.aventi-diritto', $riunione));

        $this->assertSame(2, RiunionePartecipante::query()->where('riunione_id', $riunione->id)->count());
    }

    public function test_aggiornare_l_elenco_rimuove_chi_non_ha_piu_diritto_ma_non_i_presenti(): void
    {
        $riunione = $this->assemblea();
        $resta = $this->socio();
        $daSospendere = $this->socio();
        $presenteSospeso = $this->socio();

        $this->actAsAdmin()->post(route('riunioni.aventi-diritto', $riunione));
        $this->assertSame(3, RiunionePartecipante::query()->where('riunione_id', $riunione->id)->count());

        RiunionePartecipante::query()
            ->where('riunione_id', $riunione->id)
            ->where('socio_id', $presenteSospeso->id)
            ->update(['stato' => RiunionePartecipante::STATO_CONFERMATO]);
        $daSospendere->update(['stato' => Socio::STATO_SOSPESO]);
        $presenteSospeso->update(['stato' => Socio::STATO_SOSPESO]);
        $nuovo = $this->socio();

        $this->actAsAdmin()->post(route('riunioni.aventi-diritto', $riunione));

        $this->assertEqualsCanonicalizing(
            [$resta->id, $presenteSospeso->id, $nuovo->id],
            RiunionePartecipante::query()->where('riunione_id', $riunione->id)->pluck('socio_id')->all(),
        );
    }

    public function test_elenco_non_si_aggiorna_dopo_lo_svolgimento(): void
    {
        $riunione = $this->assemblea(['stato' => Riunione::STATO_SVOLTA]);
        $this->socio();

        $this->actAsAdmin()
            ->post(route('riunioni.aventi-diritto', $riunione))
            ->assertSessionHas('warning');

        $this->assertSame(0, RiunionePartecipante::query()->where('riunione_id', $riunione->id)->count());
    }

    public function test_aventi_diritto_solo_per_le_assemblee(): void
    {
        $direttivo = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);
        $this->socio();

        $this->actAsAdmin()
            ->post(route('riunioni.aventi-diritto', $direttivo))
            ->assertSessionHas('warning');

        $this->assertSame(0, RiunionePartecipante::query()->where('riunione_id', $direttivo->id)->count());
    }

    public function test_quorum_prima_e_seconda_convocazione(): void
    {
        $service = app(AssembleaService::class);

        $riunione = $this->assembleaConPresenti(10, 5, ['seconda_convocazione_at' => now()->addDay()]);
        $quorum = $service->quorum($riunione);

        $this->assertSame(10, $quorum['aventi_diritto']);
        $this->assertSame(5, $quorum['presenti']);
        // Maggioranza assoluta di 10 = 6
        $this->assertSame(6, $quorum['prima']['minimo']);
        $this->assertFalse($quorum['prima']['raggiunto']);
        $this->assertSame(1, $quorum['prima']['mancano']);
        // Seconda convocazione ordinaria: qualunque numero, ma solo dalla sua ora
        $this->assertTrue($quorum['seconda']['raggiunto']);
        $this->assertFalse($quorum['seconda']['iniziata']);
        $this->assertNull($quorum['costituita_in']);

        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $quorum = $service->quorum($riunione);
        $this->assertTrue($quorum['seconda']['iniziata']);
        $this->assertSame('seconda', $quorum['costituita_in']);

        RiunionePartecipante::query()
            ->where('riunione_id', $riunione->id)
            ->where('stato', RiunionePartecipante::STATO_INVITATO)
            ->limit(1)
            ->get()
            ->each->update(['stato' => RiunionePartecipante::STATO_CONFERMATO]);

        $this->assertSame('prima', $service->quorum($riunione)['costituita_in']);
    }

    public function test_quorum_senza_seconda_convocazione_e_assemblea_straordinaria(): void
    {
        $service = app(AssembleaService::class);

        $ordinaria = $this->assembleaConPresenti(4, 2);
        $quorum = $service->quorum($ordinaria);
        $this->assertNull($quorum['seconda']);
        $this->assertNull($quorum['costituita_in']);

        $straordinaria = $this->assembleaConPresenti(8, 5, ['organo' => Riunione::ORGANO_ASSEMBLEA_STRAORDINARIA]);
        $quorum = $service->quorum($straordinaria);
        $this->assertSame('tre_quarti', $quorum['prima']['regola']);
        $this->assertSame(6, $quorum['prima']['minimo']);
        $this->assertFalse($quorum['prima']['raggiunto']);
    }

    public function test_i_presenti_senza_voto_non_contano_per_il_quorum(): void
    {
        $riunione = $this->assembleaConPresenti(2, 1);
        RiunionePartecipante::factory()->confermato()->create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $riunione->id,
            'socio_id' => $this->socio()->id,
            'carica_ruolo' => null,
            'avente_diritto_voto' => false,
        ]);

        $quorum = app(AssembleaService::class)->quorum($riunione);

        $this->assertSame(2, $quorum['aventi_diritto']);
        $this->assertSame(1, $quorum['presenti']);
        $this->assertSame(2, $quorum['presenti_totali']);
    }

    public function test_minimo_presenti_per_ogni_regola(): void
    {
        $service = app(AssembleaService::class);

        $this->assertSame(1, $service->minimoPresenti('qualsiasi', 100));
        $this->assertSame(34, $service->minimoPresenti('un_terzo', 100));
        $this->assertSame(51, $service->minimoPresenti('maggioranza_assoluta', 100));
        $this->assertSame(50, $service->minimoPresenti('maggioranza_assoluta', 99));
        $this->assertSame(67, $service->minimoPresenti('due_terzi', 100));
        $this->assertSame(75, $service->minimoPresenti('tre_quarti', 100));
    }

    public function test_esito_secondo_la_maggioranza(): void
    {
        $service = app(AssembleaService::class);

        $this->assertSame('approvato', $service->esito('semplice', 4, 3, 20));
        $this->assertSame('respinto', $service->esito('semplice', 3, 3, 20));
        $this->assertSame('respinto', $service->esito('assoluta', 10, 0, 20));
        $this->assertSame('approvato', $service->esito('assoluta', 11, 0, 20));
        $this->assertSame('respinto', $service->esito('due_terzi', 13, 0, 20));
        $this->assertSame('approvato', $service->esito('due_terzi', 14, 0, 21));
    }

    public function test_appello_registra_la_presenza_dal_qr_della_tessera(): void
    {
        $riunione = $this->assemblea();
        $socio = $this->socio();
        $tessera = Tessera::factory()->create(['socio_id' => $socio->id]);

        $this->actAsAdmin()->post(route('riunioni.aventi-diritto', $riunione));

        $this->actAsAdmin()
            ->post(route('riunioni.appello.codice', $riunione), [
                'codice' => route('tessere.verifica', $tessera->id),
            ])
            ->assertRedirect(route('riunioni.appello', $riunione))
            ->assertSessionHas('success');

        $partecipante = RiunionePartecipante::query()->where('socio_id', $socio->id)->firstOrFail();
        $this->assertTrue($partecipante->isPresente());
        $this->assertNotNull($partecipante->presente_at);

        // Seconda lettura: già presente, nessuna modifica
        $this->actAsAdmin()
            ->post(route('riunioni.appello.codice', $riunione), ['codice' => $tessera->numero_tessera])
            ->assertSessionHas('warning');
    }

    public function test_appello_per_numero_socio_e_codice_sconosciuto(): void
    {
        $riunione = $this->assemblea();
        $socio = $this->socio(['numero_socio' => 'A-42']);
        $nonAvente = Socio::factory()->sospeso()->create([
            'associazione_id' => $this->associazione->id,
            'numero_socio' => 'B-7',
        ]);

        $this->actAsAdmin()->post(route('riunioni.aventi-diritto', $riunione));

        $this->actAsAdmin()
            ->post(route('riunioni.appello.codice', $riunione), ['codice' => 'A-42'])
            ->assertSessionHas('success');
        $this->assertTrue(RiunionePartecipante::query()->where('socio_id', $socio->id)->firstOrFail()->isPresente());

        $this->actAsAdmin()
            ->post(route('riunioni.appello.codice', $riunione), ['codice' => 'B-7'])
            ->assertSessionHas('error');
        $this->assertFalse(RiunionePartecipante::query()->where('socio_id', $nonAvente->id)->exists());

        $this->actAsAdmin()
            ->post(route('riunioni.appello.codice', $riunione), ['codice' => 'inesistente'])
            ->assertSessionHas('error');
    }

    public function test_pagina_appello_con_ricerca_e_presenza_a_un_clic(): void
    {
        $riunione = $this->assemblea();
        // Cognomi che il faker non genera: la pagina contiene anche altri nomi
        $rossi = $this->socio(['cognome' => 'Rossiqx', 'nome' => 'Anna']);
        $this->socio(['cognome' => 'Bianchiqx', 'nome' => 'Luca']);

        $this->actAsAdmin()->post(route('riunioni.aventi-diritto', $riunione));

        $this->actAsAdmin()
            ->get(route('riunioni.appello', ['riunione' => $riunione, 'search' => 'Rossiq']))
            ->assertOk()
            ->assertSee('Rossiqx')
            ->assertDontSee('Bianchiqx')
            ->assertSee('Quorum costitutivo');

        $partecipante = RiunionePartecipante::query()->where('socio_id', $rossi->id)->firstOrFail();

        $this->actAsAdmin()
            ->put(route('riunioni.partecipanti.presenza', [$riunione, $partecipante]), [
                'stato' => RiunionePartecipante::STATO_CONFERMATO,
                'ritorno' => 'appello',
                'search' => 'Rossiq',
            ])
            ->assertRedirect(route('riunioni.appello', ['riunione' => $riunione, 'search' => 'Rossiq']));

        $this->assertNotNull($partecipante->fresh()->presente_at);

        $this->actAsAdmin()
            ->put(route('riunioni.partecipanti.presenza', [$riunione, $partecipante]), [
                'stato' => RiunionePartecipante::STATO_INVITATO,
                'ritorno' => 'appello',
            ]);

        $this->assertNull($partecipante->fresh()->presente_at);
    }

    public function test_appello_non_disponibile_per_il_direttivo(): void
    {
        $direttivo = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actAsAdmin()
            ->get(route('riunioni.appello', $direttivo))
            ->assertRedirect(route('riunioni.show', $direttivo));
    }

    public function test_appello_di_un_altra_associazione_non_accessibile(): void
    {
        $altra = Riunione::factory()->assemblea()->create();

        $this->actAsAdmin()
            ->get(route('riunioni.appello', $altra))
            ->assertNotFound();
    }

    public function test_operatore_senza_permesso_non_accede_all_appello(): void
    {
        $riunione = $this->assemblea();
        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($operatore)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('riunioni.appello', $riunione))
            ->assertForbidden();

        $this->actingAs($operatore)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->post(route('riunioni.aventi-diritto', $riunione))
            ->assertForbidden();
    }

    public function test_votazione_in_assemblea_calcola_l_esito(): void
    {
        $riunione = $this->assembleaConPresenti(10, 9, [
            'odg' => [
                ['punto' => 'Bilancio', 'descrizione' => null],
                ['punto' => 'Statuto', 'descrizione' => null, 'maggioranza' => 'due_terzi'],
            ],
        ]);

        // L'esito dichiarato viene ignorato: 5 favorevoli su 4 contrari = approvato
        $this->actAsAdmin()->post(route('riunioni.votazioni.store', $riunione), [
            'punto_indice' => 0,
            'esito' => VotazioneRiunione::ESITO_RESPINTO,
            'favorevoli' => 5,
            'contrari' => 4,
            'astenuti' => 0,
        ])->assertSessionHas('success');

        // Due terzi di 9 presenti = 6: con 5 favorevoli è respinto
        $this->actAsAdmin()->post(route('riunioni.votazioni.store', $riunione), [
            'punto_indice' => 1,
            'esito' => VotazioneRiunione::ESITO_APPROVATO,
            'favorevoli' => 5,
            'contrari' => 0,
            'astenuti' => 4,
        ]);

        $esiti = VotazioneRiunione::query()->where('riunione_id', $riunione->id)->pluck('esito', 'punto_indice');
        $this->assertSame('approvato', $esiti[0]);
        $this->assertSame('respinto', $esiti[1]);
    }

    public function test_votazione_in_assemblea_rinviata_e_voti_oltre_i_presenti(): void
    {
        $riunione = $this->assembleaConPresenti(5, 3);

        $this->actAsAdmin()->post(route('riunioni.votazioni.store', $riunione), [
            'punto_indice' => 0,
            'esito' => VotazioneRiunione::ESITO_RINVIATO,
            'favorevoli' => 0,
            'contrari' => 0,
            'astenuti' => 0,
        ]);
        $this->assertSame('rinviato', VotazioneRiunione::query()->where('punto_indice', 0)->value('esito'));

        $this->actAsAdmin()->post(route('riunioni.votazioni.store', $riunione), [
            'punto_indice' => 1,
            'esito' => VotazioneRiunione::ESITO_APPROVATO,
            'favorevoli' => 3,
            'contrari' => 1,
            'astenuti' => 0,
        ])->assertSessionHas('error');
        $this->assertFalse(VotazioneRiunione::query()->where('punto_indice', 1)->exists());
    }

    public function test_votazione_del_direttivo_mantiene_l_esito_dichiarato(): void
    {
        $riunione = Riunione::factory()->svolta()->create(['associazione_id' => $this->associazione->id]);

        $this->actAsAdmin()->post(route('riunioni.votazioni.store', $riunione), [
            'punto_indice' => 0,
            'esito' => VotazioneRiunione::ESITO_RESPINTO,
            'favorevoli' => 5,
            'contrari' => 1,
            'astenuti' => 0,
        ]);

        $this->assertSame('respinto', VotazioneRiunione::query()->where('riunione_id', $riunione->id)->value('esito'));
    }

    public function test_convocazione_dell_assemblea_agli_aventi_diritto_senza_opt_out(): void
    {
        Notification::fake();

        $riunione = $this->assemblea(['seconda_convocazione_at' => now()->addWeek()->addDay()]);
        $conEmail = $this->socio(['email' => 'socio@example.test']);
        $optOut = $this->socio(['email' => 'no@example.test', 'email_opt_out' => true]);
        $sospeso = Socio::factory()->sospeso()->create(['associazione_id' => $this->associazione->id]);

        // L'elenco viene calcolato dalla convocazione stessa
        $this->actAsAdmin()
            ->post(route('riunioni.convoca', $riunione))
            ->assertRedirect(route('riunioni.show', $riunione))
            ->assertSessionHas('success');

        $riunione->refresh();
        $this->assertNotNull($riunione->aventi_diritto_at);
        $this->assertNotNull($riunione->convocazione_inviata_at);

        Notification::assertSentTo($conEmail, ConvocazioneRiunioneNotification::class, function ($notifica) use ($conEmail) {
            $mail = $notifica->toMail($conEmail);

            return str_contains($mail->subject, 'assemblea ordinaria')
                && collect($mail->introLines)->contains(fn ($riga) => str_starts_with($riga, 'Seconda convocazione:'));
        });
        Notification::assertNotSentTo($optOut, ConvocazioneRiunioneNotification::class);
        Notification::assertNotSentTo($sospeso, ConvocazioneRiunioneNotification::class);
        Notification::assertNotSentTo($this->admin, ConvocazioneRiunioneNotification::class);
    }

    public function test_pagina_assemblea_mostra_quorum_e_azioni(): void
    {
        $riunione = $this->assembleaConPresenti(3, 2, ['stato' => Riunione::STATO_PIANIFICATA]);
        $riunione->forceFill(['aventi_diritto_at' => now()])->save();

        $this->actAsAdmin()
            ->get(route('riunioni.show', $riunione))
            ->assertOk()
            ->assertSee('Assemblea Ordinaria')
            ->assertSee('Quorum costitutivo')
            ->assertSee('Aggiorna elenco')
            ->assertSee(route('riunioni.appello', $riunione), false);
    }

    public function test_bozza_verbale_dell_assemblea_con_quorum_ed_esiti(): void
    {
        $riunione = $this->assembleaConPresenti(4, 3, [
            'odg' => [['punto' => 'Statuto', 'descrizione' => null, 'maggioranza' => 'due_terzi']],
        ]);

        VotazioneRiunione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $riunione->id,
            'punto_indice' => 0,
            'esito' => VotazioneRiunione::ESITO_APPROVATO,
            'favorevoli' => 3,
            'contrari' => 0,
            'astenuti' => 0,
        ]);

        $this->actAsAdmin()->post(route('riunioni.genera-verbale', $riunione));

        $verbale = Verbale::query()->firstOrFail();
        $this->assertSame('Assemblea Ordinaria', $verbale->tipo_riunione);
        $this->assertStringContainsString('Aventi diritto al voto: 4.', $verbale->presenti);
        $this->assertStringContainsString('Presenti con diritto di voto: 3 (3 di persona, 0 per delega).', $verbale->presenti);
        $this->assertStringContainsString('validamente costituita in prima convocazione (maggioranza assoluta, minimo 3 presenti)', $verbale->presenti);
        $this->assertStringContainsString('maggioranza dei due terzi', $verbale->deliberazioni);
    }

    public function test_i_punti_con_votazioni_non_si_spostano_ne_si_tolgono(): void
    {
        $riunione = $this->assembleaConPresenti(3, 2, [
            'odg' => [['punto' => 'Bilancio', 'descrizione' => null], ['punto' => 'Statuto', 'descrizione' => null, 'maggioranza' => 'due_terzi']],
        ]);
        VotazioneRiunione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $riunione->id,
            'punto_indice' => 1,
        ]);

        $modifica = fn (array $odg) => $this->actAsAdmin()->put(route('riunioni.update', $riunione), [
            'titolo' => $riunione->titolo,
            'organo' => $riunione->organo,
            'data_ora' => $riunione->data_ora->format('Y-m-d\TH:i'),
            'stato' => $riunione->stato,
            'odg' => $odg,
        ]);

        // Punti scambiati: la votazione sullo statuto finirebbe sul bilancio
        $modifica([['punto' => 'Statuto', 'maggioranza' => 'due_terzi'], ['punto' => 'Bilancio']])->assertSessionHasErrors('odg');
        // Maggioranza cambiata dopo il voto
        $modifica([['punto' => 'Bilancio'], ['punto' => 'Statuto', 'maggioranza' => 'semplice']])->assertSessionHasErrors('odg');
        $this->assertSame('Bilancio', $riunione->fresh()->odg[0]['punto']);

        // Un punto nuovo in fondo è ammesso, come cambiare un punto senza votazioni
        $modifica([['punto' => 'Bilancio consuntivo'], ['punto' => 'Statuto', 'maggioranza' => 'due_terzi'], ['punto' => 'Varie']])->assertSessionHasNoErrors();
        $this->assertCount(3, $riunione->fresh()->odg);
    }
}
