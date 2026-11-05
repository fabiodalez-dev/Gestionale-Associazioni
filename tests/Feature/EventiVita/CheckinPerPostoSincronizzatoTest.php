<?php

namespace Tests\Feature\EventiVita;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Prenotazione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Services\EventiVita\FeedbackEventiService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Check-in per singolo posto (posti_entrati) e origine del check-in
 * (checkin_origine) delle prenotazioni:
 * - il check-in con la tessera del socio (backoffice, kiosk, API v1) fa
 *   entrare solo il suo posto, il biglietto resta valido per i compagni;
 * - la scansione del biglietto e le presenze in blocco registrano i posti
 *   rimanenti, conservando l'orario del primo ingresso;
 * - rimuovendo il partecipante socio o portandolo fuori da "Confermato" il
 *   check-in sincronizzato (tessera/api) si annulla, quello manuale resta.
 */
class CheckinPerPostoSincronizzatoTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    private Evento $evento;

    private Socio $socio;

    private Tessera $tessera;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        // Gli endpoint di check-in richiedono i permessi spatie
        $this->seed(RolesSeeder::class);

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->admin->assignRole('admin_associazione');

        $this->evento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);
        $this->socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato' => Socio::STATO_ATTIVO,
        ]);
        $this->tessera = Tessera::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $this->socio->id,
        ]);
        $this->account = Account::factory()->verificato()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $this->socio->id,
        ]);
    }

    private function prenotazioneDelSocio(int $posti): Prenotazione
    {
        return Prenotazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $this->evento->id,
            'account_id' => $this->account->id,
            'posti' => $posti,
        ]);
    }

    private function checkinTessera(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('tessere.checkin.store'), [
                'evento_id' => $this->evento->id,
                'tessera_id' => $this->tessera->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    private function scansionaBiglietto(Prenotazione $prenotazione): TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/biglietti/checkin', [
            'qr_token' => $prenotazione->qr_token,
            'evento_id' => $this->evento->id,
        ]);
    }

    private function contatoreKiosk(): int
    {
        return (int) $this->actingAs($this->admin)
            ->get("/checkin-kiosk/{$this->evento->id}/contatore")
            ->assertOk()
            ->json('presenze');
    }

    private function partecipante(): EventoPartecipante
    {
        return EventoPartecipante::query()
            ->where('evento_id', $this->evento->id)
            ->where('socio_id', $this->socio->id)
            ->sole();
    }

    // ------------------------------------------------------------------
    // #19 — check-in per singolo posto
    // ------------------------------------------------------------------

    public function test_checkin_con_tessera_fa_entrare_solo_il_posto_del_socio(): void
    {
        $prenotazione = $this->prenotazioneDelSocio(3);

        $this->checkinTessera();

        $prenotazione->refresh();
        $this->assertNotNull($prenotazione->checkin_at);
        $this->assertSame(1, $prenotazione->posti_entrati);
        $this->assertSame(Prenotazione::CHECKIN_ORIGINE_TESSERA, $prenotazione->checkin_origine);
        $this->assertSame(2, $prenotazione->postiDaEntrare());

        // Il kiosk conta il socio, non i due compagni ancora fuori
        $this->assertSame(1, $this->contatoreKiosk());
    }

    public function test_scansione_del_biglietto_dopo_la_tessera_registra_i_posti_rimanenti(): void
    {
        $prenotazione = $this->prenotazioneDelSocio(3);

        $this->travelTo(now()->setTime(20, 0));
        $this->checkinTessera();
        $primoIngresso = $prenotazione->refresh()->checkin_at;

        $this->travelTo(now()->setTime(20, 15));
        $this->scansionaBiglietto($prenotazione)
            ->assertOk()
            ->assertJson([
                'success' => true,
                'already_checked_in' => false,
                'posti_registrati' => 2,
                'biglietto' => ['posti' => 3, 'posti_entrati' => 3, 'posti_da_entrare' => 0],
            ]);

        $prenotazione->refresh();
        $this->assertSame(3, $prenotazione->posti_entrati);
        $this->assertSame(Prenotazione::CHECKIN_ORIGINE_BIGLIETTO, $prenotazione->checkin_origine);
        // checkin_at resta l'orario del primo ingresso
        $this->assertTrue($primoIngresso->equalTo($prenotazione->checkin_at));
        $this->assertSame(3, $this->contatoreKiosk());

        // Terza scansione: ora il biglietto è usato per intero
        $this->scansionaBiglietto($prenotazione)
            ->assertOk()
            ->assertJson(['already_checked_in' => true, 'posti_registrati' => 0]);
    }

    public function test_biglietto_da_un_posto_risulta_usato_dopo_la_tessera(): void
    {
        $prenotazione = $this->prenotazioneDelSocio(1);

        $this->checkinTessera();

        $this->scansionaBiglietto($prenotazione)
            ->assertOk()
            ->assertJson(['already_checked_in' => true]);
    }

    public function test_prima_scansione_del_biglietto_registra_tutti_i_posti(): void
    {
        $prenotazione = $this->prenotazioneDelSocio(4);

        $this->scansionaBiglietto($prenotazione)
            ->assertOk()
            ->assertJson(['already_checked_in' => false, 'posti_registrati' => 4, 'message' => 'Check-in registrato.']);

        $prenotazione->refresh();
        $this->assertSame(4, $prenotazione->posti_entrati);
        $this->assertSame(Prenotazione::CHECKIN_ORIGINE_BIGLIETTO, $prenotazione->checkin_origine);
        $this->assertSame((string) $this->admin->id, (string) $prenotazione->checkin_utente_id);
        $this->assertSame(4, $this->contatoreKiosk());
    }

    public function test_verifica_pubblica_mostra_i_posti_ancora_da_far_entrare(): void
    {
        $prenotazione = $this->prenotazioneDelSocio(3);
        $this->checkinTessera();

        $url = URL::signedRoute('biglietti.verifica', ['token' => $prenotazione->qr_token]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Biglietto valido')
            ->assertSee('Posti ancora da far entrare:')
            ->assertDontSee('Biglietto già utilizzato');
    }

    public function test_presenze_in_blocco_completano_l_ingresso_iniziato_con_la_tessera(): void
    {
        $prenotazione = $this->prenotazioneDelSocio(3);
        $this->checkinTessera();
        $primoIngresso = $prenotazione->refresh()->checkin_at;

        // La riga parzialmente entrata resta selezionabile (checkbox e
        // pulsante di registrazione attivi)
        $this->actingAs($this->admin)
            ->get(route('eventi.presenze.index', $this->evento))
            ->assertOk()
            ->assertSee('1/3 entrati')
            ->assertSee('value="'.$prenotazione->id.'" >', false)
            ->assertDontSee('btn-primary btn-sm" disabled', false);

        $this->travel(10)->minutes();

        $this->actingAs($this->admin)
            ->post(route('eventi.presenze.store', $this->evento), ['prenotazioni' => [$prenotazione->id]])
            ->assertSessionHas('success', 'Presenze registrate: 1.');

        $prenotazione->refresh();
        $this->assertSame(3, $prenotazione->posti_entrati);
        $this->assertSame(Prenotazione::CHECKIN_ORIGINE_PRESENZE_BLOCCO, $prenotazione->checkin_origine);
        $this->assertTrue($primoIngresso->equalTo($prenotazione->checkin_at));
    }

    // ------------------------------------------------------------------
    // #18 — origine del check-in e annullamento del check-in sincronizzato
    // ------------------------------------------------------------------

    public function test_rimozione_del_partecipante_annulla_il_checkin_della_tessera(): void
    {
        $prenotazione = $this->prenotazioneDelSocio(2);
        $this->checkinTessera();
        $this->assertSame(1, $this->contatoreKiosk());

        $this->actingAs($this->admin)
            ->delete("/eventi/{$this->evento->id}/partecipanti/{$this->partecipante()->id}")
            ->assertSessionHas('success');

        $prenotazione->refresh();
        $this->assertNull($prenotazione->checkin_at);
        $this->assertSame(0, $prenotazione->posti_entrati);
        $this->assertNull($prenotazione->checkin_origine);
        $this->assertNull($prenotazione->checkin_utente_id);
        $this->assertSame(0, $this->contatoreKiosk());

        // Il biglietto torna valido per tutti i posti
        $this->scansionaBiglietto($prenotazione)
            ->assertOk()
            ->assertJson(['already_checked_in' => false, 'posti_registrati' => 2]);
    }

    public function test_partecipante_portato_a_non_partecipa_annulla_il_checkin_dell_api(): void
    {
        $prenotazione = $this->prenotazioneDelSocio(1);

        ApiKey::create([
            'associazione_id' => $this->associazione->id,
            'api_key' => 'sk_test_scoped_0000000000000000000000000000000000000077',
            'nome' => 'Chiave check-in',
            'permessi' => ['eventi:read', 'eventi:write'],
            'attiva' => true,
        ]);

        $this->postJson("/api/v1/eventi/{$this->evento->id}/checkin", ['socio_id' => $this->socio->id], [
            'Authorization' => 'Bearer sk_test_scoped_0000000000000000000000000000000000000077',
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('already_checked_in', false);

        $prenotazione->refresh();
        $this->assertSame(Prenotazione::CHECKIN_ORIGINE_API, $prenotazione->checkin_origine);
        $this->assertSame(1, $prenotazione->posti_entrati);

        $this->actingAs($this->admin)
            ->put("/eventi/{$this->evento->id}/partecipanti/{$this->partecipante()->id}", [
                'stato_partecipazione' => EventoPartecipante::STATO_NON_PARTECIPA,
            ])
            ->assertSessionHas('success');

        $prenotazione->refresh();
        $this->assertNull($prenotazione->checkin_at);
        $this->assertSame(0, $prenotazione->posti_entrati);
        $this->assertNull($prenotazione->checkin_origine);
    }

    public function test_aggiunta_con_stato_forse_sul_partecipante_esistente_annulla_il_checkin(): void
    {
        $prenotazione = $this->prenotazioneDelSocio(1);
        $this->checkinTessera();

        $this->actingAs($this->admin)
            ->post("/eventi/{$this->evento->id}/partecipanti", [
                'socio_id' => $this->socio->id,
                'stato_partecipazione' => EventoPartecipante::STATO_FORSE,
            ])
            ->assertSessionHas('success');

        $this->assertSame(EventoPartecipante::STATO_FORSE, $this->partecipante()->stato_partecipazione);
        $this->assertNull($prenotazione->refresh()->checkin_at);
    }

    public function test_conferma_del_partecipante_non_tocca_il_checkin(): void
    {
        $prenotazione = $this->prenotazioneDelSocio(1);
        $this->checkinTessera();

        $this->actingAs($this->admin)
            ->put("/eventi/{$this->evento->id}/partecipanti/{$this->partecipante()->id}", [
                'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
                'note' => 'Arrivato in anticipo',
            ])
            ->assertSessionHas('success');

        $this->assertNotNull($prenotazione->refresh()->checkin_at);
        $this->assertSame(Prenotazione::CHECKIN_ORIGINE_TESSERA, $prenotazione->checkin_origine);
    }

    public function test_checkin_del_biglietto_resta_alla_rimozione_del_partecipante(): void
    {
        $prenotazione = $this->prenotazioneDelSocio(2);

        // Il biglietto registra il socio tra i partecipanti confermati
        $this->scansionaBiglietto($prenotazione)->assertOk()->assertJson(['already_checked_in' => false]);

        $this->actingAs($this->admin)
            ->delete("/eventi/{$this->evento->id}/partecipanti/{$this->partecipante()->id}")
            ->assertSessionHas('success');

        $prenotazione->refresh();
        $this->assertNotNull($prenotazione->checkin_at);
        $this->assertSame(2, $prenotazione->posti_entrati);
        $this->assertSame(Prenotazione::CHECKIN_ORIGINE_BIGLIETTO, $prenotazione->checkin_origine);
    }

    public function test_ingresso_completato_col_biglietto_non_si_annulla_con_la_partecipazione(): void
    {
        $prenotazione = $this->prenotazioneDelSocio(3);
        $this->checkinTessera();
        $this->scansionaBiglietto($prenotazione)->assertOk()->assertJson(['posti_registrati' => 2]);

        $this->actingAs($this->admin)
            ->put("/eventi/{$this->evento->id}/partecipanti/{$this->partecipante()->id}", [
                'stato_partecipazione' => EventoPartecipante::STATO_NON_PARTECIPA,
            ])
            ->assertSessionHas('success');

        $prenotazione->refresh();
        $this->assertNotNull($prenotazione->checkin_at);
        $this->assertSame(3, $prenotazione->posti_entrati);
    }

    public function test_dopo_l_annullamento_non_parte_la_richiesta_di_feedback(): void
    {
        Notification::fake();

        $this->evento->forceFill(['data_evento' => now()->subDay()->setTime(18, 0)])->save();
        $prenotazione = $this->prenotazioneDelSocio(1);
        $this->checkinTessera();

        $this->actingAs($this->admin)
            ->delete("/eventi/{$this->evento->id}/partecipanti/{$this->partecipante()->id}")
            ->assertSessionHas('success');

        $this->assertSame(0, app(FeedbackEventiService::class)->inviaRichieste());
        $this->assertDatabaseMissing('feedback_eventi', ['prenotazione_id' => $prenotazione->id]);
    }

    // ------------------------------------------------------------------
    // Migration: i check-in esistenti valgono per tutti i posti
    // ------------------------------------------------------------------

    public function test_migration_porta_i_checkin_esistenti_a_tutti_i_posti_e_si_annulla(): void
    {
        $migration = require database_path('migrations/2026_11_05_100100_add_checkin_posti_origine_to_prenotazioni.php');

        $arrivata = $this->prenotazioneDelSocio(3);
        $arrivata->forceFill(['checkin_at' => now()])->save();
        $nonArrivata = Prenotazione::factory()->confermata()->create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $this->evento->id,
            'posti' => 2,
        ]);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('prenotazioni', 'posti_entrati'));
        $this->assertFalse(Schema::hasColumn('prenotazioni', 'checkin_origine'));

        $migration->up();

        $righe = DB::table('prenotazioni')->get()->keyBy('id');
        $this->assertSame(3, (int) $righe[$arrivata->id]->posti_entrati);
        $this->assertNull($righe[$arrivata->id]->checkin_origine);
        $this->assertSame(0, (int) $righe[$nonArrivata->id]->posti_entrati);
    }
}
