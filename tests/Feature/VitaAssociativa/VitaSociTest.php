<?php

namespace Tests\Feature\VitaAssociativa;

use App\Mail\AreaSoci\ImpostaPasswordSocioMail;
use App\Models\Account;
use App\Models\Annuncio;
use App\Models\Associazione;
use App\Models\Carica;
use App\Models\Cessazione;
use App\Models\DocumentoSocio;
use App\Models\EmailLog;
use App\Models\EmailQueue;
use App\Models\EmailTemplate;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\FeedbackEvento;
use App\Models\Mansione;
use App\Models\Movimento;
use App\Models\NewsletterInviata;
use App\Models\Nucleo;
use App\Models\PaymentGatewaySetting;
use App\Models\Prenotazione;
use App\Models\PromemoriaInviato;
use App\Models\Quota;
use App\Models\Ricevuta;
use App\Models\RichiestaIscrizione;
use App\Models\RichiestaModificaDati;
use App\Models\RispostaSondaggio;
use App\Models\Riunione;
use App\Models\Sede;
use App\Models\SmtpSetting;
use App\Models\Socio;
use App\Models\Sondaggio;
use App\Models\StoricoAttivitaSocio;
use App\Models\Tessera;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use App\Models\Utente;
use App\Notifications\Accounts\VerificaEmailAccount;
use App\Notifications\AnagraficaPro\DocumentiInScadenzaNotification;
use App\Notifications\Comunicazione\AuguriCompleannoNotification;
use App\Notifications\Comunicazione\ConvocazioneAssembleaNotification;
use App\Notifications\Economia\SollecitoQuotaNotification;
use App\Notifications\EventiVita\FeedbackEventoNotification;
use App\Notifications\Iscrizioni\ConfermaRichiestaInviataNotification;
use App\Notifications\Iscrizioni\IscrizioneApprovataNotification;
use App\Notifications\Iscrizioni\IscrizioneRifiutataNotification;
use App\Notifications\Iscrizioni\RichiestaIscrizioneRicevutaNotification;
use App\Notifications\Operativita\PromemoriaAttivitaNotification;
use App\Notifications\Prenotazioni\AnnullataNotification;
use App\Notifications\Prenotazioni\ConfermataNotification;
use App\Notifications\Prenotazioni\EventoEsauritoNotification;
use App\Notifications\Prenotazioni\InAttesaPagamentoNotification;
use App\Notifications\Prenotazioni\InListaAttesaNotification;
use App\Notifications\Prenotazioni\PromemoriaEventoNotification;
use App\Notifications\Prenotazioni\PromozioneListaAttesaNotification;
use App\Notifications\Privacy\DatiPersonaliPronti;
use App\Notifications\Quote\BenvenutoSocioNotification;
use App\Notifications\Quote\PagamentoQuotaNotification;
use App\Notifications\Quote\RicevutaQuotaNotification;
use App\Notifications\Rinnovi\PromemoriaRinnovoNotification;
use App\Notifications\Struttura\ConvocazioneRiunioneNotification;
use App\Notifications\VolontariTurni\CandidaturaTurnoNotification;
use App\Notifications\VolontariTurni\PromemoriaTurnoNotification;
use App\Notifications\VolontariTurni\RinunciaTurnoNotification;
use App\Services\Economia\SollecitoQuoteService;
use App\Services\Email\TenantMailer;
use App\Services\EventiVita\VitaSocialeService;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\ConfermaIscrizioneOspite;
use Tests\TestCase;

/**
 * La vita dei soci — percorsi cronologici dal punto di vista delle persone.
 *
 * Ogni test racconta un pezzo di vita associativa reale, con il tempo che
 * scorre (Carbon::setTestNow) e più attori che si alternano: il cittadino
 * sul mini-sito (/a/{slug}), il socio nell'area soci, la segreteria e il
 * consiglio nel pannello, lo scheduler che gira i comandi di notte, i
 * gateway di pagamento che richiamano i webhook. Le verifiche guardano
 * sempre tre cose: cosa è salvato nel database, quali notifiche/email
 * partono (e quante: niente doppioni) e cosa vede ciascun attore.
 *
 * Dove l'app si comporta diversamente da come dovrebbe (bug reale), il
 * percorso registra la difformità con attesoCheck() e continua con il
 * gesto che farebbe la segreteria nella vita reale; a fine percorso
 * assertNessunaDifformita() fa fallire il test elencando tutti i difetti
 * incontrati, così nessuna verifica successiva va persa.
 */
class VitaSociTest extends TestCase
{
    use ConfermaIscrizioneOspite;
    use RefreshDatabase;

    private const PASSWORD = 'Lampione-Viola-Treno-42';

    private Associazione $associazione;

    private Utente $admin;

    /** @var list<string> difformità rilevate durante il percorso */
    private array $difformita = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();

        // Persone vere, non bot: honeypot spento e niente rate limiting per IP
        // (tutti gli attori del test client condividono lo stesso indirizzo)
        config(['honeypot.enabled' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->seed(RolesSeeder::class);

        $this->associazione = Associazione::factory()->create([
            'nome' => 'Circolo Arci La Lanterna',
            'frontend_pubblico_attivo' => true,
            'costo_tessera' => 25.00,
        ]);

        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Anna',
            'cognome' => 'Presidente',
        ]);
        $this->admin->assignRole('admin_associazione');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    // ==================================================================
    // Helper: difformità
    // ==================================================================

    /**
     * Registra una difformità tra il comportamento atteso e quello reale
     * senza interrompere il percorso (il test fallirà alla fine).
     */
    private function attesoCheck(bool $condizione, string $descrizione): void
    {
        $this->addToAssertionCount(1);

        if (! $condizione) {
            $this->difformita[] = $descrizione;
        }
    }

    /**
     * Quante notifiche di una classe sono partite verso un indirizzo email
     * (destinatario on-demand o modello con attributo email).
     */
    private function notificheVersoEmail(string $email, string $classe): int
    {
        $conteggio = 0;

        foreach (Notification::sentNotifications() as $perTipo) {
            foreach ($perTipo as $perChiave) {
                foreach ($perChiave[$classe] ?? [] as $invio) {
                    $destinatario = $invio['notifiable'];
                    $indirizzo = $destinatario instanceof AnonymousNotifiable
                        ? ($destinatario->routes['mail'] ?? null)
                        : ($destinatario->email ?? null);

                    if ($indirizzo === $email) {
                        $conteggio++;
                    }
                }
            }
        }

        return $conteggio;
    }

    private function assertNessunaDifformita(): void
    {
        $this->assertSame(
            [],
            $this->difformita,
            "Il percorso ha rilevato comportamenti dell'app difformi dall'atteso:\n- ".implode("\n- ", $this->difformita)
        );
    }

    // ==================================================================
    // Helper: attori
    // ==================================================================

    private function urlTenant(string $path = ''): string
    {
        return '/a/'.$this->associazione->slug.($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    /**
     * Nuovo browser: nessun utente su nessuna guard, sessione pulita.
     */
    private function esci(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->flushSession();
        app()->forgetInstance('tenant.pubblico');
    }

    private function comeAdmin(): static
    {
        $this->esci();

        return $this->actingAs($this->admin, 'web');
    }

    private function comeUtente(Utente $utente): static
    {
        $this->esci();

        return $this->actingAs($utente->fresh(), 'web');
    }

    private function comeAccount(Account $account): static
    {
        $this->esci();

        return $this->actingAs($account->fresh(), 'account');
    }

    private function comeSocio(Socio $socio): static
    {
        $this->esci();

        return $this->actingAs($socio->fresh(), 'socio');
    }

    private function creaOperatore(string $nome = 'Luca', string $cognome = 'Segreteria'): Utente
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => $nome,
            'cognome' => $cognome,
            'email' => strtolower($nome.'.'.$cognome).'@lanterna.test',
        ]);
        $operatore->assignRole('operatore');

        return $operatore;
    }

    /**
     * Persona che si registra sul sito, chiude il browser, apre il link
     * dell'email dal telefono e poi accede con le sue credenziali.
     *
     * @param  array<string, string>  $dati
     */
    private function registraEVerifica(array $dati): Account
    {
        $this->esci();

        $dati = array_merge([
            'telefono' => '3331234567',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'privacy' => '1',
        ], $dati);

        // Dopo la registrazione si torna all'accesso (risposta neutra, niente login automatico)
        $this->post($this->urlTenant('registrati'), $dati)
            ->assertRedirect($this->urlTenant('login'));

        /** @var Account $account */
        $account = Account::allTenants()->where('email', $dati['email'])->firstOrFail();

        // Dopo la registrazione non è loggato: nessun logout da fare
        $this->assertGuest('account');

        $this->get($this->linkDiVerifica($account))
            ->assertRedirect($this->urlTenant('login'))
            ->assertSessionHas('success');

        $this->assertNotNull($account->fresh()->email_verified_at);

        $this->post($this->urlTenant('login'), [
            'email' => $dati['email'],
            'password' => $dati['password'],
        ])->assertRedirect($this->urlTenant());

        $this->assertAuthenticatedAs($account->fresh(), 'account');

        return $account->fresh();
    }

    private function linkDiVerifica(Account $account): string
    {
        $link = null;

        Notification::assertSentTo(
            $account,
            VerificaEmailAccount::class,
            function (VerificaEmailAccount $notifica, array $canali, Account $destinatario) use (&$link): bool {
                $link = $notifica->toMail($destinatario)->actionUrl;

                return true;
            }
        );

        $this->assertIsString($link);

        return $link;
    }

    /**
     * Il socio chiede il link "imposta password" dell'area soci, apre la
     * mail, sceglie la password ed entra.
     */
    private function primoAccessoAreaSoci(Socio $socio, int $mailAttese = 1): void
    {
        $this->esci();

        $this->post(route('area-soci.password.email'), ['email' => $socio->email])
            ->assertSessionHas('status');

        $link = null;
        Mail::assertSent(ImpostaPasswordSocioMail::class, function (ImpostaPasswordSocioMail $mail) use ($socio, &$link): bool {
            if ($mail->hasTo($socio->email)) {
                $link = $mail->url;

                return true;
            }

            return false;
        });
        Mail::assertSent(ImpostaPasswordSocioMail::class, $mailAttese);
        $this->assertIsString($link);

        $this->get($link)->assertOk();

        $token = basename(parse_url($link, PHP_URL_PATH));
        $this->post(route('area-soci.set-password.store', ['token' => $token]), [
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($socio->fresh()->password_hash);

        $this->esci();
        $this->post(route('area-soci.login.attempt'), [
            'email' => $socio->email,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('area-soci.dashboard'));

        $this->assertAuthenticatedAs($socio->fresh(), 'socio');
    }

    // ==================================================================
    // Helper: dati di partenza
    // ==================================================================

    /**
     * @param  array<string, mixed>  $attributi
     */
    private function creaSocio(array $attributi = []): Socio
    {
        return Socio::factory()->create(array_merge([
            'associazione_id' => $this->associazione->id,
            // data di nascita fissa: niente auguri "casuali" nei percorsi con compleanni:auguri
            'data_nascita' => '1975-03-03',
        ], $attributi));
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function creaTessera(Socio $socio, int $anno, array $override = []): Tessera
    {
        static $progressivo = 0;
        $progressivo++;

        return Tessera::factory()->create(array_merge([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno_validita' => $anno,
            'numero_tessera' => $anno.str_pad((string) (9000 + $progressivo), 4, '0', STR_PAD_LEFT),
            'data_emissione' => $anno.'-01-10',
            'data_scadenza' => $anno.'-12-31',
            'stato' => Tessera::STATO_ATTIVA,
        ], $override));
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function creaQuota(Socio $socio, int $anno, float $importo = 25.0, array $override = []): Quota
    {
        return Quota::factory()->create(array_merge([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno' => $anno,
            'importo' => $importo,
            'data_scadenza' => $anno.'-12-31',
            'data_pagamento' => null,
            'stato' => Quota::STATO_DA_PAGARE,
            'tipo' => 'Quota Associativa',
        ], $override));
    }

    private function configuraStripe(): PaymentGatewaySetting
    {
        return PaymentGatewaySetting::factory()->conStripe()->create([
            'associazione_id' => $this->associazione->id,
            'auto_attivazione_pagamento' => true,
        ]);
    }

    private function configuraSmtp(array $override = []): SmtpSetting
    {
        return SmtpSetting::create(array_merge([
            'associazione_id' => $this->associazione->id,
            'smtp_host' => 'smtp.lanterna.test',
            'smtp_port' => 587,
            'smtp_user' => 'posta',
            'smtp_pass_encrypted' => 'segretissima',
            'smtp_encryption' => 'tls',
            'from_email' => 'segreteria@lanterna.test',
            'from_name' => 'Circolo La Lanterna',
            'max_per_hour' => 500,
        ], $override));
    }

    /**
     * Stripe conferma il pagamento richiamando il webhook del tenant
     * (firma verificata dal gateway, qui un mock che restituisce il payload).
     */
    private function stripeConfermaPagamento(Quota $quota, string $transazione): void
    {
        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('constructWebhookEvent')
                ->andReturnUsing(fn (string $payload) => json_decode($payload, true));
        });

        $this->postJson(route('webhooks.stripe'), [
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_'.$transazione,
                    'payment_intent' => $transazione,
                    'metadata' => [
                        'quota_id' => $quota->id,
                        'associazione_id' => $quota->associazione_id,
                    ],
                ],
            ],
        ], ['Stripe-Signature' => 't=1,v1=firma'])->assertOk();
    }

    /**
     * Pagamento offline registrato dalla segreteria dal pannello quote.
     */
    private function pagaOffline(Quota $quota, string $metodo = 'contanti', ?string $data = null): void
    {
        $this->comeAdmin()
            ->post(route('quote.pagamento', $quota), [
                'payment_method' => $metodo,
                'payment_date' => $data ?? now()->toDateString(),
            ])
            ->assertRedirect(route('quote.index'))
            ->assertSessionHas('success', 'Pagamento registrato con successo.');
    }

    /**
     * Form completo della scheda socio, come lo invia la segreteria.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function formSocio(Socio $socio, array $override = []): array
    {
        return array_merge([
            'numero_socio' => $socio->numero_socio,
            'nome' => $socio->nome,
            'cognome' => $socio->cognome,
            'email' => $socio->email,
            'telefono' => $socio->telefono,
            'data_nascita' => $socio->data_nascita?->toDateString(),
            'data_iscrizione' => $socio->data_iscrizione?->toDateString(),
            'stato' => $socio->stato,
            'indirizzo' => $socio->indirizzo,
            'citta' => $socio->citta,
            'cap' => $socio->cap,
            'sede_id' => $socio->sede_id,
            'tutore_nome' => $socio->tutore_nome,
            'tutore_telefono' => $socio->tutore_telefono,
            'tutore_email' => $socio->tutore_email,
            'privacy_consenso' => '1',
        ], $override);
    }

    /**
     * La segreteria iscrive una persona allo sportello: pre-iscrizione,
     * approvazione e pagamento in contanti (flusso SocioStatoController).
     *
     * @param  array<string, mixed>  $dati
     */
    private function iscriviInSegreteria(array $dati, float $importo = 25.0, ?Utente $chi = null): Socio
    {
        $this->comeUtente($chi ?? $this->admin)
            ->post(route('soci.store'), array_merge([
                'data_iscrizione' => now()->toDateString(),
                'stato' => Socio::STATO_IN_ATTESA,
                'privacy_consenso' => '1',
                'genera_tessera' => '0',
            ], $dati))
            ->assertSessionHasNoErrors();

        $socio = Socio::allTenants()
            ->where('associazione_id', $this->associazione->id)
            ->where('nome', $dati['nome'])
            ->where('cognome', $dati['cognome'])
            ->firstOrFail();

        $this->comeAdmin()
            ->from(route('soci.show', $socio))
            ->post(route('soci.approva', $socio))
            ->assertSessionHas('success');

        $this->assertSame(Socio::STATO_IN_ATTESA_DI_PAGAMENTO, $socio->fresh()->stato);

        $this->from(route('soci.show', $socio))
            ->post(route('soci.conferma-pagamento', $socio), [
                'importo' => number_format($importo, 2, '.', ''),
                'metodo' => 'contanti',
                'tipo' => 'Quota Associativa',
                'data_pagamento' => now()->toDateString(),
            ])
            ->assertSessionHas('success');

        $this->assertSame(Socio::STATO_ATTIVO, $socio->fresh()->stato);

        return $socio->fresh();
    }

    private function apriAnno(int $anno): void
    {
        $this->comeAdmin()
            ->post(route('apertura-anno.genera'), ['anno' => $anno])
            ->assertRedirect(route('apertura-anno.index', ['anno' => $anno]))
            ->assertSessionHas('success');
    }

    private function quotaDi(Socio $socio, int $anno): Quota
    {
        return Quota::allTenants()->where('socio_id', $socio->id)->where('anno', $anno)->sole();
    }

    /**
     * Step consumati dai promemoria di rinnovo ricevuti dal socio, in ordine.
     *
     * @return list<int>
     */
    private function stepPromemoriaRicevuti(Socio $socio): array
    {
        $step = [];

        Notification::assertSentTo($socio, PromemoriaRinnovoNotification::class, function (PromemoriaRinnovoNotification $n) use (&$step): bool {
            $step[] = $n->step;

            return true;
        });

        return $step;
    }

    /**
     * Testo di tutte le celle di un export Excel scaricato (BinaryFileResponse).
     */
    private function contenutoExcel(TestResponse $risposta): string
    {
        $file = $risposta->baseResponse->getFile()->getPathname();
        $foglio = IOFactory::load($file);
        $testo = [];

        foreach ($foglio->getAllSheets() as $sheet) {
            foreach ($sheet->toArray() as $riga) {
                $testo[] = implode(' | ', array_map(fn ($c) => (string) $c, $riga));
            }
        }

        return implode("\n", $testo);
    }

    /**
     * Form "nuovo evento" del pannello, compilato con una serata pubblica gratuita.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function datiEvento(array $override = []): array
    {
        return array_merge([
            'titolo' => 'Serata al circolo',
            'descrizione' => 'Incontro aperto ai soci e agli amici del circolo.',
            'data_evento' => now()->addDays(20)->setTime(21, 0)->format('Y-m-d\TH:i'),
            'data_fine' => now()->addDays(20)->setTime(23, 30)->format('Y-m-d\TH:i'),
            'luogo' => 'Sala grande',
            'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
            'capienza' => 30,
            'prezzo' => '0',
            'max_posti_per_prenotazione' => 4,
            'lista_attesa_attiva' => '1',
            'iscrizioni_aperte' => '1',
            'richiede_approvazione' => '0',
            'annullamento_entro_ore' => 24,
            'solo_soci' => '0',
        ], $override);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function adminCreaEvento(array $override = []): Evento
    {
        $dati = $this->datiEvento($override);

        $this->comeAdmin()
            ->post(route('eventi.store'), $dati)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('eventi.index'));

        return Evento::allTenants()->where('titolo', $dati['titolo'])->firstOrFail();
    }

    private function urlPubblica(string $nome, array $parametri = []): string
    {
        return route('pubblico.'.$nome, ['associazione' => $this->associazione->slug] + $parametri);
    }

    private function prenota(Account $account, Evento $evento, int $posti = 1): Prenotazione
    {
        $this->comeAccount($account)
            ->post($this->urlPubblica('eventi.prenota', ['evento' => $evento->id]), ['posti' => $posti])
            ->assertSessionHasNoErrors();

        return Prenotazione::allTenants()
            ->where('account_id', $account->id)
            ->where('evento_id', $evento->id)
            ->latest()
            ->firstOrFail();
    }

    /**
     * Account del mini-sito già verificato e collegato al socio.
     */
    private function accountDelSocio(Socio $socio): Account
    {
        return Account::factory()->verificato()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => $socio->nome,
            'cognome' => $socio->cognome,
            'email' => $socio->email,
            'socio_id' => $socio->id,
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * Convocazione dell'assemblea preparata e inviata dall'admin.
     */
    private function inviaConvocazioneAssemblea(string $titolo): Annuncio
    {
        $this->comeAdmin()->post(route('annunci.store'), [
            'titolo' => $titolo,
            'tipo' => Annuncio::TIPO_CONVOCAZIONE,
            'corpo_html' => '<p>Si convoca l\'assemblea ordinaria dei soci.</p>',
            'data_assemblea' => now()->addDays(20)->setTime(21, 0)->format('Y-m-d\TH:i'),
            'luogo_assemblea' => 'Sede sociale, via Roma 1',
            'ordine_del_giorno' => "1. Bilancio\n2. Varie",
            'pubblicato_dal' => now()->subHour()->format('Y-m-d\TH:i'),
            'solo_soci' => '1',
        ])->assertRedirect(route('annunci.index'));

        $annuncio = Annuncio::allTenants()->where('titolo', $titolo)->firstOrFail();

        $this->post(route('annunci.invia-convocazione', $annuncio))
            ->assertRedirect(route('annunci.index'))
            ->assertSessionHas('success');

        return $annuncio;
    }

    /**
     * Newsletter spedita dall'admin con un annuncio pubblicato.
     */
    private function inviaNewsletter(string $titolo): NewsletterInviata
    {
        $annuncio = Annuncio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Notizia: '.$titolo,
            'corpo_html' => '<p>Le novità del circolo.</p>',
        ]);

        $this->comeAdmin()
            ->post(route('newsletter.store'), ['titolo' => $titolo, 'annunci' => [$annuncio->id]])
            ->assertRedirect(route('newsletter.index'));

        return NewsletterInviata::allTenants()->where('titolo', $titolo)->sole();
    }

    // ==================================================================
    // 1. Dal mini-sito alla tessera, pagando online
    // ==================================================================

    public function test_vita_01_dal_minisito_alla_tessera_un_cittadino_diventa_socio_pagando_online_e_riceve_tutte_le_email_nell_ordine_giusto(): void
    {
        Carbon::setTestNow('2026-02-03 18:30:00');
        $this->configuraStripe();

        // Marco scopre il circolo dal mini-sito e guarda il modulo di iscrizione
        $this->get($this->urlTenant())->assertOk()->assertSee('Circolo Arci La Lanterna');
        $this->get($this->urlTenant('iscriviti'))->assertOk()->assertSee('name="email"', false);

        // Si registra, verifica l'email ed entra
        $account = $this->registraEVerifica([
            'nome' => 'Marco',
            'cognome' => 'Bellini',
            'email' => 'marco.bellini@example.test',
        ]);
        Notification::assertSentToTimes($account, VerificaEmailAccount::class, 1);

        // Compila il modulo soci dalla sua area
        $this->post($this->urlTenant('iscriviti'), [
            'telefono' => '3331234567',
            'data_nascita' => '1988-04-02',
            'citta' => 'Pisa',
        ])->assertRedirect($this->urlTenant('iscriviti'))->assertSessionHas('success');

        $richiesta = RichiestaIscrizione::allTenants()->where('account_id', $account->id)->sole();
        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->stato);
        Notification::assertSentToTimes($account, ConfermaRichiestaInviataNotification::class, 1);
        Notification::assertSentToTimes($this->admin, RichiestaIscrizioneRicevutaNotification::class, 1);
        Notification::assertNotSentTo($account, IscrizioneApprovataNotification::class);

        // Due giorni dopo il consiglio delibera l'ammissione: l'admin approva
        Carbon::setTestNow('2026-02-05 21:15:00');

        $this->comeAdmin()
            ->post(route('richieste-iscrizione.approva', $richiesta), [
                'genera_tessera' => '0',
                'genera_quota' => '1',
                'delibera_numero' => '4/2026',
                'delibera_data' => '2026-02-05',
            ])
            ->assertRedirect('/richieste-iscrizione')
            ->assertSessionHas('success');

        $richiesta->refresh();
        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $richiesta->stato);
        $this->assertSame('4/2026', $richiesta->delibera_numero);
        $this->assertSame('2026-02-05', $richiesta->delibera_data?->toDateString());

        $socio = Socio::allTenants()->findOrFail($richiesta->socio_id);
        $this->assertSame($socio->id, $account->fresh()->socio_id);
        $this->assertNotNull($socio->numero_socio);

        $quota = $this->quotaDi($socio, 2026);
        $this->assertSame(25.0, (float) $quota->importo);
        $this->assertFalse($quota->isPagata());
        $this->assertSame(0, Tessera::allTenants()->where('socio_id', $socio->id)->count(), 'Con genera_tessera=0 la tessera arriva solo con il pagamento.');

        // L'email di approvazione deve portare alla pagina di pagamento della quota
        $linkPagamento = route('pagamento.show', ['token' => $quota->fresh()->payment_token]);
        $mail = null;
        Notification::assertSentTo($account, IscrizioneApprovataNotification::class, function (IscrizioneApprovataNotification $n, array $canali, Account $dest) use (&$mail, $linkPagamento): bool {
            $mail = $n->toMail($dest);

            return $n->linkPagamentoQuota === $linkPagamento;
        });
        Notification::assertSentToTimes($account, IscrizioneApprovataNotification::class, 1);
        $testoMail = $mail->actionUrl.' '.implode(' ', $mail->introLines).' '.implode(' ', $mail->outroLines);
        $this->attesoCheck(
            str_contains($testoMail, $linkPagamento),
            'Test 1: l\'email IscrizioneApprovataNotification annuncia "puoi pagarla online in un minuto" ma non contiene il link di pagamento: MailMessage ha un solo pulsante e la seconda ->action("Vai alla tua area riservata") sovrascrive "Paga la quota associativa" (app/Notifications/Iscrizioni/IscrizioneApprovataNotification.php:62 e :66). actionUrl effettivo: '.$mail->actionUrl
        );

        // Marco segue il link dell'email: arriva comunque alla sua area, dove c'è "Paga ora"
        $this->comeAccount($account)
            ->get($mail->actionUrl)
            ->assertOk()
            ->assertSee('Paga ora')
            ->assertSee($linkPagamento, false);
        $link = $linkPagamento;

        // Libro soci: la data di ammissione è quella della delibera, non quella della domanda
        $this->attesoCheck(
            $socio->fresh()->data_iscrizione?->toDateString() === '2026-02-05',
            'Test 1: dopo l\'approvazione con delibera del 05/02/2026 soci.data_iscrizione resta la data della domanda ('
            .$socio->fresh()->data_iscrizione?->toDateString().'): FormIscrizioneService::salva (app/Services/Iscrizioni/FormIscrizioneService.php:234) la fissa all\'invio del modulo e ApprovazioneIscrizioneService::approva (riga 81) non la aggiorna, mentre la conferma in segreteria e l\'attivazione via webhook la impostano alla data di ammissione; il libro soci (LibroSociService ordina e numera per data_iscrizione) riporta così una data di ammissione diversa dalla delibera.'
        );

        // Marco apre il link dall'email e paga con la carta
        $this->esci();
        $this->get($link)->assertOk()->assertSee('Circolo Arci La Lanterna')->assertSee('25,00');

        $this->stripeConfermaPagamento($quota, 'pi_marco_2026');

        $quota->refresh();
        $socio->refresh();
        $this->assertTrue($quota->isPagata());
        $this->assertSame(Quota::STATO_PAGATA, $quota->stato);
        $this->assertSame('stripe', $quota->gateway);
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);

        // Tessera dell'anno emessa con la numerazione {anno}NNNN e scadenza solare
        $tessera = Tessera::allTenants()->where('socio_id', $socio->id)->sole();
        $this->assertSame(2026, (int) $tessera->anno_validita);
        $this->assertStringStartsWith('2026', $tessera->numero_tessera);
        $this->assertSame('2026-12-31', $tessera->data_scadenza->toDateString());
        $this->assertSame(Tessera::STATO_ATTIVA, $tessera->stato);

        // Ricevuta n. 1 dell'anno e incasso in prima nota
        $ricevuta = Ricevuta::allTenants()->where('quota_id', $quota->id)->sole();
        $this->assertSame(1, (int) $ricevuta->numero);
        $movimenti = Movimento::allTenants()->where('origine_id', $quota->id)->get();
        $this->assertCount(1, $movimenti);
        $this->assertSame(Movimento::TIPO_ENTRATA, $movimenti->first()->tipo);
        $this->assertEqualsWithDelta(25.0, (float) $movimenti->first()->importo, 0.001);

        // Email al socio: conferma pagamento e ricevuta. Il benvenuto non parte:
        // con l'approvazione online il socio è già Attivo (l'email di
        // approvazione fa da benvenuto), la BenvenutoSocioNotification è
        // riservata all'attivazione automatica di chi era "In Attesa di Pagamento".
        Notification::assertSentToTimes($socio, PagamentoQuotaNotification::class, 1);
        Notification::assertSentToTimes($socio, RicevutaQuotaNotification::class, 1);
        Notification::assertNotSentTo($socio, BenvenutoSocioNotification::class);

        // Stripe ritrasmette lo stesso webhook: nessun effetto doppio
        $this->stripeConfermaPagamento($quota, 'pi_marco_2026');
        Notification::assertSentToTimes($socio, PagamentoQuotaNotification::class, 1);
        Notification::assertSentToTimes($socio, RicevutaQuotaNotification::class, 1);
        $this->assertSame(1, Tessera::allTenants()->where('socio_id', $socio->id)->count());
        $this->assertSame(1, Ricevuta::allTenants()->where('quota_id', $quota->id)->count());
        $this->assertSame(1, Movimento::allTenants()->where('origine_id', $quota->id)->count());

        // Il libro soci lo riporta con la delibera di ammissione
        $this->comeAdmin()->get(route('libro-soci.index'))
            ->assertOk()
            ->assertSee('Bellini')
            ->assertSee('4/2026');

        // Dall'area account vede la sua tessera
        $this->comeAccount($account)
            ->get($this->urlTenant('account/tessera'))
            ->assertOk()
            ->assertSee($tessera->numero_tessera);

        // Primo accesso all'area soci: imposta la password e scarica la tessera
        $this->primoAccessoAreaSoci($socio);

        $pdf = $this->get(route('area-soci.tessere.pdf', $tessera));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 2. Iscrizione allo sportello, contanti, benvenuto dalla coda, check-in
    // ==================================================================

    public function test_vita_02_socio_iscritto_a_mano_in_segreteria_paga_in_contanti_riceve_benvenuto_dalla_coda_email_e_nel_corso_dell_anno_viene_fatto_il_checkin(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00');

        $smtp = $this->configuraSmtp(['auto_benvenuto' => true]);
        EmailTemplate::create([
            'associazione_id' => $this->associazione->id,
            'codice' => 'benvenuto',
            'nome' => 'Benvenuto',
            'oggetto' => 'Benvenuta {{nome}} nel circolo',
            'corpo_html' => '<p>Ciao {{nome}}, il tuo numero socio è {{numero_socio}}.</p>',
            'attivo' => true,
        ]);

        // La coda spedisce con l'SMTP del tenant (qui un finto server che accetta tutto)
        $invii = [];
        $this->mock(TenantMailer::class, function ($mock) use (&$invii) {
            $mock->shouldReceive('send')->andReturnUsing(function (SmtpSetting $settings, string $to) use (&$invii) {
                $invii[] = $to;

                return ['success' => true, 'error' => null];
            });
        });

        // L'operatore di segreteria registra la pre-iscrizione di Giulia
        $operatore = $this->creaOperatore();
        $this->comeUtente($operatore)
            ->post(route('soci.store'), [
                'nome' => 'Giulia',
                'cognome' => 'Sportello',
                'email' => 'giulia.sportello@example.test',
                'data_nascita' => '1990-06-21',
                'data_iscrizione' => '2026-01-15',
                'stato' => Socio::STATO_IN_ATTESA,
                'privacy_consenso' => '1',
                'genera_tessera' => '0',
            ])
            ->assertSessionHasNoErrors();

        $giulia = Socio::allTenants()->where('email', 'giulia.sportello@example.test')->sole();
        $this->assertSame(Socio::STATO_IN_ATTESA, $giulia->stato);

        // Comportamento documentato (spec soci §1.3 punto 11 e §452): il benvenuto è
        // accodato già alla creazione del socio, qualunque sia lo stato, e di nuovo
        // alla conferma del pagamento. Qui ne verifichiamo solo la presenza.
        $this->assertSame(1, EmailQueue::allTenants()->where('socio_id', $giulia->id)->where('template_codice', 'benvenuto')->count());

        // L'admin approva e Giulia paga 25 euro in contanti
        $this->comeAdmin()->from(route('soci.show', $giulia))->post(route('soci.approva', $giulia))->assertSessionHas('success');
        $this->assertSame(Socio::STATO_IN_ATTESA_DI_PAGAMENTO, $giulia->fresh()->stato);

        $this->from(route('soci.show', $giulia))
            ->post(route('soci.conferma-pagamento', $giulia), [
                'importo' => '25.00',
                'metodo' => 'contanti',
                'data_pagamento' => '2026-01-15',
            ])
            ->assertRedirect(route('soci.show', $giulia))
            ->assertSessionHas('success');

        $giulia->refresh();
        $this->assertSame(Socio::STATO_ATTIVO, $giulia->stato);
        $this->assertSame('2026-01-15', $giulia->data_iscrizione->toDateString());

        $quota = $this->quotaDi($giulia, 2026);
        $this->assertTrue($quota->isPagata());
        $this->assertSame('contanti', $quota->metodo_pagamento);

        $tessera = Tessera::allTenants()->where('socio_id', $giulia->id)->sole();
        $this->assertSame(2026, (int) $tessera->anno_validita);
        $this->assertSame(Tessera::STATO_ATTIVA, $tessera->stato);
        $this->assertDatabaseHas('storico_attivita_socio', ['socio_id' => $giulia->id, 'tipo_attivita' => 'Pagamento confermato']);
        $this->assertDatabaseHas('storico_attivita_socio', ['socio_id' => $giulia->id, 'tipo_attivita' => 'Generazione Tessera']);

        // L'incasso allo sportello deve finire in prima nota come ogni altro pagamento di quota
        $this->attesoCheck(
            Movimento::allTenants()->where('origine_id', $quota->id)->where('tipo', Movimento::TIPO_ENTRATA)->exists(),
            'Test 2: il pagamento in contanti confermato con soci.conferma-pagamento crea la quota Pagata ma nessun movimento di prima nota (SocioStatoController::confermaPagamento, app/Http/Controllers/Soci/SocioStatoController.php:105, fa Quota::create senza PrimaNotaService::registraIncassoQuotaBestEffort né ricevuta): l\'incasso manca dal rendiconto.'
        );

        // Due email di benvenuto per spec (creazione + conferma pagamento, soci §452):
        // l'ultima è quella dell'attivazione, con nome e numero socio sostituiti
        $benvenuti = EmailQueue::allTenants()->where('socio_id', $giulia->id)->where('template_codice', 'benvenuto')->get();
        $this->assertCount(2, $benvenuti);
        $benvenuto = $benvenuti->last();
        $this->assertNotNull($benvenuto);
        $this->assertSame('Benvenuta Giulia nel circolo', $benvenuto->subject);
        $this->assertStringContainsString('Ciao Giulia, il tuo numero socio è '.$giulia->numero_socio, $benvenuto->body_html);
        $this->assertSame(EmailQueue::STATUS_PENDING, $benvenuto->status);

        // Lo scheduler svuota la coda
        $this->artisan('emails:process-queue', ['--sync' => true])->assertSuccessful();

        $this->assertSame(EmailQueue::STATUS_SENT, $benvenuto->fresh()->status);
        $this->assertContains('giulia.sportello@example.test', $invii);
        $this->assertTrue(EmailLog::allTenants()->where('queue_id', $benvenuto->id)->where('status', EmailLog::STATUS_SENT)->exists());

        // A giugno la festa sociale: check-in al banco con il numero di tessera
        Carbon::setTestNow('2026-06-13 19:00:00');
        $festa = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Festa d\'estate',
            'data_evento' => '2026-06-13 20:00:00',
        ]);

        // Nel frattempo è arrivata un'altra pre-iscrizione, non ancora approvata
        $this->comeAdmin()->post(route('soci.store'), [
            'nome' => 'Paolo',
            'cognome' => 'Attesa',
            'email' => 'paolo.attesa@example.test',
            'data_iscrizione' => '2026-06-10',
            'stato' => Socio::STATO_IN_ATTESA,
            'genera_tessera' => '0',
        ])->assertSessionHasNoErrors();
        $inAttesa = Socio::allTenants()->where('email', 'paolo.attesa@example.test')->sole();

        $this->comeAdmin()
            ->postJson(route('tessere.checkin.store'), ['evento_id' => $festa->id, 'numero_tessera' => $tessera->numero_tessera])
            ->assertOk()
            ->assertJson(['success' => true, 'already_checked_in' => false, 'socio' => ['cognome' => 'Sportello']]);

        $this->postJson(route('tessere.checkin.store'), ['evento_id' => $festa->id, 'numero_tessera' => $tessera->numero_tessera])
            ->assertOk()
            ->assertJson(['success' => true, 'already_checked_in' => true]);

        $this->assertSame(1, EventoPartecipante::allTenants()->where('evento_id', $festa->id)->where('socio_id', $giulia->id)->where('stato_partecipazione', EventoPartecipante::STATO_CONFERMATO)->count());

        $this->postJson(route('tessere.checkin.store'), ['evento_id' => $festa->id, 'numero_socio' => $inAttesa->numero_socio])
            ->assertStatus(400)
            ->assertJson(['success' => false, 'code' => 'socio_not_active']);

        $this->assertSame(1, EventoPartecipante::allTenants()->where('evento_id', $festa->id)->count());

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 3. Minorenne iscritta dal genitore, diventa maggiorenne
    // ==================================================================

    public function test_vita_03_minorenne_iscritto_dal_genitore_con_tutore_obbligatorio_diventa_maggiorenne_e_al_rinnovo_la_scheda_resta_coerente(): void
    {
        Carbon::setTestNow('2026-03-01 17:00:00');
        $this->associazione->update(['auguri_automatici' => true]);

        // Il papà compila il modulo per Sara (17 anni) dimenticando il tutore
        $datiSara = [
            'nome' => 'Sara',
            'cognome' => 'Giovani',
            'email' => 'sara.giovani@example.test',
            'privacy' => '1',
            'telefono' => '3471112233',
            'data_nascita' => '2008-09-10',
            'citta' => 'Lucca',
        ];

        $this->from($this->urlTenant('iscriviti'))
            ->post($this->urlTenant('iscriviti'), $datiSara)
            ->assertRedirect($this->urlTenant('iscriviti'))
            ->assertSessionHasErrors(['tutore_nome', 'tutore_telefono', 'tutore_email']);

        $this->assertSame(0, RichiestaIscrizione::allTenants()->count());

        // Riprova con i dati del tutore e conferma l'email dal link ricevuto
        $this->inviaEConfermaIscrizioneOspite($this->urlTenant('iscriviti'), $datiSara + [
            'tutore_nome' => 'Franco Giovani',
            'tutore_telefono' => '3479998877',
            'tutore_email' => 'franco.giovani@example.test',
        ])->assertRedirect($this->urlTenant())->assertSessionHas('success');

        $richiesta = RichiestaIscrizione::allTenants()->sole();
        $this->assertSame('Franco Giovani', $richiesta->dati_form['tutore_nome']);
        $this->assertSame('3479998877', $richiesta->dati_form['tutore_telefono']);
        $this->assertSame('franco.giovani@example.test', $richiesta->dati_form['tutore_email']);
        Notification::assertSentToTimes($this->admin, RichiestaIscrizioneRicevutaNotification::class, 1);

        // L'admin approva con quota e tessera, poi la quota viene pagata in sede
        $this->comeAdmin()
            ->post(route('richieste-iscrizione.approva', $richiesta), ['genera_tessera' => '1', 'genera_quota' => '1'])
            ->assertSessionHas('success');

        $sara = Socio::allTenants()->findOrFail($richiesta->fresh()->socio_id);
        $this->assertSame(Socio::STATO_ATTIVO, $sara->stato);
        $this->assertSame('Franco Giovani', $sara->tutore_nome);
        $this->assertSame('3479998877', $sara->tutore_telefono);
        $this->assertSame('franco.giovani@example.test', $sara->tutore_email);

        $this->pagaOffline($this->quotaDi($sara, 2026), 'contanti');
        $this->assertTrue($this->quotaDi($sara, 2026)->isPagata());
        Notification::assertSentToTimes($sara, PagamentoQuotaNotification::class, 1);
        $tessera = Tessera::allTenants()->where('socio_id', $sara->id)->where('anno_validita', 2026)->sole();

        // 10 settembre: Sara compie 18 anni, lo scheduler manda gli auguri (una volta sola)
        Carbon::setTestNow('2026-09-10 08:15:00');
        $this->artisan('compleanni:auguri')->assertSuccessful();
        $this->artisan('compleanni:auguri')->assertSuccessful();

        Notification::assertSentToTimes($sara, AuguriCompleannoNotification::class, 1);
        $this->assertSame(1, PromemoriaInviato::allTenants()->where('socio_id', $sara->id)->where('tipo', 'compleanno')->where('step', 2026)->count());

        // 2 dicembre: primo promemoria di rinnovo (30 giorni dalla scadenza)
        Carbon::setTestNow('2026-12-02 07:00:00');
        $this->artisan('rinnovi:promemoria')->assertSuccessful();
        $this->assertSame([30], $this->stepPromemoriaRicevuti($sara));
        $this->assertDatabaseHas('promemoria_inviati', ['socio_id' => $sara->id, 'tessera_id' => $tessera->id, 'tipo' => PromemoriaInviato::TIPO_RINNOVO_TESSERA, 'step' => 30]);

        // Gennaio 2027: Sara, maggiorenne, aggiorna da sola email e telefono
        Carbon::setTestNow('2027-01-12 19:00:00');
        $sara->forceFill(['password_hash' => self::PASSWORD])->save();

        $this->comeSocio($sara)
            ->post(route('area-soci.modifica-dati.store'), [
                'nome' => 'Sara',
                'cognome' => 'Giovani',
                'email' => 'sara.giovani@studenti.example.test',
                'telefono' => '3470001122',
                'citta' => 'Lucca',
            ])
            ->assertRedirect(route('area-soci.modifica-dati'))
            ->assertSessionHas('success');

        $modifica = RichiestaModificaDati::withoutGlobalScopes()->where('socio_id', $sara->id)->sole();
        $this->assertEquals(['email' => 'sara.giovani@studenti.example.test', 'telefono' => '3470001122'], $modifica->proposte);

        $this->comeAdmin()
            ->post(route('richieste-modifica-dati.approva', $modifica))
            ->assertSessionHas('success');

        $sara->refresh();
        $this->assertSame('sara.giovani@studenti.example.test', $sara->email);
        $this->assertSame('3470001122', $sara->telefono);
        $this->assertSame('Franco Giovani', $sara->tutore_nome);
        $this->assertSame('3479998877', $sara->tutore_telefono);
        $this->assertSame('franco.giovani@example.test', $sara->tutore_email);
        $this->assertDatabaseHas('storico_attivita_socio', ['socio_id' => $sara->id, 'tipo_attivita' => 'Modifica Dati']);

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 4. Famiglia di quattro con nucleo e rinnovo a fine anno
    // ==================================================================

    public function test_vita_04_famiglia_di_quattro_nucleo_con_capofamiglia_quote_di_tutti_e_rinnovo_a_fine_anno_solo_per_chi_resta(): void
    {
        Carbon::setTestNow('2026-02-10 10:00:00');
        $this->associazione->update(['costo_tessera' => 30.00]);
        $this->configuraStripe();

        // La famiglia Rossi si iscrive allo sportello, uno alla volta
        $padre = $this->iscriviInSegreteria(['nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario.rossi@example.test', 'data_nascita' => '1972-05-04'], 30.0);
        $madre = $this->iscriviInSegreteria(['nome' => 'Elisa', 'cognome' => 'Rossi', 'email' => 'elisa.rossi@example.test', 'data_nascita' => '1975-11-20'], 30.0);
        $grande = $this->iscriviInSegreteria(['nome' => 'Luca', 'cognome' => 'Rossi', 'email' => 'luca.rossi@example.test', 'data_nascita' => '2003-02-02'], 30.0);
        // In segreteria l'email è obbligatoria anche per un bambino: si usa quella dedicata del ragazzo
        $piccolo = $this->iscriviInSegreteria([
            'nome' => 'Tommi',
            'cognome' => 'Rossi',
            'email' => 'tommi.rossi@example.test',
            'data_nascita' => '2014-07-07',
            'tutore_nome' => 'Mario Rossi',
            'tutore_telefono' => '3331112222',
            'tutore_email' => 'mario.rossi@example.test',
        ], 30.0);

        $famiglia = [$padre, $madre, $grande, $piccolo];
        foreach ($famiglia as $membro) {
            $this->assertSame(1, Tessera::allTenants()->where('socio_id', $membro->id)->where('anno_validita', 2026)->where('stato', Tessera::STATO_ATTIVA)->count());
        }
        $this->assertSame('Mario Rossi', $piccolo->tutore_nome);

        // La segreteria crea il nucleo con il padre capofamiglia e aggiunge gli altri
        $this->comeAdmin()
            ->from(route('soci.show', $padre))
            ->post(route('nuclei.store'), ['nome' => 'Famiglia Rossi', 'capofamiglia_id' => $padre->id])
            ->assertSessionHas('success');
        $nucleo = Nucleo::allTenants()->sole();
        $this->assertSame($padre->id, $nucleo->capofamiglia_id);

        foreach ([$madre, $grande, $piccolo] as $membro) {
            $this->post(route('nuclei.membri.store', $nucleo), ['socio_id' => $membro->id])->assertSessionHas('success');
        }
        foreach ($famiglia as $membro) {
            $this->assertSame($nucleo->id, $membro->fresh()->nucleo_id);
        }

        // Novembre: Luca va a studiare all'estero e si dimette
        Carbon::setTestNow('2026-11-05 18:00:00');
        $this->comeAdmin()
            ->post(route('cessazioni.store'), [
                'socio_id' => $grande->id,
                'tipo' => Cessazione::TIPO_DIMISSIONI,
                'data' => '2026-11-05',
                'motivo' => 'Trasferimento per studio',
            ])
            ->assertRedirect(route('cessazioni.index'))
            ->assertSessionHas('success');

        $this->assertSame(Socio::STATO_RADIATO, $grande->fresh()->stato);
        $this->assertSame($nucleo->id, $grande->fresh()->nucleo_id, 'Il dimesso resta nel nucleo come storico familiare.');
        $this->assertSame(Tessera::STATO_ANNULLATA, Tessera::allTenants()->where('socio_id', $grande->id)->sole()->stato);

        // 1° dicembre: apertura dell'anno 2027, solo per chi resta
        Carbon::setTestNow('2026-12-01 09:00:00');
        $this->apriAnno(2027);

        $quote2027 = Quota::allTenants()->where('anno', 2027)->get();
        $this->assertCount(3, $quote2027);
        $this->assertEqualsCanonicalizing([$padre->id, $madre->id, $piccolo->id], $quote2027->pluck('socio_id')->all());
        $this->assertTrue($quote2027->every(fn (Quota $q) => (float) $q->importo === 30.0));

        // 2 dicembre: promemoria di rinnovo ai tre rimasti
        Carbon::setTestNow('2026-12-02 07:00:00');
        $this->artisan('rinnovi:promemoria')->assertSuccessful();

        foreach ([$padre, $madre, $piccolo] as $membro) {
            Notification::assertSentToTimes($membro, PromemoriaRinnovoNotification::class, 1);
        }
        Notification::assertNotSentTo($grande, PromemoriaRinnovoNotification::class);
        $this->assertSame(3, PromemoriaInviato::allTenants()->where('tipo', PromemoriaInviato::TIPO_RINNOVO_TESSERA)->count());

        // Gennaio 2027: il padre rinnova dall'area soci e paga con la carta
        Carbon::setTestNow('2027-01-09 20:00:00');
        $padre->forceFill(['password_hash' => self::PASSWORD])->save();
        $risposta = $this->comeSocio($padre)->post(route('area-soci.rinnova'));
        $quotaPadre = $this->quotaDi($padre, 2027);
        $risposta->assertRedirect(route('pagamento.show', ['token' => $quotaPadre->fresh()->payment_token]));
        $this->esci();
        $this->stripeConfermaPagamento($quotaPadre, 'pi_rossi_padre_2027');

        // Madre e figlio piccolo pagano in contanti in sede
        $this->pagaOffline($this->quotaDi($madre, 2027));
        $this->pagaOffline($this->quotaDi($piccolo, 2027));

        foreach ([$madre, $piccolo] as $membro) {
            $haTessera = Tessera::allTenants()->where('socio_id', $membro->id)->where('anno_validita', 2027)->exists();
            $this->attesoCheck(
                $haTessera,
                'Test 4: il pagamento offline (quote.pagamento) della quota di rinnovo 2027 di '.$membro->nome.' non emette la tessera 2027, mentre il pagamento online sì (PaymentService::registerOfflinePayment, app/Services/Pagamenti/PaymentService.php:81, non chiama TesseraIssuer::emettiSeAssente come fa onPaymentCompleted alla riga 441).'
            );

            if (! $haTessera) {
                // Nella vita reale la segreteria stampa la tessera dal pannello
                $this->comeAdmin()->post(route('tessere.emetti', $membro))->assertRedirect();
            }
        }

        $tessere2027 = Tessera::allTenants()->where('anno_validita', 2027)->get();
        $this->assertCount(3, $tessere2027);
        $this->assertCount(3, $tessere2027->pluck('numero_tessera')->unique());
        $this->assertTrue($tessere2027->every(fn (Tessera $t) => preg_match('/^2027\d{4}$/', $t->numero_tessera) === 1));
        $this->assertSame(3, Quota::allTenants()->where('anno', 2027)->count(), 'Nessuna quota 2027 duplicata dal rinnovo.');

        // Vista per nuclei e libro soci
        $this->comeAdmin()->get(route('nuclei.index'))
            ->assertOk()
            ->assertSee('Famiglia Rossi')
            ->assertSee('Mario')
            ->assertSee('Elisa')
            ->assertSee('Luca')
            ->assertSee('Tommi')
            ->assertSee('4 membri')
            ->assertSee('Minorenne');
        // La vista per nuclei non mostra lo stato: il dimesso si distingue nella lista soci
        $this->assertSame(
            [Socio::STATO_ATTIVO, Socio::STATO_ATTIVO, Socio::STATO_RADIATO, Socio::STATO_ATTIVO],
            array_map(fn (Socio $s) => $s->fresh()->stato, $famiglia)
        );

        $this->get(route('libro-soci.index'))
            ->assertOk()
            ->assertSee('05/11/2026')
            ->assertSee('Dimissioni');

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 5. Il socio distratto: promemoria, solleciti, decadenza
    // ==================================================================

    public function test_vita_05_socio_distratto_non_rinnova_riceve_i_tre_promemoria_poi_i_solleciti_e_viene_dichiarato_decaduto_dal_consiglio(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');
        $paolo = $this->creaSocio(['nome' => 'Paolo', 'cognome' => 'Distratti', 'email' => 'paolo.distratti@example.test']);
        $tessera = $this->creaTessera($paolo, 2026);
        // Un socio puntuale nella stessa campagna, per confronto
        $puntuale = $this->creaSocio(['nome' => 'Rita', 'cognome' => 'Puntuale', 'email' => 'rita.puntuale@example.test']);
        $this->creaTessera($puntuale, 2026);

        $giri = [
            '2026-11-15' => [],
            '2026-12-02' => [30],
            '2026-12-03' => [30],
            '2026-12-16' => [30, 15],
            '2026-12-28' => [30, 15, 3],
            '2026-12-29' => [30, 15, 3],
        ];

        foreach ($giri as $giorno => $attesi) {
            Carbon::setTestNow($giorno.' 07:00:00');
            $this->artisan('rinnovi:promemoria')->assertSuccessful();

            if ($attesi === []) {
                Notification::assertNotSentTo($paolo, PromemoriaRinnovoNotification::class);
            } else {
                $this->assertSame($attesi, $this->stepPromemoriaRicevuti($paolo), "Promemoria ricevuti da Paolo al {$giorno}");
            }
        }

        $this->assertSame(
            [3, 15, 30],
            PromemoriaInviato::allTenants()->where('socio_id', $paolo->id)->where('tipo', PromemoriaInviato::TIPO_RINNOVO_TESSERA)->orderBy('step')->pluck('step')->map(fn ($s) => (int) $s)->all()
        );

        // Gennaio: si apre il 2027 e il tesoriere sollecita la quota
        Carbon::setTestNow('2027-01-05 09:30:00');
        $this->apriAnno(2027);
        $quota2027 = $this->quotaDi($paolo, 2027);

        $this->comeAdmin()
            ->post(route('solleciti-quote.invia', $quota2027))
            ->assertRedirect(route('solleciti-quote.index'))
            ->assertSessionHas('success', 'Sollecito inviato.');

        $quota2027->refresh();
        $this->assertTrue($quota2027->hasValidPaymentToken());
        Notification::assertSentTo($paolo, SollecitoQuotaNotification::class, fn (SollecitoQuotaNotification $n) => $n->paymentUrl === route('pagamento.show', ['token' => $quota2027->payment_token]));

        Carbon::setTestNow('2027-02-15 09:30:00');
        $this->comeAdmin()->post(route('solleciti-quote.invia', $quota2027))->assertSessionHas('success', 'Sollecito inviato.');

        Notification::assertSentToTimes($paolo, SollecitoQuotaNotification::class, 2);
        $this->assertSame(2, PromemoriaInviato::allTenants()->where('socio_id', $paolo->id)->where('tipo', SollecitoQuoteService::TIPO_SOLLECITO_QUOTA)->where('step', 2027)->count());

        // 20 marzo: il consiglio dichiara la decadenza (delibera del 18 marzo)
        Carbon::setTestNow('2027-03-20 18:00:00');
        $this->comeAdmin()
            ->post(route('cessazioni.store'), [
                'socio_id' => $paolo->id,
                'tipo' => Cessazione::TIPO_DECADENZA,
                'data' => '2027-03-20',
                'motivo' => 'Mancato versamento della quota 2027',
                'delibera_numero' => '2/2027',
                'delibera_data' => '2027-03-18',
            ])
            ->assertRedirect(route('cessazioni.index'))
            ->assertSessionHas('success');

        $paolo->refresh();
        $this->assertSame(Socio::STATO_RADIATO, $paolo->stato);
        $this->assertDatabaseHas('cessazioni', ['socio_id' => $paolo->id, 'tipo' => Cessazione::TIPO_DECADENZA, 'delibera_numero' => '2/2027']);
        // La tessera 2026, già oltre la scadenza ma ancora "Attiva" a DB, viene annullata
        $this->assertSame(Tessera::STATO_ANNULLATA, $tessera->fresh()->stato);
        $this->assertFalse($quota2027->fresh()->isPagata(), 'La quota 2027 resta da pagare: la decadenza non la cancella.');

        $storico = StoricoAttivitaSocio::allTenants()->where('socio_id', $paolo->id)->where('tipo_attivita', 'Cessazione')->sole();
        $this->assertStringContainsString('Cessazione per decadenza', $storico->descrizione);
        $this->assertStringContainsString('delibera n. 2/2027', $storico->descrizione);

        // Non compare più tra i soci da rinnovare
        $this->comeAdmin()->get(route('campagna-rinnovi.index'))
            ->assertOk()
            ->assertSee('Puntuale')
            ->assertDontSee('Distratti');

        // Il giorno dopo nessuna automazione deve più scrivergli
        Carbon::setTestNow('2027-03-21 07:00:00');
        $this->artisan('rinnovi:promemoria')->assertSuccessful();

        $this->comeAdmin()
            ->post(route('solleciti-quote.bulk'), ['quota_ids' => [$quota2027->id, $this->quotaDi($puntuale, 2027)->id]])
            ->assertRedirect(route('solleciti-quote.index'));

        Notification::assertSentToTimes($paolo, PromemoriaRinnovoNotification::class, 3);
        $solleciti = 0;
        Notification::assertSentTo($paolo, SollecitoQuotaNotification::class, function () use (&$solleciti) {
            $solleciti++;

            return true;
        });
        $this->attesoCheck(
            $solleciti === 2,
            'Test 5: dopo la delibera di decadenza il sollecito in blocco (solleciti-quote.bulk) raggiunge ancora Paolo (Radiato): SollecitoQuoteService::invia (app/Services/Economia/SollecitoQuoteService.php:33-49) e SollecitoQuoteController::index/inviaBulk non escludono i soci cessati; solleciti ricevuti: '.$solleciti.' invece di 2.'
        );

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 6. Rinnovo in anticipo a dicembre
    // ==================================================================

    public function test_vita_06_socio_che_rinnova_in_anticipo_a_dicembre_non_riceve_promemoria_ne_campagna_e_ha_due_tessere_consecutive(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $lucia = $this->creaSocio(['nome' => 'Lucia', 'cognome' => 'Anticipi', 'email' => 'lucia.anticipi@example.test', 'password_hash' => self::PASSWORD]);
        $tessera2026 = $this->creaTessera($lucia, 2026);
        $ritardatario = $this->creaSocio(['nome' => 'Marco', 'cognome' => 'Ritardi', 'email' => 'marco.ritardi@example.test']);
        $this->creaTessera($ritardatario, 2026);

        // 25 novembre: l'admin apre l'anno 2027
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->apriAnno(2027);
        $quota2027 = $this->quotaDi($lucia, 2027);

        // 26 novembre: Lucia fa il bonifico, la segreteria lo registra
        Carbon::setTestNow('2026-11-26 11:00:00');
        $this->pagaOffline($quota2027, 'bonifico', '2026-11-26');

        $this->assertTrue($quota2027->fresh()->isPagata());
        Notification::assertSentToTimes($lucia, PagamentoQuotaNotification::class, 1);
        Notification::assertSentToTimes($lucia, RicevutaQuotaNotification::class, 1);

        $tessera2027 = Tessera::allTenants()->where('socio_id', $lucia->id)->where('anno_validita', 2027)->first();
        $this->attesoCheck(
            $tessera2027 !== null,
            'Test 6: pagata in anticipo (offline) la quota 2027, Lucia non ha la tessera 2027: registerOfflinePayment non emette la tessera dell\'anno della quota (app/Services/Pagamenti/PaymentService.php:81), a differenza del pagamento online (riga 441).'
        );

        if ($tessera2027 === null) {
            // La segreteria la emette a mano dal pannello tessere
            $this->comeAdmin()->post(route('tessere.store'), [
                'socio_id' => $lucia->id,
                'numero_tessera' => '20270001',
                'anno_validita' => 2027,
                'data_emissione' => '2026-11-26',
                'stato' => Tessera::STATO_ATTIVA,
            ])->assertRedirect();
            $tessera2027 = Tessera::allTenants()->where('socio_id', $lucia->id)->where('anno_validita', 2027)->sole();
        }

        $this->assertSame('2027-12-31', $tessera2027->data_scadenza->toDateString());
        $this->assertSame('2026-11-26', $tessera2027->data_emissione->toDateString());

        // Dicembre: i promemoria di rinnovo saltano chi ha già rinnovato
        foreach (['2026-12-02', '2026-12-16', '2026-12-28'] as $giorno) {
            Carbon::setTestNow($giorno.' 07:00:00');
            $this->artisan('rinnovi:promemoria')->assertSuccessful();
        }

        Notification::assertNotSentTo($lucia, PromemoriaRinnovoNotification::class);
        $this->assertSame(0, PromemoriaInviato::allTenants()->where('socio_id', $lucia->id)->count());
        Notification::assertSentToTimes($ritardatario, PromemoriaRinnovoNotification::class, 3);

        // 10 dicembre: la campagna rinnovi del pannello lavora sull'anno solare
        // corrente (2026), quindi a dicembre non serve per il 2027
        Carbon::setTestNow('2026-12-10 10:00:00');
        $this->comeAdmin()->get(route('campagna-rinnovi.index'))
            ->assertOk()
            ->assertViewHas('anno', 2026)
            ->assertViewHas('contatori', fn (array $c) => $c['da_rinnovare'] === 0);

        // 2 gennaio 2027: l'admin lancia la campagna 2027 a tutti
        Carbon::setTestNow('2027-01-02 09:00:00');
        $this->comeAdmin()->get(route('campagna-rinnovi.index'))
            ->assertOk()
            ->assertViewHas('anno', 2027)
            ->assertSee('Ritardi')
            ->assertDontSee('Anticipi');

        $this->post(route('campagna-rinnovi.invia'), ['socio_ids' => [$lucia->id, $ritardatario->id]])
            ->assertRedirect(route('campagna-rinnovi.index'))
            ->assertSessionHas('success', 'Campagna rinnovi 2027: 1 email accodate. 1 soci saltati (senza email, opt-out o già rinnovati).');

        $this->assertSame(0, EmailQueue::allTenants()->where('socio_id', $lucia->id)->count());
        $this->assertSame(1, EmailQueue::allTenants()->where('socio_id', $ritardatario->id)->where('template_codice', 'campagna_rinnovi_2027')->count());
        $this->assertSame(1, Quota::allTenants()->where('socio_id', $lucia->id)->where('anno', 2027)->count());

        // Lucia entra nell'area soci: due tessere consecutive, la 2027 è quella valida
        $this->comeSocio($lucia)->get(route('area-soci.tessere'))
            ->assertOk()
            ->assertSee($tessera2026->numero_tessera)
            ->assertSee($tessera2027->numero_tessera);

        $this->assertTrue($tessera2027->fresh()->isValida());
        $this->assertTrue($tessera2026->fresh()->isScadutaEffettiva());

        // Lo storico tesseramento esportato le contiene entrambe
        $export = $this->comeAdmin()->get(route('soci.storico-tesseramento.export', $lucia));
        $export->assertOk();
        $contenuto = $this->contenutoExcel($export);
        $this->assertStringContainsString($tessera2026->numero_tessera, $contenuto);
        $this->assertStringContainsString($tessera2027->numero_tessera, $contenuto);

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 7. Dimissioni e ritorno dopo due anni
    // ==================================================================

    public function test_vita_07_socio_che_si_dimette_a_meta_anno_poi_torna_dopo_due_anni_con_un_nuovo_account_e_viene_riammesso_con_delibera(): void
    {
        Carbon::setTestNow('2025-01-20 10:00:00');
        $davide = $this->creaSocio([
            'nome' => 'Davide',
            'cognome' => 'Viaggiatori',
            'email' => 'davide.viaggiatori@example.test',
            'data_iscrizione' => '2025-01-20',
        ]);
        $tessera2025 = $this->creaTessera($davide, 2025);

        // Giugno 2025: Davide scrive alla segreteria, si trasferisce all'estero
        Carbon::setTestNow('2025-06-10 11:00:00');
        $this->comeAdmin()
            ->post(route('cessazioni.store'), [
                'socio_id' => $davide->id,
                'tipo' => Cessazione::TIPO_DIMISSIONI,
                'data' => '2025-06-10',
                'motivo' => 'trasferimento all\'estero',
            ])
            ->assertSessionHas('success');

        $this->assertSame(1, Cessazione::allTenants()->where('socio_id', $davide->id)->count());
        $this->assertSame(Socio::STATO_RADIATO, $davide->fresh()->stato);
        $this->assertSame(Tessera::STATO_ANNULLATA, $tessera2025->fresh()->stato);
        $this->get(route('libro-soci.index'))->assertOk()->assertSee('Viaggiatori')->assertSee('10/06/2025')->assertSee('trasferimento all&#039;estero', false);

        // Settembre 2027: Davide torna, si registra sul mini-sito con la stessa email
        Carbon::setTestNow('2027-09-01 18:00:00');
        $this->configuraStripe();
        $account = $this->registraEVerifica(['nome' => 'Davide', 'cognome' => 'Viaggiatori', 'email' => 'davide.viaggiatori@example.test']);
        $this->assertNull($account->socio_id, 'L\'account non si aggancia da solo al vecchio socio.');

        // Il modulo di iscrizione lo ferma: l'email appartiene già a un socio
        // (anche se radiato). Scelta voluta: il messaggio lo invita a
        // contattare l'associazione, che ha lo storico della persona.
        $this->from($this->urlTenant('iscriviti'))
            ->post($this->urlTenant('iscriviti'), ['telefono' => '3331234567', 'data_nascita' => '1985-05-05'])
            ->assertRedirect($this->urlTenant('iscriviti'))
            ->assertSessionHasErrors(['iscrizione' => "Risulta già un socio con questa email: se sei tu, contatta l'associazione oppure accedi al tuo account."]);
        $this->assertSame(0, RichiestaIscrizione::allTenants()->count());

        // La segreteria collega l'account al vecchio socio e, dopo la delibera
        // di riammissione n. 9/2027, lo riporta Attivo dalla scheda
        $this->comeAdmin()
            ->post(route('accounts.collega-socio', $account), ['socio_id' => $davide->id])
            ->assertRedirect(route('accounts.show', $account))
            ->assertSessionHas('success');
        $this->assertSame($davide->id, $account->fresh()->socio_id);

        $this->put(route('soci.update', $davide), $this->formSocio($davide->fresh(), [
            'stato' => Socio::STATO_ATTIVO,
            'note' => 'Riammesso con delibera n. 9/2027 del 01/09/2027',
        ]))->assertRedirect(route('soci.show', $davide))->assertSessionHas('success');

        $this->post(route('quote.store'), [
            'socio_id' => $davide->id,
            'anno' => 2027,
            'importo' => '25.00',
            'data_scadenza' => '2027-12-31',
            'tipo' => 'Quota Associativa',
        ])->assertRedirect(route('quote.index'));
        $quota2027 = $this->quotaDi($davide, 2027);
        $this->post(route('quote.payment-link', $quota2027))->assertSessionHas('payment_link');

        // Davide paga online la quota 2027
        $this->esci();
        $this->get(route('pagamento.show', ['token' => $quota2027->fresh()->payment_token]))->assertOk();
        $this->stripeConfermaPagamento($quota2027->fresh(), 'pi_davide_2027');

        // Un solo socio attivo con quell'email, con tessera e quota 2027
        $this->assertSame(1, Socio::allTenants()->where('email', 'davide.viaggiatori@example.test')->count());
        $davide->refresh();
        $this->assertSame(Socio::STATO_ATTIVO, $davide->stato);
        $this->assertTrue($quota2027->fresh()->isPagata());
        $tessera2027 = Tessera::allTenants()->where('socio_id', $davide->id)->where('anno_validita', 2027)->sole();
        $this->assertSame(Tessera::STATO_ATTIVA, $tessera2027->stato);
        $this->assertSame(Tessera::STATO_ANNULLATA, $tessera2025->fresh()->stato);

        // Riammissione "di segreteria": nessuna richiesta, nessuna email di
        // approvazione/benvenuto; il libro soci conserva la riga storica
        // (data di iscrizione 2025) con la cessazione 2025 ancora collegata
        Notification::assertNotSentTo($account, IscrizioneApprovataNotification::class);
        Notification::assertNotSentTo($davide, BenvenutoSocioNotification::class);
        Notification::assertSentToTimes($davide, PagamentoQuotaNotification::class, 1);
        $this->assertSame('2025-01-20', $davide->data_iscrizione->toDateString());

        $libro = $this->comeAdmin()->get(route('libro-soci.index'))->assertOk()->assertSee('Viaggiatori');
        $righe = collect($libro->viewData('righe'))->filter(fn (array $r) => $r['socio']->id === $davide->id);
        $this->assertCount(1, $righe, 'Il libro soci ha una sola riga per Davide.');
        $this->assertSame(Cessazione::TIPO_DIMISSIONI, $righe->first()['cessazione']?->tipo);

        // Nessun promemoria per la vecchia tessera annullata
        Carbon::setTestNow('2027-09-15 07:00:00');
        $this->artisan('rinnovi:promemoria')->assertSuccessful();
        Notification::assertNotSentTo($davide, PromemoriaRinnovoNotification::class);

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 8. Consigliera, poi tesoriera, fino alla fine del mandato
    // ==================================================================

    public function test_vita_08_socia_attiva_viene_eletta_consigliera_poi_tesoriera_e_al_termine_del_mandato_riceve_le_convocazioni_corrette(): void
    {
        Carbon::setTestNow('2026-04-20 21:00:00');
        $carla = $this->creaSocio(['nome' => 'Carla', 'cognome' => 'Consiglia', 'email' => 'carla.consiglia@example.test']);
        $this->creaTessera($carla, 2026);

        // L'assemblea elettiva nomina Carla consigliera per tre anni
        $this->comeAdmin()
            ->post(route('organi-sociali.store'), ['socio_id' => $carla->id, 'ruolo' => 'Consigliere', 'inizio' => '2026-04-20', 'fine' => '2029-04-19'])
            ->assertRedirect(route('organi-sociali.index'))
            ->assertSessionHas('success');

        $convoca = function (string $titolo, string $quando): Riunione {
            $this->comeAdmin()->post(route('riunioni.store'), [
                'titolo' => $titolo,
                'data_ora' => $quando,
                'luogo' => 'Sede sociale',
                'stato' => Riunione::STATO_PIANIFICATA,
                'odg' => [['punto' => 'Varie ed eventuali', 'descrizione' => '']],
            ])->assertSessionHasNoErrors();

            $riunione = Riunione::allTenants()->where('titolo', $titolo)->sole();
            $this->post(route('riunioni.convoca', $riunione))->assertRedirect(route('riunioni.show', $riunione))->assertSessionHas('success');

            return $riunione;
        };

        Carbon::setTestNow('2026-05-05 10:00:00');
        $direttivo2026 = $convoca('Direttivo di maggio 2026', '2026-05-12T21:00');

        // Gennaio 2027: Carla passa a tesoriera
        Carbon::setTestNow('2027-01-10 10:00:00');
        $this->apriAnno(2027);
        $this->pagaOffline($this->quotaDi($carla, 2027));

        $consigliere = Carica::allTenants()->where('socio_id', $carla->id)->sole();
        $this->comeAdmin()->put(route('organi-sociali.update', $consigliere), [
            'socio_id' => $carla->id, 'ruolo' => 'Consigliere', 'inizio' => '2026-04-20', 'fine' => '2027-01-09',
        ])->assertRedirect(route('organi-sociali.index'));
        $this->post(route('organi-sociali.store'), [
            'socio_id' => $carla->id, 'ruolo' => 'Tesoriere', 'inizio' => '2027-01-10', 'fine' => '2029-04-19',
        ])->assertSessionHas('success');

        Carbon::setTestNow('2027-02-01 10:00:00');
        $direttivo2027 = $convoca('Direttivo di febbraio 2027', '2027-02-08T21:00');

        foreach ([2028, 2029] as $anno) {
            Carbon::setTestNow($anno.'-01-10 10:00:00');
            $this->apriAnno($anno);
            $this->pagaOffline($this->quotaDi($carla, $anno));
        }

        // Febbraio 2029: la dashboard avvisa che il mandato sta per scadere
        Carbon::setTestNow('2029-02-01 09:00:00');
        $this->comeAdmin()->get(route('dashboard'))
            ->assertOk()
            ->assertSee('mandato in scadenza')
            ->assertSee('Carla Consiglia');

        // 20 aprile 2029: mandato concluso, Carla non viene più convocata
        Carbon::setTestNow('2029-04-20 10:00:00');
        $direttivo2029 = $convoca('Direttivo di aprile 2029', '2029-04-27T21:00');

        $cariche = Carica::allTenants()->where('socio_id', $carla->id)->orderBy('inizio')->get();
        $this->assertCount(2, $cariche);
        $this->assertSame('2027-01-09', $cariche[0]->fine->toDateString());
        $this->assertSame('Tesoriere', $cariche[1]->ruolo);

        $this->assertDatabaseHas('riunioni_partecipanti', ['riunione_id' => $direttivo2026->id, 'socio_id' => $carla->id, 'carica_ruolo' => 'Consigliere']);
        $this->assertDatabaseHas('riunioni_partecipanti', ['riunione_id' => $direttivo2027->id, 'socio_id' => $carla->id, 'carica_ruolo' => 'Tesoriere']);
        $this->assertDatabaseMissing('riunioni_partecipanti', ['riunione_id' => $direttivo2029->id, 'socio_id' => $carla->id]);

        Notification::assertSentToTimes($carla, ConvocazioneRiunioneNotification::class, 2);
        Notification::assertSentToTimes($this->admin, ConvocazioneRiunioneNotification::class, 3);

        $pagina = $this->comeAdmin()->get(route('organi-sociali.index'))->assertOk()->assertSee('Consiglia');
        $this->assertCount(0, $pagina->viewData('attive'));
        $this->assertEqualsCanonicalizing($cariche->pluck('id')->all(), collect($pagina->viewData('storiche')->items())->pluck('id')->all());

        foreach ([2027, 2028, 2029] as $anno) {
            $this->assertTrue($this->quotaDi($carla, $anno)->isPagata());
        }
        $this->get(route('libro-soci.index'))->assertOk()->assertSee('Consiglia')->assertSee(Socio::STATO_ATTIVO);

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 9. Evento a pagamento: prenotazione, promemoria, presenza, feedback
    // ==================================================================

    public function test_vita_09_socio_prenota_un_evento_a_pagamento_riceve_promemoria_fa_il_checkin_e_lascia_feedback_il_giorno_dopo(): void
    {
        Carbon::setTestNow('2026-05-01 10:00:00');
        $elena = $this->creaSocio(['nome' => 'Elena', 'cognome' => 'Cene', 'email' => 'elena.cene@example.test']);
        $this->creaTessera($elena, 2026);
        $account = $this->accountDelSocio($elena);

        $cena = $this->adminCreaEvento([
            'titolo' => 'Cena sociale',
            'data_evento' => '2026-05-20T20:00',
            'data_fine' => '2026-05-20T23:00',
            'prezzo' => '15,00',
            'capienza' => 30,
        ]);

        // Elena prenota due posti: in attesa di pagamento
        $prenotazione = $this->prenota($account, $cena, 2);
        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $prenotazione->stato);
        $this->assertSame('30.00', (string) $prenotazione->importo_totale);
        Notification::assertSentToTimes($account, InAttesaPagamentoNotification::class, 1);

        // Paga in sede: l'admin conferma il pagamento offline
        $this->comeAdmin()
            ->post(route('eventi.prenotazioni.conferma-pagamento', ['evento' => $cena->id, 'prenotazione' => $prenotazione->id]))
            ->assertSessionHas('success');
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $prenotazione->fresh()->stato);
        Notification::assertSentToTimes($account, ConfermataNotification::class, 1);

        // L'incasso offline della prenotazione finisce in prima nota
        $incasso = Movimento::allTenants()->where('origine_id', $prenotazione->id)->sole();
        $this->assertEqualsWithDelta(30.0, (float) $incasso->importo, 0.001);

        // Promemoria evento: lo scheduler delle 08:30 invia tra 24 e 48 ore prima
        foreach (['2026-05-17', '2026-05-18', '2026-05-19', '2026-05-20'] as $giorno) {
            Carbon::setTestNow($giorno.' 08:30:00');
            $this->artisan('prenotazioni:promemoria-eventi')->assertSuccessful();

            $attesi = $giorno >= '2026-05-19' ? 1 : 0;
            Notification::assertSentToTimes($account, PromemoriaEventoNotification::class, $attesi);
        }

        // La sera della cena l'operatore all'ingresso segna la presenza
        Carbon::setTestNow('2026-05-20 20:05:00');
        $operatore = $this->creaOperatore();
        $this->comeUtente($operatore)
            ->post(route('eventi.presenze.store', $cena), ['prenotazioni' => [$prenotazione->id]])
            ->assertRedirect(route('eventi.presenze.index', $cena))
            ->assertSessionHas('success', 'Presenze registrate: 1.');

        $prenotazione->refresh();
        $this->assertNotNull($prenotazione->checkin_at);
        $this->assertSame($operatore->id, $prenotazione->checkin_utente_id);
        $this->assertSame(2, (int) $prenotazione->posti);

        // La mattina dopo arriva la richiesta di feedback
        Carbon::setTestNow('2026-05-21 10:00:00');
        $this->artisan('eventi:invia-feedback')->assertSuccessful();

        $link = null;
        Notification::assertSentTo($account, FeedbackEventoNotification::class, function ($n, array $canali, Account $dest) use (&$link) {
            $link = $n->toMail($dest)->actionUrl;

            return true;
        });

        $this->esci();
        $this->get($link)->assertOk()->assertSee('Cena sociale');
        $feedback = FeedbackEvento::allTenants()->where('prenotazione_id', $prenotazione->id)->sole();
        $this->post(URL::signedRoute('feedback-eventi.show', ['token' => $feedback->token]) /* POST sull'URL firmata del link (audit run-1) */, [
            'voto' => 5,
            'commento' => 'Cena ottima e compagnia splendida.',
        ])->assertOk();

        Carbon::setTestNow('2026-05-22 10:00:00');
        $this->artisan('eventi:invia-feedback')->assertSuccessful();
        Notification::assertSentToTimes($account, FeedbackEventoNotification::class, 1);

        $feedback->refresh();
        $this->assertSame(5, $feedback->voto);
        $this->assertSame('Cena ottima e compagnia splendida.', $feedback->commento);
        $this->assertNotNull($feedback->inviato_at);
        $this->assertNotNull($feedback->compilato_at);

        $stats = $this->comeAdmin()->get(route('eventi.feedback.index', $cena))->assertOk()->viewData('stats');
        $this->assertSame(5.0, $stats['media']);
        $this->assertSame(1, $stats['totale_compilati']);

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 10. Evento riservato ai soci, lista d'attesa e promozione
    // ==================================================================

    public function test_vita_10_evento_riservato_ai_soci_il_simpatizzante_viene_respinto_e_quando_diventa_socio_entra_in_lista_d_attesa_e_viene_promosso(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        $this->configuraStripe();

        $assemblea = $this->adminCreaEvento([
            'titolo' => 'Assemblea conviviale',
            'data_evento' => '2026-09-25T20:00',
            'data_fine' => '2026-09-25T23:00',
            'capienza' => 1,
            'max_posti_per_prenotazione' => 1,
            'solo_soci' => '1',
            'lista_attesa_attiva' => '1',
        ]);

        // Tommaso, simpatizzante con account, prova a prenotare: respinto
        $tommaso = $this->registraEVerifica(['nome' => 'Tommaso', 'cognome' => 'Simpatia', 'email' => 'tommaso.simpatia@example.test']);
        $this->from($this->urlPubblica('eventi.show', ['evento' => $assemblea->id]))
            ->post($this->urlPubblica('eventi.prenota', ['evento' => $assemblea->id]), ['posti' => 1])
            ->assertSessionHasErrors('posti');
        $this->assertSame(0, Prenotazione::allTenants()->count());

        // Anna, socia, prenota l'unico posto: evento al completo
        $anna = $this->creaSocio(['nome' => 'Anna', 'cognome' => 'Socia', 'email' => 'anna.socia@example.test']);
        $this->creaTessera($anna, 2026);
        $accountAnna = $this->accountDelSocio($anna);
        $prenotazioneAnna = $this->prenota($accountAnna, $assemblea);
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $prenotazioneAnna->stato);
        Notification::assertSentToTimes($accountAnna, ConfermataNotification::class, 1);
        Notification::assertSentToTimes($this->admin, EventoEsauritoNotification::class, 1);

        // Tommaso decide di diventare socio
        $this->comeAccount($tommaso)
            ->post($this->urlTenant('iscriviti'), ['telefono' => '3335556677', 'data_nascita' => '1992-10-10'])
            ->assertSessionHas('success');
        $richiesta = RichiestaIscrizione::allTenants()->where('account_id', $tommaso->id)->sole();
        Notification::assertSentToTimes($this->admin, RichiestaIscrizioneRicevutaNotification::class, 1);

        $this->comeAdmin()->post(route('richieste-iscrizione.approva', $richiesta), ['genera_quota' => '1'])->assertSessionHas('success');
        $socioTommaso = Socio::allTenants()->findOrFail($richiesta->fresh()->socio_id);
        $this->esci();
        $this->stripeConfermaPagamento($this->quotaDi($socioTommaso, 2026), 'pi_tommaso');
        $this->assertSame(Socio::STATO_ATTIVO, $socioTommaso->fresh()->stato);

        // Ora può prenotare: finisce in lista d'attesa
        $prenotazioneTommaso = $this->prenota($tommaso, $assemblea);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $prenotazioneTommaso->stato);
        Notification::assertSentToTimes($tommaso, InListaAttesaNotification::class, 1);

        // Tre giorni prima Anna ha un imprevisto e annulla dalla sua area
        Carbon::setTestNow('2026-09-22 12:00:00');
        $this->comeAccount($accountAnna)
            ->post($this->urlPubblica('account.prenotazioni.annulla', ['prenotazione' => $prenotazioneAnna->id]))
            ->assertSessionHas('success');

        $this->assertSame(Prenotazione::STATO_ANNULLATA, $prenotazioneAnna->fresh()->stato);
        Notification::assertSentToTimes($accountAnna, AnnullataNotification::class, 1);

        // Il posto liberato va a Tommaso (promozione automatica); lo scheduler
        // delle scadenze, passando dopo, non cambia nulla
        $this->artisan('prenotazioni:gestisci-scadenze')->assertSuccessful();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $prenotazioneTommaso->fresh()->stato);
        Notification::assertSentToTimes($tommaso, PromozioneListaAttesaNotification::class, 1);
        $this->assertSame(1, Prenotazione::postiOccupati($assemblea->id));

        $this->comeAdmin()->get(route('eventi.prenotazioni.index', $assemblea))
            ->assertOk()
            ->assertSee('Simpatia')
            ->assertSee('Socia');
        Notification::assertSentToTimes($this->admin, EventoEsauritoNotification::class, 1);

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 11. Volontariato: candidatura, promemoria, ore, attestato
    // ==================================================================

    public function test_vita_11_socio_volontario_si_candida_a_un_turno_viene_confermato_riceve_il_promemoria_fa_le_ore_e_scarica_l_attestato(): void
    {
        Carbon::setTestNow('2026-03-01 10:00:00');
        $nadia = $this->creaSocio(['nome' => 'Nadia', 'cognome' => 'Volontari', 'email' => 'nadia.volontari@example.test', 'password_hash' => self::PASSWORD]);
        $this->creaTessera($nadia, 2026);

        $this->comeAdmin()->post(route('mansioni.store'), ['nome' => 'Banco alimentare'])->assertRedirect();
        $mansione = Mansione::allTenants()->where('nome', 'Banco alimentare')->sole();
        $this->post(route('turni.store'), [
            'titolo' => 'Banco alimentare di marzo',
            'data' => '2026-03-14',
            'ora_inizio' => '09:00',
            'ora_fine' => '13:00',
            'posti' => 2,
            'mansione_id' => $mansione->id,
            'stato' => Turno::STATO_APERTO,
            'auto_conferma' => '0',
        ])->assertRedirect();
        $turno = Turno::allTenants()->where('titolo', 'Banco alimentare di marzo')->sole();

        // Nadia aggiorna la disponibilità e si candida dall'area soci
        $this->comeSocio($nadia)
            ->patch(route('area-soci.volontariato.disponibilita'), [
                'volontario_attivo' => '1',
                'disponibilita' => ['sab' => ['mattina'], 'dom' => ['pomeriggio']],
            ])
            ->assertRedirect(route('area-soci.volontariato'))
            ->assertSessionHas('success');

        $this->get(route('area-soci.volontariato'))->assertOk()->assertSee('Banco alimentare di marzo');
        $this->post(route('area-soci.volontariato.candidati', $turno))
            ->assertRedirect(route('area-soci.volontariato'))
            ->assertSessionHas('success', 'Candidatura inviata: attendi la conferma.');

        $assegnazione = TurnoAssegnazione::allTenants()->where('turno_id', $turno->id)->where('socio_id', $nadia->id)->sole();
        $this->assertSame(TurnoAssegnazione::STATO_PROPOSTO, $assegnazione->stato);
        Notification::assertSentToTimes($this->admin, CandidaturaTurnoNotification::class, 1);

        $this->comeAdmin()->post(route('turni.conferma', [$turno, $assegnazione]))->assertSessionHas('success', 'Candidatura confermata.');
        $this->assertSame(TurnoAssegnazione::STATO_CONFERMATO, $assegnazione->fresh()->stato);

        // Promemoria del giorno prima (scheduler delle 17:00)
        foreach (['2026-03-12', '2026-03-13', '2026-03-13'] as $giorno) {
            Carbon::setTestNow($giorno.' 17:00:00');
            $this->artisan('turni:promemoria')->assertSuccessful();
        }
        Notification::assertSentToTimes($nadia, PromemoriaTurnoNotification::class, 1);
        $this->assertDatabaseHas('promemoria_inviati', ['socio_id' => $nadia->id, 'tipo' => PromemoriaInviato::TIPO_TURNO, 'riferimento_id' => $turno->id]);
        $this->assertSame(1, PromemoriaInviato::allTenants()->where('socio_id', $nadia->id)->where('tipo', PromemoriaInviato::TIPO_TURNO)->count());

        // Dopo il turno l'admin registra 4 ore
        Carbon::setTestNow('2026-03-14 13:30:00');
        $this->comeAdmin()
            ->post(route('turni.presenze', $turno), ['ore' => [$assegnazione->id => '4']])
            ->assertRedirect(route('turni.show', $turno))
            ->assertSessionHas('success');
        $this->assertEqualsWithDelta(4.0, (float) $assegnazione->fresh()->ore_effettive, 0.001);

        // A dicembre si candida a un secondo turno ma poi rinuncia
        Carbon::setTestNow('2026-12-01 10:00:00');
        $this->post(route('turni.store'), [
            'titolo' => 'Banco alimentare di Natale',
            'data' => '2026-12-19',
            'ora_inizio' => '09:00',
            'ora_fine' => '12:00',
            'posti' => 3,
            'mansione_id' => $mansione->id,
            'stato' => Turno::STATO_APERTO,
            'auto_conferma' => '0',
        ])->assertRedirect();
        $natale = Turno::allTenants()->where('titolo', 'Banco alimentare di Natale')->sole();

        $this->comeSocio($nadia)->post(route('area-soci.volontariato.candidati', $natale))->assertSessionHas('success');
        $seconda = TurnoAssegnazione::allTenants()->where('turno_id', $natale->id)->where('socio_id', $nadia->id)->sole();
        $this->post(route('area-soci.volontariato.rinuncia', $seconda), ['motivo' => 'Influenza'])
            ->assertRedirect(route('area-soci.volontariato'))
            ->assertSessionHas('success');

        $this->assertSame(TurnoAssegnazione::STATO_RINUNCIATO, $seconda->fresh()->stato);
        Notification::assertSentToTimes($this->admin, RinunciaTurnoNotification::class, 1);
        Notification::assertSentToTimes($this->admin, CandidaturaTurnoNotification::class, 2);

        // Registro ore e attestato annuale
        $registro = $this->comeAdmin()->get(route('registro-ore.index', ['anno' => 2026]))->assertOk()->assertSee('Nadia');
        $this->assertEqualsWithDelta(4.0, $registro->viewData('totale'), 0.001);

        $attestato = $this->get(route('registro-ore.attestato', [$nadia, 'anno' => 2026]));
        $attestato->assertOk();
        $this->assertSame('application/pdf', $attestato->headers->get('content-type'));
        $this->assertStringContainsString('nadia', strtolower((string) $attestato->headers->get('content-disposition')));

        $nadia->refresh();
        $this->assertTrue((bool) $nadia->volontario_attivo);
        $this->assertIsArray($nadia->disponibilita);
        $this->assertSame(['mattina'], $nadia->disponibilita['sab'] ?? null);

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 12. Opt-out dalle email
    // ==================================================================

    public function test_vita_12_socio_chiede_l_opt_out_dalle_email_e_da_quel_momento_non_riceve_newsletter_auguri_ne_convocazioni_ma_continua_a_ricevere_le_comunicazioni_contabili(): void
    {
        Carbon::setTestNow('2026-01-10 10:00:00');
        $this->configuraSmtp();
        $this->associazione->update(['auguri_automatici' => true]);

        $token = str_repeat('b1', 32);
        $bruno = $this->creaSocio([
            'nome' => 'Bruno', 'cognome' => 'Silenzi', 'email' => 'bruno.silenzi@example.test',
            'data_nascita' => '1970-01-20', 'email_opt_out' => false, 'email_opt_out_token' => $token,
        ]);
        $this->creaTessera($bruno, 2026);
        $amica = $this->creaSocio(['nome' => 'Ada', 'cognome' => 'Presente', 'email' => 'ada.presente@example.test']);
        $this->creaTessera($amica, 2026);

        // Prima dell'opt-out Bruno riceve newsletter e convocazione
        $prima = $this->inviaNewsletter('Notizie di gennaio');
        $this->assertSame(2, $prima->inviata_a);
        $this->assertSame(1, EmailQueue::allTenants()->where('socio_id', $bruno->id)->where('template_codice', 'newsletter')->count());
        $this->inviaConvocazioneAssemblea('Assemblea di febbraio');
        Notification::assertSentToTimes($bruno, ConvocazioneAssembleaNotification::class, 1);

        // 15 gennaio: clicca il link in fondo a un'email e conferma l'opt-out
        Carbon::setTestNow('2026-01-15 21:00:00');
        $this->esci();
        $this->get(route('area-soci.optout', ['token' => $token]))->assertOk();
        $this->assertFalse((bool) $bruno->fresh()->email_opt_out, 'La sola apertura del link non disiscrive.');
        $this->post(route('area-soci.optout.store', ['token' => $token]))->assertOk();
        $this->assertTrue((bool) $bruno->fresh()->email_opt_out);

        // 20 gennaio: compleanno, niente auguri
        Carbon::setTestNow('2026-01-20 08:15:00');
        $this->artisan('compleanni:auguri')->assertSuccessful();
        Notification::assertNotSentTo($bruno, AuguriCompleannoNotification::class);
        $this->assertSame(0, PromemoriaInviato::allTenants()->where('socio_id', $bruno->id)->where('tipo', 'compleanno')->count());

        // 1° febbraio: nuova newsletter e nuova convocazione, senza Bruno
        Carbon::setTestNow('2026-02-01 10:00:00');
        $seconda = $this->inviaNewsletter('Notizie di febbraio');
        $this->assertSame(1, $seconda->inviata_a);
        $this->assertSame(1, EmailQueue::allTenants()->where('socio_id', $bruno->id)->count());
        $this->inviaConvocazioneAssemblea('Assemblea di marzo');
        Notification::assertSentToTimes($bruno, ConvocazioneAssembleaNotification::class, 1);
        Notification::assertSentToTimes($amica, ConvocazioneAssembleaNotification::class, 2);

        // Dicembre: il promemoria di rinnovo resta solo nella sua area (canale database)
        Carbon::setTestNow('2026-12-02 07:00:00');
        $this->artisan('rinnovi:promemoria')->assertSuccessful();
        Notification::assertSentTo($bruno, PromemoriaRinnovoNotification::class, fn ($n, array $canali) => $canali === ['database']);
        Notification::assertSentTo($amica, PromemoriaRinnovoNotification::class, fn ($n, array $canali) => in_array('mail', $canali, true));

        // Gennaio 2027: apertura anno e sollecito. Il servizio solleciti salta
        // chi ha fatto opt-out (nemmeno la notifica nell'area): il tesoriere
        // riceve l'avviso e deve contattarlo in altro modo
        Carbon::setTestNow('2027-01-10 10:00:00');
        $this->apriAnno(2027);
        $quota = $this->quotaDi($bruno, 2027);
        $this->comeAdmin()->post(route('solleciti-quote.invia', $quota))
            ->assertSessionHas('error', 'Sollecito non inviato: quota già pagata oppure socio senza email (o con opt-out).');
        Notification::assertNotSentTo($bruno, SollecitoQuotaNotification::class);

        // Bruno paga in sede: conferma e ricevuta restano solo nell'area (niente email)
        $this->pagaOffline($quota);
        Notification::assertSentToTimes($bruno, PagamentoQuotaNotification::class, 1);
        Notification::assertSentTo($bruno, PagamentoQuotaNotification::class, fn ($n, array $canali) => $canali === ['database']);
        Notification::assertSentToTimes($bruno, RicevutaQuotaNotification::class, 1);
        Notification::assertSentTo($bruno, RicevutaQuotaNotification::class, fn ($n, array $canali) => $canali === ['database']);
        $this->assertSame(1, Ricevuta::allTenants()->where('quota_id', $quota->id)->count());
        $this->assertSame(1, EmailQueue::allTenants()->where('socio_id', $bruno->id)->count());

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 13. Tre richieste di modifica dati in un anno
    // ==================================================================

    public function test_vita_13_il_socio_aggiorna_i_dati_tre_volte_in_un_anno_una_viene_rifiutata_e_l_email_cambiata_vale_per_le_notifiche_successive(): void
    {
        Carbon::setTestNow('2026-02-01 10:00:00');
        $chiara = $this->creaSocio([
            'nome' => 'Chiara', 'cognome' => 'Traslochi', 'email' => 'chiara.traslochi@example.test',
            'telefono' => '3330000001', 'indirizzo' => 'Via Vecchia 1', 'cap' => '56100',
            'codice_fiscale' => 'TRSCHR85T50G702X', 'password_hash' => self::PASSWORD,
        ]);
        $this->creaTessera($chiara, 2026);
        $account = $this->accountDelSocio($chiara);
        $this->creaSocio(['nome' => 'Altro', 'cognome' => 'Socio', 'email' => 'occupata@example.test']);

        // Febbraio: dal mini-sito propone nuovo indirizzo e CAP, l'admin approva
        $this->comeAccount($account)
            ->post($this->urlTenant('account/modifica-dati'), ['indirizzo' => 'Via Nuova 22', 'cap' => '56121'])
            ->assertSessionHas('success');
        $prima = RichiestaModificaDati::withoutGlobalScopes()->where('socio_id', $chiara->id)->sole();
        $this->assertEquals(['indirizzo' => 'Via Nuova 22', 'cap' => '56121'], $prima->proposte);
        $this->comeAdmin()->post(route('richieste-modifica-dati.approva', $prima))->assertSessionHas('success');
        $this->assertSame('Via Nuova 22', $chiara->fresh()->indirizzo);
        $this->assertSame('56121', $chiara->fresh()->cap);

        // Maggio: dall'area soci prova un'email già di un altro socio → bloccata
        Carbon::setTestNow('2026-05-10 10:00:00');
        $this->comeSocio($chiara)
            ->from(route('area-soci.modifica-dati'))
            ->post(route('area-soci.modifica-dati.store'), ['email' => 'occupata@example.test'])
            ->assertSessionHasErrors('email');
        // ...poi chiede di correggere la data di nascita: l'admin rifiuta
        $this->post(route('area-soci.modifica-dati.store'), ['data_nascita' => '1985-12-11'])->assertSessionHas('success');
        $seconda = RichiestaModificaDati::withoutGlobalScopes()->where('socio_id', $chiara->id)->where('stato', RichiestaModificaDati::STATO_IN_ATTESA)->sole();
        $this->comeAdmin()->post(route('richieste-modifica-dati.rifiuta', $seconda), ['motivo' => 'Serve il documento di identità'])->assertSessionHas('success');

        // Settembre: nuova email e nuovo telefono; un secondo invio mentre è pendente viene respinto
        Carbon::setTestNow('2026-09-15 10:00:00');
        $this->comeSocio($chiara)
            ->post(route('area-soci.modifica-dati.store'), ['email' => 'chiara.nuova@example.test', 'telefono' => '3339998877'])
            ->assertSessionHas('success');
        $this->post(route('area-soci.modifica-dati.store'), ['citta' => 'Livorno'])->assertSessionHas('error');
        $terza = RichiestaModificaDati::withoutGlobalScopes()->where('socio_id', $chiara->id)->where('stato', RichiestaModificaDati::STATO_IN_ATTESA)->sole();
        $this->comeAdmin()->post(route('richieste-modifica-dati.approva', $terza))->assertSessionHas('success');

        $richieste = RichiestaModificaDati::withoutGlobalScopes()->where('socio_id', $chiara->id)->orderBy('created_at')->get();
        $this->assertSame(
            [RichiestaModificaDati::STATO_APPROVATA, RichiestaModificaDati::STATO_RIFIUTATA, RichiestaModificaDati::STATO_APPROVATA],
            $richieste->pluck('stato')->all()
        );
        $this->assertSame('Serve il documento di identità', $richieste[1]->proposte['_motivo_rifiuto'] ?? null);
        $this->assertSame($this->admin->id, $richieste[1]->gestita_da);

        $chiara->refresh();
        $this->assertSame('chiara.nuova@example.test', $chiara->email);
        $this->assertSame('3339998877', $chiara->telefono);
        $this->assertSame('TRSCHR85T50G702X', $chiara->codice_fiscale);
        // Lo storico registra le due approvazioni e il rifiuto
        $this->assertSame(3, StoricoAttivitaSocio::allTenants()->where('socio_id', $chiara->id)->where('tipo_attivita', 'Modifica Dati')->count());

        // L'account del mini-sito mantiene la sua email di accesso (non viene toccato)
        $this->assertSame('chiara.traslochi@example.test', $account->fresh()->email);

        // Dicembre: il promemoria parte verso l'indirizzo nuovo
        Carbon::setTestNow('2026-12-02 07:00:00');
        $this->artisan('rinnovi:promemoria')->assertSuccessful();
        Notification::assertSentTo($chiara, PromemoriaRinnovoNotification::class, function ($n, array $canali, Socio $dest) {
            return in_array('mail', $canali, true)
                && $dest->email === 'chiara.nuova@example.test'
                && $dest->routeNotificationFor('mail') === 'chiara.nuova@example.test';
        });

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 14. Il socio ambasciatore e il codice "porta un amico"
    // ==================================================================

    public function test_vita_14_socio_ambasciatore_porta_due_amici_con_il_codice_referral_e_a_fine_anno_compare_in_cima_alla_classifica(): void
    {
        Carbon::setTestNow('2026-03-01 10:00:00');
        $this->configuraStripe();
        $fabio = $this->creaSocio(['nome' => 'Fabio', 'cognome' => 'Ambasciatori', 'email' => 'fabio.ambasciatori@example.test']);
        $this->creaTessera($fabio, 2026);
        $codice = $fabio->fresh()->codice_referral;
        $this->assertNotEmpty($codice, 'Il codice referral nasce con il socio.');

        // Tre persone compilano il modulo pubblico: due con il codice di Fabio, una con un codice sbagliato
        $amici = [
            ['Gaia', 'Amica', 'gaia.amica@example.test', $codice],
            ['Piero', 'Amico', 'piero.amico@example.test', $codice],
            ['Ugo', 'Sbaglio', 'ugo.sbaglio@example.test', 'CODICE-INESISTENTE'],
        ];
        foreach ($amici as [$nome, $cognome, $email, $ref]) {
            $this->esci();
            $this->get($this->urlTenant('iscriviti').'?ref='.urlencode($ref))->assertOk();
            $this->inviaEConfermaIscrizioneOspite($this->urlTenant('iscriviti'), [
                'nome' => $nome, 'cognome' => $cognome, 'email' => $email, 'privacy' => '1',
                'telefono' => '3331234567', 'data_nascita' => '1990-01-15', 'referral_codice' => $ref,
            ])->assertRedirect($this->urlTenant())->assertSessionHas('success');
        }

        $richieste = RichiestaIscrizione::allTenants()->get()->keyBy(fn (RichiestaIscrizione $r) => Socio::allTenants()->find($r->socio_id)?->email);
        $this->assertSame($codice, $richieste['gaia.amica@example.test']->referral_codice);
        $this->assertSame($codice, $richieste['piero.amico@example.test']->referral_codice);
        $this->assertNull($richieste['ugo.sbaglio@example.test']->referral_codice, 'Un codice inesistente non viene salvato.');

        Notification::assertSentOnDemandTimes(ConfermaRichiestaInviataNotification::class, 3);
        Notification::assertSentToTimes($this->admin, RichiestaIscrizioneRicevutaNotification::class, 3);

        // L'admin approva Gaia (pagherà online) e Piero (paga in sede, tessera subito); rifiuta Ugo
        $this->comeAdmin()->post(route('richieste-iscrizione.approva', $richieste['gaia.amica@example.test']), ['genera_quota' => '1'])->assertSessionHas('success');
        $this->post(route('richieste-iscrizione.approva', $richieste['piero.amico@example.test']), ['genera_quota' => '1', 'genera_tessera' => '1'])->assertSessionHas('success');
        $this->post(route('richieste-iscrizione.rifiuta', $richieste['ugo.sbaglio@example.test']), ['motivo' => 'Documento non leggibile'])->assertSessionHas('success');

        $gaia = Socio::allTenants()->where('email', 'gaia.amica@example.test')->sole();
        $piero = Socio::allTenants()->where('email', 'piero.amico@example.test')->sole();
        $this->esci();
        $this->stripeConfermaPagamento($this->quotaDi($gaia, 2026), 'pi_gaia');
        $this->pagaOffline($this->quotaDi($piero, 2026));

        // Gli amici si sono iscritti da ospiti: hanno ricevuto per email la
        // conferma di ricezione, e devono ricevere allo stesso modo l'esito
        $this->assertSame('Documento non leggibile', $richieste['ugo.sbaglio@example.test']->fresh()->motivo_rifiuto);
        $this->assertSame(1, $this->notificheVersoEmail('ugo.sbaglio@example.test', ConfermaRichiestaInviataNotification::class));
        $this->attesoCheck(
            $this->notificheVersoEmail('gaia.amica@example.test', IscrizioneApprovataNotification::class) === 1
                && $this->notificheVersoEmail('ugo.sbaglio@example.test', IscrizioneRifiutataNotification::class) === 1,
            'Test 14: chi si iscrive da ospite dal modulo pubblico riceve per email la conferma di ricezione, ma non riceve mai l\'esito: né l\'approvazione con il link di pagamento della quota né il rifiuto con il motivo (ApprovazioneIscrizioneService::notificaAccount, app/Services/Iscrizioni/ApprovazioneIscrizioneService.php:294, notifica solo l\'Account e per gli ospiti esce senza inviare nulla).'
        );

        foreach ([$gaia, $piero] as $nuovo) {
            $nuovo->refresh();
            $this->assertSame(Socio::STATO_ATTIVO, $nuovo->stato);
            $this->assertSame(1, Tessera::allTenants()->where('socio_id', $nuovo->id)->where('anno_validita', 2026)->where('stato', Tessera::STATO_ATTIVA)->count());
            $this->assertNotEmpty($nuovo->codice_referral);
            $this->assertNotSame($codice, $nuovo->codice_referral);
        }

        // Dicembre: la classifica degli ambasciatori
        Carbon::setTestNow('2026-12-15 10:00:00');
        $pagina = $this->comeAdmin()->get(route('ambasciatori.index'))->assertOk()->assertSee('Ambasciatori');
        $primo = $pagina->viewData('ambasciatori')->first();
        $this->assertSame($fabio->id, $primo->id);
        $this->assertSame(2, $primo->iscrizioni_approvate);
        $this->assertSame(2, $primo->iscrizioni_totali);
        $this->assertSame(2, $pagina->viewData('totaleApprovate'));

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 15. Documenti in scadenza
    // ==================================================================

    public function test_vita_15_socio_con_documento_in_scadenza_riceve_il_digest_carica_il_nuovo_documento_e_il_promemoria_non_si_ripete(): void
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-03-15 10:00:00');
        $ilaria = $this->creaSocio(['nome' => 'Ilaria', 'cognome' => 'Atletica', 'email' => 'ilaria.atletica@example.test']);
        $this->creaTessera($ilaria, 2026);

        $carica = function (string $nomeFile, string $tipo, string $scadenza) use ($ilaria): DocumentoSocio {
            $this->comeAdmin()
                ->post(route('soci.documenti.store', $ilaria), [
                    'file' => UploadedFile::fake()->create($nomeFile, 120, 'application/pdf'),
                    'tipo_documento' => $tipo,
                    'data_scadenza' => $scadenza,
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect();

            return DocumentoSocio::allTenants()->where('nome_file', $nomeFile)->sole();
        };
        $certificato = $carica('certificato-medico-2025.pdf', 'Certificato medico', '2026-04-25');
        $fidal = $carica('tessera-fidal-2025.pdf', 'Tessera federale', '2026-04-28');

        // 20 marzo: le scadenze sono ancora lontane (oltre 30 giorni)
        Carbon::setTestNow('2026-03-20 07:45:00');
        $this->artisan('documenti:promemoria-scadenze')->assertSuccessful();
        Notification::assertNotSentTo($ilaria, DocumentiInScadenzaNotification::class);

        // 1° aprile: un unico digest con entrambi i documenti, al socio e agli admin
        Carbon::setTestNow('2026-04-01 07:45:00');
        $this->artisan('documenti:promemoria-scadenze')->assertSuccessful();
        Notification::assertSentTo($ilaria, DocumentiInScadenzaNotification::class, fn ($n) => $n->documenti->pluck('id')->sort()->values()->all() === collect([$certificato->id, $fidal->id])->sort()->values()->all());
        Notification::assertSentToTimes($this->admin, DocumentiInScadenzaNotification::class, 1);
        // Il tracking è un digest per socio (una riga), non una per documento
        $this->assertSame(1, PromemoriaInviato::allTenants()->where('socio_id', $ilaria->id)->where('tipo', PromemoriaInviato::TIPO_DOCUMENTO_SCADENZA)->count());

        Carbon::setTestNow('2026-04-02 07:45:00');
        $this->artisan('documenti:promemoria-scadenze')->assertSuccessful();
        Notification::assertSentToTimes($ilaria, DocumentiInScadenzaNotification::class, 1);
        $this->assertSame(1, PromemoriaInviato::allTenants()->where('socio_id', $ilaria->id)->where('tipo', PromemoriaInviato::TIPO_DOCUMENTO_SCADENZA)->count());

        // 20 aprile: Ilaria porta il nuovo certificato, la segreteria elimina il vecchio
        Carbon::setTestNow('2026-04-20 10:00:00');
        $nuovo = $carica('certificato-medico-2026.pdf', 'Certificato medico', '2027-04-20');
        $this->comeAdmin()->delete(route('soci.documenti.destroy', [$ilaria, $certificato]))->assertRedirect();
        $this->assertSoftDeleted('documenti_socio', ['id' => $certificato->id]);
        $this->assertDatabaseHas('documenti_socio', ['id' => $nuovo->id, 'deleted_at' => null]);

        $scheda = $this->get(route('soci.show', $ilaria))
            ->assertOk()
            ->assertSee('certificato-medico-2026.pdf');
        // Nella tabella documenti della scheda il vecchio certificato non c'è più
        // (resta citato solo nello storico attività del caricamento)
        $this->assertEqualsCanonicalizing(
            ['certificato-medico-2026.pdf', 'tessera-fidal-2025.pdf'],
            $scheda->viewData('socio')->documenti->pluck('nome_file')->all()
        );

        // Marzo 2027: il nuovo certificato entra in finestra, la vecchia tessera federale è già stata notificata
        Carbon::setTestNow('2027-03-25 07:45:00');
        $this->artisan('documenti:promemoria-scadenze')->assertSuccessful();
        Notification::assertSentToTimes($ilaria, DocumentiInScadenzaNotification::class, 2);
        Notification::assertSentTo($ilaria, DocumentiInScadenzaNotification::class, fn ($n) => $n->documenti->pluck('id')->all() === [$nuovo->id]);

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 16. Il decesso di una socia
    // ==================================================================

    public function test_vita_16_una_socia_muore_la_segreteria_registra_il_decesso_e_nessuna_automazione_le_scrive_piu(): void
    {
        Carbon::setTestNow('2026-05-02 10:00:00');
        $this->associazione->update(['auguri_automatici' => true]);
        $this->configuraSmtp();
        foreach (['scadenza_tessera' => 'La tua tessera {NUMERO_TESSERA} scade il {DATA_SCADENZA}', 'scadenza_quota' => 'Quota {ANNO} in scadenza'] as $codiceTpl => $oggetto) {
            EmailTemplate::create([
                'associazione_id' => $this->associazione->id,
                'codice' => $codiceTpl,
                'nome' => $codiceTpl,
                'oggetto' => $oggetto,
                'corpo_html' => '<p>Ciao {NOME}, <a href="{LINK_RINNOVO}">rinnova qui</a>.</p>',
                'attivo' => true,
            ]);
        }

        $rosa = $this->creaSocio(['nome' => 'Rosa', 'cognome' => 'Memoria', 'email' => 'rosa.memoria@example.test', 'data_nascita' => '1940-05-10']);
        $tesseraRosa = $this->creaTessera($rosa, 2026);
        $this->creaQuota($rosa, 2026, 25.0, ['data_pagamento' => '2026-01-10', 'stato' => Quota::STATO_PAGATA]);
        $lino = $this->creaSocio(['nome' => 'Lino', 'cognome' => 'Vivo', 'email' => 'lino.vivo@example.test']);
        $this->creaTessera($lino, 2026);

        // 4 maggio: la famiglia comunica il decesso del 1° maggio
        Carbon::setTestNow('2026-05-04 10:00:00');
        $this->comeAdmin()->post(route('cessazioni.store'), [
            'socio_id' => $rosa->id,
            'tipo' => Cessazione::TIPO_DECESSO,
            'data' => '2026-05-01',
        ])->assertSessionHas('success');

        $this->assertSame(Socio::STATO_DECEDUTO, $rosa->fresh()->stato);
        $this->assertDatabaseHas('cessazioni', ['socio_id' => $rosa->id, 'tipo' => Cessazione::TIPO_DECESSO]);
        $this->assertSame(Tessera::STATO_ANNULLATA, $tesseraRosa->fresh()->stato);

        // Tutte le automazioni dell'anno passano senza toccarla
        Carbon::setTestNow('2026-05-10 08:15:00');
        $this->artisan('compleanni:auguri')->assertSuccessful();

        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->apriAnno(2027);
        $this->assertSame(0, Quota::allTenants()->where('socio_id', $rosa->id)->where('anno', 2027)->count());

        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->comeAdmin()->post(route('campagna-rinnovi.invia'), ['socio_ids' => [$rosa->id, $lino->id]])
            ->assertSessionHas('success', 'Campagna rinnovi 2026: 0 email accodate. 2 soci saltati (senza email, opt-out o già rinnovati).');

        Carbon::setTestNow('2026-12-02 07:00:00');
        $this->artisan('rinnovi:promemoria')->assertSuccessful();
        Notification::assertSentToTimes($lino, PromemoriaRinnovoNotification::class, 1);

        Carbon::setTestNow('2026-12-05 08:00:00');
        $this->artisan('emails:send-reminders')->assertSuccessful();
        $promemoriaLino = EmailQueue::allTenants()->where('socio_id', $lino->id)->where('template_codice', 'scadenza_tessera')->sole();

        // Il link "rinnova" dell'email di scadenza deve aprire una pagina valida
        $this->assertSame(1, preg_match('/href="([^"]+)"[^>]*>rinnova qui/', $promemoriaLino->body_html, $m));
        $linkRinnovo = html_entity_decode($m[1]);
        $this->esci();
        $esito = $this->get($linkRinnovo)->status();
        $this->attesoCheck(
            $esito === 200,
            'Test 16: il link {LINK_RINNOVO} dell\'email "scadenza_tessera" accodata da emails:send-reminders ('.$linkRinnovo.') risponde '.$esito.': il comando costruisce url(\'/preiscrizione\').\'?assoc=…&token=…\' (app/Console/Commands/EmailsSendReminders.php:102) mentre la rotta è /preiscrizione/{associazione}?token=… (routes/modules/struttura.php:32).'
        );

        Carbon::setTestNow('2027-01-20 10:00:00');
        $nonPagate = Quota::allTenants()->whereNull('data_pagamento')->pluck('id')->all();
        $this->comeAdmin()->post(route('solleciti-quote.bulk'), ['quota_ids' => $nonPagate])->assertSessionHas('success');
        Notification::assertSentToTimes($lino, SollecitoQuotaNotification::class, 1);

        // Nessuna comunicazione per Rosa
        Notification::assertNotSentTo($rosa, AuguriCompleannoNotification::class);
        Notification::assertNotSentTo($rosa, PromemoriaRinnovoNotification::class);
        Notification::assertNotSentTo($rosa, SollecitoQuotaNotification::class);
        $this->assertSame(0, EmailQueue::allTenants()->where('socio_id', $rosa->id)->count());

        // Il registro conserva la memoria
        $this->comeAdmin()->get(route('libro-soci.index'))->assertOk()->assertSee('Memoria')->assertSee('01/05/2026')->assertSee('Decesso');
        $this->get(route('libro-soci.excel'))->assertOk();
        $this->get(route('libro-soci.pdf'))->assertOk();
        $this->get(route('soci.index', ['status' => Socio::STATO_DECEDUTO, 'tessera_stato' => 'all']))
            ->assertOk()->assertSee('Memoria')->assertDontSee('Vivo');
        $this->get(route('dashboard'))->assertOk()->assertViewHas('stats', fn (array $s) => $s['soci_attivi'] === 1);

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 17. Espulsione dopo un procedimento
    // ==================================================================

    public function test_vita_17_socio_espulso_dal_consiglio_dopo_un_procedimento_viene_bloccato_al_checkin_e_la_sua_area_soci_si_chiude(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');
        $gino = $this->creaSocio(['nome' => 'Gino', 'cognome' => 'Litigi', 'email' => 'gino.litigi@example.test', 'password_hash' => self::PASSWORD]);
        $tessera = $this->creaTessera($gino, 2026);
        $account = $this->accountDelSocio($gino);

        // La segreteria annota la segnalazione e si dà un promemoria
        $this->comeAdmin()
            ->post(route('soci.note.store', $gino), ['testo' => 'Segnalazione riservata: comportamento offensivo alla festa di maggio.', 'fissata' => '1'])
            ->assertRedirect();
        $this->post(route('attivita-admin.store'), [
            'titolo' => 'Convocare Gino',
            'descrizione' => 'Audizione davanti al consiglio',
            'scadenza' => '2026-06-10',
            'assegnatario_id' => $this->admin->id,
            'collegamento' => 'socio',
            'socio_id' => $gino->id,
        ])->assertSessionHas('success', 'Attività creata.');

        Carbon::setTestNow('2026-06-10 07:15:00');
        $this->artisan('attivita:promemoria')->assertSuccessful();
        $this->artisan('attivita:promemoria')->assertSuccessful();
        Notification::assertSentToTimes($this->admin, PromemoriaAttivitaNotification::class, 1);

        // 20 giugno: il consiglio delibera l'espulsione
        Carbon::setTestNow('2026-06-20 21:00:00');
        $this->comeAdmin()->post(route('cessazioni.store'), [
            'socio_id' => $gino->id,
            'tipo' => Cessazione::TIPO_ESPULSIONE,
            'data' => '2026-06-20',
            'motivo' => 'Gravi violazioni dello statuto',
            'delibera_numero' => '5/2026',
            'delibera_data' => '2026-06-20',
        ])->assertSessionHas('success');

        $this->assertSame(Socio::STATO_RADIATO, $gino->fresh()->stato);
        $this->assertSame(Tessera::STATO_ANNULLATA, $tessera->fresh()->stato);
        $this->assertDatabaseHas('cessazioni', ['socio_id' => $gino->id, 'tipo' => Cessazione::TIPO_ESPULSIONE, 'delibera_numero' => '5/2026']);
        $storico = StoricoAttivitaSocio::allTenants()->where('socio_id', $gino->id)->where('tipo_attivita', 'Cessazione')->sole();
        $this->assertStringContainsString('Cessazione per espulsione', $storico->descrizione);

        // Il consiglio apre un sondaggio riservato ai soci
        $this->post(route('sondaggi.store'), [
            'domanda' => 'Dove organizziamo la festa d\'autunno?',
            'opzioni_testo' => "Parco\nSede",
            'aperto_dal' => now()->subHour()->format('Y-m-d\TH:i'),
            'aperto_al' => now()->addWeeks(2)->format('Y-m-d\TH:i'),
            'solo_soci' => '1',
        ])->assertRedirect();
        $sondaggio = Sondaggio::allTenants()->sole();
        $evento = $this->adminCreaEvento(['titolo' => 'Cena dei soci', 'solo_soci' => '1', 'data_evento' => '2026-07-10T20:00', 'data_fine' => '2026-07-10T23:00']);

        // 25 giugno: Gino prova a usare i servizi riservati
        Carbon::setTestNow('2026-06-25 18:00:00');
        $this->esci();
        // Un socio cessato (Radiato) non è più socio: le credenziali dell'area
        // soci non valgono più (LoginSocioRequest e EnsureSocioValido guardano
        // lo stato, non solo cestino e password)
        $this->from(route('area-soci.login'))
            ->post(route('area-soci.login.attempt'), ['email' => 'gino.litigi@example.test', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');
        $this->assertGuest('socio');

        // Anche una sessione rimasta aperta da prima dell'espulsione si chiude
        $this->actingAs($gino->fresh(), 'socio')->get(route('area-soci.dashboard'))->assertRedirect(route('area-soci.login'));
        $this->assertGuest('socio');
        $this->assertSame(0, RispostaSondaggio::allTenants()->where('sondaggio_id', $sondaggio->id)->count());

        // Al banco della festa la sua tessera viene rifiutata
        $this->comeAdmin()
            ->postJson(route('tessere.checkin.store'), ['evento_id' => $evento->id, 'numero_tessera' => $tessera->numero_tessera])
            ->assertStatus(400)
            ->assertJson(['success' => false, 'code' => 'socio_not_active']);
        $this->assertSame(0, EventoPartecipante::allTenants()->where('socio_id', $gino->id)->count());

        // Dal mini-sito non può prenotare l'evento riservato ai soci
        $this->comeAccount($account)
            ->post($this->urlPubblica('eventi.prenota', ['evento' => $evento->id]), ['posti' => 1])
            ->assertSessionHasErrors('posti');
        $this->assertSame(0, Prenotazione::allTenants()->where('account_id', $account->id)->count());

        $this->comeAdmin()->get(route('soci.show', $gino))
            ->assertOk()
            ->assertSee('comportamento offensivo alla festa di maggio')
            ->assertSee(Socio::STATO_RADIATO);

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 18. Dieci anni di un socio fedele
    // ==================================================================

    public function test_vita_18_dieci_anni_di_vita_di_un_socio_fedele_rinnovi_consecutivi_numerazione_tessere_e_storico_tesseramento_completo(): void
    {
        Carbon::setTestNow('2017-01-10 10:00:00');
        $this->configuraStripe();
        $mario = $this->iscriviInSegreteria(['nome' => 'Mario', 'cognome' => 'Fedeli', 'email' => 'mario.fedeli@example.test', 'data_nascita' => '1960-08-08']);
        $this->assertSame('2017-01-10', $mario->data_iscrizione->toDateString());

        $tesseraOfflineSegnalata = false;

        for ($anno = 2018; $anno <= 2026; $anno++) {
            Carbon::setTestNow($anno.'-01-10 10:00:00');
            $this->apriAnno($anno);
            $quota = $this->quotaDi($mario, $anno);

            if ($anno === 2021) {
                // Quell'anno se ne dimentica: sollecito a febbraio, paga a marzo
                Carbon::setTestNow('2021-02-15 10:00:00');
                $this->comeAdmin()->post(route('solleciti-quote.invia', $quota))->assertSessionHas('success', 'Sollecito inviato.');
                Carbon::setTestNow('2021-03-10 10:00:00');
                $this->pagaOffline($quota, 'contanti');
            } elseif ($anno === 2023) {
                // Nel 2023 paga online dal link
                $this->comeAdmin()->post(route('quote.payment-link', $quota))->assertSessionHas('payment_link');
                $this->esci();
                $this->stripeConfermaPagamento($quota->fresh(), 'pi_mario_2023');
            } else {
                $this->pagaOffline($quota, 'bonifico');
            }

            $this->assertTrue($quota->fresh()->isPagata(), "Quota {$anno} pagata");

            if (Tessera::allTenants()->where('socio_id', $mario->id)->where('anno_validita', $anno)->doesntExist()) {
                if (! $tesseraOfflineSegnalata) {
                    $this->attesoCheck(false, "Test 18: pagata offline la quota {$anno}, nessuna tessera {$anno} viene emessa (PaymentService::registerOfflinePayment, app/Services/Pagamenti/PaymentService.php:81): la segreteria deve emetterla a mano ogni anno.");
                    $tesseraOfflineSegnalata = true;
                }
                $this->comeAdmin()->post(route('tessere.emetti', $mario))->assertRedirect();
            }

            if ($anno === 2024) {
                // A giugno Mario perde la tessera: la segreteria la annulla e ne emette un duplicato
                Carbon::setTestNow('2024-06-05 10:00:00');
                $originale = Tessera::allTenants()->where('socio_id', $mario->id)->where('anno_validita', 2024)->sole();
                $this->comeAdmin()->put(route('tessere.update', $originale), [
                    'socio_id' => $mario->id,
                    'numero_tessera' => $originale->numero_tessera,
                    'anno_validita' => 2024,
                    'data_emissione' => $originale->data_emissione->toDateString(),
                    'stato' => Tessera::STATO_ANNULLATA,
                ])->assertRedirect();
                $this->assertSame(Tessera::STATO_ANNULLATA, $originale->fresh()->stato);

                // Comportamento documentato (spec tessere §6, emissione on-demand): "Emetti
                // tessera" restituisce l'ultima tessera dell'anno anche se annullata, non
                // crea duplicati. Il duplicato lo registra la segreteria a mano.
                $this->post(route('tessere.emetti', $mario))->assertRedirect(route('tessere.pdf', $originale));
                $this->assertSame(1, Tessera::allTenants()->where('socio_id', $mario->id)->where('anno_validita', 2024)->count());

                $this->post(route('tessere.store'), [
                    'socio_id' => $mario->id,
                    'numero_tessera' => '20240099',
                    'anno_validita' => 2024,
                    'data_emissione' => '2024-06-05',
                    'stato' => Tessera::STATO_ATTIVA,
                ])->assertRedirect();
                $this->assertTrue(Tessera::allTenants()->where('socio_id', $mario->id)->where('anno_validita', 2024)->where('stato', Tessera::STATO_ATTIVA)->exists());
            }
        }

        Carbon::setTestNow('2026-12-20 10:00:00');
        $tessere = Tessera::allTenants()->where('socio_id', $mario->id)->orderBy('anno_validita')->orderBy('created_at')->get();
        $this->assertCount(11, $tessere);
        $this->assertCount(11, $tessere->pluck('numero_tessera')->unique());
        $this->assertSame(range(2017, 2026), $tessere->pluck('anno_validita')->map(fn ($a) => (int) $a)->unique()->values()->all());
        $this->assertSame(1, $tessere->where('anno_validita', 2026)->where('stato', Tessera::STATO_ATTIVA)->count());
        $this->assertSame([Tessera::STATO_ANNULLATA, Tessera::STATO_ATTIVA], $tessere->where('anno_validita', 2024)->pluck('stato')->values()->all());

        $formatoErrato = $tessere->reject(fn (Tessera $t) => preg_match('/^'.$t->anno_validita.'\d{4}$/', $t->numero_tessera) === 1)->pluck('numero_tessera')->all();
        $this->attesoCheck(
            $formatoErrato === [],
            'Test 18: numerazione tessere non unificata: '.implode(', ', $formatoErrato).' non rispetta il formato {anno}NNNN di TesseraIssuer. La tessera emessa alla conferma del pagamento in segreteria usa NumeroProgressivoService::prossimoNumeroTessera (app/Services/Soci/NumeroProgressivoService.php:33, max+1 a 4 cifre), come PaymentService::createTesseraForSocio (app/Services/Pagamenti/PaymentService.php:515).'
        );

        $quote = Quota::allTenants()->where('socio_id', $mario->id)->get();
        $this->assertCount(10, $quote);
        $this->assertTrue($quote->every(fn (Quota $q) => $q->isPagata()));
        $this->assertCount(10, $quote->pluck('anno')->unique());

        // Ricevute: una per ogni quota pagata (2017–2026), ciascuna la n. 1 del suo
        // anno; anche la quota 2017 confermata allo sportello ha la sua ricevuta
        // e la conferma di pagamento, come ogni altro incasso offline
        $ricevute = Ricevuta::allTenants()->whereIn('quota_id', $quote->pluck('id'))->get();
        $this->assertCount(10, $ricevute);
        $this->assertTrue($ricevute->every(fn (Ricevuta $r) => (int) $r->numero === 1));
        $this->assertSame(range(2017, 2026), $ricevute->pluck('anno')->map(fn ($a) => (int) $a)->sort()->values()->all());

        Notification::assertSentToTimes($mario, SollecitoQuotaNotification::class, 1);
        Notification::assertSentToTimes($mario, PagamentoQuotaNotification::class, 10);

        $export = $this->comeAdmin()->get(route('soci.storico-tesseramento.export', $mario));
        $export->assertOk();
        $testo = $this->contenutoExcel($export);
        foreach ($tessere as $t) {
            $this->assertStringContainsString($t->numero_tessera, $testo);
        }

        $stampa = $this->get(route('tessere.stampa-massiva', ['anno' => 2026]));
        $stampa->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $stampa->headers->get('Content-Type'));

        $libro = $this->get(route('libro-soci.index'))->assertOk();
        $riga = collect($libro->viewData('righe'))->first(fn (array $r) => $r['socio']->id === $mario->id);
        $this->assertSame(1, $riga['progressivo']);
        $this->assertSame(2017, $riga['anno']);
        $this->assertSame('2017-01-10', $mario->fresh()->data_iscrizione->toDateString());

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 19. Sospeso per morosità, poi riattivato
    // ==================================================================

    public function test_vita_19_socio_sospeso_per_morosita_non_riceve_promemoria_di_rinnovo_ma_dopo_il_pagamento_torna_attivo_e_rientra_in_tutte_le_liste(): void
    {
        Carbon::setTestNow('2026-07-01 10:00:00');
        $this->configuraSmtp();
        $sergio = $this->creaSocio(['nome' => 'Sergio', 'cognome' => 'Morosi', 'email' => 'sergio.morosi@example.test']);
        $this->creaTessera($sergio, 2026);
        $quota2026 = $this->creaQuota($sergio, 2026);
        $amica = $this->creaSocio(['nome' => 'Ada', 'cognome' => 'Regolare', 'email' => 'ada.regolare@example.test']);
        $this->creaTessera($amica, 2026);

        // Il consiglio sospende Sergio per morosità
        $this->comeAdmin()->put(route('soci.update', $sergio), $this->formSocio($sergio, ['stato' => Socio::STATO_SOSPESO]))
            ->assertRedirect(route('soci.show', $sergio));
        $this->assertSame(Socio::STATO_SOSPESO, $sergio->fresh()->stato);

        Carbon::setTestNow('2026-08-01 10:00:00');
        $this->inviaNewsletter('Notizie d\'agosto');
        $this->inviaConvocazioneAssemblea('Assemblea di settembre');
        $this->assertSame(0, EmailQueue::allTenants()->where('socio_id', $sergio->id)->count());
        Notification::assertNotSentTo($sergio, ConvocazioneAssembleaNotification::class);

        Carbon::setTestNow('2026-12-02 07:00:00');
        $this->artisan('rinnovi:promemoria')->assertSuccessful();
        Notification::assertNotSentTo($sergio, PromemoriaRinnovoNotification::class);
        $this->assertSame(0, PromemoriaInviato::allTenants()->where('socio_id', $sergio->id)->count());

        // 10 dicembre: salda la quota in segreteria e viene riattivato
        Carbon::setTestNow('2026-12-10 10:00:00');
        $this->pagaOffline($quota2026);
        Notification::assertSentToTimes($sergio, PagamentoQuotaNotification::class, 1);
        $this->comeAdmin()->put(route('soci.update', $sergio), $this->formSocio($sergio->fresh(), ['stato' => Socio::STATO_ATTIVO]))
            ->assertRedirect(route('soci.show', $sergio));

        // 16 dicembre: un solo promemoria (step 15); lo step 30 saltato viene solo tracciato
        Carbon::setTestNow('2026-12-16 07:00:00');
        $this->artisan('rinnovi:promemoria')->assertSuccessful();
        $this->assertSame([15], $this->stepPromemoriaRicevuti($sergio));
        $this->assertSame([15, 30], PromemoriaInviato::allTenants()->where('socio_id', $sergio->id)->orderBy('step')->pluck('step')->map(fn ($s) => (int) $s)->all());

        Carbon::setTestNow('2026-12-20 10:00:00');
        $this->inviaConvocazioneAssemblea('Assemblea di gennaio');
        Notification::assertSentToTimes($sergio, ConvocazioneAssembleaNotification::class, 1);
        $this->assertTrue($quota2026->fresh()->isPagata());

        // Il registro modifiche conserva la sequenza degli stati
        $stati = Activity::query()
            ->where('subject_type', $sergio->getMorphClass())
            ->where('subject_id', $sergio->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($a) => data_get($a->attribute_changes, 'attributes.stato'))
            ->filter()
            ->values()
            ->all();
        $this->assertSame([Socio::STATO_ATTIVO, Socio::STATO_SOSPESO, Socio::STATO_ATTIVO], $stati);
        $this->assertSame(2, StoricoAttivitaSocio::allTenants()->where('socio_id', $sergio->id)->where('tipo_attivita', 'Modifica Anagrafica')->count());

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 20. Una coppia con una sola email di famiglia
    // ==================================================================

    public function test_vita_20_una_coppia_di_soci_con_la_stessa_email_di_famiglia_la_segreteria_gestisce_pagamenti_e_notifiche_senza_confusione(): void
    {
        Carbon::setTestNow('2026-02-01 10:00:00');

        // Luca si iscrive con l'email di famiglia
        $this->comeAdmin()->post(route('soci.store'), [
            'nome' => 'Luca', 'cognome' => 'Bianchi', 'email' => 'famiglia@example.test',
            'telefono' => '3331000001', 'data_nascita' => '1970-04-04', 'data_iscrizione' => '2026-02-01', 'stato' => Socio::STATO_ATTIVO, 'privacy_consenso' => '1',
        ])->assertSessionHasNoErrors();
        $luca = Socio::allTenants()->where('email', 'famiglia@example.test')->sole();
        $this->assertSame(1, Tessera::allTenants()->where('socio_id', $luca->id)->where('anno_validita', 2026)->count());

        // Marta non ha una sua email: la segreteria non può registrarla né
        // senza email né con quella del marito (email obbligatoria e unica)
        $marta = ['nome' => 'Marta', 'cognome' => 'Bianchi', 'telefono' => '3331000002', 'data_nascita' => '1972-09-09', 'data_iscrizione' => '2026-02-01', 'stato' => Socio::STATO_ATTIVO];
        $this->from(route('soci.create'))->post(route('soci.store'), $marta)->assertSessionHasErrors('email');
        $this->from(route('soci.create'))->post(route('soci.store'), $marta + ['email' => 'famiglia@example.test'])->assertSessionHasErrors('email');
        $this->assertSame(1, Socio::allTenants()->count());

        // Marta arriva allora dal vecchio registro cartaceo, con l'email vuota
        $marta = $this->creaSocio(['nome' => 'Marta', 'cognome' => 'Bianchi', 'email' => '', 'telefono' => '3331000002']);
        $this->creaTessera($marta, 2026);

        // Dicembre: apertura 2027 e promemoria di rinnovo
        Carbon::setTestNow('2026-12-01 10:00:00');
        $this->apriAnno(2027);
        Carbon::setTestNow('2026-12-02 07:00:00');
        $this->artisan('rinnovi:promemoria')->assertSuccessful();
        Notification::assertSentTo($luca, PromemoriaRinnovoNotification::class, fn ($n, array $canali) => in_array('mail', $canali, true));
        Notification::assertSentTo($marta, PromemoriaRinnovoNotification::class, fn ($n, array $canali) => $canali === ['database']);

        // Gennaio 2027: la campagna rinnovi raggiunge solo chi ha l'email
        Carbon::setTestNow('2027-01-03 10:00:00');
        $this->comeAdmin()->get(route('campagna-rinnovi.index'))
            ->assertOk()
            ->assertViewHas('contatori', fn (array $c) => $c['da_rinnovare'] === 2 && $c['inviate'] === 0);
        $this->post(route('campagna-rinnovi.invia'), ['socio_ids' => [$luca->id, $marta->id]])
            ->assertSessionHas('success', 'Campagna rinnovi 2027: 1 email accodate. 1 soci saltati (senza email, opt-out o già rinnovati).');
        $this->assertSame(1, EmailQueue::allTenants()->where('socio_id', $luca->id)->count());
        $this->assertSame(0, EmailQueue::allTenants()->where('socio_id', $marta->id)->count());
        $this->get(route('campagna-rinnovi.index'))->assertViewHas('contatori', fn (array $c) => $c['inviate'] === 1);

        // Metà gennaio: solleciti in blocco
        Carbon::setTestNow('2027-01-15 10:00:00');
        $quoteIds = [$this->quotaDi($luca, 2027)->id, $this->quotaDi($marta, 2027)->id];
        $this->post(route('solleciti-quote.bulk'), ['quota_ids' => $quoteIds])
            ->assertSessionHas('success', 'Solleciti inviati: 1 — saltati: 1 (senza email o con opt-out).');
        Notification::assertSentToTimes($luca, SollecitoQuotaNotification::class, 1);
        Notification::assertNotSentTo($marta, SollecitoQuotaNotification::class);
        $this->assertSame(1, PromemoriaInviato::allTenants()->where('tipo', SollecitoQuoteService::TIPO_SOLLECITO_QUOTA)->count());

        // Marta si fa un indirizzo suo e lo comunica in segreteria
        $this->put(route('soci.update', $marta), $this->formSocio($marta->fresh(), ['email' => 'marta.bianchi@example.test']))
            ->assertRedirect(route('soci.show', $marta));
        $this->assertSame('marta.bianchi@example.test', $marta->fresh()->email);

        $this->post(route('solleciti-quote.bulk'), ['quota_ids' => $quoteIds])
            ->assertSessionHas('success', 'Solleciti inviati: 2.');
        Notification::assertSentToTimes($marta, SollecitoQuotaNotification::class, 1);
        // Il servizio non deduplica i solleciti manuali nello stesso giorno:
        // Luca riceve il secondo sollecito perché è la segreteria a chiederlo
        Notification::assertSentToTimes($luca, SollecitoQuotaNotification::class, 2);

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 21. Cancella l'account del mini-sito ma resta socio
    // ==================================================================

    public function test_vita_21_socio_cancella_l_account_pubblico_ma_resta_socio_la_segreteria_continua_la_vita_associativa_e_lui_riaccede_dall_area_soci(): void
    {
        Storage::fake('personal-data-exports');
        Carbon::setTestNow('2026-03-01 10:00:00');
        $this->configuraStripe();
        $pietro = $this->creaSocio(['nome' => 'Pietro', 'cognome' => 'Riservati', 'email' => 'pietro.riservati@example.test']);
        $tessera = $this->creaTessera($pietro, 2026);
        $this->creaQuota($pietro, 2026, 25.0, ['data_pagamento' => '2026-01-10', 'stato' => Quota::STATO_PAGATA]);
        $account = $this->accountDelSocio($pietro);

        $concerto = $this->adminCreaEvento(['titolo' => 'Concerto di primavera', 'data_evento' => '2026-04-10T21:00', 'data_fine' => '2026-04-10T23:00']);
        $prenotazione = $this->prenota($account, $concerto);
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $prenotazione->stato);

        // 15 marzo: chiede l'archivio dei suoi dati e poi cancella l'account
        Carbon::setTestNow('2026-03-15 20:00:00');
        $this->comeAccount($account)->get($this->urlTenant('account/privacy'))->assertOk();
        $this->post($this->urlPubblica('account.privacy.archivio'))->assertSessionHas('success');
        Notification::assertSentToTimes($account, DatiPersonaliPronti::class, 1);

        $this->post($this->urlTenant('account/privacy/cancella'), ['password' => self::PASSWORD])
            ->assertRedirect($this->urlTenant())
            ->assertSessionHas('success');

        $anonimo = Account::allTenants()->withTrashed()->findOrFail($account->id);
        $this->assertTrue($anonimo->trashed());
        $this->assertNull($anonimo->socio_id);
        $this->assertStringEndsWith('@anonimo.invalid', $anonimo->email);
        $pietro->refresh();
        $this->assertSame(Socio::STATO_ATTIVO, $pietro->stato);
        $this->assertSame('pietro.riservati@example.test', $pietro->email);

        // La prenotazione resta confermata, legata all'account anonimizzato
        $prenotazione->refresh();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $prenotazione->stato);
        $this->assertSame($account->id, $prenotazione->account_id);

        // 10 aprile: al concerto entra con la tessera
        Carbon::setTestNow('2026-04-10 20:45:00');
        $this->comeAdmin()
            ->postJson(route('tessere.checkin.store'), ['evento_id' => $concerto->id, 'tessera_id' => $tessera->id])
            ->assertOk()
            ->assertJson(['success' => true, 'already_checked_in' => false]);
        $this->assertSame(1, EventoPartecipante::allTenants()->where('evento_id', $concerto->id)->where('socio_id', $pietro->id)->count());

        Carbon::setTestNow('2026-12-02 07:00:00');
        $this->artisan('rinnovi:promemoria')->assertSuccessful();
        Notification::assertSentToTimes($pietro, PromemoriaRinnovoNotification::class, 1);

        // Gennaio 2027: rientra dall'area soci, imposta la password e rinnova
        Carbon::setTestNow('2027-01-05 19:00:00');
        $this->primoAccessoAreaSoci($pietro);
        $this->get(route('area-soci.quote'))->assertOk()->assertSee('2026');
        $this->get(route('area-soci.tessere'))->assertOk()->assertSee($tessera->numero_tessera);

        $risposta = $this->post(route('area-soci.rinnova'));
        $quota2027 = $this->quotaDi($pietro, 2027);
        $risposta->assertRedirect(route('pagamento.show', ['token' => $quota2027->payment_token]));
        $this->get(route('area-soci.quote'))->assertOk()->assertSee('2027');
        $this->assertFalse($quota2027->isPagata());

        $this->esci();
        $this->stripeConfermaPagamento($quota2027, 'pi_pietro_2027');

        $tessera2027 = Tessera::allTenants()->where('socio_id', $pietro->id)->where('anno_validita', 2027)->sole();
        $this->assertSame(Tessera::STATO_ATTIVA, $tessera2027->stato);
        Notification::assertSentToTimes($pietro, PagamentoQuotaNotification::class, 1);
        Notification::assertSentToTimes($pietro, RicevutaQuotaNotification::class, 1);
        Notification::assertNotSentTo($pietro, BenvenutoSocioNotification::class);
        Mail::assertSent(ImpostaPasswordSocioMail::class, 1);

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 22. Trasferimento in un'altra sede
    // ==================================================================

    public function test_vita_22_socio_trasferito_in_un_altra_sede_dell_associazione_l_operatore_della_vecchia_sede_non_lo_vede_piu_e_le_quote_seguono_la_sede_nuova(): void
    {
        Carbon::setTestNow('2026-02-01 10:00:00');
        $this->comeAdmin()->post(route('sedi.save'), ['nome' => 'Sede Nord', 'citta' => 'Milano', 'provincia' => 'MI', 'cap' => '20100'])->assertSessionHas('success');
        $this->post(route('sedi.save'), ['nome' => 'Sede Sud', 'citta' => 'Napoli', 'provincia' => 'NA', 'cap' => '80100'])->assertSessionHas('success');
        $nord = Sede::allTenants()->where('nome', 'Sede Nord')->sole();
        $sud = Sede::allTenants()->where('nome', 'Sede Sud')->sole();

        $ugo = $this->creaOperatore('Ugo', 'Nordico');
        $this->put(route('ruoli.update', $ugo), ['ruoli' => ['operatore'], 'sedi' => [$nord->id]])->assertSessionHas('success');

        $viola = $this->creaSocio(['nome' => 'Viola', 'cognome' => 'Migranti', 'email' => 'viola.migranti@example.test', 'sede_id' => $nord->id]);
        $this->creaTessera($viola, 2026);

        // Ugo, operatore di Nord, la segue e le scrive una nota
        $this->comeUtente($ugo)->get(route('soci.index', ['tessera_stato' => 'all']))->assertOk()->assertSee('Migranti');
        $this->post(route('soci.note.store', $viola), ['testo' => 'Ha chiesto informazioni sul trasferimento a Napoli.'])->assertRedirect();
        $this->assertDatabaseHas('note_socio', ['socio_id' => $viola->id, 'autore_id' => $ugo->id]);

        // Giugno: l'admin la sposta alla sede Sud
        Carbon::setTestNow('2026-06-01 10:00:00');
        $this->comeAdmin()->put(route('soci.update', $viola), $this->formSocio($viola, ['sede_id' => $sud->id]))
            ->assertRedirect(route('soci.show', $viola));
        $this->assertSame($sud->id, $viola->fresh()->sede_id);
        $cambioSede = Activity::query()
            ->where('subject_id', $viola->id)
            ->get()
            ->first(fn ($a) => data_get($a->attribute_changes, 'attributes.sede_id') === $sud->id);
        $this->assertNotNull($cambioSede, 'Il registro modifiche traccia il cambio di sede.');
        $this->assertSame($nord->id, data_get($cambioSede->attribute_changes, 'old.sede_id'));

        // Ugo non la vede più e non può modificarla
        $this->comeUtente($ugo)->get(route('soci.index', ['tessera_stato' => 'all']))->assertOk()->assertDontSee('Migranti');
        $this->get(route('soci.show', $viola))->assertNotFound();
        $this->put(route('soci.update', $viola), $this->formSocio($viola->fresh(), ['sede_id' => $nord->id]))->assertNotFound();
        $this->assertSame($sud->id, $viola->fresh()->sede_id);

        // Dicembre: apertura 2027; a gennaio Viola paga in segreteria
        Carbon::setTestNow('2026-12-01 10:00:00');
        $this->apriAnno(2027);
        Carbon::setTestNow('2027-01-08 10:00:00');
        $quota = $this->quotaDi($viola, 2027);
        $this->pagaOffline($quota);
        if (Tessera::allTenants()->where('socio_id', $viola->id)->where('anno_validita', 2027)->doesntExist()) {
            // stesso limite del pagamento offline visto nei percorsi 4, 6 e 18: tessera stampata dal pannello
            $this->comeAdmin()->post(route('tessere.emetti', $viola))->assertRedirect();
        }
        $this->assertSame(1, Tessera::allTenants()->where('socio_id', $viola->id)->where('anno_validita', 2027)->count());

        // Il movimento di prima nota prende la sede del socio (Sud)
        $movimento = Movimento::allTenants()->where('origine_id', $quota->id)->sole();
        $this->assertSame($sud->id, $movimento->sede_id);

        $this->comeAdmin()->get(route('report-economico.index', ['dal' => '2027-01-01', 'al' => '2027-01-31']))
            ->assertOk()
            ->assertViewHas('totaleEntrate', 25.0);
        $this->comeUtente($ugo)->get(route('report-economico.index', ['dal' => '2027-01-01', 'al' => '2027-01-31']))
            ->assertOk()
            ->assertViewHas('totaleEntrate', 0.0);
        $this->get(route('quote.index', ['year' => 2027]))->assertOk()->assertDontSee('Migranti');

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 23. Il pre-iscritto che non paga mai
    // ==================================================================

    public function test_vita_23_socio_in_attesa_di_pagamento_che_non_paga_mai_il_link_scade_la_segreteria_lo_sollecita_e_dopo_sei_mesi_lo_rifiuta(): void
    {
        Carbon::setTestNow('2026-01-20 18:00:00');
        $this->configuraStripe();

        $this->inviaEConfermaIscrizioneOspite($this->urlTenant('iscriviti'), [
            'nome' => 'Omar', 'cognome' => 'Indecisi', 'email' => 'omar.indecisi@example.test', 'privacy' => '1',
            'telefono' => '3337778899', 'data_nascita' => '1995-02-02',
        ])->assertSessionHas('success');
        $richiesta = RichiestaIscrizione::allTenants()->sole();

        $this->comeAdmin()->post(route('richieste-iscrizione.approva', $richiesta), ['genera_quota' => '1', 'genera_tessera' => '0'])->assertSessionHas('success');
        $omar = Socio::allTenants()->findOrFail($richiesta->fresh()->socio_id);
        $quota = $this->quotaDi($omar, 2026);
        $primoToken = $quota->payment_token;
        $this->assertNotNull($primoToken);
        // Con l'approvazione online il socio diventa subito Attivo (scelta di
        // ApprovazioneIscrizioneService): la quota resta "Da Pagare"
        $this->assertSame(Socio::STATO_ATTIVO, $omar->stato);

        // 30 gennaio: il link (72 ore) è scaduto
        Carbon::setTestNow('2026-01-30 12:00:00');
        $this->esci();
        $this->get(route('pagamento.show', ['token' => $primoToken]))->assertOk()->assertViewHas('tokenExpired', true);

        // 5 febbraio: nuovo link e sollecito
        Carbon::setTestNow('2026-02-05 10:00:00');
        $this->comeAdmin()->post(route('quote.payment-link', $quota))->assertSessionHas('payment_link');
        $quota->refresh();
        $this->assertNotSame($primoToken, $quota->payment_token);
        $this->assertTrue($quota->payment_token_expires->isFuture());
        $this->post(route('solleciti-quote.invia', $quota))->assertSessionHas('success', 'Sollecito inviato.');
        Notification::assertSentTo($omar, SollecitoQuotaNotification::class, fn ($n) => $n->paymentUrl === route('pagamento.show', ['token' => $quota->payment_token]));
        Notification::assertSentToTimes($omar, SollecitoQuotaNotification::class, 1);

        Carbon::setTestNow('2026-02-06 21:00:00');
        $this->esci();
        $this->get(route('pagamento.show', ['token' => $quota->payment_token]))->assertOk()->assertViewHas('tokenExpired', false)->assertViewHas('alreadyPaid', false);

        // 20 luglio: dopo sei mesi la segreteria vuole chiudere la pratica
        Carbon::setTestNow('2026-07-20 10:00:00');
        $this->assertFalse($quota->fresh()->isPagata());
        $this->comeAdmin()->from(route('soci.show', $omar))->post(route('soci.rifiuta', $omar))
            ->assertSessionHas('error', 'Socio non trovato o stato non valido.');
        $this->assertNotNull(Socio::allTenants()->find($omar->id), 'Un socio già Attivo non si può più "rifiutare".');

        // Strada percorribile: decadenza per mancato versamento
        $this->post(route('cessazioni.store'), [
            'socio_id' => $omar->id, 'tipo' => Cessazione::TIPO_DECADENZA, 'data' => '2026-07-20',
            'motivo' => 'Quota di ammissione mai versata',
        ])->assertSessionHas('success');
        $this->assertSame(Socio::STATO_RADIATO, $omar->fresh()->stato);
        $this->assertSame(0, Tessera::allTenants()->where('socio_id', $omar->id)->count());
        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $richiesta->fresh()->stato);
        $this->assertSame($omar->id, $richiesta->fresh()->socio_id);

        Carbon::setTestNow('2026-12-02 07:00:00');
        $this->artisan('rinnovi:promemoria')->assertSuccessful();
        $this->apriAnno(2027);
        $this->assertSame(0, Quota::allTenants()->where('socio_id', $omar->id)->where('anno', 2027)->count());
        Notification::assertNotSentTo($omar, PromemoriaRinnovoNotification::class);

        // Ammesso con l'approvazione, resta nel libro soci con la sua decadenza
        $this->comeAdmin()->get(route('libro-soci.index'))->assertOk()->assertSee('Indecisi')->assertSee('20/07/2026');

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 24. Una stagione di eventi nella scheda "vita sociale"
    // ==================================================================

    public function test_vita_24_il_socio_partecipa_a_tutta_la_stagione_di_eventi_e_la_sua_scheda_vita_sociale_riassume_presenze_feedback_e_volontariato(): void
    {
        Carbon::setTestNow('2026-01-10 10:00:00');
        $beatrice = $this->creaSocio(['nome' => 'Beatrice', 'cognome' => 'Presenze', 'email' => 'beatrice.presenze@example.test']);
        $tessera = $this->creaTessera($beatrice, 2026);
        $account = $this->accountDelSocio($beatrice);

        // Serie di quattro incontri settimanali a febbraio, più la festa di giugno
        $this->comeAdmin()->post(route('eventi-ricorrenti.store'), $this->datiEvento([
            'titolo' => 'Incontro di lettura',
            'data_evento' => '2026-02-05T21:00',
            'data_fine' => '2026-02-05T23:00',
            'frequenza' => 'settimanale',
            'fino_al' => '2026-02-26',
        ]))->assertSessionHasNoErrors()->assertSessionHas('success', 'Serie ricorrente creata: 4 eventi in calendario.');
        $incontri = Evento::allTenants()->where('titolo', 'Incontro di lettura')->orderBy('data_evento')->get();
        $this->assertCount(4, $incontri);
        $festa = $this->adminCreaEvento(['titolo' => 'Festa d\'estate', 'data_evento' => '2026-06-20T21:00', 'data_fine' => '2026-06-20T23:59']);

        $prenotazioni = [];
        foreach ([...$incontri, $festa] as $evento) {
            $prenotazioni[$evento->id] = $this->prenota($account, $evento);
        }
        Notification::assertSentToTimes($account, ConfermataNotification::class, 5);

        // Promemoria eventi: il giorno prima del primo incontro e della festa
        foreach (['2026-02-04', '2026-06-19'] as $giorno) {
            Carbon::setTestNow($giorno.' 08:30:00');
            $this->artisan('prenotazioni:promemoria-eventi')->assertSuccessful();
        }
        Notification::assertSentToTimes($account, PromemoriaEventoNotification::class, 2);

        // Tre incontri su quattro: presenza registrata la sera, feedback il giorno dopo
        foreach ($incontri as $i => $incontro) {
            Carbon::setTestNow($incontro->data_evento->copy()->addMinutes(10));
            if ($i < 3) {
                $this->comeAdmin()->post(route('eventi.presenze.store', $incontro), ['prenotazioni' => [$prenotazioni[$incontro->id]->id]])
                    ->assertSessionHas('success', 'Presenze registrate: 1.');
            }
            Carbon::setTestNow($incontro->data_evento->copy()->addDay()->setTime(10, 0));
            $this->artisan('eventi:invia-feedback')->assertSuccessful();
        }

        // Compila i primi due feedback
        $feedback = FeedbackEvento::allTenants()->orderBy('inviato_at')->get();
        $this->assertCount(3, $feedback);
        foreach ($feedback->take(2) as $f) {
            $this->esci();
            $this->post(URL::signedRoute('feedback-eventi.show', ['token' => $f->token]) /* POST sull'URL firmata del link (audit run-1) */, ['voto' => 4, 'commento' => 'Bella serata'])->assertOk();
        }

        // Giugno: turno volontario alla festa, poi check-in con la tessera
        Carbon::setTestNow('2026-06-10 10:00:00');
        $this->comeAdmin()->post(route('turni.store'), [
            'titolo' => 'Allestimento festa', 'data' => '2026-06-20', 'ora_inizio' => '15:00', 'ora_fine' => '19:00', 'posti' => 4,
            'stato' => Turno::STATO_APERTO, 'evento_id' => $festa->id,
        ])->assertRedirect();
        $turno = Turno::allTenants()->where('titolo', 'Allestimento festa')->sole();
        $this->post(route('turni.assegna', $turno), ['socio_id' => $beatrice->id, 'conferma' => '1'])->assertSessionHas('success');
        $assegnazione = TurnoAssegnazione::allTenants()->where('turno_id', $turno->id)->sole();

        Carbon::setTestNow('2026-06-20 21:05:00');
        $this->comeAdmin()->postJson(route('tessere.checkin.store'), ['evento_id' => $festa->id, 'tessera_id' => $tessera->id])
            ->assertOk()->assertJson(['success' => true]);
        $this->post(route('turni.presenze', $turno), ['ore' => [$assegnazione->id => '4']])->assertSessionHas('success');

        Carbon::setTestNow('2026-06-21 10:00:00');
        $this->artisan('eventi:invia-feedback')->assertSuccessful();

        // Bilancio dell'anno
        Carbon::setTestNow('2026-12-31 12:00:00');
        $tutte = Prenotazione::allTenants()->where('account_id', $account->id)->get();
        $this->assertCount(5, $tutte);
        $this->assertTrue($tutte->every(fn ($p) => $p->stato === Prenotazione::STATO_CONFERMATA));
        $this->assertSame(1, EventoPartecipante::allTenants()->where('socio_id', $beatrice->id)->where('evento_id', $festa->id)->count());
        $this->assertSame(2, FeedbackEvento::allTenants()->whereNotNull('compilato_at')->count());

        $stats = app(VitaSocialeService::class)->statisticheSocio($beatrice->fresh());
        $this->assertSame(5, $stats['prenotazioni_totali']);
        $this->comeAdmin()->get(route('soci.show', $beatrice))->assertOk()->assertSee('Presenze');
        $metriche = $this->get(route('statistiche-annuali.index', ['anno' => 2026]))->assertOk()->viewData('dati');
        $this->assertEqualsWithDelta(4.0, (float) data_get($metriche, 'corrente.ore_volontariato', data_get($metriche, 'ore_volontariato')), 0.001);

        // Beatrice era alla festa (check-in con la tessera, con prenotazione
        // confermata): deve risultare presente come agli altri tre incontri
        $festaPrenotazione = $prenotazioni[$festa->id]->fresh();
        $partecipazioniAnno = (int) data_get($metriche, 'corrente.partecipazioni', data_get($metriche, 'partecipazioni'));
        $this->attesoCheck(
            $festaPrenotazione->checkin_at !== null && $stats['prenotazioni_con_checkin'] === 4,
            'Test 24: il check-in con la tessera (tessere.checkin.store) di una socia che ha una prenotazione confermata per l\'evento non segna la prenotazione (checkin_at resta null, app/Http/Controllers/Tessere/CheckinController.php:138 scrive solo eventi_partecipanti): la scheda vita sociale conta '.$stats['prenotazioni_con_checkin'].' presenze su 4 (tasso '.$stats['tasso_partecipazione'].'%) e la festa non riceve la richiesta di feedback.'
        );
        $this->attesoCheck(
            $partecipazioniAnno >= 4,
            'Test 24: le statistiche annuali contano '.$partecipazioniAnno.' partecipazioni per il 2026 pur con 4 presenze registrate: StatisticheAnnualiService::metriche (app/Services/Relazioni/StatisticheAnnualiService.php:84) conta solo eventi_partecipanti e ignora le presenze sulle prenotazioni (checkin_at).'
        );
        // Quattro presenze, quattro richieste di feedback: tre incontri e la festa
        Notification::assertSentToTimes($account, FeedbackEventoNotification::class, 4);

        $this->assertNessunaDifformita();
    }

    // ==================================================================
    // 25. Un intero anno sociale, con il conteggio esatto di ogni email
    // ==================================================================

    public function test_vita_25_un_intero_anno_sociale_di_un_nuovo_socio_dal_primo_click_di_gennaio_al_rinnovo_di_gennaio_successivo_con_il_conteggio_esatto_di_ogni_email(): void
    {
        $giorno = function (string $data, string $comando, array $parametri = []): void {
            // Ogni comando dello scheduler gira due giorni di fila: il secondo non deve duplicare nulla
            Carbon::setTestNow($data);
            $this->artisan($comando, $parametri)->assertSuccessful();
            Carbon::setTestNow(Carbon::parse($data)->addDay());
            $this->artisan($comando, $parametri)->assertSuccessful();
        };

        Carbon::setTestNow('2026-01-08 20:00:00');
        $this->configuraStripe();
        $this->associazione->update(['auguri_automatici' => true]);

        // Gennaio: registrazione, iscrizione, approvazione con delibera, pagamento online
        $account = $this->registraEVerifica(['nome' => 'Alessia', 'cognome' => 'Annuali', 'email' => 'alessia.annuali@example.test']);
        $this->post($this->urlTenant('iscriviti'), ['telefono' => '3332223344', 'data_nascita' => '1990-07-14', 'citta' => 'Siena'])->assertSessionHas('success');
        $richiesta = RichiestaIscrizione::allTenants()->sole();

        Carbon::setTestNow('2026-01-12 21:00:00');
        $this->comeAdmin()->post(route('richieste-iscrizione.approva', $richiesta), [
            'genera_quota' => '1', 'delibera_numero' => '1/2026', 'delibera_data' => '2026-01-12',
        ])->assertSessionHas('success');
        $alessia = Socio::allTenants()->findOrFail($richiesta->fresh()->socio_id);

        Carbon::setTestNow('2026-01-13 09:00:00');
        $this->esci();
        $this->stripeConfermaPagamento($this->quotaDi($alessia, 2026), 'pi_alessia_2026');

        // Febbraio: convocazione dell'assemblea e newsletter
        Carbon::setTestNow('2026-02-01 10:00:00');
        $this->configuraSmtp();
        $this->inviaConvocazioneAssemblea('Assemblea ordinaria 2026');
        $this->inviaNewsletter('Il circolo a febbraio');

        // Marzo: un evento, con promemoria, presenza e feedback
        Carbon::setTestNow('2026-03-05 18:00:00');
        $evento = $this->adminCreaEvento(['titolo' => 'Serata di giochi', 'data_evento' => '2026-03-14T21:00', 'data_fine' => '2026-03-14T23:30']);
        $prenotazione = $this->prenota($account->fresh(), $evento);
        $giorno('2026-03-12 08:30:00', 'prenotazioni:promemoria-eventi');
        Carbon::setTestNow('2026-03-14 21:10:00');
        $this->comeAdmin()->post(route('eventi.presenze.store', $evento), ['prenotazioni' => [$prenotazione->id]])->assertSessionHas('success');
        $giorno('2026-03-15 10:00:00', 'eventi:invia-feedback');
        $feedback = FeedbackEvento::allTenants()->sole();
        $this->esci();
        $this->post(URL::signedRoute('feedback-eventi.show', ['token' => $feedback->token]) /* POST sull'URL firmata del link (audit run-1) */, ['voto' => 5])->assertOk();

        // Maggio: si candida a un turno di volontariato
        Carbon::setTestNow('2026-05-10 10:00:00');
        $this->comeAdmin()->post(route('turni.store'), [
            'titolo' => 'Pulizia del parco', 'data' => '2026-05-20', 'ora_inizio' => '09:00', 'ora_fine' => '12:00',
            'posti' => 5, 'stato' => Turno::STATO_APERTO, 'auto_conferma' => '0',
        ])->assertRedirect();
        $turno = Turno::allTenants()->sole();
        $alessia->forceFill(['password_hash' => self::PASSWORD])->save();
        $this->comeSocio($alessia)->post(route('area-soci.volontariato.candidati', $turno))->assertSessionHas('success');
        $assegnazione = TurnoAssegnazione::allTenants()->sole();
        $this->comeAdmin()->post(route('turni.conferma', [$turno, $assegnazione]))->assertSessionHas('success');
        $giorno('2026-05-19 17:00:00', 'turni:promemoria');

        // Luglio: compleanno
        $giorno('2026-07-14 08:15:00', 'compleanni:auguri');

        // Ottobre: aggiorna l'indirizzo dall'area soci
        Carbon::setTestNow('2026-10-01 18:00:00');
        $this->comeSocio($alessia)->post(route('area-soci.modifica-dati.store'), ['indirizzo' => 'Via del Campo 3', 'cap' => '53100'])->assertSessionHas('success');
        $this->comeAdmin()->post(route('richieste-modifica-dati.approva', RichiestaModificaDati::withoutGlobalScopes()->sole()))->assertSessionHas('success');

        // Novembre-dicembre: apertura 2027, promemoria, rinnovo anticipato
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->apriAnno(2027);
        $giorno('2026-12-02 07:00:00', 'rinnovi:promemoria');
        $giorno('2026-12-16 07:00:00', 'rinnovi:promemoria');

        Carbon::setTestNow('2026-12-20 18:00:00');
        $quota2027 = $this->quotaDi($alessia, 2027);
        $risposta = $this->comeSocio($alessia)->post(route('area-soci.rinnova'));
        $this->attesoCheck(
            $risposta->headers->get('Location') !== route('area-soci.dashboard'),
            'Test 25: il 20/12 Alessia, invitata dai promemoria di dicembre a "rinnovare online dalla tua area soci", non può rinnovare per il 2027: area-soci.rinnova risponde "Il rinnovo non è disponibile: la tessera per il 2026 risulta già emessa" perché RinnovoService lavora solo sull\'anno solare corrente (app/Services/Rinnovi/RinnovoService.php:24 annoCorrente, :33 puoRinnovare) e ignora la quota 2027 già aperta.'
        );
        if ($risposta->headers->get('Location') === route('area-soci.dashboard')) {
            // La segreteria le manda il link di pagamento della quota 2027 già aperta
            $this->comeAdmin()->post(route('quote.payment-link', $quota2027))->assertSessionHas('payment_link');
        }
        $this->esci();
        $this->stripeConfermaPagamento($quota2027->fresh(), 'pi_alessia_2027');
        $giorno('2026-12-28 07:00:00', 'rinnovi:promemoria');

        // Gennaio 2027: solleciti in blocco su tutte le quote non pagate
        Carbon::setTestNow('2027-01-05 10:00:00');
        $moroso = $this->creaSocio(['nome' => 'Mauro', 'cognome' => 'Morosi', 'email' => 'mauro.morosi@example.test']);
        $this->creaQuota($moroso, 2027);
        $this->comeAdmin()->post(route('solleciti-quote.bulk'), ['quota_ids' => Quota::allTenants()->whereNull('data_pagamento')->pluck('id')->all()])
            ->assertSessionHas('success', 'Solleciti inviati: 1.');

        // ---------------- Conteggio finale ----------------
        $alessia->refresh();
        $account->refresh();

        foreach ([
            VerificaEmailAccount::class => 1,
            ConfermaRichiestaInviataNotification::class => 1,
            IscrizioneApprovataNotification::class => 1,
            ConfermataNotification::class => 1,
            PromemoriaEventoNotification::class => 1,
            FeedbackEventoNotification::class => 1,
        ] as $classe => $volte) {
            Notification::assertSentToTimes($account, $classe, $volte);
        }

        foreach ([
            PagamentoQuotaNotification::class => 2,
            RicevutaQuotaNotification::class => 2,
            ConvocazioneAssembleaNotification::class => 1,
            PromemoriaTurnoNotification::class => 1,
            AuguriCompleannoNotification::class => 1,
            PromemoriaRinnovoNotification::class => 2,
        ] as $classe => $volte) {
            Notification::assertSentToTimes($alessia, $classe, $volte);
        }
        $this->assertSame([30, 15], $this->stepPromemoriaRicevuti($alessia));
        Notification::assertNotSentTo($alessia, SollecitoQuotaNotification::class);
        // Approvata online, Alessia era già Attiva al pagamento: niente BenvenutoSocio
        // (il benvenuto è l'email di approvazione)
        Notification::assertNotSentTo($alessia, BenvenutoSocioNotification::class);

        Notification::assertSentToTimes($this->admin, RichiestaIscrizioneRicevutaNotification::class, 1);
        Notification::assertSentToTimes($this->admin, CandidaturaTurnoNotification::class, 1);
        $this->assertSame(1, EmailQueue::allTenants()->where('socio_id', $alessia->id)->where('template_codice', 'newsletter')->count());
        $this->assertSame(1, EmailQueue::allTenants()->where('socio_id', $alessia->id)->count());

        // Database a fine anno sociale
        $quote = Quota::allTenants()->where('socio_id', $alessia->id)->get();
        $this->assertCount(2, $quote);
        $this->assertTrue($quote->every(fn (Quota $q) => $q->isPagata()));
        $this->assertSame([2026, 2027], Tessera::allTenants()->where('socio_id', $alessia->id)->where('stato', Tessera::STATO_ATTIVA)->orderBy('anno_validita')->pluck('anno_validita')->map(fn ($a) => (int) $a)->all());
        $this->assertSame(2, Ricevuta::allTenants()->whereIn('quota_id', $quote->pluck('id'))->count());
        $this->assertSame(2, Movimento::allTenants()->whereIn('origine_id', $quote->pluck('id'))->where('tipo', Movimento::TIPO_ENTRATA)->count());
        $promemoria = PromemoriaInviato::allTenants()->where('socio_id', $alessia->id)->get()->countBy('tipo')->all();
        ksort($promemoria);
        $this->assertSame(['compleanno' => 1, PromemoriaInviato::TIPO_RINNOVO_TESSERA => 2, PromemoriaInviato::TIPO_TURNO => 1], $promemoria);

        $libro = $this->comeAdmin()->get(route('libro-soci.index'))->assertOk()->assertSee('1/2026');
        $this->assertCount(1, collect($libro->viewData('righe'))->filter(fn (array $r) => $r['socio']->id === $alessia->id));

        $storico = StoricoAttivitaSocio::allTenants()->where('socio_id', $alessia->id)->orderBy('data_attivita')->orderBy('created_at')->pluck('tipo_attivita')->all();
        $this->assertSame(
            ['Iscrizione', 'Approvazione iscrizione', 'Pagamento Online', 'Modifica Dati', 'Pagamento Online'],
            array_values(array_filter($storico, fn (string $t) => in_array($t, ['Iscrizione', 'Approvazione iscrizione', 'Pagamento Online', 'Modifica Dati'], true)))
        );

        $this->assertNessunaDifformita();
    }
}
