<?php

namespace Tests\Feature\Percorsi;

use App\Models\Account;
use App\Models\Adempimento;
use App\Models\AdempimentoAnno;
use App\Models\Annuncio;
use App\Models\Associazione;
use App\Models\Attrezzatura;
use App\Models\Carica;
use App\Models\CategoriaMovimento;
use App\Models\Documento;
use App\Models\Donazione;
use App\Models\EmailLog;
use App\Models\EmailQueue;
use App\Models\Mansione;
use App\Models\MilestoneProgetto;
use App\Models\Movimento;
use App\Models\NewsletterInviata;
use App\Models\PaymentGatewaySetting;
use App\Models\PrenotazioneSpazio;
use App\Models\PrestitoAttrezzatura;
use App\Models\Progetto;
use App\Models\Quota;
use App\Models\RispostaSondaggio;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Scadenza;
use App\Models\SmtpSetting;
use App\Models\Socio;
use App\Models\Sondaggio;
use App\Models\Spazio;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use App\Models\Utente;
use App\Models\Verbale;
use App\Models\VotazioneRiunione;
use App\Notifications\Comunicazione\ConvocazioneAssembleaNotification;
use App\Notifications\PromemoriaScadenzaNotification;
use App\Notifications\Struttura\ConvocazioneRiunioneNotification;
use App\Notifications\Struttura\PromemoriaAdempimentoNotification;
use App\Notifications\VolontariTurni\CandidaturaTurnoNotification;
use App\Notifications\VolontariTurni\RinunciaTurnoNotification;
use App\Services\Economia\PrimaNotaService;
use App\Services\Email\TenantMailer;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Honeypot\ProtectAgainstSpam;
use Tests\TestCase;

/**
 * Percorsi reali — comunicazione, decisioni, economia e operatività.
 *
 * Ogni test segue passo per passo quello che succede davvero in
 * un'associazione: più richieste HTTP in sequenza fatte da attori diversi
 * (admin, socio nell'area soci, volontario, visitatore del mini-sito,
 * scheduler via Artisan), con asserzioni su database, notifiche/coda
 * email e pagine.
 */
class Percorso05_ComunicazioneOperativitaTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $this->associazione = Associazione::factory()->create([
            'slug' => 'circolo-aurora',
            'frontend_pubblico_attivo' => true,
        ]);
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'presidenza@circolo-aurora.test',
        ]);
        $this->admin->assignRole('admin_associazione');
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────
    // 1. Convocazione dell'assemblea
    // ─────────────────────────────────────────────────────────────────

    public function test_percorso_convocazione_assemblea_arriva_ai_soci_attivi_e_compare_in_bacheca(): void
    {
        Notification::fake();

        $anna = $this->creaSocio(['nome' => 'Anna', 'cognome' => 'Bianchi', 'email' => 'anna@example.test', 'password_hash' => 'password']);
        $luca = $this->creaSocio(['nome' => 'Luca', 'cognome' => 'Verdi', 'email' => 'luca@example.test']);
        $cessato = $this->creaSocio(['stato' => Socio::STATO_RADIATO, 'email' => 'ex-socio@example.test']);
        $optOut = $this->creaSocio(['email' => 'nomail@example.test', 'email_opt_out' => true]);

        // L'admin prepara la convocazione (riservata ai soci) con data, luogo e OdG
        $this->actingAs($this->admin)->post(route('annunci.store'), [
            'titolo' => 'Assemblea ordinaria dei soci 2026',
            'tipo' => Annuncio::TIPO_CONVOCAZIONE,
            'corpo_html' => '<p>Si convoca l\'assemblea ordinaria dei soci.</p>',
            'data_assemblea' => now()->addDays(20)->setTime(21, 0)->format('Y-m-d\TH:i'),
            'luogo_assemblea' => 'Sede sociale, via Roma 1',
            'ordine_del_giorno' => "1. Approvazione bilancio consuntivo\n2. Rinnovo del direttivo",
            'pubblicato_dal' => now()->subHour()->format('Y-m-d\TH:i'),
            'in_evidenza' => '1',
            'solo_soci' => '1',
        ])->assertRedirect(route('annunci.index'));

        $annuncio = Annuncio::query()->where('titolo', 'Assemblea ordinaria dei soci 2026')->firstOrFail();
        $this->assertTrue($annuncio->isConvocazione());
        $this->assertNull($annuncio->convocazione_inviata_at);

        // ...e la invia ai soci
        $this->actingAs($this->admin)
            ->post(route('annunci.invia-convocazione', $annuncio))
            ->assertRedirect(route('annunci.index'))
            ->assertSessionHas('success', 'Convocazione inviata a 2 soci.');

        $this->assertNotNull($annuncio->fresh()->convocazione_inviata_at);

        // Gli attivi la ricevono via email (canale mail oltre al database)...
        Notification::assertSentTo(
            [$anna, $luca],
            ConvocazioneAssembleaNotification::class,
            fn (ConvocazioneAssembleaNotification $n, array $canali) => in_array('mail', $canali, true)
                && $n->annuncio->is($annuncio)
        );
        // ...il cessato e chi ha fatto opt-out no
        Notification::assertNotSentTo([$cessato, $optOut], ConvocazioneAssembleaNotification::class);

        // Anna entra nell'area soci e trova la convocazione in bacheca
        $this->actingAs($anna, 'socio')
            ->get(route('area-soci.bacheca'))
            ->assertOk()
            ->assertSee('Assemblea ordinaria dei soci 2026')
            ->assertSee('Sede sociale, via Roma 1');

        // Essendo riservata ai soci, un visitatore del mini-sito non la vede
        $this->get(route('pubblico.novita', $this->associazione->slug))
            ->assertOk()
            ->assertDontSee('Assemblea ordinaria dei soci 2026');
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. Newsletter con anteprima e opt-out
    // ─────────────────────────────────────────────────────────────────

    public function test_percorso_newsletter_con_anteprima_rispetta_gli_opt_out_e_registra_lo_storico(): void
    {
        $this->configuraSmtp();

        $festa = Annuncio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Festa di primavera al parco',
            'corpo_html' => '<p>Vi aspettiamo domenica con la banda del paese.</p>',
        ]);
        $bozza = Annuncio::factory()->bozza()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Bozza ancora da rivedere',
        ]);

        $marta = $this->creaSocio(['nome' => 'Marta', 'cognome' => 'Neri', 'email' => 'marta@example.test']);
        $paolo = $this->creaSocio([
            'email' => 'paolo@example.test',
            'email_opt_out' => false,
            'email_opt_out_token' => str_repeat('a', 64),
        ]);
        $giulia = $this->creaSocio([
            'email' => 'giulia@example.test',
            'email_opt_out' => false,
            'email_opt_out_token' => str_repeat('b', 64),
        ]);
        $this->creaSocio(['stato' => Socio::STATO_SOSPESO, 'email' => 'sospeso@example.test']);

        // Paolo si disiscrive dal link in fondo a una vecchia email (double opt-out)
        $this->get(route('email.unsubscribe.show', ['socio' => $paolo->id, 'token' => $paolo->email_opt_out_token]))
            ->assertOk()
            ->assertSee('Conferma disiscrizione');
        $this->assertFalse($paolo->fresh()->email_opt_out);

        $this->post(route('email.unsubscribe.confirm', ['socio' => $paolo->id, 'token' => $paolo->email_opt_out_token]), [
            'confirm_unsubscribe' => 1,
        ])->assertOk();
        $this->assertTrue($paolo->fresh()->email_opt_out);

        // Giulia fa lo stesso dal link dell'area soci
        $this->post(route('area-soci.optout.store', ['token' => $giulia->email_opt_out_token]))
            ->assertOk()
            ->assertSee('Disiscrizione confermata');
        $this->assertTrue($giulia->fresh()->email_opt_out);

        // L'admin controlla l'anteprima brandizzata...
        $this->actingAs($this->admin)
            ->post(route('newsletter.anteprima'), [
                'titolo' => 'Le novità di aprile',
                'annunci' => [$festa->id, $bozza->id],
            ])
            ->assertOk()
            ->assertSee('Le novità di aprile')
            ->assertSee('Festa di primavera al parco')
            ->assertSee('banda del paese')
            ->assertDontSee('Bozza ancora da rivedere')
            ->assertSee($this->associazione->nome);

        // ...e spedisce
        $this->actingAs($this->admin)
            ->post(route('newsletter.store'), [
                'titolo' => 'Le novità di aprile',
                'annunci' => [$festa->id, $bozza->id],
            ])
            ->assertRedirect(route('newsletter.index'))
            ->assertSessionHas('success');

        // In coda c'è una sola email: quella di Marta (Paolo e Giulia hanno fatto opt-out)
        $coda = EmailQueue::query()->get();
        $this->assertCount(1, $coda);
        $this->assertSame('marta@example.test', $coda->first()->to_email);
        $this->assertSame($marta->id, $coda->first()->socio_id);
        $this->assertSame('Le novità di aprile', $coda->first()->subject);
        $this->assertSame('newsletter', $coda->first()->template_codice);
        $this->assertStringContainsString('Festa di primavera al parco', $coda->first()->body_html);
        $this->assertStringNotContainsString('Bozza ancora da rivedere', $coda->first()->body_html);

        // Lo storico registra l'invio con i soli annunci pubblicati
        $storico = NewsletterInviata::query()->firstOrFail();
        $this->assertSame('Le novità di aprile', $storico->titolo);
        $this->assertSame(1, $storico->inviata_a);
        $this->assertSame([$festa->id], $storico->annunci_ids);
        $this->assertSame($this->admin->id, $storico->inviata_da);

        $this->actingAs($this->admin)
            ->get(route('newsletter.index'))
            ->assertOk()
            ->assertSee('Le novità di aprile');
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. Sondaggio: voto dall'area soci e dall'account pubblico
    // ─────────────────────────────────────────────────────────────────

    public function test_percorso_sondaggio_un_voto_per_persona_risultati_e_chiusura(): void
    {
        // L'admin apre il sondaggio a soci e account del mini-sito
        $this->actingAs($this->admin)->post(route('sondaggi.store'), [
            'domanda' => 'Dove facciamo la gita sociale?',
            'opzioni_testo' => "Mare\nMontagna\nCittà d'arte",
            'aperto_dal' => now()->subHour()->format('Y-m-d\TH:i'),
            'aperto_al' => now()->addWeek()->format('Y-m-d\TH:i'),
            'solo_soci' => '0',
        ]);

        $sondaggio = Sondaggio::query()->where('domanda', 'Dove facciamo la gita sociale?')->firstOrFail();
        $this->assertSame(['Mare', 'Montagna', "Città d'arte"], $sondaggio->opzioni);

        // Il socio Carlo vota "Mare" dall'area soci
        $carlo = $this->creaSocio(['nome' => 'Carlo', 'cognome' => 'Rossi', 'password_hash' => 'password']);

        $this->actingAs($carlo, 'socio')
            ->get(route('area-soci.sondaggi'))
            ->assertOk()
            ->assertSee('Dove facciamo la gita sociale?');

        $this->actingAs($carlo, 'socio')
            ->post(route('area-soci.sondaggi.vota', $sondaggio), ['opzione' => 0])
            ->assertRedirect(route('area-soci.sondaggi'))
            ->assertSessionHas('success');

        // Ci riprova cambiando idea: il primo voto resta
        $this->actingAs($carlo, 'socio')
            ->post(route('area-soci.sondaggi.vota', $sondaggio), ['opzione' => 1])
            ->assertSessionHas('warning');

        // Un simpatizzante con account sul mini-sito vota "Montagna"
        $account = Account::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($account, 'account')
            ->get($this->urlTenant('account/sondaggi'))
            ->assertOk()
            ->assertSee('Dove facciamo la gita sociale?');

        $this->actingAs($account, 'account')
            ->post($this->urlTenant('account/sondaggi/'.$sondaggio->id.'/vota'), ['opzione' => 1])
            ->assertSessionHas('success');

        // Carlo ha anche un account collegato: dal mini-sito è sempre la stessa persona
        $accountDiCarlo = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $carlo->id,
        ]);
        $this->actingAs($accountDiCarlo, 'account')
            ->post($this->urlTenant('account/sondaggi/'.$sondaggio->id.'/vota'), ['opzione' => 2])
            ->assertSessionHas('warning');

        $this->assertSame(2, RispostaSondaggio::query()->where('sondaggio_id', $sondaggio->id)->count());
        $this->assertSame(0, RispostaSondaggio::query()->where('votante_id', $carlo->id)->firstOrFail()->opzione);
        $this->assertSame(1, RispostaSondaggio::query()->where('votante_id', $account->id)->firstOrFail()->opzione);

        // L'admin legge i risultati
        $this->actingAs($this->admin)
            ->get(route('sondaggi.show', $sondaggio))
            ->assertOk()
            ->assertSee('2 voti totali')
            ->assertSee('1 voto (50%)');

        // ...e chiude il sondaggio anticipando la data di fine
        $this->actingAs($this->admin)->put(route('sondaggi.update', $sondaggio), [
            'domanda' => 'Dove facciamo la gita sociale?',
            'opzioni_testo' => "Mare\nMontagna\nCittà d'arte",
            'aperto_dal' => now()->subDays(2)->format('Y-m-d\TH:i'),
            'aperto_al' => now()->subMinute()->format('Y-m-d\TH:i'),
            'solo_soci' => '0',
        ])->assertRedirect(route('sondaggi.show', $sondaggio));

        $this->assertFalse($sondaggio->fresh()->isAperto());

        // Un socio ritardatario non può più votare
        $ritardatario = $this->creaSocio(['password_hash' => 'password']);
        $this->actingAs($ritardatario, 'socio')
            ->post(route('area-soci.sondaggi.vota', $sondaggio), ['opzione' => 2])
            ->assertSessionHas('warning');

        $this->assertSame(2, RispostaSondaggio::query()->where('sondaggio_id', $sondaggio->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. Coda email: accodamento, scheduler, retry
    // ─────────────────────────────────────────────────────────────────

    public function test_percorso_coda_email_dallo_scheduler_con_log_e_retry_di_un_invio_fallito(): void
    {
        $smtp = $this->configuraSmtp();

        // L'SMTP del tenant viene usato per ogni invio; la casella di Dario rimbalza finché non la sistema
        $invii = [];
        $caselleRotte = ['dario@example.test'];

        $this->mock(TenantMailer::class, function ($mock) use (&$invii, &$caselleRotte, $smtp) {
            $mock->shouldReceive('send')
                ->andReturnUsing(function (SmtpSetting $settings, string $to) use (&$invii, &$caselleRotte, $smtp) {
                    $this->assertTrue($settings->is($smtp), 'La coda deve spedire con l\'SMTP dell\'associazione.');
                    $invii[] = $to;

                    return in_array($to, $caselleRotte, true)
                        ? ['success' => false, 'error' => '550 Mailbox unavailable']
                        : ['success' => true, 'error' => null];
                });
        });

        $elena = $this->creaSocio(['nome' => 'Elena', 'cognome' => 'Galli', 'email' => 'elena@example.test']);
        $dario = $this->creaSocio(['nome' => 'Dario', 'cognome' => 'Fontana', 'email' => 'dario@example.test']);

        // L'admin manda una comunicazione a tutti i soci...
        $this->actingAs($this->admin)
            ->post(route('comunicazioni.store'), [
                'subject' => 'Chiusura estiva della sede',
                'corpo_html' => '<p>Gentile {NOME}, la sede resterà chiusa ad agosto.</p>',
                'recipient_type' => 'all',
            ])
            ->assertRedirect(route('comunicazioni.index'))
            ->assertSessionHas('success');

        // ...e un messaggio diretto a Elena dalla sua scheda
        $this->actingAs($this->admin)
            ->post(route('soci.invia-email', $elena), [
                'oggetto' => 'Documenti per il rinnovo',
                'corpo' => "Ciao Elena,\nti ricordo di portare il certificato medico.",
            ])
            ->assertRedirect(route('soci.show', $elena))
            ->assertSessionHas('success');

        $coda = EmailQueue::query()->orderBy('created_at')->get();
        $this->assertCount(3, $coda);
        $this->assertTrue($coda->every(fn (EmailQueue $e) => $e->status === EmailQueue::STATUS_PENDING));
        $this->assertSame('Chiusura estiva della sede', $coda->firstWhere('to_email', 'dario@example.test')->subject);
        $comunicazioneElena = $coda->first(fn (EmailQueue $e) => $e->to_email === 'elena@example.test' && $e->template_codice === 'comunicazione');
        $this->assertStringContainsString('Gentile Elena', $comunicazioneElena->body_html);
        $this->assertSame(3, $coda->firstWhere('subject', 'Documenti per il rinnovo')->priority);

        // Lo scheduler passa (ogni 5 minuti)
        Carbon::setTestNow(now());
        $this->artisan('emails:process-queue', ['--sync' => true])->assertSuccessful();

        $this->assertCount(3, $invii);
        $this->assertSame(2, EmailQueue::query()->where('status', EmailQueue::STATUS_SENT)->count());

        $rimbalzata = EmailQueue::query()->where('to_email', 'dario@example.test')->firstOrFail();
        $this->assertSame(EmailQueue::STATUS_PENDING, $rimbalzata->status);
        $this->assertSame(1, $rimbalzata->attempts);
        $this->assertSame('550 Mailbox unavailable', $rimbalzata->error_message);
        $this->assertTrue($rimbalzata->next_attempt_at->isFuture());

        // Nel log ci sono solo gli esiti definitivi: le due inviate
        $this->assertSame(2, EmailLog::query()->where('status', EmailLog::STATUS_SENT)->count());
        $this->assertSame(0, EmailLog::query()->where('status', EmailLog::STATUS_FAILED)->count());

        // Passano le ore: lo scheduler riprova fino a esaurire i tentativi
        Carbon::setTestNow(now()->addMinutes(6));
        $this->artisan('emails:process-queue', ['--sync' => true])->assertSuccessful();
        $this->assertSame(2, $rimbalzata->fresh()->attempts);

        Carbon::setTestNow(now()->addMinutes(11));
        $this->artisan('emails:process-queue', ['--sync' => true])->assertSuccessful();

        $rimbalzata->refresh();
        $this->assertSame(EmailQueue::STATUS_FAILED, $rimbalzata->status);
        $this->assertSame(3, $rimbalzata->attempts);
        $this->assertCount(5, $invii);

        $logFallito = EmailLog::query()->where('status', EmailLog::STATUS_FAILED)->firstOrFail();
        $this->assertSame($rimbalzata->id, $logFallito->queue_id);
        $this->assertSame('550 Mailbox unavailable', $logFallito->error_message);

        // L'admin vede l'errore nella coda e, sistemata la casella di Dario, rimette in coda
        $this->actingAs($this->admin)
            ->get(route('email-coda.index'))
            ->assertOk()
            ->assertSee('dario@example.test')
            ->assertSee('550 Mailbox unavailable');

        $this->actingAs($this->admin)
            ->post(route('email-coda.retry', $rimbalzata))
            ->assertRedirect(route('email-coda.index'))
            ->assertSessionHas('success', 'Email rimessa in coda.');

        $rimbalzata->refresh();
        $this->assertSame(EmailQueue::STATUS_PENDING, $rimbalzata->status);
        $this->assertSame(0, $rimbalzata->attempts);

        $caselleRotte = [];
        $this->artisan('emails:process-queue', ['--sync' => true])->assertSuccessful();

        $this->assertSame(EmailQueue::STATUS_SENT, $rimbalzata->fresh()->status);
        $this->assertSame(3, EmailLog::query()->where('status', EmailLog::STATUS_SENT)->count());
        $this->assertSame(1, EmailLog::query()->where('status', EmailLog::STATUS_FAILED)->count());

        $this->actingAs($this->admin)
            ->get(route('email-log.index'))
            ->assertOk()
            ->assertSee('Chiusura estiva della sede')
            ->assertSee('Documenti per il rinnovo');

        // Nessuna email nuova dopo l'ultima elaborazione
        $this->artisan('emails:process-queue', ['--sync' => true])
            ->expectsOutput('Nessuna email in coda.')
            ->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────────
    // 5. Riunione del direttivo
    // ─────────────────────────────────────────────────────────────────

    public function test_percorso_riunione_del_direttivo_convocazione_presenze_votazioni_verbale_e_decisioni(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-03-02 10:00:00');

        $presidente = $this->creaSocio(['nome' => 'Giorgio', 'cognome' => 'Mancini', 'email' => 'giorgio@example.test']);
        $segretaria = $this->creaSocio(['nome' => 'Sara', 'cognome' => 'Conti', 'email' => 'sara@example.test']);
        $exTesoriere = $this->creaSocio(['nome' => 'Ugo', 'cognome' => 'Ferri', 'email' => 'ugo@example.test']);

        Carica::factory()->create(['socio_id' => $presidente->id, 'ruolo' => 'Presidente']);
        Carica::factory()->create(['socio_id' => $segretaria->id, 'ruolo' => 'Segretario']);
        Carica::factory()->conclusa()->create(['socio_id' => $exTesoriere->id, 'ruolo' => 'Tesoriere']);

        // L'admin pianifica la riunione con l'ordine del giorno
        $this->actingAs($this->admin)->post(route('riunioni.store'), [
            'titolo' => 'Direttivo di marzo',
            'data_ora' => '2026-03-10T21:00',
            'luogo' => 'Sede sociale',
            'stato' => Riunione::STATO_PIANIFICATA,
            'odg' => [
                ['punto' => 'Approvazione bilancio consuntivo 2025', 'descrizione' => 'Esame del rendiconto'],
                ['punto' => 'Acquisto furgone per le consegne', 'descrizione' => ''],
            ],
        ]);

        $riunione = Riunione::query()->where('titolo', 'Direttivo di marzo')->firstOrFail();
        $this->assertCount(2, $riunione->odg);

        // Convoca gli intestatari delle cariche attive (non chi ha concluso il mandato)
        $this->actingAs($this->admin)
            ->post(route('riunioni.convoca', $riunione))
            ->assertRedirect(route('riunioni.show', $riunione))
            ->assertSessionHas('success');

        $this->assertNotNull($riunione->fresh()->convocazione_inviata_at);
        $this->assertSame(2, RiunionePartecipante::query()->where('riunione_id', $riunione->id)->count());
        $this->assertFalse(RiunionePartecipante::query()->where('socio_id', $exTesoriere->id)->exists());

        Notification::assertSentTo([$presidente, $segretaria, $this->admin], ConvocazioneRiunioneNotification::class);
        Notification::assertNotSentTo($exTesoriere, ConvocazioneRiunioneNotification::class);

        // La sera della riunione: la segretaria è assente, il presidente presente
        Carbon::setTestNow('2026-03-10 23:00:00');

        $this->actingAs($this->admin)->put(route('riunioni.update', $riunione), [
            'titolo' => 'Direttivo di marzo',
            'data_ora' => '2026-03-10T21:00',
            'luogo' => 'Sede sociale',
            'stato' => Riunione::STATO_SVOLTA,
            'odg' => $riunione->odg,
        ])->assertRedirect(route('riunioni.show', $riunione));

        $partPresidente = RiunionePartecipante::query()->where('socio_id', $presidente->id)->firstOrFail();
        $partSegretaria = RiunionePartecipante::query()->where('socio_id', $segretaria->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('riunioni.partecipanti.presenza', [$riunione, $partPresidente]), ['stato' => RiunionePartecipante::STATO_CONFERMATO])
            ->assertRedirect(route('riunioni.show', $riunione));
        $this->actingAs($this->admin)
            ->put(route('riunioni.partecipanti.presenza', [$riunione, $partSegretaria]), ['stato' => RiunionePartecipante::STATO_ASSENTE]);

        $this->assertTrue($partPresidente->fresh()->isPresente());
        $this->assertSame(RiunionePartecipante::STATO_ASSENTE, $partSegretaria->fresh()->stato);

        // Si vota: bilancio approvato, furgone respinto
        $this->actingAs($this->admin)->post(route('riunioni.votazioni.store', $riunione), [
            'punto_indice' => 0,
            'esito' => VotazioneRiunione::ESITO_APPROVATO,
            'favorevoli' => 4,
            'contrari' => 0,
            'astenuti' => 1,
            'note' => 'Bilancio approvato all\'unanimità dei votanti.',
        ])->assertSessionHas('success');

        $this->actingAs($this->admin)->post(route('riunioni.votazioni.store', $riunione), [
            'punto_indice' => 1,
            'esito' => VotazioneRiunione::ESITO_RESPINTO,
            'favorevoli' => 1,
            'contrari' => 4,
            'astenuti' => 0,
        ])->assertSessionHas('success');

        $this->assertSame(2, VotazioneRiunione::query()->where('riunione_id', $riunione->id)->count());

        // Si genera il verbale precompilato
        $this->actingAs($this->admin)
            ->post(route('riunioni.genera-verbale', $riunione))
            ->assertRedirect()
            ->assertSessionHas('success');

        $verbale = Verbale::query()->where('titolo', 'Direttivo di marzo')->firstOrFail();
        $this->assertSame($verbale->id, $riunione->fresh()->verbale_id);
        $this->assertSame('1/2026', $verbale->numero_completo);
        $this->assertSame('Consiglio Direttivo', $verbale->tipo_riunione);
        $this->assertStringContainsString('Giorgio Mancini (Presidente)', $verbale->presenti);
        $this->assertStringNotContainsString('Sara Conti', $verbale->presenti);
        $this->assertStringContainsString('Approvazione bilancio consuntivo 2025: Approvato', $verbale->deliberazioni);
        $this->assertStringContainsString('Acquisto furgone per le consegne: Respinto', $verbale->deliberazioni);

        // Una seconda generazione è rifiutata
        $this->actingAs($this->admin)
            ->post(route('riunioni.genera-verbale', $riunione))
            ->assertSessionHas('warning');
        $this->assertSame(1, Verbale::query()->count());

        $this->actingAs($this->admin)
            ->get(route('verbali.index'))
            ->assertOk()
            ->assertSee('Direttivo di marzo')
            ->assertSee('1/2026');

        // Nell'archivio delle decisioni compare solo quanto approvato
        $this->actingAs($this->admin)
            ->get(route('decisioni.index'))
            ->assertOk()
            ->assertSee('Approvazione bilancio consuntivo 2025')
            ->assertSee('Favorevoli 4')
            ->assertDontSee('Acquisto furgone per le consegne');
    }

    // ─────────────────────────────────────────────────────────────────
    // 6. Verbale firmato, approvato e pubblicato in trasparenza
    // ─────────────────────────────────────────────────────────────────

    public function test_percorso_verbale_firmato_approvato_e_pubblicato_in_trasparenza_per_i_visitatori(): void
    {
        Storage::fake('local');

        $presidente = $this->creaSocio(['nome' => 'Giorgio', 'cognome' => 'Mancini']);

        // L'admin redige il verbale dell'assemblea
        $this->actingAs($this->admin)->post(route('verbali.store'), [
            'titolo' => 'Assemblea ordinaria 2026',
            'data_riunione' => '2026-04-18',
            'tipo_riunione' => 'Assemblea Ordinaria',
            'presenti' => '42 soci presenti, 5 per delega',
            'ordine_del_giorno' => "1. Bilancio consuntivo 2025\n2. Programma attività",
            'deliberazioni' => 'Il bilancio consuntivo 2025 è approvato all\'unanimità.',
        ])->assertRedirect(route('verbali.index'));

        $verbale = Verbale::query()->where('titolo', 'Assemblea ordinaria 2026')->firstOrFail();
        $this->assertFalse((bool) $verbale->approvato);

        // Firmatari: il presidente (socio) e il segretario verbalizzante (nominativo)
        $this->actingAs($this->admin)->post(route('verbali.firmatari.store', $verbale), [
            'socio_id' => $presidente->id,
            'ruolo' => 'Presidente',
        ])->assertRedirect(route('verbali.index'));

        $this->actingAs($this->admin)->post(route('verbali.firmatari.store', $verbale), [
            'nome' => 'Sara Conti',
            'ruolo' => 'Segretario verbalizzante',
        ])->assertRedirect(route('verbali.index'));

        $firmatari = $verbale->firmatari()->orderBy('created_at')->get();
        $this->assertCount(2, $firmatari);
        $this->assertTrue($firmatari->every(fn ($f) => $f->firmato_at === null));

        foreach ($firmatari as $firmatario) {
            $this->actingAs($this->admin)
                ->post(route('verbali.firmatari.firma', [$verbale, $firmatario]))
                ->assertRedirect(route('verbali.index'))
                ->assertSessionHas('success');
        }

        $this->assertTrue($verbale->firmatari()->get()->every(fn ($f) => $f->firmato_at !== null));

        // Approvazione one-shot
        $this->actingAs($this->admin)
            ->post(route('verbali.approva', $verbale))
            ->assertRedirect(route('verbali.index'))
            ->assertSessionHas('success');

        $verbale->refresh();
        $this->assertTrue((bool) $verbale->approvato);
        $this->assertSame($this->admin->id, $verbale->approvato_da);

        $this->actingAs($this->admin)
            ->post(route('verbali.approva', $verbale))
            ->assertSessionHas('warning');

        // Il PDF del verbale viene scaricato e archiviato tra i documenti
        $pdf = $this->actingAs($this->admin)->get(route('verbali.pdf', $verbale));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));

        $this->actingAs($this->admin)->post(route('documenti.store'), [
            'document_file' => UploadedFile::fake()->create('verbale-assemblea-2026.pdf', 80, 'application/pdf'),
            'categoria' => 'Verbali',
            'descrizione' => 'Verbale assemblea ordinaria 2026 firmato',
        ])->assertRedirect(route('documenti.index'));

        $this->actingAs($this->admin)->post(route('documenti.store'), [
            'document_file' => UploadedFile::fake()->create('elenco-soci-riservato.pdf', 40, 'application/pdf'),
            'categoria' => 'Riservati',
        ])->assertRedirect(route('documenti.index'));

        $pubblico = Documento::query()->where('nome_file', 'verbale-assemblea-2026.pdf')->firstOrFail();
        $riservato = Documento::query()->where('nome_file', 'elenco-soci-riservato.pdf')->firstOrFail();

        // Prima della pubblicazione il mini-sito non mostra nulla
        $this->get(route('pubblico.trasparenza', $this->associazione->slug))
            ->assertOk()
            ->assertDontSee('verbale-assemblea-2026.pdf');

        // L'admin pubblica il verbale in Trasparenza
        $this->actingAs($this->admin)
            ->post(route('documenti.trasparenza', $pubblico), ['categoria_pubblica' => 'verbale'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue($pubblico->fresh()->pubblico);
        $this->assertFalse($riservato->fresh()->pubblico);

        // Il visitatore scarica il verbale pubblico ma non il documento riservato
        $this->get(route('pubblico.trasparenza', $this->associazione->slug))
            ->assertOk()
            ->assertSee('verbale-assemblea-2026.pdf')
            ->assertSee('Verbale assemblea ordinaria 2026 firmato')
            ->assertDontSee('elenco-soci-riservato.pdf');

        $this->get(route('pubblico.trasparenza.download', [$this->associazione->slug, $pubblico->id]))
            ->assertOk()
            ->assertDownload('verbale-assemblea-2026.pdf');

        $this->get(route('pubblico.trasparenza.download', [$this->associazione->slug, $riservato->id]))
            ->assertNotFound();

        // Dall'area admin, invece, il documento riservato resta scaricabile
        $this->actingAs($this->admin)
            ->get(route('documenti.download', $riservato))
            ->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────
    // 7. Prima nota ed economia
    // ─────────────────────────────────────────────────────────────────

    public function test_percorso_prima_nota_quote_spese_donazione_e_report_annuale(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-05-10 12:00:00');

        // Al primo accesso alla prima nota le categorie vengono create
        $this->actingAs($this->admin)->get(route('prima-nota.index'))->assertOk();
        $categoriaUscita = CategoriaMovimento::query()
            ->where('tipo', CategoriaMovimento::TIPO_USCITA)
            ->orderBy('nome')
            ->firstOrFail();

        // Il socio paga la quota 2026 in sede: l'entrata nasce da sola
        $socio = $this->creaSocio(['nome' => 'Rita', 'cognome' => 'Villa']);
        $quota = Quota::factory()->create([
            'socio_id' => $socio->id,
            'anno' => 2026,
            'importo' => 50,
            'data_pagamento' => null,
        ]);

        $this->actingAs($this->admin)->post(route('quote.pagamento', $quota), [
            'payment_method' => 'contanti',
            'payment_date' => '2026-03-15',
        ])->assertRedirect(route('quote.index'));

        $entrataQuota = Movimento::query()
            ->where('origine_type', $quota->getMorphClass())
            ->where('origine_id', $quota->id)
            ->firstOrFail();
        $this->assertSame(Movimento::TIPO_ENTRATA, $entrataQuota->tipo);
        $this->assertEqualsWithDelta(50.0, (float) $entrataQuota->importo, 0.001);
        $this->assertSame(PrimaNotaService::CATEGORIA_QUOTE, $entrataQuota->categoria?->nome);

        // L'admin registra l'affitto della sala e corregge l'importo
        $this->actingAs($this->admin)->post(route('prima-nota.store'), [
            'tipo' => Movimento::TIPO_USCITA,
            'importo' => '120.00',
            'data' => '2026-04-01',
            'categoria_id' => $categoriaUscita->id,
            'descrizione' => 'Affitto sala per il corso di ceramica',
            'metodo' => 'bonifico',
        ])->assertRedirect(route('prima-nota.index'));

        $affitto = Movimento::query()->where('descrizione', 'Affitto sala per il corso di ceramica')->firstOrFail();

        $this->actingAs($this->admin)->put(route('prima-nota.update', $affitto), [
            'tipo' => Movimento::TIPO_USCITA,
            'importo' => '130.00',
            'data' => '2026-04-01',
            'categoria_id' => $categoriaUscita->id,
            'descrizione' => 'Affitto sala per il corso di ceramica',
            'metodo' => 'bonifico',
        ])->assertSessionHas('success');
        $this->assertEqualsWithDelta(130.0, (float) $affitto->fresh()->importo, 0.001);

        // Una spesa inserita per errore viene eliminata
        $this->actingAs($this->admin)->post(route('prima-nota.store'), [
            'tipo' => Movimento::TIPO_USCITA,
            'importo' => '20.00',
            'data' => '2026-04-02',
            'categoria_id' => $categoriaUscita->id,
            'descrizione' => 'Doppione da cancellare',
        ]);
        $doppione = Movimento::query()->where('descrizione', 'Doppione da cancellare')->firstOrFail();
        $this->actingAs($this->admin)->delete(route('prima-nota.destroy', $doppione))->assertSessionHas('success');
        $this->assertNull(Movimento::query()->find($doppione->id));

        // L'entrata automatica della quota non si tocca
        $this->actingAs($this->admin)->put(route('prima-nota.update', $entrataQuota), [
            'tipo' => Movimento::TIPO_ENTRATA,
            'importo' => '1.00',
            'data' => '2026-03-15',
            'categoria_id' => $entrataQuota->categoria_id,
        ])->assertSessionHas('error');
        $this->actingAs($this->admin)->delete(route('prima-nota.destroy', $entrataQuota))->assertSessionHas('error');
        $this->assertEqualsWithDelta(50.0, (float) $entrataQuota->fresh()->importo, 0.001);

        // Un sostenitore dona 40 euro dal mini-sito
        PaymentGatewaySetting::factory()->conStripe()->create(['associazione_id' => $this->associazione->id]);
        $this->withoutMiddleware(ProtectAgainstSpam::class);

        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')->once()->andReturn('https://checkout.stripe.com/sessione-test');
            $mock->shouldReceive('constructWebhookEvent')->andReturnUsing(fn (string $payload) => json_decode($payload, true));
        });

        $this->post($this->urlTenant('dona'), [
            'importo' => '40.00',
            'donatore_nome' => 'Franco Belli',
            'donatore_email' => 'franco@example.test',
            'gateway' => 'stripe',
        ])->assertRedirect('https://checkout.stripe.com/sessione-test');

        $donazione = Donazione::query()->where('donatore_email', 'franco@example.test')->firstOrFail();
        $this->assertSame(Donazione::STATO_IN_ATTESA, $donazione->stato);

        // Stripe conferma il pagamento via webhook
        $this->postJson(route('webhooks.stripe'), [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_dona',
                'payment_intent' => 'pi_dona_franco',
                'metadata' => [
                    'tipo' => 'donazione',
                    'pagabile_id' => $donazione->id,
                    'associazione_id' => $donazione->associazione_id,
                ],
            ]],
        ], ['Stripe-Signature' => 't=1,v1=abc'])->assertOk();

        $this->assertSame(Donazione::STATO_PAGATA, $donazione->fresh()->stato);

        $entrataDonazione = Movimento::query()
            ->where('origine_type', $donazione->getMorphClass())
            ->where('origine_id', $donazione->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(40.0, (float) $entrataDonazione->importo, 0.001);
        $this->assertSame(PrimaNotaService::CATEGORIA_DONAZIONI, $entrataDonazione->categoria?->nome);

        // Il donatore torna sulla pagina di esito
        $this->get(route('pubblico.dona.esito', [$this->associazione->slug, $donazione->id, 'esito' => 'success']))
            ->assertOk()
            ->assertSee('40,00');

        // Il report dell'anno torna i totali giusti
        $report = $this->actingAs($this->admin)
            ->get(route('report-economico.index', ['dal' => '2026-01-01', 'al' => '2026-12-31']));

        $report->assertOk();
        $report->assertViewHas('totaleEntrate', 90.0);
        $report->assertViewHas('totaleUscite', 130.0);
        $report->assertViewHas('saldo', -40.0);
        $report->assertViewHas('numeroMovimenti', 3);

        $mensile = $report->viewData('mensile');
        $this->assertEqualsWithDelta(50.0, $mensile['2026-03']['entrate'], 0.001);
        $this->assertEqualsWithDelta(130.0, $mensile['2026-04']['uscite'], 0.001);
        $this->assertEqualsWithDelta(40.0, $mensile['2026-05']['entrate'], 0.001);

        Excel::fake();
        $this->actingAs($this->admin)
            ->get(route('report-economico.export', ['dal' => '2026-01-01', 'al' => '2026-12-31']))
            ->assertOk();
        Excel::assertDownloaded('report-economico_20260101-20261231.xlsx');
    }

    // ─────────────────────────────────────────────────────────────────
    // 8. Scadenze e adempimenti con promemoria dallo scheduler
    // ─────────────────────────────────────────────────────────────────

    public function test_percorso_scadenze_e_adempimenti_con_promemoria_inviati_una_sola_volta(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-03-01 08:00:00');

        // L'admin inserisce due scadenze: una vicina e una a fine maggio (entro i 90 giorni dello scadenzario)
        $this->actingAs($this->admin)->post(route('scadenze.store'), [
            'titolo' => 'Rinnovo polizza RC volontari',
            'descrizione' => 'Polizza in scadenza',
            'data_scadenza' => '2026-03-10',
            'tipo_scadenza' => 'Documenti',
            'priorita' => 'Alta',
            'stato' => Scadenza::STATO_ATTIVA,
            'assegnato_a' => '',
            'promemoria_giorni' => 7,
        ])->assertRedirect(route('scadenze.index'));

        $this->actingAs($this->admin)->post(route('scadenze.store'), [
            'titolo' => 'Deposito bilancio al RUNTS',
            'data_scadenza' => '2026-05-30',
            'tipo_scadenza' => 'Generale',
            'priorita' => 'Media',
            'stato' => Scadenza::STATO_ATTIVA,
            'assegnato_a' => '',
            'promemoria_giorni' => 7,
        ])->assertRedirect(route('scadenze.index'));

        $polizza = Scadenza::query()->where('titolo', 'Rinnovo polizza RC volontari')->firstOrFail();
        $bilancio = Scadenza::query()->where('titolo', 'Deposito bilancio al RUNTS')->firstOrFail();

        // 1 marzo: la polizza scade tra 9 giorni, fuori dalla finestra di 7 → nessun avviso
        $this->artisan('scadenze:invia-promemoria')->expectsOutput('Promemoria inviati: 0')->assertSuccessful();
        Notification::assertNothingSent();

        // 4 marzo: la polizza entra nella finestra → avviso agli admin
        Carbon::setTestNow('2026-03-04 08:00:00');
        $this->artisan('scadenze:invia-promemoria')->expectsOutput('Promemoria inviati: 1')->assertSuccessful();

        Notification::assertSentTo(
            $this->admin,
            PromemoriaScadenzaNotification::class,
            fn (PromemoriaScadenzaNotification $n) => $n->scadenza->is($polizza)
        );
        $this->assertNotNull($polizza->fresh()->promemoria_inviato_at);
        $this->assertNull($bilancio->fresh()->promemoria_inviato_at);

        // Il giorno dopo lo scheduler ripassa: niente doppioni
        Carbon::setTestNow('2026-03-05 08:00:00');
        $this->artisan('scadenze:invia-promemoria')->expectsOutput('Promemoria inviati: 0')->assertSuccessful();
        Notification::assertSentToTimes($this->admin, PromemoriaScadenzaNotification::class, 1);

        // Lo scadenzario elenca entrambe le scadenze
        $scadenzario = $this->actingAs($this->admin)->get(route('scadenzario.index'));
        $scadenzario->assertOk()
            ->assertSee('Rinnovo polizza RC volontari')
            ->assertSee('Deposito bilancio al RUNTS');

        // Gli adempimenti ricorrenti di marzo: uno già fatto, uno no
        $this->actingAs($this->admin)->post(route('adempimenti.store'), [
            'titolo' => 'Invio modello EAS',
            'mese_scadenza' => 3,
            'attivo' => '1',
        ])->assertRedirect(route('adempimenti.index'));

        $this->actingAs($this->admin)->post(route('adempimenti.store'), [
            'titolo' => 'Convocazione assemblea di bilancio',
            'mese_scadenza' => 3,
            'attivo' => '1',
        ])->assertRedirect(route('adempimenti.index'));

        $eas = Adempimento::query()->where('titolo', 'Invio modello EAS')->firstOrFail();
        $convocazione = Adempimento::query()->where('titolo', 'Convocazione assemblea di bilancio')->firstOrFail();

        $this->actingAs($this->admin)
            ->from(route('scadenzario.index'))
            ->post(route('adempimenti.toggle-anno', $convocazione), ['anno' => 2026])
            ->assertRedirect(route('scadenzario.index'));

        $this->assertTrue($convocazione->fresh()->isCompletatoPerAnno(2026));
        $this->assertSame($this->admin->id, AdempimentoAnno::query()->where('adempimento_id', $convocazione->id)->firstOrFail()->completato_da);

        $this->artisan('adempimenti:promemoria')->expectsOutput('Promemoria adempimenti inviati: 1')->assertSuccessful();

        Notification::assertSentTo(
            $this->admin,
            PromemoriaAdempimentoNotification::class,
            fn (PromemoriaAdempimentoNotification $n) => $n->adempimento->is($eas)
        );
        Notification::assertSentToTimes($this->admin, PromemoriaAdempimentoNotification::class, 1);

        // Re-run dello scheduler nello stesso mese: niente doppioni
        $this->artisan('adempimenti:promemoria')->expectsOutput('Promemoria adempimenti inviati: 0')->assertSuccessful();
        Notification::assertSentToTimes($this->admin, PromemoriaAdempimentoNotification::class, 1);

        $this->actingAs($this->admin)
            ->get(route('scadenzario.index'))
            ->assertOk()
            ->assertSee('Invio modello EAS')
            ->assertSee('Convocazione assemblea di bilancio');

        // La polizza viene rinnovata: completata e fuori dallo scadenzario
        $this->actingAs($this->admin)
            ->post(route('scadenze.completa', $polizza))
            ->assertRedirect(route('scadenze.index'))
            ->assertSessionHas('success');

        $polizza->refresh();
        $this->assertSame(Scadenza::STATO_COMPLETATA, $polizza->stato);
        $this->assertSame($this->admin->id, $polizza->completata_da);

        $voci = $this->actingAs($this->admin)->get(route('scadenzario.index'))->viewData('voci');
        $this->assertFalse($voci->contains(fn ($voce) => $voce['titolo'] === 'Rinnovo polizza RC volontari'));
        $this->assertTrue($voci->contains(fn ($voce) => $voce['titolo'] === 'Deposito bilancio al RUNTS'));
    }

    // ─────────────────────────────────────────────────────────────────
    // 9. Volontariato: turno, candidatura, presenze, attestato
    // ─────────────────────────────────────────────────────────────────

    public function test_percorso_volontariato_dalla_mansione_al_turno_fino_al_registro_ore_e_attestato(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-06-01 09:00:00');

        // L'admin definisce la mansione e pianifica il turno con 2 posti
        $this->actingAs($this->admin)->post(route('mansioni.store'), [
            'nome' => 'Accoglienza',
            'descrizione' => 'Accoglienza al banco informazioni',
        ])->assertRedirect();

        $mansione = Mansione::query()->where('nome', 'Accoglienza')->firstOrFail();

        $this->actingAs($this->admin)->post(route('turni.store'), [
            'titolo' => 'Accoglienza festa di giugno',
            'data' => '2026-06-14',
            'ora_inizio' => '09:00',
            'ora_fine' => '12:00',
            'posti' => 2,
            'mansione_id' => $mansione->id,
            'stato' => Turno::STATO_APERTO,
        ])->assertRedirect();

        $turno = Turno::query()->where('titolo', 'Accoglienza festa di giugno')->firstOrFail();
        $this->assertSame($mansione->id, $turno->mansione_id);

        $marco = $this->creaSocio(['nome' => 'Marco', 'cognome' => 'Riva', 'volontario_attivo' => true, 'password_hash' => 'password']);
        $lia = $this->creaSocio(['nome' => 'Lia', 'cognome' => 'Serra', 'volontario_attivo' => true, 'password_hash' => 'password']);
        $teo = $this->creaSocio(['nome' => 'Teo', 'cognome' => 'Bassi', 'volontario_attivo' => true, 'password_hash' => 'password']);

        // Marco si candida dall'area soci
        $this->actingAs($marco, 'socio')
            ->get(route('area-soci.volontariato'))
            ->assertOk()
            ->assertSee('Accoglienza festa di giugno');

        $this->actingAs($marco, 'socio')
            ->post(route('area-soci.volontariato.candidati', $turno))
            ->assertRedirect(route('area-soci.volontariato'))
            ->assertSessionHas('success', 'Candidatura inviata: attendi la conferma.');

        $candidaturaMarco = TurnoAssegnazione::query()->where('turno_id', $turno->id)->where('socio_id', $marco->id)->firstOrFail();
        $this->assertSame(TurnoAssegnazione::STATO_PROPOSTO, $candidaturaMarco->stato);
        Notification::assertSentTo($this->admin, CandidaturaTurnoNotification::class);

        // L'admin conferma Marco e assegna direttamente Lia
        $this->actingAs($this->admin)
            ->post(route('turni.conferma', [$turno, $candidaturaMarco]))
            ->assertRedirect()
            ->assertSessionHas('success', 'Candidatura confermata.');
        $this->assertSame(TurnoAssegnazione::STATO_CONFERMATO, $candidaturaMarco->fresh()->stato);

        $this->actingAs($this->admin)
            ->post(route('turni.assegna', $turno), ['socio_id' => $lia->id, 'conferma' => '1'])
            ->assertRedirect(route('turni.show', $turno))
            ->assertSessionHas('success');

        $assegnazioneLia = TurnoAssegnazione::query()->where('turno_id', $turno->id)->where('socio_id', $lia->id)->firstOrFail();
        $this->assertSame(TurnoAssegnazione::STATO_CONFERMATO, $assegnazioneLia->stato);

        // Posti esauriti: Teo non riesce a candidarsi
        $this->actingAs($teo, 'socio')
            ->post(route('area-soci.volontariato.candidati', $turno))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertFalse(TurnoAssegnazione::query()->where('turno_id', $turno->id)->where('socio_id', $teo->id)->exists());

        // Lia rinuncia con motivo: il posto si libera e Teo entra
        $this->actingAs($lia, 'socio')
            ->post(route('area-soci.volontariato.rinuncia', $assegnazioneLia), ['motivo' => 'Impegno di lavoro imprevisto'])
            ->assertRedirect(route('area-soci.volontariato'))
            ->assertSessionHas('success');

        $assegnazioneLia->refresh();
        $this->assertSame(TurnoAssegnazione::STATO_RINUNCIATO, $assegnazioneLia->stato);
        $this->assertSame('Impegno di lavoro imprevisto', $assegnazioneLia->motivo_rinuncia);
        Notification::assertSentTo($this->admin, RinunciaTurnoNotification::class);

        $this->actingAs($teo, 'socio')
            ->post(route('area-soci.volontariato.candidati', $turno))
            ->assertSessionHas('success');
        $candidaturaTeo = TurnoAssegnazione::query()->where('turno_id', $turno->id)->where('socio_id', $teo->id)->firstOrFail();
        $this->actingAs($this->admin)->post(route('turni.conferma', [$turno, $candidaturaTeo]))->assertSessionHas('success');

        // Dopo il turno l'admin registra le presenze: Marco 3 ore, Teo non si è presentato
        Carbon::setTestNow('2026-06-14 13:00:00');

        $this->actingAs($this->admin)
            ->post(route('turni.presenze', $turno), [
                'ore' => [
                    $candidaturaMarco->id => '3',
                    $candidaturaTeo->id => '',
                ],
            ])
            ->assertRedirect(route('turni.show', $turno))
            ->assertSessionHas('success', 'Presenze registrate (1).');

        $this->assertEqualsWithDelta(3.0, (float) $candidaturaMarco->fresh()->ore_effettive, 0.001);
        $this->assertNull($candidaturaTeo->fresh()->ore_effettive);
        $this->assertSame(Turno::STATO_CHIUSO, $turno->fresh()->stato);

        // Registro ore e attestato annuale di Marco
        $registro = $this->actingAs($this->admin)->get(route('registro-ore.index', ['anno' => 2026]));
        $registro->assertOk()
            ->assertSee('Marco Riva')
            ->assertSee('Accoglienza festa di giugno');
        // Nel registro c'è la sola voce di Marco: l'assente non matura ore
        $this->assertCount(1, $registro->viewData('voci'));
        $this->assertSame($marco->id, $registro->viewData('voci')->first()->socio_id);
        $this->assertEqualsWithDelta(3.0, $registro->viewData('totale'), 0.001);

        $attestato = $this->actingAs($this->admin)->get(route('registro-ore.attestato', [$marco, 'anno' => 2026]));
        $attestato->assertOk();
        $this->assertSame('application/pdf', $attestato->headers->get('content-type'));
        $this->assertStringContainsString('attestato-volontariato-marco-riva-2026.pdf', (string) $attestato->headers->get('content-disposition'));
    }

    // ─────────────────────────────────────────────────────────────────
    // 10. Spazi, attrezzature e progetto con milestone
    // ─────────────────────────────────────────────────────────────────

    public function test_percorso_spazi_attrezzature_e_progetto_con_milestone_e_report(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');

        // La sala riunioni entra in anagrafica
        $this->actingAs($this->admin)->post(route('spazi.store'), [
            'nome' => 'Sala riunioni',
            'descrizione' => 'Primo piano',
            'capienza' => 30,
            'attivo' => '1',
        ])->assertRedirect();

        $sala = Spazio::query()->where('nome', 'Sala riunioni')->firstOrFail();

        // Il corso di ceramica prenota 10-12; il gruppo lettura prova a sovrapporsi 11-13
        $this->actingAs($this->admin)->post(route('spazi.prenotazioni.store'), [
            'spazio_id' => $sala->id,
            'titolo' => 'Corso di ceramica',
            'dal' => '2026-09-10T10:00',
            'al' => '2026-09-10T12:00',
        ])->assertRedirect()->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->from(route('spazi.prenotazioni.index'))
            ->post(route('spazi.prenotazioni.store'), [
                'spazio_id' => $sala->id,
                'titolo' => 'Gruppo di lettura',
                'dal' => '2026-09-10T11:00',
                'al' => '2026-09-10T13:00',
            ])
            ->assertRedirect(route('spazi.prenotazioni.index'))
            ->assertSessionHasErrors('dal');

        $this->assertStringContainsString('Corso di ceramica', session('errors')->first('dal'));
        $this->assertFalse(PrenotazioneSpazio::query()->where('titolo', 'Gruppo di lettura')->exists());

        // Spostato alle 12-14 va bene (adiacente, non sovrapposto)
        $this->actingAs($this->admin)->post(route('spazi.prenotazioni.store'), [
            'spazio_id' => $sala->id,
            'titolo' => 'Gruppo di lettura',
            'dal' => '2026-09-10T12:00',
            'al' => '2026-09-10T14:00',
        ])->assertSessionHas('success');

        $this->assertSame(2, PrenotazioneSpazio::query()->where('spazio_id', $sala->id)->count());

        $this->actingAs($this->admin)
            ->get(route('spazi.prenotazioni.index', ['spazio' => $sala->id, 'settimana' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Corso di ceramica')
            ->assertSee('Gruppo di lettura');

        // Tre gazebo in inventario: due in prestito, il terzo richiesto da un altro socio
        $this->actingAs($this->admin)->post(route('attrezzature.store'), [
            'nome' => 'Gazebo pieghevole 3x3',
            'quantita' => 3,
            'stato' => Attrezzatura::STATO_BUONO,
            'ubicazione' => 'Magazzino',
        ])->assertRedirect();

        $gazebo = Attrezzatura::query()->where('nome', 'Gazebo pieghevole 3x3')->firstOrFail();
        $pietro = $this->creaSocio(['nome' => 'Pietro', 'cognome' => 'Landi']);
        $chiara = $this->creaSocio(['nome' => 'Chiara', 'cognome' => 'Moro']);

        $this->actingAs($this->admin)->post(route('prestiti.store'), [
            'attrezzatura_id' => $gazebo->id,
            'socio_id' => $pietro->id,
            'quantita' => 2,
            'dal' => '2026-09-01',
            'restituzione_prevista' => '2026-09-08',
        ])->assertRedirect(route('prestiti.index'))->assertSessionHas('success');

        $prestitoPietro = PrestitoAttrezzatura::query()->where('socio_id', $pietro->id)->firstOrFail();
        $this->assertNull($prestitoPietro->restituito_at);
        $this->assertSame(1, $gazebo->fresh()->quantitaDisponibile());

        $this->actingAs($this->admin)
            ->from(route('prestiti.index'))
            ->post(route('prestiti.store'), [
                'attrezzatura_id' => $gazebo->id,
                'socio_id' => $chiara->id,
                'quantita' => 2,
                'dal' => '2026-09-02',
                'restituzione_prevista' => '2026-09-05',
            ])
            ->assertSessionHasErrors('quantita');

        // Pietro restituisce: la disponibilità torna piena e Chiara ottiene i suoi due gazebo
        Carbon::setTestNow('2026-09-08 18:00:00');
        $this->actingAs($this->admin)
            ->post(route('prestiti.restituisci', $prestitoPietro))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($prestitoPietro->fresh()->restituito_at);
        $this->assertSame('2026-09-08', $prestitoPietro->fresh()->restituito_at->toDateString());
        $this->assertSame(3, $gazebo->fresh()->quantitaDisponibile());

        $this->actingAs($this->admin)->post(route('prestiti.store'), [
            'attrezzatura_id' => $gazebo->id,
            'socio_id' => $chiara->id,
            'quantita' => 2,
            'dal' => '2026-09-09',
            'restituzione_prevista' => '2026-09-12',
        ])->assertSessionHas('success');

        $this->assertSame(1, $gazebo->fresh()->quantitaDisponibile());

        $attivi = $this->actingAs($this->admin)->get(route('prestiti.index', ['filtro' => 'attivi']));
        $attivi->assertOk()->assertSee('Chiara Moro');
        $this->assertCount(1, $attivi->viewData('prestiti'));
        $this->assertSame($chiara->id, $attivi->viewData('prestiti')->first()->socio_id);

        // Il progetto "Orto sociale" con due milestone, una completata
        $this->actingAs($this->admin)->post(route('progetti.store'), [
            'nome' => 'Orto sociale di quartiere',
            'descrizione' => 'Recupero dell\'area verde dietro la sede',
            'dal' => '2026-09-01',
            'al' => '2027-06-30',
            'stato' => Progetto::STATO_ATTIVO,
            'budget' => '2500.00',
        ]);

        $progetto = Progetto::query()->where('nome', 'Orto sociale di quartiere')->firstOrFail();

        $this->actingAs($this->admin)->post(route('progetti.milestone.store', $progetto), [
            'titolo' => 'Bonifica del terreno',
            'scadenza' => '2026-10-15',
            'ordine' => 1,
        ])->assertRedirect(route('progetti.show', $progetto));

        $this->actingAs($this->admin)->post(route('progetti.milestone.store', $progetto), [
            'titolo' => 'Prima semina',
            'scadenza' => '2027-03-20',
            'ordine' => 2,
        ])->assertRedirect(route('progetti.show', $progetto));

        $bonifica = MilestoneProgetto::query()->where('titolo', 'Bonifica del terreno')->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('progetti.milestone.toggle', [$progetto, $bonifica]))
            ->assertRedirect(route('progetti.show', $progetto))
            ->assertSessionHas('success', 'Milestone completata.');

        $this->assertNotNull($bonifica->fresh()->completata_at);
        $this->assertNull(MilestoneProgetto::query()->where('titolo', 'Prima semina')->firstOrFail()->completata_at);

        $this->actingAs($this->admin)
            ->get(route('progetti.show', $progetto))
            ->assertOk()
            ->assertSee('Orto sociale di quartiere')
            ->assertSee('Bonifica del terreno')
            ->assertSee('Prima semina')
            ->assertSee('50%');

        $pdf = $this->actingAs($this->admin)->get(route('progetti.report-pdf', $progetto));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringContainsString('report-progetto-orto-sociale-di-quartiere-20260908.pdf', (string) $pdf->headers->get('content-disposition'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $attributi
     */
    private function creaSocio(array $attributi = []): Socio
    {
        return Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            ...$attributi,
        ]);
    }

    private function configuraSmtp(): SmtpSetting
    {
        return SmtpSetting::create([
            'associazione_id' => $this->associazione->id,
            'smtp_host' => 'smtp.circolo-aurora.test',
            'smtp_port' => 587,
            'smtp_user' => 'posta',
            'smtp_pass_encrypted' => 'segretissima',
            'smtp_encryption' => 'tls',
            'from_email' => 'noreply@circolo-aurora.test',
            'from_name' => 'Circolo Aurora',
            'max_per_hour' => 100,
        ]);
    }

    private function urlTenant(string $path = ''): string
    {
        return '/a/'.$this->associazione->slug.($path !== '' ? '/'.ltrim($path, '/') : '');
    }
}
