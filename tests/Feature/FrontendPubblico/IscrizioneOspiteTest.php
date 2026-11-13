<?php

namespace Tests\Feature\FrontendPubblico;

use App\Http\Controllers\FrontendPubblico\IscrizioneController;
use App\Models\Account;
use App\Models\Associazione;
use App\Models\IscrizioneDaConfermare;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use App\Models\Utente;
use App\Notifications\Iscrizioni\ConfermaEmailIscrizioneNotification;
use App\Notifications\Iscrizioni\ConfermaRichiestaInviataNotification;
use App\Notifications\Iscrizioni\RichiestaIscrizioneRicevutaNotification;
use App\Services\Iscrizioni\FormIscrizioneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Spatie\Honeypot\ProtectAgainstSpam;
use Tests\TestCase;

/**
 * Iscrizione all'associazione da OSPITE (senza account): il form
 * /a/{slug}/iscriviti è pubblico, chiede anagrafica + privacy e crea
 * la richiesta senza account collegato, ma solo dopo che il richiedente
 * ha confermato l'email con il link ricevuto.
 */
class IscrizioneOspiteTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create([
            'slug' => 'ospiti',
            'frontend_pubblico_attivo' => true,
            'iscrizioni_socio_aperte' => true,
            'modalita_iscrizione' => 'preiscrizione',
        ]);

        $this->withoutMiddleware(ProtectAgainstSpam::class);
        Notification::fake();
    }

    /**
     * Invia il modulo da ospite e apre il link di conferma ricevuto via email
     * (dal 2026-11 la richiesta nasce solo dopo la conferma dell'indirizzo).
     */
    private function inviaEConferma(array $extra = []): TestResponse
    {
        $payload = $this->payloadOspite($extra);
        $this->post('/a/ospiti/iscriviti', $payload)->assertSessionHas('success');

        return $this->get($this->linkConferma($payload['email']));
    }

    private function linkConferma(string $email): string
    {
        $url = null;
        Notification::assertSentOnDemand(ConfermaEmailIscrizioneNotification::class, function (ConfermaEmailIscrizioneNotification $n, array $canali, AnonymousNotifiable $destinatario) use ($email, &$url): bool {
            if (($destinatario->routes['mail'] ?? null) === $email) {
                $url = $n->url;
            }

            return true;
        });
        $this->assertIsString($url, 'Nessuna email di conferma inviata a '.$email);

        return $url;
    }

    /** @return array<string, mixed> */
    private function payloadOspite(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Giulia',
            'cognome' => 'Neri',
            'email' => 'giulia.neri@example.com',
            'telefono' => '+39 333 0000001',
            'data_nascita' => '1992-03-15',
            'privacy' => '1',
        ], $extra);
    }

    public function test_il_form_e_raggiungibile_anche_da_ospite(): void
    {
        $this->get('/a/ospiti/iscriviti')
            ->assertOk()
            ->assertSee('Iscriviti a')
            ->assertSee('name="nome"', false)
            ->assertSee('name="cognome"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="privacy"', false);
    }

    public function test_ospite_invia_la_richiesta_e_nasce_il_preiscritto_senza_account(): void
    {
        $this->inviaEConferma()
            ->assertRedirect(route('pubblico.home.show', 'ospiti'))
            ->assertSessionHas('success');

        $richiesta = RichiestaIscrizione::withoutGlobalScopes()->latest()->firstOrFail();
        $this->assertNull($richiesta->account_id);
        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->stato);
        $this->assertSame('giulia.neri@example.com', $richiesta->dati_form['email']);

        $socio = Socio::withoutGlobalScopes()->where('email', 'giulia.neri@example.com')->firstOrFail();
        $this->assertSame(Socio::STATO_IN_ATTESA, $socio->stato);
        $this->assertSame('Giulia', $socio->nome);
    }

    public function test_ospite_senza_privacy_viene_bloccato(): void
    {
        $this->post('/a/ospiti/iscriviti', $this->payloadOspite(['privacy' => null]))
            ->assertSessionHasErrors('privacy');

        $this->assertDatabaseCount('richieste_iscrizione', 0);
    }

    public function test_ospite_senza_anagrafica_viene_bloccato(): void
    {
        $this->post('/a/ospiti/iscriviti', $this->payloadOspite(['nome' => '', 'email' => 'non-valida']))
            ->assertSessionHasErrors(['nome', 'email']);
    }

    public function test_email_gia_di_un_socio_da_ospite_ha_risposta_neutra_senza_creare_richieste(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'giulia.neri@example.com',
        ]);

        // Stessa risposta di una richiesta inviata: un anonimo non deve poter
        // scoprire dal modulo pubblico che l'email appartiene a un socio
        $this->post('/a/ospiti/iscriviti', $this->payloadOspite())
            ->assertRedirect('/a/ospiti')
            ->assertSessionHas('success', IscrizioneController::MESSAGGIO_CONFERMA_OSPITE)
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('richieste_iscrizione', 0);
        $this->assertDatabaseCount('iscrizioni_da_confermare', 0);
        Notification::assertNothingSent();
    }

    public function test_doppia_richiesta_ospite_con_stessa_email_viene_bloccata(): void
    {
        $this->inviaEConferma()->assertSessionHas('success');

        // Da ospite la seconda richiesta ha la stessa risposta neutra (prima l'errore
        // "hai già una richiesta in attesa" rivelava a chiunque l'iscrizione in corso)
        $this->post('/a/ospiti/iscriviti', $this->payloadOspite())
            ->assertSessionDoesntHaveErrors()
            ->assertSessionHas('success', IscrizioneController::MESSAGGIO_CONFERMA_OSPITE);

        $this->assertDatabaseCount('richieste_iscrizione', 1);
        $this->assertDatabaseCount('iscrizioni_da_confermare', 0);
    }

    public function test_modalita_diretta_da_ospite_crea_subito_il_socio_attivo(): void
    {
        $this->associazione->update(['modalita_iscrizione' => 'diretta']);

        // Anche in modalità diretta prima serve la conferma dell'email
        $this->post('/a/ospiti/iscriviti', $this->payloadOspite());
        $this->assertSame(0, Socio::withoutGlobalScopes()->where('email', 'giulia.neri@example.com')->count());

        $this->get($this->linkConferma('giulia.neri@example.com'))
            ->assertRedirect(route('pubblico.home.show', 'ospiti'));

        $socio = Socio::withoutGlobalScopes()->where('email', 'giulia.neri@example.com')->firstOrFail();
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);
    }

    public function test_ospite_senza_conferma_non_crea_nulla_e_non_occupa_l_email(): void
    {
        $this->post('/a/ospiti/iscriviti', $this->payloadOspite())
            ->assertRedirect('/a/ospiti')
            ->assertSessionHas('success', IscrizioneController::MESSAGGIO_CONFERMA_OSPITE);

        // Nessun socio, nessuna richiesta per la segreteria, nessun avviso agli admin
        $this->assertDatabaseCount('richieste_iscrizione', 0);
        $this->assertSame(0, Socio::withoutGlobalScopes()->where('associazione_id', $this->associazione->id)->count());
        Notification::assertSentTimes(RichiestaIscrizioneRicevutaNotification::class, 0);
        Notification::assertSentTimes(ConfermaRichiestaInviataNotification::class, 0);

        // I dati in sospeso sono cifrati e scadono dopo 48 ore
        $pendente = IscrizioneDaConfermare::query()->sole();
        $this->assertStringNotContainsString('giulia.neri', (string) DB::table('iscrizioni_da_confermare')->value('dati_form'));
        $this->assertSame('Giulia', $pendente->dati_form['nome']);
        $this->assertEqualsWithDelta(now()->addHours(FormIscrizioneService::ORE_VALIDITA_CONFERMA)->timestamp, $pendente->scade_at->timestamp, 5);

        // La stessa email resta libera: la vera titolare può chiedere e confermare
        $this->inviaEConferma(['nome' => 'Giulia vera'])->assertSessionHas('success');
        $this->assertSame(1, RichiestaIscrizione::withoutGlobalScopes()->count());
    }

    public function test_conferma_crea_la_richiesta_e_solo_allora_avvisa_segreteria_e_richiedente(): void
    {
        $admin = Utente::factory()->create([
            'associazione_id' => $this->associazione->id,
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE,
            'attivo' => true,
        ]);

        $this->post('/a/ospiti/iscriviti', $this->payloadOspite());
        Notification::assertNotSentTo($admin, RichiestaIscrizioneRicevutaNotification::class);

        $link = $this->linkConferma('giulia.neri@example.com');
        $this->get($link)->assertRedirect('/a/ospiti')->assertSessionHas('success', fn (string $m) => str_contains($m, 'Email confermata'));

        $richiesta = RichiestaIscrizione::withoutGlobalScopes()->sole();
        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->stato);
        $this->assertSame(Socio::STATO_IN_ATTESA, Socio::withoutGlobalScopes()->findOrFail($richiesta->socio_id)->stato);
        $this->assertDatabaseCount('iscrizioni_da_confermare', 0);
        Notification::assertSentTo($admin, RichiestaIscrizioneRicevutaNotification::class);
        Notification::assertSentOnDemand(ConfermaRichiestaInviataNotification::class);

        // Secondo clic sullo stesso link: nessun doppione
        $this->get($link)->assertRedirect('/a/ospiti/iscriviti')->assertSessionHasErrors('iscrizione');
        $this->assertSame(1, RichiestaIscrizione::withoutGlobalScopes()->count());
    }

    public function test_link_di_conferma_manomesso_scaduto_o_di_un_altra_associazione_rifiutato(): void
    {
        $this->post('/a/ospiti/iscriviti', $this->payloadOspite());
        $link = $this->linkConferma('giulia.neri@example.com');
        $pendente = IscrizioneDaConfermare::query()->sole();

        // Firma manomessa
        $this->get(preg_replace('/signature=([0-9a-f]+)/', 'signature=deadbeef', $link))
            ->assertRedirect('/a/ospiti/iscriviti')->assertSessionHasErrors('iscrizione');
        // Senza firma
        $this->get('/a/ospiti/iscriviti?conferma='.$pendente->id)->assertSessionHasErrors('iscrizione');

        // Link firmato valido ma verso un'altra associazione: l'id non le appartiene
        $altra = Associazione::factory()->create(['slug' => 'altra', 'frontend_pubblico_attivo' => true, 'iscrizioni_socio_aperte' => true]);
        $this->get(URL::temporarySignedRoute('pubblico.iscriviti', now()->addHour(), ['associazione' => $altra->slug, 'conferma' => $pendente->id]))
            ->assertSessionHasErrors('iscrizione');
        $this->assertDatabaseCount('richieste_iscrizione', 0);

        // Scaduto (48 ore passate): rifiutato anche con la firma giusta, e ripulito al prossimo invio
        $this->travel(FormIscrizioneService::ORE_VALIDITA_CONFERMA + 1)->hours();
        $this->get($link)->assertSessionHasErrors('iscrizione');
        $this->assertDatabaseCount('richieste_iscrizione', 0);
        $this->post('/a/ospiti/iscriviti', $this->payloadOspite(['email' => 'altra@example.com']));
        $this->assertSame(0, IscrizioneDaConfermare::query()->whereKey($pendente->id)->count());
    }

    public function test_il_modulo_non_serve_a_inondare_una_casella_email(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        for ($i = 0; $i < FormIscrizioneService::CONFERME_PER_INDIRIZZO + 2; $i++) {
            $this->post('/a/ospiti/iscriviti', $this->payloadOspite())
                ->assertSessionHas('success', IscrizioneController::MESSAGGIO_CONFERMA_OSPITE);
        }

        Notification::assertSentOnDemandTimes(ConfermaEmailIscrizioneNotification::class, FormIscrizioneService::CONFERME_PER_INDIRIZZO);
    }

    public function test_richieste_e_preiscritti_gia_esistenti_restano_validi(): void
    {
        // Richiesta di un ospite nata prima della conferma email: resta in attesa e si approva come prima
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'storico@example.com',
            'stato' => Socio::STATO_IN_ATTESA,
        ]);
        $richiesta = RichiestaIscrizione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'account_id' => null,
            'socio_id' => $socio->id,
            'stato' => RichiestaIscrizione::STATO_IN_ATTESA,
        ]);

        // Un nuovo invio con la stessa email non crea nulla (risposta neutra)
        $this->post('/a/ospiti/iscriviti', $this->payloadOspite(['email' => 'storico@example.com']))
            ->assertSessionHas('success', IscrizioneController::MESSAGGIO_CONFERMA_OSPITE);
        $this->assertDatabaseCount('iscrizioni_da_confermare', 0);
        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->fresh()->stato);
        $this->assertSame(Socio::STATO_IN_ATTESA, $socio->fresh()->stato);
    }

    public function test_account_loggato_continua_a_usare_il_flusso_collegato(): void
    {
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($account, 'account')
            ->post('/a/ospiti/iscriviti', ['telefono' => '+39 333 0000002', 'data_nascita' => '1990-01-01'])
            ->assertSessionHas('success');

        $richiesta = RichiestaIscrizione::withoutGlobalScopes()->latest()->firstOrFail();
        $this->assertSame($account->id, $richiesta->account_id);
    }
}
