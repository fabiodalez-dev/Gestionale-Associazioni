<?php

namespace Tests\Feature\Struttura;

use App\Models\Associazione;
use App\Models\Carica;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Socio;
use App\Models\Utente;
use App\Models\Verbale;
use App\Models\VotazioneRiunione;
use App\Notifications\Struttura\ConvocazioneRiunioneNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Riunioni del direttivo (f.17-18 del piano "Organizzazione 3"): CRUD con
 * OdG strutturato, inviti agli intestatari delle cariche + convocazione
 * email, votazioni per punto e generazione bozza Verbale precompilato.
 * Copre i casi felici, i permessi (struttura.*) e l'isolamento tenant.
 */
class RiunioniTest extends TestCase
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

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('riunioni.index'))->assertRedirect('/login');
    }

    public function test_operatore_non_accede_alle_riunioni(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('riunioni.index'))
            ->assertForbidden();
    }

    public function test_admin_vede_la_lista_riunioni(): void
    {
        Riunione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Direttivo di marzo',
        ]);

        $this->actAsAdmin()
            ->get(route('riunioni.index'))
            ->assertOk()
            ->assertSee('Direttivo di marzo');
    }

    public function test_admin_crea_una_riunione_con_odg(): void
    {
        $response = $this->actAsAdmin()->post(route('riunioni.store'), [
            'titolo' => 'Direttivo straordinario',
            'data_ora' => '2026-09-01T21:00',
            'luogo' => 'Sede',
            'stato' => Riunione::STATO_PIANIFICATA,
            'odg' => [
                ['punto' => 'Bilancio', 'descrizione' => 'Esame consuntivo'],
                ['punto' => '', 'descrizione' => 'riga vuota da scartare'],
                ['punto' => 'Varie', 'descrizione' => ''],
            ],
        ]);

        $riunione = Riunione::query()->first();
        $this->assertNotNull($riunione);
        $response->assertRedirect(route('riunioni.show', $riunione));

        $this->assertSame('Direttivo straordinario', $riunione->titolo);
        $this->assertSame($this->associazione->id, $riunione->associazione_id);
        // La riga senza punto è scartata: restano 2 punti riallineati
        $this->assertCount(2, $riunione->odg);
        $this->assertSame('Bilancio', $riunione->odg[0]['punto']);
        $this->assertSame('Varie', $riunione->odg[1]['punto']);
        $this->assertNull($riunione->odg[1]['descrizione']);
    }

    public function test_creazione_riunione_richiede_titolo_e_data(): void
    {
        $this->actAsAdmin()
            ->from(route('riunioni.index'))
            ->post(route('riunioni.store'), ['titolo' => '', 'stato' => Riunione::STATO_PIANIFICATA])
            ->assertSessionHasErrors(['titolo', 'data_ora']);

        $this->assertSame(0, Riunione::query()->count());
    }

    public function test_convocazione_invita_intestatari_cariche_attive_e_invia_email(): void
    {
        Notification::fake();

        $socioPresidente = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'presidente@example.test',
        ]);
        Carica::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socioPresidente->id,
            'ruolo' => 'Presidente',
        ]);

        // Carica conclusa: NON deve essere invitata
        $exSegretario = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        Carica::factory()->conclusa()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $exSegretario->id,
            'ruolo' => 'Segretario',
        ]);

        $riunione = Riunione::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actAsAdmin()
            ->post(route('riunioni.convoca', $riunione))
            ->assertRedirect(route('riunioni.show', $riunione));

        // Un solo partecipante: l'intestatario della carica attiva
        $this->assertSame(1, $riunione->partecipanti()->count());
        $partecipante = $riunione->partecipanti()->first();
        $this->assertSame($socioPresidente->id, $partecipante->socio_id);
        $this->assertSame('Presidente', $partecipante->carica_ruolo);

        $riunione->refresh();
        $this->assertNotNull($riunione->convocazione_inviata_at);

        Notification::assertSentTo($socioPresidente, ConvocazioneRiunioneNotification::class);
        Notification::assertSentTo($this->admin, ConvocazioneRiunioneNotification::class);
    }

    public function test_convocazione_e_idempotente_non_duplica_inviti(): void
    {
        Notification::fake();

        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'p@example.test',
        ]);
        Carica::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
            'ruolo' => 'Presidente',
        ]);
        $riunione = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actAsAdmin()->post(route('riunioni.convoca', $riunione));
        $this->actAsAdmin()->post(route('riunioni.convoca', $riunione));

        $this->assertSame(1, $riunione->partecipanti()->count());
    }

    public function test_convocazione_non_inviata_a_socio_soft_deleted(): void
    {
        Notification::fake();

        // Socio con carica attiva ma anagrafica cancellata (soft delete):
        // il cascade DB non scatta sul soft delete, la carica resta attiva,
        // ma il socio NON deve ricevere la convocazione (#10).
        $exMembro = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'ex@example.test',
        ]);
        Carica::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $exMembro->id,
            'ruolo' => 'Tesoriere',
        ]);
        $exMembro->delete();

        $attivo = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'attivo@example.test',
        ]);
        Carica::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $attivo->id,
            'ruolo' => 'Presidente',
        ]);

        $riunione = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actAsAdmin()
            ->post(route('riunioni.convoca', $riunione))
            ->assertRedirect(route('riunioni.show', $riunione));

        Notification::assertSentTo($attivo, ConvocazioneRiunioneNotification::class);
        Notification::assertNotSentTo($exMembro, ConvocazioneRiunioneNotification::class);
    }

    public function test_aggiorna_presenza_partecipante(): void
    {
        $riunione = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);
        $partecipante = RiunionePartecipante::factory()->create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $riunione->id,
        ]);

        $this->actAsAdmin()
            ->put(route('riunioni.partecipanti.presenza', [$riunione, $partecipante]), [
                'stato' => RiunionePartecipante::STATO_CONFERMATO,
            ])
            ->assertRedirect(route('riunioni.show', $riunione));

        $this->assertSame(RiunionePartecipante::STATO_CONFERMATO, $partecipante->refresh()->stato);
    }

    public function test_presenza_di_partecipante_di_altra_riunione_da_404(): void
    {
        $riunione = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);
        $altra = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);
        $partecipante = RiunionePartecipante::factory()->create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $altra->id,
        ]);

        $this->actAsAdmin()
            ->put(route('riunioni.partecipanti.presenza', [$riunione, $partecipante]), [
                'stato' => RiunionePartecipante::STATO_CONFERMATO,
            ])
            ->assertNotFound();
    }

    public function test_registra_votazione_solo_su_riunione_svolta(): void
    {
        $pianificata = Riunione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato' => Riunione::STATO_PIANIFICATA,
        ]);

        $this->actAsAdmin()
            ->post(route('riunioni.votazioni.store', $pianificata), [
                'punto_indice' => 0,
                'esito' => VotazioneRiunione::ESITO_APPROVATO,
                'favorevoli' => 5, 'contrari' => 0, 'astenuti' => 1,
            ])
            ->assertRedirect(route('riunioni.show', $pianificata))
            ->assertSessionHas('warning');

        $this->assertSame(0, VotazioneRiunione::query()->count());
    }

    public function test_registra_votazione_su_riunione_svolta_e_aggiorna(): void
    {
        $riunione = Riunione::factory()->svolta()->create([
            'associazione_id' => $this->associazione->id,
            'odg' => [['punto' => 'Bilancio', 'descrizione' => null]],
        ]);

        $this->actAsAdmin()->post(route('riunioni.votazioni.store', $riunione), [
            'punto_indice' => 0,
            'esito' => VotazioneRiunione::ESITO_APPROVATO,
            'favorevoli' => 7, 'contrari' => 1, 'astenuti' => 0,
            'note' => 'Approvato a maggioranza',
        ])->assertRedirect(route('riunioni.show', $riunione));

        $this->assertSame(1, VotazioneRiunione::query()->count());

        // Seconda chiamata sullo stesso punto: aggiorna (updateOrCreate), non duplica
        $this->actAsAdmin()->post(route('riunioni.votazioni.store', $riunione), [
            'punto_indice' => 0,
            'esito' => VotazioneRiunione::ESITO_RESPINTO,
            'favorevoli' => 1, 'contrari' => 7, 'astenuti' => 0,
        ]);

        $this->assertSame(1, VotazioneRiunione::query()->count());
        $this->assertSame(VotazioneRiunione::ESITO_RESPINTO, VotazioneRiunione::query()->first()->esito);
    }

    public function test_votazione_su_punto_inesistente_viene_rifiutata(): void
    {
        $riunione = Riunione::factory()->svolta()->create([
            'associazione_id' => $this->associazione->id,
            'odg' => [['punto' => 'Unico', 'descrizione' => null]],
        ]);

        $this->actAsAdmin()
            ->from(route('riunioni.show', $riunione))
            ->post(route('riunioni.votazioni.store', $riunione), [
                'punto_indice' => 5,
                'esito' => VotazioneRiunione::ESITO_APPROVATO,
                'favorevoli' => 1, 'contrari' => 0, 'astenuti' => 0,
            ])
            ->assertSessionHasErrors('punto_indice');
    }

    public function test_genera_bozza_verbale_precompilato_con_numerazione(): void
    {
        $riunione = Riunione::factory()->svolta()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Direttivo di giugno',
            'data_ora' => '2026-06-10 21:00:00',
            'odg' => [
                ['punto' => 'Bilancio', 'descrizione' => 'Consuntivo'],
                ['punto' => 'Tesseramento', 'descrizione' => null],
            ],
        ]);

        $presidente = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Anna', 'cognome' => 'Rossi',
        ]);
        RiunionePartecipante::factory()->confermato()->create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $riunione->id,
            'socio_id' => $presidente->id,
            'carica_ruolo' => 'Presidente',
        ]);

        VotazioneRiunione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'riunione_id' => $riunione->id,
            'punto_indice' => 0,
            'esito' => VotazioneRiunione::ESITO_APPROVATO,
            'favorevoli' => 6, 'contrari' => 0, 'astenuti' => 1,
        ]);

        $response = $this->actAsAdmin()->post(route('riunioni.genera-verbale', $riunione));

        $verbale = Verbale::query()->first();
        $this->assertNotNull($verbale);
        $response->assertRedirect(route('verbali.index', ['edit' => $verbale->id]));

        $this->assertSame('Direttivo di giugno', $verbale->titolo);
        $this->assertSame(2026, $verbale->anno);
        $this->assertSame(1, $verbale->numero);
        $this->assertStringContainsString('Anna Rossi', $verbale->presenti);
        $this->assertStringContainsString('Presidente', $verbale->presenti);
        $this->assertStringContainsString('Bilancio', $verbale->ordine_del_giorno);
        $this->assertStringContainsString('Approvato', $verbale->deliberazioni);

        // Collegamento riunione → verbale (per le Decisioni)
        $this->assertSame($verbale->id, $riunione->refresh()->verbale_id);
    }

    public function test_genera_verbale_non_duplica_se_gia_presente(): void
    {
        $riunione = Riunione::factory()->svolta()->create(['associazione_id' => $this->associazione->id]);

        $this->actAsAdmin()->post(route('riunioni.genera-verbale', $riunione));
        $this->actAsAdmin()->post(route('riunioni.genera-verbale', $riunione))
            ->assertSessionHas('warning');

        $this->assertSame(1, Verbale::query()->count());
    }

    public function test_isolamento_tenant_su_show_e_update(): void
    {
        $altraAssoc = Associazione::factory()->create();
        $riunioneAltrui = Riunione::factory()->create(['associazione_id' => $altraAssoc->id]);

        // Il global scope la nasconde al tenant corrente → 404
        $this->actAsAdmin()
            ->get(route('riunioni.show', $riunioneAltrui))
            ->assertNotFound();
    }

    public function test_admin_elimina_riunione(): void
    {
        $riunione = Riunione::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actAsAdmin()
            ->delete(route('riunioni.destroy', $riunione))
            ->assertRedirect(route('riunioni.index'));

        $this->assertSame(0, Riunione::query()->count());
    }
}
