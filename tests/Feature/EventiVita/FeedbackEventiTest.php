<?php

namespace Tests\Feature\EventiVita;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\FeedbackEvento;
use App\Models\Prenotazione;
use App\Models\Utente;
use App\Notifications\EventiVita\FeedbackEventoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * f.11 — Feedback post-evento: comando eventi:invia-feedback (giorno
 * dopo data_evento, solo partecipanti con check-in, niente duplicati),
 * form pubblico firmato (voto 1–5 + commento, uno per prenotazione)
 * e report medie nel dettaglio evento admin.
 */
class FeedbackEventiTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Evento $eventoIeri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        $this->eventoIeri = Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->subDay()->setTime(21, 0),
        ]);
    }

    private function prenotazioneConCheckin(?Evento $evento = null): Prenotazione
    {
        return Prenotazione::factory()->conCheckin()->create([
            'evento_id' => ($evento ?? $this->eventoIeri)->id,
        ]);
    }

    // ------------------------------------------------------------------
    // Comando eventi:invia-feedback
    // ------------------------------------------------------------------

    public function test_comando_crea_feedback_e_notifica_i_partecipanti_con_checkin(): void
    {
        Notification::fake();

        $conCheckin = $this->prenotazioneConCheckin();
        // Confermata SENZA check-in: nessuna richiesta
        $senzaCheckin = Prenotazione::factory()->confermata()->create(['evento_id' => $this->eventoIeri->id]);

        $this->artisan('eventi:invia-feedback')
            ->expectsOutputToContain('Richieste di feedback inviate: 1')
            ->assertSuccessful();

        $feedback = FeedbackEvento::allTenants()->where('prenotazione_id', $conCheckin->id)->first();
        $this->assertNotNull($feedback);
        $this->assertNotNull($feedback->inviato_at);
        $this->assertNull($feedback->compilato_at);
        $this->assertSame($this->eventoIeri->id, $feedback->evento_id);
        $this->assertSame($this->associazione->id, $feedback->associazione_id);

        $this->assertSame(0, FeedbackEvento::allTenants()->where('prenotazione_id', $senzaCheckin->id)->count());

        $account = Account::allTenants()->find($conCheckin->account_id);
        Notification::assertSentTo($account, FeedbackEventoNotification::class);
    }

    public function test_comando_ignora_eventi_non_di_ieri(): void
    {
        Notification::fake();

        $eventoVecchio = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->subDays(3),
        ]);
        $eventoFuturo = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'data_evento' => now()->addDay(),
        ]);
        $this->prenotazioneConCheckin($eventoVecchio);
        $this->prenotazioneConCheckin($eventoFuturo);

        $this->artisan('eventi:invia-feedback')->assertSuccessful();

        $this->assertSame(0, FeedbackEvento::allTenants()->count());
        Notification::assertNothingSent();
    }

    public function test_comando_rieseguito_non_duplica_le_richieste(): void
    {
        Notification::fake();

        $this->prenotazioneConCheckin();

        $this->artisan('eventi:invia-feedback')->assertSuccessful();
        $this->artisan('eventi:invia-feedback')
            ->expectsOutputToContain('Richieste di feedback inviate: 0')
            ->assertSuccessful();

        $this->assertSame(1, FeedbackEvento::allTenants()->count());
    }

    public function test_comando_salta_gli_account_disattivati(): void
    {
        Notification::fake();

        $prenotazione = $this->prenotazioneConCheckin();
        Account::allTenants()->find($prenotazione->account_id)->forceFill(['attivo' => false])->save();

        $this->artisan('eventi:invia-feedback')
            ->expectsOutputToContain('Richieste di feedback inviate: 0')
            ->assertSuccessful();

        $this->assertSame(0, FeedbackEvento::allTenants()->count());
        Notification::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Form pubblico firmato
    // ------------------------------------------------------------------

    private function creaFeedback(): FeedbackEvento
    {
        return FeedbackEvento::factory()->create([
            'prenotazione_id' => $this->prenotazioneConCheckin()->id,
        ]);
    }

    public function test_link_firmato_mostra_il_form(): void
    {
        $feedback = $this->creaFeedback();

        $url = URL::signedRoute('feedback-eventi.show', ['token' => $feedback->token]);

        $this->get($url)
            ->assertOk()
            ->assertSee($this->eventoIeri->titolo)
            ->assertSee('Invia il feedback');
    }

    public function test_link_senza_firma_viene_rifiutato(): void
    {
        $feedback = $this->creaFeedback();

        $this->get("/feedback-eventi/{$feedback->token}")->assertForbidden();
    }

    public function test_token_inesistente_da_404(): void
    {
        $url = URL::signedRoute('feedback-eventi.show', ['token' => str_repeat('a', 64)]);

        $this->get($url)->assertNotFound();
    }

    public function test_post_salva_voto_e_commento(): void
    {
        $feedback = $this->creaFeedback();

        $this->post("/feedback-eventi/{$feedback->token}", [
            'voto' => 4,
            'commento' => 'Bellissima serata, torneremo!',
        ])->assertOk()->assertSee('feedback è stato registrato', false);

        $feedback->refresh();
        $this->assertSame(4, $feedback->voto);
        $this->assertSame('Bellissima serata, torneremo!', $feedback->commento);
        $this->assertNotNull($feedback->compilato_at);
    }

    public function test_voto_fuori_scala_fallisce(): void
    {
        $feedback = $this->creaFeedback();

        $this->post("/feedback-eventi/{$feedback->token}", ['voto' => 6])
            ->assertSessionHasErrors('voto');

        $this->assertNull($feedback->fresh()->compilato_at);
    }

    public function test_secondo_invio_viene_bloccato(): void
    {
        $feedback = $this->creaFeedback();

        $this->post("/feedback-eventi/{$feedback->token}", ['voto' => 5]);
        $this->post("/feedback-eventi/{$feedback->token}", ['voto' => 1, 'commento' => 'cambio idea'])
            ->assertSessionHasErrors('voto');

        $feedback->refresh();
        $this->assertSame(5, $feedback->voto);
        $this->assertNull($feedback->commento);
    }

    // ------------------------------------------------------------------
    // Report admin
    // ------------------------------------------------------------------

    public function test_report_admin_mostra_media_e_commenti(): void
    {
        FeedbackEvento::factory()
            ->compilato()
            ->create([
                'prenotazione_id' => $this->prenotazioneConCheckin()->id,
            ])
            ->forceFill(['voto' => 5, 'commento' => 'Commento entusiasta'])->save();

        FeedbackEvento::factory()
            ->compilato()
            ->create([
                'prenotazione_id' => $this->prenotazioneConCheckin()->id,
            ])
            ->forceFill(['voto' => 3, 'commento' => null])->save();

        $admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $response = $this->actingAs($admin)->get("/eventi/{$this->eventoIeri->id}/feedback");

        $response->assertOk();
        $response->assertViewIs('eventi-vita.feedback');
        $response->assertSee('Commento entusiasta');

        $stats = $response->viewData('stats');
        $this->assertSame(4.0, $stats['media']);
        $this->assertSame(2, $stats['totale_compilati']);
        $this->assertSame(1, $stats['distribuzione'][5]);
        $this->assertSame(1, $stats['distribuzione'][3]);
    }

    public function test_report_di_un_altro_tenant_da_404(): void
    {
        $altra = Associazione::factory()->create();
        $estraneo = Utente::factory()->adminAssociazione()->create(['associazione_id' => $altra->id]);

        $this->actingAs($estraneo)
            ->get("/eventi/{$this->eventoIeri->id}/feedback")
            ->assertNotFound();
    }
}
