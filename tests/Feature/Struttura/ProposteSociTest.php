<?php

namespace Tests\Feature\Struttura;

use App\Models\AdesioneProposta;
use App\Models\Associazione;
use App\Models\Proposta;
use App\Models\Riunione;
use App\Models\Socio;
use App\Models\Utente;
use App\Models\VotazioneRiunione;
use App\Notifications\Struttura\PropostaNotification;
use App\Services\Struttura\PropostaNonValidaException;
use App\Services\Struttura\ProposteService;
use App\Settings\PartecipazioneSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Proposte dei soci (issue #10): presentazione dall'area soci, moderazione,
 * adesioni con soglia, inserimento nell'OdG ed esito dalla votazione.
 */
class ProposteSociTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    private ProposteService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $this->service = app(ProposteService::class);
        $this->impostazioni(['proposte_soglia' => 3]);
    }

    private function impostazioni(array $valori): void
    {
        $this->associazione->refresh()->impostazioni(PartecipazioneSettings::class)->aggiorna($valori);
        $this->associazione->refresh();
    }

    private function actAsAdmin(): self
    {
        $this->actingAs($this->admin)->withSession(['associazione_id' => $this->associazione->id]);

        return $this;
    }

    private function socio(array $attributi = []): Socio
    {
        return Socio::factory()->conAccessoAreaSoci()->create(array_merge(['associazione_id' => $this->associazione->id], $attributi));
    }

    private function proposta(Socio $proponente, array $attributi = []): Proposta
    {
        return $this->service->presenta($proponente, array_merge([
            'titolo' => 'Corso di fotografia',
            'testo' => 'Organizziamo un corso per i soci.',
            'categoria' => 'attivita',
            'destinatario' => 'direttivo',
        ], $attributi));
    }

    public function test_socio_presenta_una_proposta_che_resta_in_revisione(): void
    {
        $socio = $this->socio();

        $this->actingAs($socio, 'socio')->post(route('area-soci.proposte.store'), [
            'titolo' => 'Gita sociale',
            'testo' => 'Una gita in primavera.',
            'categoria' => 'attivita',
            'destinatario' => 'assemblea',
        ])->assertSessionHas('success');

        $proposta = Proposta::query()->firstOrFail();
        $this->assertSame(Proposta::STATO_IN_REVISIONE, $proposta->stato);
        $this->assertSame($this->associazione->id, $proposta->associazione_id);
        // Il proponente è la prima adesione
        $this->assertTrue(AdesioneProposta::query()->where('socio_id', $socio->id)->exists());

        // Gli altri soci non la vedono finché non è pubblicata
        $this->actingAs($this->socio(), 'socio')->get(route('area-soci.proposte'))->assertOk()->assertDontSee('Gita sociale');
        $this->actingAs($socio, 'socio')->get(route('area-soci.proposte'))->assertSee('Gita sociale');
    }

    public function test_senza_moderazione_la_proposta_e_subito_pubblicata(): void
    {
        $this->impostazioni(['proposte_moderazione' => false]);

        $this->assertSame(Proposta::STATO_PUBBLICATA, $this->proposta($this->socio())->stato);
    }

    public function test_segreteria_pubblica_o_non_ammette_avvisando_il_proponente(): void
    {
        $proponente = $this->socio();
        $daPubblicare = $this->proposta($proponente);
        $daRespingere = $this->proposta($proponente, ['titolo' => 'Duplicata']);

        $this->actAsAdmin()->post(route('proposte.pubblica', $daPubblicare))->assertSessionHas('success');
        $this->actAsAdmin()->post(route('proposte.non-ammettere', $daRespingere), ['motivazione' => 'Già presentata.'])->assertSessionHas('success');

        $this->assertSame(Proposta::STATO_PUBBLICATA, $daPubblicare->fresh()->stato);
        $this->assertSame(Proposta::STATO_NON_AMMESSA, $daRespingere->fresh()->stato);
        $this->assertSame('Già presentata.', $daRespingere->fresh()->motivazione);
        Notification::assertSentToTimes($proponente, PropostaNotification::class, 2);
    }

    public function test_adesioni_uniche_e_soglia_che_avvisa_la_segreteria(): void
    {
        $proposta = $this->proposta($this->socio());
        $this->service->pubblica($proposta);

        $socio = $this->socio();
        $this->actingAs($socio, 'socio')->post(route('area-soci.proposte.aderisci', $proposta))->assertSessionHas('success');
        $this->actingAs($socio, 'socio')->post(route('area-soci.proposte.aderisci', $proposta))->assertSessionHas('error');
        $this->assertSame(Proposta::STATO_PUBBLICATA, $proposta->fresh()->stato);

        // Terza adesione = soglia
        $this->actingAs($this->socio(), 'socio')->post(route('area-soci.proposte.aderisci', $proposta));

        $proposta->refresh();
        $this->assertSame(Proposta::STATO_DA_ESAMINARE, $proposta->stato);
        $this->assertNotNull($proposta->soglia_raggiunta_at);
        Notification::assertSentTo($this->admin, PropostaNotification::class);

        // Ancora adesioni dopo la soglia, finché non è in OdG
        $this->actingAs($this->socio(), 'socio')->post(route('area-soci.proposte.aderisci', $proposta))->assertSessionHas('success');
        $this->assertSame(4, $proposta->adesioni()->count());
    }

    public function test_revoca_dell_adesione_ma_non_del_proponente(): void
    {
        $proponente = $this->socio();
        $proposta = $this->proposta($proponente);
        $this->service->pubblica($proposta);
        $socio = $this->socio();
        $this->service->aderisci($proposta, $socio);

        $this->actingAs($socio, 'socio')->delete(route('area-soci.proposte.revoca', $proposta))->assertSessionHas('success');
        $this->actingAs($proponente, 'socio')->delete(route('area-soci.proposte.revoca', $proposta))->assertSessionHas('error');

        $this->assertSame(1, $proposta->adesioni()->count());
    }

    public function test_soglia_in_percentuale_dei_soci_attivi(): void
    {
        $this->impostazioni(['proposte_soglia' => 25, 'proposte_soglia_percentuale' => true]);
        foreach (range(1, 9) as $i) {
            $this->socio();
        }
        Socio::factory()->sospeso()->create(['associazione_id' => $this->associazione->id]);

        // 25% di 9 attivi = 3 (arrotondato per eccesso)
        $this->assertSame(3, $this->service->soglia($this->associazione));
    }

    public function test_non_si_aderisce_a_proposte_in_revisione(): void
    {
        $proposta = $this->proposta($this->socio());

        $this->expectException(PropostaNonValidaException::class);
        $this->service->aderisci($proposta, $this->socio());
    }

    public function test_inserimento_nell_odg_ed_esito_dalla_votazione(): void
    {
        $proponente = $this->socio();
        $aderente = $this->socio();
        $proposta = $this->proposta($proponente);
        $this->service->pubblica($proposta);
        $this->service->aderisci($proposta, $aderente);

        $riunione = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);
        $puntiPrima = count($riunione->odg);

        $this->actAsAdmin()->post(route('proposte.odg', $proposta), ['riunione_id' => $riunione->id])->assertSessionHas('success');

        $proposta->refresh();
        $riunione->refresh();
        $this->assertSame(Proposta::STATO_IN_ODG, $proposta->stato);
        $this->assertSame($puntiPrima, $proposta->punto_indice);
        $this->assertSame('Corso di fotografia', $riunione->odg[$puntiPrima]['punto']);
        $this->assertSame($proposta->id, $riunione->odg[$puntiPrima]['proposta_id']);
        Notification::assertSentTo($aderente, PropostaNotification::class);

        // Votazione del punto: approvato → accolta
        $riunione->update(['stato' => Riunione::STATO_SVOLTA]);
        $this->actAsAdmin()->post(route('riunioni.votazioni.store', $riunione), [
            'punto_indice' => $puntiPrima,
            'esito' => VotazioneRiunione::ESITO_APPROVATO,
            'favorevoli' => 5,
            'contrari' => 0,
            'astenuti' => 0,
        ]);

        $this->assertSame(Proposta::STATO_ACCOLTA, $proposta->fresh()->stato);
    }

    public function test_proposta_per_l_assemblea_solo_in_un_assemblea(): void
    {
        $proposta = $this->proposta($this->socio(), ['destinatario' => 'assemblea']);
        $this->service->pubblica($proposta);
        $direttivo = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->expectException(PropostaNonValidaException::class);
        $this->service->inserisciInOdg($proposta, $direttivo);
    }

    public function test_riordino_e_rimozione_dall_odg_riallineano_la_proposta(): void
    {
        $proposta = $this->proposta($this->socio());
        $this->service->pubblica($proposta);
        $riunione = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);
        $this->service->inserisciInOdg($proposta, $riunione);

        $odg = $riunione->fresh()->odg;
        $puntoProposta = array_pop($odg);

        // Il punto della proposta diventa il primo
        $this->actAsAdmin()->put(route('riunioni.update', $riunione), [
            'titolo' => $riunione->titolo,
            'data_ora' => $riunione->data_ora->format('Y-m-d\TH:i'),
            'stato' => Riunione::STATO_PIANIFICATA,
            'odg' => array_merge([$puntoProposta], $odg),
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, $proposta->fresh()->punto_indice);

        // Tolto dall'OdG: torna da esaminare
        $this->actAsAdmin()->put(route('riunioni.update', $riunione), [
            'titolo' => $riunione->titolo,
            'data_ora' => $riunione->data_ora->format('Y-m-d\TH:i'),
            'stato' => Riunione::STATO_PIANIFICATA,
            'odg' => $odg,
        ]);
        $proposta->refresh();
        $this->assertSame(Proposta::STATO_DA_ESAMINARE, $proposta->stato);
        $this->assertNull($proposta->riunione_id);
    }

    public function test_esito_registrato_a_mano_e_pagine_della_segreteria(): void
    {
        $proposta = $this->proposta($this->socio());
        $this->service->pubblica($proposta);

        $this->actAsAdmin()->get(route('proposte.index'))->assertOk()->assertSee('Corso di fotografia');
        $this->actAsAdmin()->get(route('proposte.show', $proposta))->assertOk()->assertSee('Inserisci nell');

        $this->actAsAdmin()->post(route('proposte.esito', $proposta), ['esito' => 'respinta', 'motivazione' => 'Costi troppo alti.']);

        $proposta->refresh();
        $this->assertSame(Proposta::STATO_RESPINTA, $proposta->stato);
        $this->assertSame('Costi troppo alti.', $proposta->motivazione);
    }

    public function test_impostazioni_e_permessi(): void
    {
        $this->actAsAdmin()->post(route('proposte.impostazioni'), [
            'proposte_soglia' => 150,
            'proposte_soglia_percentuale' => '1',
            'proposte_moderazione' => '0',
        ])->assertSessionHasErrors('proposte_soglia');

        $this->actAsAdmin()->post(route('proposte.impostazioni'), [
            'proposte_soglia' => 20,
            'proposte_soglia_percentuale' => '1',
            'proposte_moderazione' => '0',
        ])->assertSessionHas('success');

        $impostazioni = $this->associazione->refresh()->impostazioni(PartecipazioneSettings::class);
        $this->assertSame(20, $impostazioni->proposte_soglia);
        $this->assertTrue($impostazioni->proposte_soglia_percentuale);
        $this->assertFalse($impostazioni->proposte_moderazione);

        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($operatore)->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('proposte.index'))->assertForbidden();
    }

    public function test_proposta_di_altra_associazione_non_accessibile(): void
    {
        $altro = Socio::factory()->create();
        $proposta = Proposta::withoutEvents(fn () => Proposta::create([
            'associazione_id' => $altro->associazione_id,
            'socio_id' => $altro->id,
            'titolo' => 'Altrove',
            'testo' => '…',
            'categoria' => 'altro',
            'destinatario' => 'direttivo',
            'stato' => Proposta::STATO_PUBBLICATA,
        ]));

        $this->actAsAdmin()->get(route('proposte.show', $proposta))->assertNotFound();
        $this->actingAs($this->socio(), 'socio')->post(route('area-soci.proposte.aderisci', $proposta))->assertNotFound();
    }
}
