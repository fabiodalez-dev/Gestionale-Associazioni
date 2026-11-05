<?php

namespace Tests\Feature\VitaAssociativa;

use App\Mail\Struttura\InvitoAmministratoreMail;
use App\Models\Account;
use App\Models\Adempimento;
use App\Models\AdempimentoAnno;
use App\Models\Annuncio;
use App\Models\Associazione;
use App\Models\AttivitaAdmin;
use App\Models\Carica;
use App\Models\CategoriaMovimento;
use App\Models\Cessazione;
use App\Models\Documento;
use App\Models\Donazione;
use App\Models\EmailLog;
use App\Models\EmailQueue;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Movimento;
use App\Models\NewsletterInviata;
use App\Models\PaymentGatewaySetting;
use App\Models\PromemoriaInviato;
use App\Models\Quota;
use App\Models\Ricevuta;
use App\Models\RichiestaIscrizione;
use App\Models\RispostaSondaggio;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Scadenza;
use App\Models\SmtpSetting;
use App\Models\Socio;
use App\Models\Sondaggio;
use App\Models\Tessera;
use App\Models\TipoSocio;
use App\Models\Utente;
use App\Models\Verbale;
use App\Models\VerbaleFirmatario;
use App\Models\VotazioneRiunione;
use App\Notifications\Comunicazione\ConvocazioneAssembleaNotification;
use App\Notifications\Economia\SollecitoQuotaNotification;
use App\Notifications\Iscrizioni\IscrizioneApprovataNotification;
use App\Notifications\Iscrizioni\IscrizioneRifiutataNotification;
use App\Notifications\Iscrizioni\RichiestaIscrizioneRicevutaNotification;
use App\Notifications\Operativita\PromemoriaAttivitaNotification;
use App\Notifications\PromemoriaScadenzaNotification;
use App\Notifications\Rinnovi\PromemoriaRinnovoNotification;
use App\Notifications\Struttura\ConvocazioneRiunioneNotification;
use App\Notifications\Struttura\PromemoriaAdempimentoNotification;
use App\Services\Economia\PrimaNotaService;
use App\Services\Economia\SollecitoQuoteService;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Honeypot\ProtectAgainstSpam;
use Tests\TestCase;

/**
 * La vita burocratica — l'anno sociale visto dal consiglio direttivo e
 * dalla segreteria.
 *
 * Ogni test racconta un pezzo dell'anno di un'associazione di promozione
 * sociale: il presidente (Utente admin_associazione), il segretario
 * (secondo amministratore), il tesoriere (operatore con i permessi di
 * economia), i soci che ricoprono le cariche, i cittadini che chiedono di
 * entrare e lo scheduler (comandi Artisan). Il tempo scorre con
 * Carbon::setTestNow: aperture d'anno, campagne, riunioni, verbali,
 * adempimenti e scadenze, fino al libro soci e alle statistiche.
 *
 * Le verifiche guardano sempre il database, le notifiche/email e le pagine.
 * Dove l'app si comporta in modo scorretto il test resta rosso e il
 * messaggio dell'asserzione spiega il problema.
 */
class VitaBurocraticaTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Quercia-Lanterna-Fiume-27';

    private Associazione $associazione;

    /** Presidente: primo amministratore dell'associazione. */
    private Utente $presidente;

    /** Segretario: secondo amministratore dell'associazione. */
    private Utente $segretario;

    /** Tesoriere: operatore dello staff con il ruolo associativo "tesoriere". */
    private Utente $tesoriere;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-01-02 09:00:00');

        $this->seed(RolesSeeder::class);
        Notification::fake();
        Mail::fake();
        Storage::fake('public');
        Storage::fake('local');
        config(['honeypot.enabled' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->associazione = Associazione::factory()->create([
            'nome' => 'APS La Rondine',
            'slug' => 'aps-la-rondine',
            'email' => 'segreteria@larondine.test',
            'frontend_pubblico_attivo' => true,
            'costo_tessera' => 30.00,
        ]);

        $this->presidente = $this->creaAdmin('Paola', 'Presidenti');
        $this->segretario = $this->creaAdmin('Sergio', 'Segretari');
        $this->tesoriere = $this->creaStaff('Teresa', 'Tesorieri', ['tesoriere']);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ==================================================================
    // Attori e fixture
    // ==================================================================

    private function creaAdmin(string $nome, string $cognome, ?Associazione $associazione = null): Utente
    {
        $associazione ??= $this->associazione;

        $utente = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $associazione->id,
            'nome' => $nome,
            'cognome' => $cognome,
            'email' => strtolower($nome.'.'.$cognome).'@'.$associazione->slug.'.test',
            'password_hash' => self::PASSWORD,
        ]);
        $utente->assignRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE);

        return $utente;
    }

    /**
     * @param  list<string>  $ruoli
     */
    private function creaStaff(string $nome, string $cognome, array $ruoli = ['operatore']): Utente
    {
        $utente = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => $nome,
            'cognome' => $cognome,
            'email' => strtolower($nome.'.'.$cognome).'@aps-la-rondine.test',
            'password_hash' => self::PASSWORD,
        ]);
        $utente->syncRoles($ruoli);

        return $utente;
    }

    /**
     * @param  array<string, mixed>  $attributi
     */
    private function creaSocio(string $nome, string $cognome, array $attributi = [], ?Associazione $associazione = null): Socio
    {
        $associazione ??= $this->associazione;

        return Socio::factory()->create(array_merge([
            'associazione_id' => $associazione->id,
            'nome' => $nome,
            'cognome' => $cognome,
            'email' => strtolower($nome.'.'.$cognome).'@soci.test',
            'data_iscrizione' => '2024-01-15',
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
            'associazione_id' => $socio->associazione_id,
            'anno_validita' => $anno,
            'numero_tessera' => $anno.'9'.str_pad((string) $progressivo, 3, '0', STR_PAD_LEFT),
            'data_emissione' => $anno.'-01-10',
            'data_scadenza' => $anno.'-12-31',
            'stato' => Tessera::STATO_ATTIVA,
        ], $override));
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function creaQuota(Socio $socio, int $anno, float $importo, array $override = []): Quota
    {
        return Quota::factory()->create(array_merge([
            'socio_id' => $socio->id,
            'associazione_id' => $socio->associazione_id,
            'anno' => $anno,
            'importo' => $importo,
            'data_scadenza' => $anno.'-12-31',
            'data_pagamento' => null,
            'stato' => Quota::STATO_DA_PAGARE,
            'tipo' => 'Quota Associativa',
        ], $override));
    }

    private function carica(Socio $socio, string $ruolo, string $inizio, ?string $fine = null): Carica
    {
        return Carica::create([
            'associazione_id' => $socio->associazione_id,
            'socio_id' => $socio->id,
            'ruolo' => $ruolo,
            'inizio' => $inizio,
            'fine' => $fine,
        ]);
    }

    private function come(Utente $utente): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->actingAs($utente, 'web');
    }

    /**
     * Come un nuovo visitatore: nessuna sessione né utente dal passo precedente
     * (e guard di default riportato a "web" dopo un actingAs su socio/account).
     */
    private function esci(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->flushSession();
        app()->forgetInstance('tenant.pubblico');
    }

    private function urlTenant(string $path = '', ?Associazione $associazione = null): string
    {
        $associazione ??= $this->associazione;

        return '/a/'.$associazione->slug.($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    private function vai(string $quando): void
    {
        Carbon::setTestNow($quando);
    }

    private function configuraStripe(?Associazione $associazione = null): PaymentGatewaySetting
    {
        return PaymentGatewaySetting::factory()->conStripe()->create([
            'associazione_id' => ($associazione ?? $this->associazione)->id,
        ]);
    }

    private function configuraSmtp(?Associazione $associazione = null, array $override = []): SmtpSetting
    {
        return SmtpSetting::create(array_merge([
            'associazione_id' => ($associazione ?? $this->associazione)->id,
            'smtp_host' => 'smtp.larondine.test',
            'smtp_port' => 587,
            'smtp_user' => 'posta',
            'smtp_pass_encrypted' => 'segretissima',
            'smtp_encryption' => 'tls',
            'from_email' => 'noreply@larondine.test',
            'from_name' => 'APS La Rondine',
            'max_per_hour' => 500,
        ], $override));
    }

    /**
     * Stripe conferma il pagamento della quota (webhook con firma
     * verificata da un gateway finto che restituisce il payload).
     */
    private function stripePagaQuota(Quota $quota, string $transazione): void
    {
        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('constructWebhookEvent')
                ->andReturnUsing(fn (string $payload) => json_decode($payload, true));
        });

        $this->esci();
        $this->postJson(route('webhooks.stripe'), [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_'.$transazione,
                'payment_intent' => $transazione,
                'metadata' => [
                    'quota_id' => $quota->id,
                    'associazione_id' => $quota->associazione_id,
                ],
            ]],
        ], ['Stripe-Signature' => 't=1,v1=firma'])->assertOk();
    }

    /**
     * L'azione è andata a buon fine (flash "success"); altrimenti il
     * messaggio dell'errore mostrato all'utente finisce nell'asserzione.
     */
    private function riuscito(TestResponse $risposta): TestResponse
    {
        $this->assertTrue(
            session()->has('success'),
            'Esito inatteso: '.json_encode(session()->only(['error', 'warning', 'danger', 'errors']) + ['status' => $risposta->status()], JSON_UNESCAPED_UNICODE)
        );

        return $risposta;
    }

    private function pagaOffline(Utente $chi, Quota $quota, string $data, string $metodo = 'contanti'): TestResponse
    {
        return $this->come($chi)->post(route('quote.pagamento', $quota), [
            'payment_method' => $metodo,
            'payment_date' => $data,
        ]);
    }

    /**
     * Un cittadino con account verificato sul mini-sito invia la domanda
     * di iscrizione dal modulo pubblico /iscriviti.
     *
     * @param  array<string, mixed>  $dati
     */
    private function cittadinoChiedeIscrizione(string $nome, string $cognome, array $dati = []): RichiestaIscrizione
    {
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => $nome,
            'cognome' => $cognome,
            'email' => strtolower($nome.'.'.$cognome).'@cittadini.test',
        ]);

        $this->esci();
        $this->actingAs($account, 'account')
            ->post($this->urlTenant('iscriviti'), array_merge([
                'telefono' => '3331234567',
                'data_nascita' => '1990-05-20',
                'citta' => 'Bologna',
            ], $dati))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        return RichiestaIscrizione::allTenants()->where('account_id', $account->id)->sole();
    }

    /**
     * Il segretario pianifica una riunione con il suo ordine del giorno.
     *
     * @param  list<string>  $punti
     */
    private function creaRiunione(Utente $chi, string $titolo, string $dataOra, array $punti, array $extra = []): Riunione
    {
        $this->come($chi)->post(route('riunioni.store'), array_merge([
            'titolo' => $titolo,
            'data_ora' => $dataOra,
            'luogo' => 'Sede sociale, via dei Mille 4',
            'stato' => Riunione::STATO_PIANIFICATA,
            'odg' => array_map(fn (string $p) => ['punto' => $p, 'descrizione' => ''], $punti),
        ], $extra))->assertSessionHasNoErrors()->assertRedirect();

        return Riunione::query()->where('titolo', $titolo)->latest('created_at')->firstOrFail();
    }

    /**
     * La riunione si è svolta: stato "svolta", presenze dei partecipanti indicati.
     *
     * @param  list<Socio>  $presenti
     */
    private function svolgiRiunione(Utente $chi, Riunione $riunione, array $presenti): void
    {
        $this->come($chi)->put(route('riunioni.update', $riunione), [
            'titolo' => $riunione->titolo,
            'data_ora' => $riunione->data_ora->format('Y-m-d\TH:i'),
            'luogo' => $riunione->luogo,
            'spazio_id' => $riunione->spazio_id,
            'stato' => Riunione::STATO_SVOLTA,
            'odg' => $riunione->odg,
        ])->assertSessionHasNoErrors();

        foreach ($presenti as $socio) {
            $partecipante = RiunionePartecipante::query()
                ->where('riunione_id', $riunione->id)
                ->where('socio_id', $socio->id)
                ->firstOrFail();

            $this->put(route('riunioni.partecipanti.presenza', [$riunione, $partecipante]), [
                'stato' => RiunionePartecipante::STATO_CONFERMATO,
            ])->assertRedirect(route('riunioni.show', $riunione));
        }
    }

    private function vota(Riunione $riunione, int $punto, string $esito, int $favorevoli, int $contrari = 0, int $astenuti = 0, ?string $note = null): void
    {
        $this->post(route('riunioni.votazioni.store', $riunione), [
            'punto_indice' => $punto,
            'esito' => $esito,
            'favorevoli' => $favorevoli,
            'contrari' => $contrari,
            'astenuti' => $astenuti,
            'note' => $note,
        ])->assertSessionHas('success');
    }

    private function generaVerbale(Riunione $riunione): Verbale
    {
        $this->post(route('riunioni.genera-verbale', $riunione))->assertSessionHas('success');

        return Verbale::query()->findOrFail($riunione->fresh()->verbale_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function datiVerbale(Verbale $verbale, array $override = []): array
    {
        return array_merge([
            'titolo' => $verbale->titolo,
            'data_riunione' => $verbale->data_riunione->toDateString(),
            'tipo_riunione' => $verbale->tipo_riunione,
            'presenti' => $verbale->presenti,
            'ordine_del_giorno' => $verbale->ordine_del_giorno,
            'deliberazioni' => $verbale->deliberazioni,
        ], $override);
    }

    /**
     * Il server SMTP del tenant è sostituito da un finto mailer che registra
     * ogni invio (indirizzo destinatario) e risponde sempre OK.
     *
     * @param  list<string>  $invii
     */
    private function mailerFinto(array &$invii): void
    {
        $this->mock(\App\Services\Email\TenantMailer::class, function ($mock) use (&$invii) {
            $mock->shouldReceive('send')->andReturnUsing(function (SmtpSetting $settings, string $to) use (&$invii) {
                $invii[] = $to;

                return ['success' => true, 'error' => null];
            });
        });
    }

    // ==================================================================
    // 1. Apertura dell'anno sociale
    // ==================================================================

    public function test_buro_01_apertura_dell_anno_sociale_il_direttivo_fissa_le_quote_per_tipo_socio_apre_l_anno_e_la_campagna_rinnovi_parte_solo_per_chi_deve_pagare(): void
    {
        $this->vai('2026-11-10 18:00:00');

        // Il direttivo fissa le regole del tesseramento: scadenza solare
        $this->come($this->presidente)
            ->post(route('configurazioni.tesseramento'), ['tipo_scadenza_default' => 'solare', 'giorni_notifica_scadenza' => 45])
            ->assertSessionHasNoErrors();
        // Gli step dei promemoria non hanno un campo nel form: li salva la configurazione del tenant
        $this->associazione->tesseramento()->aggiorna(['promemoria_rinnovo_config' => [45, 20, 5]]);
        $this->assertSame([45, 20, 5], $this->associazione->fresh()->promemoriaRinnovoSteps());

        foreach ([['Ordinario', '30'], ['Studente', '15'], ['Onorario', '0']] as [$nome, $costo]) {
            $this->post(route('tipi-socio.store'), ['nome' => $nome, 'costo_tessera' => $costo])->assertSessionHasNoErrors();
        }
        $ordinario = TipoSocio::query()->where('nome', 'Ordinario')->sole();
        $studente = TipoSocio::query()->where('nome', 'Studente')->sole();
        $onorario = TipoSocio::query()->where('nome', 'Onorario')->sole();
        $this->assertSame('0.00', (string) $onorario->costo_tessera);

        // Il corpo sociale del 2026
        $aldo = $this->creaSocio('Aldo', 'Ordini', ['tipo_socio_id' => $ordinario->id]);
        $bice = $this->creaSocio('Bice', 'Ordini', ['tipo_socio_id' => $ordinario->id]);
        $carlo = $this->creaSocio('Carlo', 'Studi', ['tipo_socio_id' => $studente->id]);
        $dora = $this->creaSocio('Dora', 'Studi', ['tipo_socio_id' => $studente->id]);
        $emilio = $this->creaSocio('Emilio', 'Onori', ['tipo_socio_id' => $onorario->id]);
        $fulvia = $this->creaSocio('Fulvia', 'Anticipi', ['tipo_socio_id' => $ordinario->id]);
        $sospeso = $this->creaSocio('Gino', 'Sospesi', ['stato' => Socio::STATO_SOSPESO, 'tipo_socio_id' => $ordinario->id]);
        $radiato = $this->creaSocio('Ugo', 'Radiati', ['stato' => Socio::STATO_RADIATO, 'tipo_socio_id' => $ordinario->id]);

        foreach ([$aldo, $bice, $carlo, $dora, $emilio, $fulvia, $sospeso, $radiato] as $socio) {
            $this->creaTessera($socio, 2026);
        }
        $daRinnovare = [$aldo, $bice, $carlo, $dora, $emilio];

        // Fulvia ha già pagato la 2027 in sede il 5 novembre e ha ritirato la tessera nuova
        $this->creaQuota($fulvia, 2027, 30, ['data_pagamento' => '2026-11-05', 'stato' => Quota::STATO_PAGATA, 'metodo_pagamento' => 'contanti']);
        $this->creaTessera($fulvia, 2027, ['data_emissione' => '2026-11-05']);

        // Il segretario guarda l'anteprima dell'apertura 2027
        $anteprima = $this->come($this->segretario)
            ->get(route('apertura-anno.index', ['anno' => 2027]))
            ->assertOk()
            ->assertSee('Ordinario')->assertSee('Studente')->assertSee('Onorario')
            ->viewData('anteprima');

        $perTipo = collect($anteprima['per_tipo'])->keyBy('tipo');
        $this->assertSame(6, $anteprima['soci_attivi']);
        $this->assertSame(1, $anteprima['gia_aperte']);
        $this->assertSame(5, $anteprima['da_generare']);
        $this->assertSame(['soci' => 2, 'unitario' => 30.0], ['soci' => $perTipo['Ordinario']['soci'], 'unitario' => $perTipo['Ordinario']['importo_unitario']]);
        $this->assertSame(['soci' => 2, 'unitario' => 15.0], ['soci' => $perTipo['Studente']['soci'], 'unitario' => $perTipo['Studente']['importo_unitario']]);
        $this->assertSame(['soci' => 1, 'unitario' => 0.0], ['soci' => $perTipo['Onorario']['soci'], 'unitario' => $perTipo['Onorario']['importo_unitario']]);
        $this->assertSame(2 * 30.0 + 2 * 15.0 + 0.0, $anteprima['importo_totale']);

        // Prima la prova a secco dallo scheduler: non scrive nulla
        $this->artisan('rinnovi:apri-anno', ['anno' => 2027, '--associazione' => $this->associazione->id, '--dry-run' => true])
            ->expectsOutputToContain('da generare: 5')
            ->assertSuccessful();
        $this->assertSame(1, Quota::query()->where('anno', 2027)->count());

        // Poi la generazione vera, ripetuta per sbaglio
        $this->come($this->segretario)
            ->post(route('apertura-anno.genera'), ['anno' => 2027])
            ->assertSessionHas('success', 'Anno 2027 aperto: generate 5 quote per i soci attivi.');
        $this->post(route('apertura-anno.genera'), ['anno' => 2027])
            ->assertSessionHas('success', 'Nessuna quota da generare per l\'anno 2027: tutti i soci attivi hanno già la quota.');

        $quote2027 = Quota::query()->where('anno', 2027)->get()->keyBy('socio_id');
        $this->assertCount(6, $quote2027);
        $this->assertSame(
            [$aldo->id => '30.00', $bice->id => '30.00', $carlo->id => '15.00', $dora->id => '15.00', $emilio->id => '0.00'],
            collect($daRinnovare)->mapWithKeys(fn (Socio $s) => [$s->id => (string) $quote2027[$s->id]->importo])->all()
        );
        foreach ($daRinnovare as $socio) {
            $this->assertSame('2027-12-31', $quote2027[$socio->id]->data_scadenza->toDateString());
            $this->assertFalse($quote2027[$socio->id]->isPagata());
        }
        $this->assertFalse($quote2027->has($sospeso->id));
        $this->assertFalse($quote2027->has($radiato->id));

        // 16 novembre: lo scheduler manda il primo promemoria (45 giorni al 31/12)
        $this->vai('2026-11-16 08:00:00');
        $this->artisan('rinnovi:promemoria')->expectsOutput('Promemoria rinnovo inviati: 5')->assertSuccessful();

        foreach ($daRinnovare as $socio) {
            Notification::assertSentTo($socio, PromemoriaRinnovoNotification::class, fn (PromemoriaRinnovoNotification $n) => $n->step === 45);
        }
        Notification::assertNotSentTo([$fulvia, $sospeso, $radiato], PromemoriaRinnovoNotification::class);

        // 11 dicembre: secondo promemoria (20 giorni), stesso giro di soci
        $this->vai('2026-12-11 08:00:00');
        $this->artisan('rinnovi:promemoria')->expectsOutput('Promemoria rinnovo inviati: 5')->assertSuccessful();
        foreach ($daRinnovare as $socio) {
            Notification::assertSentToTimes($socio, PromemoriaRinnovoNotification::class, 2);
        }
        $this->artisan('rinnovi:promemoria')->expectsOutput('Promemoria rinnovo inviati: 0')->assertSuccessful();

        $this->assertSame(10, PromemoriaInviato::query()->where('tipo', PromemoriaInviato::TIPO_RINNOVO_TESSERA)->count());
        $this->assertSame([20, 45], PromemoriaInviato::query()->where('socio_id', $aldo->id)->orderBy('step')->pluck('step')->map(fn ($s) => (int) $s)->all());
        $this->assertSame(0, PromemoriaInviato::query()->where('socio_id', $fulvia->id)->count());

        // La campagna rinnovi lavora sull'anno solare in corso: parte a gennaio 2027.
        $this->vai('2027-01-04 10:00:00');
        $soci = Socio::query()->where('stato', Socio::STATO_ATTIVO)->pluck('id')->all();
        $this->come($this->segretario)
            ->post(route('campagna-rinnovi.invia'), ['socio_ids' => $soci])
            ->assertRedirect(route('campagna-rinnovi.index'))
            ->assertSessionHas('success', 'Campagna rinnovi 2027: 5 email accodate. 1 soci saltati (senza email, opt-out o già rinnovati).');

        $campagna = EmailQueue::query()->where('template_codice', 'campagna_rinnovi_2027')->get();
        $this->assertEqualsCanonicalizing(collect($daRinnovare)->pluck('id')->all(), $campagna->pluck('socio_id')->all());
        // La campagna riusa le quote dell'apertura: nessuna quota in più
        $this->assertSame(6, Quota::query()->where('anno', 2027)->count());
        $this->assertStringContainsString('15,00', $campagna->firstWhere('socio_id', $carlo->id)->body_html);

        $tokenCarlo = $quote2027[$carlo->id]->fresh()->payment_token;
        $this->assertNotNull($tokenCarlo);

        // Rilanciata il giorno dopo con "Seleziona tutti": è un reinvio voluto (il servizio
        // non esclude chi ha già ricevuto l'email), ma non nascono quote nuove né link nuovi
        $this->vai('2027-01-05 10:00:00');
        $this->post(route('campagna-rinnovi.invia'), ['socio_ids' => $soci])->assertSessionHas('success');
        $this->assertSame(10, EmailQueue::query()->where('template_codice', 'campagna_rinnovi_2027')->count());
        $this->assertSame(6, Quota::query()->where('anno', 2027)->count());
        $this->assertSame($tokenCarlo, $quote2027[$carlo->id]->fresh()->payment_token, 'Il link di pagamento resta lo stesso al reinvio');

        $pagina = $this->get(route('campagna-rinnovi.index'))->assertOk();
        $this->assertTrue($pagina->viewData('soci')->every(fn (Socio $s) => (bool) $s->campagna_inviata));
        $this->assertSame(5, $pagina->viewData('contatori')['inviate']);
        $this->assertSame(1, $pagina->viewData('contatori')['rinnovate']);
    }

    // ==================================================================
    // 2. Ammissione di nuovi soci con delibera del direttivo
    // ==================================================================

    public function test_buro_02_il_consiglio_direttivo_delibera_l_ammissione_di_cinque_richieste_in_una_sola_seduta_e_il_libro_soci_riporta_la_stessa_delibera(): void
    {
        $this->configuraStripe();

        // Il direttivo in carica
        $presidenteSocio = $this->creaSocio('Pietro', 'Direttivi');
        $consigliere1 = $this->creaSocio('Clara', 'Consigli');
        $consigliere2 = $this->creaSocio('Cosimo', 'Consigli');
        $this->carica($presidenteSocio, 'Presidente', '2025-05-01', '2028-04-30');
        $this->carica($consigliere1, 'Consigliere', '2025-05-01', '2028-04-30');
        $this->carica($consigliere2, 'Consigliere', '2025-05-01', '2028-04-30');

        // Marzo: cinque domande dal mini-sito, in giorni diversi
        $nomi = [['Anna', 'Primi'], ['Bruno', 'Secondi'], ['Chiara', 'Terzi'], ['Davide', 'Quarti'], ['Elena', 'Incompleti']];
        $richieste = [];
        foreach ($nomi as $i => [$nome, $cognome]) {
            $this->vai('2026-03-0'.($i + 1).' 10:00:00');
            $richieste[$cognome] = $this->cittadinoChiedeIscrizione($nome, $cognome, $cognome === 'Incompleti' ? ['citta' => ''] : []);
        }
        Notification::assertSentToTimes($this->presidente, RichiestaIscrizioneRicevutaNotification::class, 5);
        $this->assertSame(5, RichiestaIscrizione::query()->where('stato', RichiestaIscrizione::STATO_IN_ATTESA)->count());

        // 12 marzo: il segretario convoca il direttivo
        $this->vai('2026-03-12 09:00:00');
        $riunione = $this->creaRiunione($this->segretario, 'Consiglio direttivo di marzo', '2026-03-15T21:00', ['Ammissione nuovi soci', 'Varie']);
        $this->post(route('riunioni.convoca', $riunione))->assertSessionHas('success');

        $partecipanti = RiunionePartecipante::query()->where('riunione_id', $riunione->id)->get();
        $this->assertCount(3, $partecipanti);
        $this->assertEqualsCanonicalizing(['Presidente', 'Consigliere', 'Consigliere'], $partecipanti->pluck('carica_ruolo')->all());
        Notification::assertSentTo([$presidenteSocio, $consigliere1, $consigliere2, $this->presidente, $this->segretario], ConvocazioneRiunioneNotification::class);

        // 15 marzo: seduta, tutti presenti, ammissione approvata all'unanimità
        $this->vai('2026-03-15 23:00:00');
        $this->svolgiRiunione($this->segretario, $riunione, [$presidenteSocio, $consigliere1, $consigliere2]);
        $this->vota($riunione, 0, VotazioneRiunione::ESITO_APPROVATO, 3, 0, 0, 'Ammessi Primi, Secondi, Terzi, Quarti. Respinta la domanda incompleta.');
        $verbale = $this->generaVerbale($riunione);

        $this->assertSame(1, VotazioneRiunione::query()->where('riunione_id', $riunione->id)->where('esito', VotazioneRiunione::ESITO_APPROVATO)->count());
        $this->assertSame([1, 2026, false], [$verbale->numero, $verbale->anno, (bool) $verbale->approvato]);
        $this->assertStringContainsString('Pietro Direttivi (Presidente)', $verbale->presenti);

        // 16 marzo: il segretario esegue la delibera
        $this->vai('2026-03-16 10:00:00');
        foreach (['Primi', 'Secondi', 'Terzi', 'Quarti'] as $cognome) {
            $this->come($this->segretario)
                ->post(route('richieste-iscrizione.approva', $richieste[$cognome]), [
                    'genera_tessera' => '0',
                    'genera_quota' => '1',
                    'delibera_numero' => $verbale->numero_completo,
                    'delibera_data' => '2026-03-15',
                ])
                ->assertRedirect(route('richieste-iscrizione.index'))
                ->assertSessionHas('success');
        }
        $this->post(route('richieste-iscrizione.rifiuta', $richieste['Incompleti']), [
            'motivo' => 'Domanda incompleta: manca la città di residenza richiesta dallo statuto.',
        ])->assertSessionHas('success');

        $this->assertSame(4, RichiestaIscrizione::query()->where('stato', RichiestaIscrizione::STATO_APPROVATA)->where('delibera_numero', '1/2026')->count());
        $this->assertSame(1, RichiestaIscrizione::query()->where('stato', RichiestaIscrizione::STATO_RIFIUTATA)->count());
        Notification::assertSentTimes(IscrizioneApprovataNotification::class, 4);
        Notification::assertSentTimes(IscrizioneRifiutataNotification::class, 1);

        $nuovi = collect(['Primi', 'Secondi', 'Terzi', 'Quarti'])
            ->mapWithKeys(fn (string $c) => [$c => Socio::query()->findOrFail($richieste[$c]->fresh()->socio_id)]);
        $this->assertTrue($nuovi->every(fn (Socio $s) => $s->stato === Socio::STATO_ATTIVO));

        // Le quote di ammissione: due pagano con carta, due in sede
        $quote = $nuovi->map(fn (Socio $s) => Quota::query()->where('socio_id', $s->id)->where('anno', 2026)->sole());
        $this->assertTrue($quote->every(fn (Quota $q) => (float) $q->importo === 30.0));

        $this->vai('2026-03-20 19:00:00');
        $this->stripePagaQuota($quote['Primi'], 'pi_primi');
        $this->stripePagaQuota($quote['Secondi'], 'pi_secondi');
        $this->vai('2026-03-28 11:00:00');
        $this->pagaOffline($this->tesoriere, $quote['Terzi'], '2026-03-28')->assertSessionHas('success');
        $this->pagaOffline($this->tesoriere, $quote['Quarti'], '2026-03-28', 'bonifico')->assertSessionHas('success');

        // Chi ha pagato in sede ritira la tessera allo sportello
        foreach (['Terzi', 'Quarti'] as $cognome) {
            if (! Tessera::query()->where('socio_id', $nuovi[$cognome]->id)->where('anno_validita', 2026)->exists()) {
                $this->come($this->segretario)->post(route('tessere.emetti', $nuovi[$cognome]))->assertSessionHasNoErrors();
            }
        }

        $this->assertTrue($quote->every(fn (Quota $q) => $q->fresh()->statoCalcolato() === Quota::STATO_PAGATA));
        foreach ($nuovi as $socio) {
            $this->assertSame(1, Tessera::query()->where('socio_id', $socio->id)->where('anno_validita', 2026)->count(), "Tessera 2026 di {$socio->cognome}");
        }
        $this->assertSame([1, 2, 3, 4], Ricevuta::query()->where('anno', 2026)->orderBy('numero')->pluck('numero')->map(fn ($n) => (int) $n)->all());

        // Il libro soci riporta l'ammissione con la stessa delibera, nell'ordine delle domande
        $righe = collect($this->come($this->presidente)->get(route('libro-soci.index', ['anno' => 2026]))
            ->assertOk()
            ->assertSee('1/2026')
            ->assertSee('15/03/2026')
            ->viewData('righe'));
        $nuoveRighe = $righe->filter(fn (array $r) => $nuovi->contains(fn (Socio $s) => $s->id === $r['socio']->id))->values();
        $this->assertSame(['Primi', 'Secondi', 'Terzi', 'Quarti'], $nuoveRighe->map(fn (array $r) => $r['socio']->cognome)->all());
        $this->assertSame([1, 2, 3, 4], $nuoveRighe->pluck('progressivo')->all());
        $this->assertTrue($nuoveRighe->every(fn (array $r) => $r['delibera_numero'] === '1/2026' && $r['delibera_data']->toDateString() === '2026-03-15'));
        $this->assertFalse($righe->contains(fn (array $r) => $r['socio']->cognome === 'Incompleti'));

        $this->get(route('decisioni.index'))->assertOk()
            ->assertSee('Ammissione nuovi soci')
            ->assertSee('Ammissione socio: Anna Primi')
            ->assertSee('Delibera n. 1/2026');

        $this->get(route('libro-soci.pdf'))->assertOk();
        Excel::fake();
        $this->get(route('libro-soci.excel'))->assertOk();
    }

    // ==================================================================
    // 3. Convocazione dell'assemblea ordinaria
    // ==================================================================

    public function test_buro_03_convocazione_dell_assemblea_ordinaria_con_preavviso_statutario_prima_convocazione_deserta_e_seconda_convocazione_valida(): void
    {
        $this->vai('2026-03-20 10:00:00');

        $attivi = [];
        foreach (['Alba', 'Bruna', 'Cesira', 'Dante', 'Ettore', 'Flavia', 'Guido'] as $i => $nome) {
            $attivi[] = $this->creaSocio($nome, 'Assembleari', ['email' => strtolower($nome).'@soci.test', 'numero_socio' => '10'.$i, 'password_hash' => self::PASSWORD]);
        }
        $optOut1 = $this->creaSocio('Ida', 'Silenti', ['email_opt_out' => true, 'numero_socio' => '201']);
        $optOut2 = $this->creaSocio('Lino', 'Silenti', ['email_opt_out' => true, 'numero_socio' => '202']);
        $senzaEmail = $this->creaSocio('Mara', 'Senzamail', ['email' => '', 'numero_socio' => '203']);
        $sospeso = $this->creaSocio('Nino', 'Sospesi', ['stato' => Socio::STATO_SOSPESO, 'numero_socio' => '204']);
        $tuttiAttivi = [...$attivi, $optOut1, $optOut2, $senzaEmail];
        foreach ([...$tuttiAttivi, $sospeso] as $socio) {
            $this->creaTessera($socio, 2026);
        }

        // Il segretario redige la convocazione: prima convocazione il 15/04 alle 18, seconda il 16/04 alle 21
        $primaConvocazione = Carbon::parse('2026-04-15 18:00:00');
        $this->come($this->segretario)->post(route('annunci.store'), [
            'titolo' => 'Convocazione assemblea ordinaria 2026',
            'tipo' => Annuncio::TIPO_CONVOCAZIONE,
            'corpo_html' => '<p>Prima convocazione il 15/04/2026 alle 18:00; in seconda convocazione il 16/04/2026 alle 21:00, valida qualunque sia il numero dei presenti.</p>',
            'data_assemblea' => $primaConvocazione->format('Y-m-d\TH:i'),
            'luogo_assemblea' => 'Sala civica, piazza Garibaldi 1',
            'ordine_del_giorno' => "1. Approvazione bilancio 2025\n2. Rinnovo cariche sociali",
            'pubblicato_dal' => now()->subHour()->format('Y-m-d\TH:i'),
            'solo_soci' => '1',
        ])->assertSessionHasNoErrors();
        $annuncio = Annuncio::query()->where('titolo', 'Convocazione assemblea ordinaria 2026')->sole();

        // Preavviso statutario: almeno 15 giorni tra l'invio e la prima convocazione
        $preavviso = (int) now()->startOfDay()->diffInDays($primaConvocazione->copy()->startOfDay());
        $this->assertSame(26, $preavviso);
        $this->assertGreaterThanOrEqual(15, $preavviso);

        $this->post(route('annunci.invia-convocazione', $annuncio))->assertSessionHas('success', 'Convocazione inviata a 7 soci.');
        $this->assertNotNull($annuncio->fresh()->convocazione_inviata_at);
        Notification::assertSentTo($attivi, ConvocazioneAssembleaNotification::class);
        Notification::assertSentTimes(ConvocazioneAssembleaNotification::class, 7);
        Notification::assertNotSentTo([$optOut1, $optOut2, $senzaEmail, $sospeso], ConvocazioneAssembleaNotification::class);

        $this->esci();
        $this->actingAs($attivi[0], 'socio')->get(route('area-soci.bacheca'))->assertOk()
            ->assertSee('Convocazione assemblea ordinaria 2026')
            ->assertSee('Sala civica, piazza Garibaldi 1');

        // Due eventi istituzionali, uno per convocazione, con i soci precaricati
        $eventi = [];
        foreach (['prima' => '2026-04-15T18:00', 'seconda' => '2026-04-16T21:00'] as $chiave => $data) {
            $this->come($this->segretario)->post(route('eventi.store'), [
                'titolo' => "Assemblea ordinaria — {$chiave} convocazione",
                'descrizione' => 'Assemblea ordinaria dei soci',
                'data_evento' => $data,
                'luogo' => 'Sala civica, piazza Garibaldi 1',
                'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
                'capienza' => 100,
                'prezzo' => '0',
                'max_posti_per_prenotazione' => 1,
                'iscrizioni_aperte' => '0',
                'richiede_approvazione' => '0',
                'solo_soci' => '1',
            ])->assertSessionHasNoErrors();
            $eventi[$chiave] = Evento::query()->where('titolo', "Assemblea ordinaria — {$chiave} convocazione")->sole();
            $this->post(route('eventi.partecipanti.storeAll', $eventi[$chiave]))->assertSessionHas('success');
            $this->assertSame(10, EventoPartecipante::query()->where('evento_id', $eventi[$chiave]->id)->count(), 'Precaricati i 10 soci attivi, non il sospeso');
        }

        // Quorum costitutivo in prima convocazione: metà più uno degli attivi
        $quorum = intdiv(count($tuttiAttivi), 2) + 1;
        $this->assertSame(6, $quorum);

        // 15 aprile: al banco accoglienza solo 3 soci passano la tessera
        $this->vai('2026-04-15 18:05:00');
        foreach (array_slice($attivi, 0, 3) as $socio) {
            $this->come($this->segretario)->postJson(route('tessere.checkin.store'), [
                'evento_id' => $eventi['prima']->id,
                'numero_socio' => $socio->numero_socio,
            ])->assertOk()->assertJsonPath('success', true);
        }
        $presentiPrima = EventoPartecipante::query()->where('evento_id', $eventi['prima']->id)->where('stato_partecipazione', EventoPartecipante::STATO_CONFERMATO)->get();
        $this->assertCount(3, $presentiPrima);
        $this->assertTrue($presentiPrima->every(fn ($p) => $p->data_conferma->toDateString() === '2026-04-15'));
        $this->assertLessThan($quorum, $presentiPrima->count(), 'Prima convocazione deserta: quorum non raggiunto');

        // 16 aprile: seconda convocazione, 4 presenti e 2 deleghe
        $this->vai('2026-04-16 21:05:00');
        foreach (array_slice($attivi, 3, 4) as $socio) {
            $this->postJson(route('tessere.checkin.store'), [
                'evento_id' => $eventi['seconda']->id,
                'numero_socio' => $socio->numero_socio,
            ])->assertOk()->assertJsonPath('already_checked_in', false);
        }
        foreach ([[$optOut1, $attivi[3]], [$optOut2, $attivi[4]]] as [$delegante, $delegato]) {
            $partecipazione = EventoPartecipante::query()->where('evento_id', $eventi['seconda']->id)->where('socio_id', $delegante->id)->sole();
            $this->put(route('eventi.partecipanti.update', ['evento' => $eventi['seconda']->id, 'partecipante' => $partecipazione->id]), [
                'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
                'note' => 'Presente per delega a '.$delegato->nome_completo,
            ])->assertSessionHas('success');
        }

        $seconda = EventoPartecipante::query()->where('evento_id', $eventi['seconda']->id)->where('stato_partecipazione', EventoPartecipante::STATO_CONFERMATO)->get();
        $this->assertCount(6, $seconda);
        $deleghe = $seconda->filter(fn ($p) => str_contains((string) $p->note, 'delega'));
        $this->assertCount(2, $deleghe);
        $this->assertSame('Presente per delega a Dante Assembleari', $deleghe->firstWhere('socio_id', $optOut1->id)->note);

        $csv = $this->get(route('eventi.partecipanti.export', $eventi['seconda']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Assembleari;Dante', $csv);
        $this->assertStringContainsString('Presente per delega a Dante Assembleari', $csv);

        // Il verbale dell'assemblea, con i presenti dal foglio
        $presenti = $seconda->map(fn ($p) => $p->socio->nome_completo.(filled($p->note) ? ' ('.$p->note.')' : ''))->implode("\n");
        $this->post(route('verbali.store'), [
            'titolo' => 'Assemblea ordinaria 2026',
            'data_riunione' => '2026-04-16',
            'tipo_riunione' => 'Assemblea Ordinaria',
            'presenti' => "Assemblea in seconda convocazione (prima convocazione del 15/04/2026 deserta).\n".$presenti,
            'ordine_del_giorno' => "1. Approvazione bilancio 2025\n2. Rinnovo cariche sociali",
            'deliberazioni' => 'Bilancio 2025 approvato.',
            'evento_id' => $eventi['seconda']->id,
        ])->assertRedirect(route('verbali.index'))->assertSessionHas('success', 'Verbale n. 1/2026 aggiunto con successo!');

        $verbale = Verbale::query()->sole();
        $this->assertSame('1/2026', $verbale->numero_completo);
        $this->assertSame($eventi['seconda']->id, $verbale->evento_id);
        $this->assertStringContainsString('seconda convocazione', $verbale->presenti);
        $this->assertStringContainsString('Presente per delega a Ettore Assembleari', $verbale->presenti);
    }

    // ==================================================================
    // 4. Rendiconto annuale: dalla prima nota alla trasparenza
    // ==================================================================

    public function test_buro_04_approvazione_del_rendiconto_annuale_dalla_prima_nota_alla_votazione_in_assemblea_al_deposito_in_trasparenza(): void
    {
        $this->vai('2025-01-10 10:00:00');

        // Il tesoriere apre la prima nota: le categorie nascono da sole
        $this->come($this->tesoriere)->get(route('prima-nota.index'))->assertOk();
        $cat = fn (string $nome) => CategoriaMovimento::query()->where('nome', $nome)->sole()->id;

        $manuali = [
            ['2025-01-31', Movimento::TIPO_USCITA, 600.00, 'Utenze e affitti', 'Affitto sede gennaio'],
            ['2025-02-15', Movimento::TIPO_USCITA, 350.00, 'Altre uscite', 'Assicurazione RC volontari'],
            ['2025-04-10', Movimento::TIPO_ENTRATA, 1000.00, 'Altre entrate', 'Contributo comunale progetto estate'],
            ['2025-06-02', Movimento::TIPO_ENTRATA, 80.00, 'Donazioni', 'Donazione in cassa festa'],
            ['2025-07-20', Movimento::TIPO_USCITA, 45.50, 'Altre uscite', 'Rimborso spese volontario'],
            ['2025-09-05', Movimento::TIPO_USCITA, 62.30, 'Materiali e forniture', 'Cancelleria e toner'],
        ];
        foreach ($manuali as [$data, $tipo, $importo, $categoria, $descrizione]) {
            $this->vai($data.' 12:00:00');
            $this->come($this->tesoriere)->post(route('prima-nota.store'), [
                'tipo' => $tipo,
                'importo' => number_format($importo, 2, '.', ''),
                'data' => $data,
                'categoria_id' => $cat($categoria),
                'descrizione' => $descrizione,
                'metodo' => 'bonifico',
            ])->assertSessionHasNoErrors()->assertRedirect(route('prima-nota.index'));
        }

        // Tre quote pagate in sede durante l'anno
        $soci = [];
        foreach (['Primo', 'Secondo', 'Terzo'] as $i => $nome) {
            $soci[$i] = $this->creaSocio($nome, 'Paganti');
            $quota = $this->creaQuota($soci[$i], 2025, 30);
            $this->vai('2025-0'.(3 + $i).'-15 10:00:00');
            $this->pagaOffline($this->tesoriere, $quota, '2025-0'.(3 + $i).'-15')->assertSessionHas('success');
        }

        // Una donazione dal mini-sito, confermata da Stripe
        $this->vai('2025-11-20 20:00:00');
        $this->configuraStripe();
        $this->withoutMiddleware(ProtectAgainstSpam::class);
        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')->andReturn('https://checkout.stripe.test/s1');
            $mock->shouldReceive('constructWebhookEvent')->andReturnUsing(fn (string $payload) => json_decode($payload, true));
        });
        $this->esci();
        $this->post($this->urlTenant('dona'), [
            'importo' => '150.00', 'donatore_nome' => 'Fondazione Amici', 'donatore_email' => 'amici@fondazione.test', 'gateway' => 'stripe',
        ])->assertRedirect('https://checkout.stripe.test/s1');
        $donazione = Donazione::query()->sole();
        $this->postJson(route('webhooks.stripe'), [
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_dona', 'payment_intent' => 'pi_dona', 'metadata' => [
                'tipo' => 'donazione', 'pagabile_id' => $donazione->id, 'associazione_id' => $donazione->associazione_id,
            ]]],
        ], ['Stripe-Signature' => 't=1,v1=abc'])->assertOk();

        $movimenti = Movimento::query()->whereYear('data', 2025)->get();
        $this->assertCount(10, $movimenti);
        $this->assertSame(6, $movimenti->whereNull('origine_id')->count());
        $this->assertSame(3, $movimenti->where('origine_type', (new Quota)->getMorphClass())->count());
        $this->assertSame(1, $movimenti->where('origine_type', $donazione->getMorphClass())->count());

        // Marzo 2026: il tesoriere prepara il rendiconto
        $this->vai('2026-03-10 10:00:00');
        $entrateAttese = 1000.00 + 80.00 + 3 * 30.00 + 150.00;
        $usciteAttese = 600.00 + 350.00 + 45.50 + 62.30;
        $report = $this->come($this->tesoriere)->get(route('report-economico.index', ['dal' => '2025-01-01', 'al' => '2025-12-31']))->assertOk();
        $this->assertEqualsWithDelta($entrateAttese, $report->viewData('totaleEntrate'), 0.001);
        $this->assertEqualsWithDelta($usciteAttese, $report->viewData('totaleUscite'), 0.001);
        $this->assertEqualsWithDelta($entrateAttese - $usciteAttese, $report->viewData('saldo'), 0.001);
        $this->assertSame(10, $report->viewData('numeroMovimenti'));

        Excel::fake();
        $this->get(route('report-economico.export', ['dal' => '2025-01-01', 'al' => '2025-12-31']))->assertOk();

        // L'automatico della quota non si tocca
        $automatico = $movimenti->firstWhere('origine_type', (new Quota)->getMorphClass());
        $this->delete(route('prima-nota.destroy', $automatico))->assertSessionHas('error');
        $this->assertNotNull(Movimento::query()->find($automatico->id));

        // Il PDF firmato del rendiconto entra nell'archivio documenti
        $this->post(route('documenti.store'), [
            'document_file' => UploadedFile::fake()->create('rendiconto-2025.pdf', 120, 'application/pdf'),
            'categoria' => 'Bilanci',
            'descrizione' => 'Rendiconto per cassa 2025',
        ])->assertRedirect(route('documenti.index'));
        $this->post(route('documenti.store'), [
            'document_file' => UploadedFile::fake()->create('estratti-conto-2025.pdf', 60, 'application/pdf'),
            'categoria' => 'Riservati',
        ])->assertRedirect(route('documenti.index'));
        $rendiconto = Documento::query()->where('nome_file', 'rendiconto-2025.pdf')->sole();
        $riservato = Documento::query()->where('nome_file', 'estratti-conto-2025.pdf')->sole();

        // 20 aprile: assemblea, votazione, verbale firmato e approvato
        $soci9 = [];
        foreach (range(1, 10) as $n) {
            $soci9[] = $this->creaSocio('Votante'.$n, 'Assemblea');
        }
        $presidenteSocio = $soci9[0];
        $segretarioSocio = $soci9[1];
        foreach ($soci9 as $i => $s) {
            $this->carica($s, $i === 0 ? 'Presidente' : ($i === 1 ? 'Segretario' : 'Consigliere'), '2024-05-01', '2027-04-30');
        }

        $this->vai('2026-04-01 09:00:00');
        $riunione = $this->creaRiunione($this->segretario, 'Assemblea per il rendiconto 2025', '2026-04-20T18:00', ['Approvazione rendiconto 2025']);
        $this->post(route('riunioni.convoca', $riunione))->assertSessionHas('success');

        $this->vai('2026-04-20 20:00:00');
        $this->svolgiRiunione($this->segretario, $riunione, $soci9);
        $this->vota($riunione, 0, VotazioneRiunione::ESITO_APPROVATO, 9, 0, 1, 'Rendiconto 2025 approvato.');
        $verbale = $this->generaVerbale($riunione);

        $this->post(route('verbali.firmatari.store', $verbale), ['socio_id' => $presidenteSocio->id, 'ruolo' => 'Presidente'])->assertRedirect(route('verbali.index'));
        $this->post(route('verbali.firmatari.store', $verbale), ['socio_id' => $segretarioSocio->id, 'ruolo' => 'Segretario'])->assertRedirect(route('verbali.index'));
        foreach ($verbale->firmatari()->get() as $firmatario) {
            $this->post(route('verbali.firmatari.firma', [$verbale, $firmatario]))->assertSessionHas('success');
        }
        $this->come($this->presidente)->post(route('verbali.approva', $verbale))->assertSessionHas('success');

        $votazione = VotazioneRiunione::query()->where('riunione_id', $riunione->id)->sole();
        $this->assertSame([VotazioneRiunione::ESITO_APPROVATO, 9, 0, 1], [$votazione->esito, (int) $votazione->favorevoli, (int) $votazione->contrari, (int) $votazione->astenuti]);
        $verbale->refresh();
        $this->assertSame('1/2026', $verbale->numero_completo);
        $this->assertStringContainsString('rendiconto', mb_strtolower($verbale->deliberazioni));
        $this->assertTrue($verbale->approvato);
        $this->assertSame($this->presidente->id, $verbale->approvato_da);
        $this->assertSame('2026-04-20', $verbale->data_approvazione->toDateString());
        $this->assertSame(2, VerbaleFirmatario::query()->where('verbale_id', $verbale->id)->whereNotNull('firmato_at')->count());

        // 21 aprile: il rendiconto va in Trasparenza e il verbale lo cita
        $this->vai('2026-04-21 09:00:00');
        $this->come($this->segretario)->post(route('documenti.trasparenza', $rendiconto), ['categoria_pubblica' => 'bilancio'])->assertSessionHas('success');
        $this->put(route('verbali.update', $verbale), $this->datiVerbale($verbale, [
            'deliberazioni' => $verbale->deliberazioni."\nIl rendiconto è pubblicato in Trasparenza (rendiconto-2025.pdf).",
        ]))->assertRedirect(route('verbali.index'));
        $this->come($this->presidente)->post(route('verbali.approva', $verbale))->assertSessionHas('success');

        $this->assertTrue($rendiconto->fresh()->pubblico);
        $this->assertSame('bilancio', $rendiconto->fresh()->categoria_pubblica);
        $this->assertStringContainsString('rendiconto-2025.pdf', $verbale->fresh()->deliberazioni);

        $this->esci();
        $this->get(route('pubblico.trasparenza', $this->associazione->slug))->assertOk()
            ->assertSee('rendiconto-2025.pdf')
            ->assertDontSee('estratti-conto-2025.pdf');
        $this->get(route('pubblico.trasparenza.download', [$this->associazione->slug, $rendiconto->id]))->assertOk();
        $this->get(route('pubblico.trasparenza.download', [$this->associazione->slug, $riservato->id]))->assertNotFound();

        $this->come($this->segretario)->get(route('verbali.pdf', $verbale))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    // ==================================================================
    // 5. Rinnovo delle cariche sociali alla fine del triennio
    // ==================================================================

    public function test_buro_05_rinnovo_delle_cariche_sociali_alla_scadenza_del_triennio_assemblea_elettiva_vecchio_direttivo_chiuso_nuovo_direttivo_convocato(): void
    {
        $this->vai('2023-05-10 10:00:00');

        $ruoli = ['Presidente', 'Vicepresidente', 'Segretario', 'Tesoriere', 'Consigliere', 'Consigliere', 'Consigliere'];
        $uscenti = [];
        foreach ($ruoli as $i => $ruolo) {
            $uscenti[$i] = $this->creaSocio('Uscente'.($i + 1), 'Direttivo'.chr(65 + $i), ['data_iscrizione' => '2020-01-10']);
            $this->come($this->segretario)->post(route('organi-sociali.store'), [
                'socio_id' => $uscenti[$i]->id, 'ruolo' => $ruolo, 'inizio' => '2023-05-10', 'fine' => '2026-05-09', 'note' => 'Eletto assemblea 2023',
            ])->assertSessionHasNoErrors()->assertRedirect(route('organi-sociali.index'));
        }

        // Febbraio 2026: la dashboard avvisa che i mandati scadono entro 90 giorni
        $this->vai('2026-02-15 09:00:00');
        $this->assertSame(7, Carica::query()->attive()->count());
        $this->come($this->presidente)->get(route('dashboard'))->assertOk()
            ->assertSee('7 mandati in scadenza')
            ->assertSee($uscenti[0]->nome_completo);

        $this->post(route('adempimenti.seed'))->assertSessionHas('success', 'Aggiunti 4 adempimenti suggeriti.');
        $rinnovoCariche = Adempimento::query()->where('titolo', 'Rinnovo cariche sociali')->sole();

        // 1° maggio: lo scheduler ricorda il rinnovo delle cariche
        $this->vai('2026-05-01 07:00:00');
        $this->artisan('adempimenti:promemoria', ['--mese' => 5])->expectsOutput('Promemoria adempimenti inviati: 1')->assertSuccessful();
        Notification::assertSentToTimes($this->presidente, PromemoriaAdempimentoNotification::class, 1);
        Notification::assertSentToTimes($this->segretario, PromemoriaAdempimentoNotification::class, 1);
        Notification::assertSentTo($this->presidente, PromemoriaAdempimentoNotification::class, fn ($n) => $n->adempimento->is($rinnovoCariche));
        $this->assertDatabaseHas('promemoria_inviati', ['tipo' => PromemoriaInviato::TIPO_ADEMPIMENTO, 'riferimento_id' => $rinnovoCariche->id, 'step' => 202605]);

        // 8 maggio: assemblea elettiva, convocati i 7 uscenti
        $elettiva = $this->creaRiunione($this->segretario, 'Assemblea elettiva 2026', '2026-05-08T18:00', ['Elezione del consiglio direttivo 2026-2029']);
        $this->post(route('riunioni.convoca', $elettiva))->assertSessionHas('success');
        Notification::assertSentTo($uscenti, ConvocazioneRiunioneNotification::class, fn ($n) => $n->riunione->is($elettiva));

        $this->vai('2026-05-08 22:00:00');
        $this->svolgiRiunione($this->segretario, $elettiva, $uscenti);
        $this->vota($elettiva, 0, VotazioneRiunione::ESITO_APPROVATO, 42, 3, 2, 'Eletta la lista unica "Rondine 2029".');
        $verbaleElettivo = $this->generaVerbale($elettiva);
        $this->come($this->presidente)->post(route('verbali.approva', $verbaleElettivo))->assertSessionHas('success');
        $this->assertSame('1/2026', $verbaleElettivo->fresh()->numero_completo);
        $this->assertTrue($verbaleElettivo->fresh()->approvato);

        // 9 maggio: il segretario chiude i mandati uscenti...
        $this->vai('2026-05-09 10:00:00');
        foreach (Carica::query()->get() as $carica) {
            $this->come($this->segretario)->put(route('organi-sociali.update', $carica), [
                'socio_id' => $carica->socio_id, 'ruolo' => $carica->ruolo, 'inizio' => '2023-05-10', 'fine' => '2026-05-09', 'note' => 'Mandato concluso',
            ])->assertSessionHasNoErrors();
        }
        // ...e registra il nuovo direttivo: 5 riconfermati e 2 nuovi
        $nuovo1 = $this->creaSocio('Nuova', 'Consigliera');
        $nuovo2 = $this->creaSocio('Nuovo', 'Consigliere');
        $esclusi = [$uscenti[5], $uscenti[6]];
        $eletti = [$uscenti[0], $uscenti[1], $uscenti[2], $uscenti[3], $uscenti[4], $nuovo1, $nuovo2];
        foreach ($eletti as $i => $socio) {
            $this->post(route('organi-sociali.store'), [
                'socio_id' => $socio->id, 'ruolo' => $ruoli[$i], 'inizio' => '2026-05-10', 'fine' => '2029-05-09',
            ])->assertSessionHasNoErrors();
        }
        // Una carica inserita per errore (vicepresidente sul socio sbagliato) viene eliminata e rifatta
        $errata = Carica::query()->where('socio_id', $uscenti[1]->id)->whereDate('inizio', '2026-05-10')->sole();
        $this->delete(route('organi-sociali.destroy', $errata))->assertRedirect(route('organi-sociali.index'));
        $this->post(route('organi-sociali.store'), [
            'socio_id' => $uscenti[1]->id, 'ruolo' => 'Vicepresidente', 'inizio' => '2026-05-10', 'fine' => '2029-05-09', 'note' => 'Reinserita',
        ])->assertSessionHasNoErrors();

        $this->assertSame(7, Carica::query()->whereDate('fine', '2026-05-09')->count());
        $this->assertSame(7, Carica::query()->whereDate('inizio', '2026-05-10')->count());

        // 1° giugno: il nuovo direttivo si riunisce
        $this->vai('2026-06-01 09:00:00');
        $pagina = $this->come($this->presidente)->get(route('organi-sociali.index'))->assertOk();
        $this->assertCount(7, $pagina->viewData('attive'));
        $this->assertTrue($pagina->viewData('attive')->every(fn (Carica $c) => $c->inizio->toDateString() === '2026-05-10'));
        $this->assertSame(7, $pagina->viewData('storiche')->total());

        $insediamento = $this->creaRiunione($this->segretario, 'Insediamento nuovo direttivo', '2026-06-05T21:00', ['Attribuzione deleghe']);
        $this->post(route('riunioni.convoca', $insediamento))->assertSessionHas('success');
        Notification::assertSentTo($eletti, ConvocazioneRiunioneNotification::class, fn ($n) => $n->riunione->is($insediamento));
        foreach ($esclusi as $exConsigliere) {
            Notification::assertNotSentTo($exConsigliere, ConvocazioneRiunioneNotification::class, fn ($n) => $n->riunione->is($insediamento));
        }
        $partecipanti = RiunionePartecipante::query()->where('riunione_id', $insediamento->id)->get();
        $this->assertEqualsCanonicalizing(collect($eletti)->pluck('id')->all(), $partecipanti->pluck('socio_id')->all());
        $this->assertSame('Consigliere', $partecipanti->firstWhere('socio_id', $nuovo2->id)->carica_ruolo);

        // L'adempimento viene spuntato: il rilancio dello scheduler non manda nulla
        $this->come($this->segretario)->from(route('scadenzario.index'))
            ->post(route('adempimenti.toggle-anno', $rinnovoCariche), ['anno' => 2026])
            ->assertRedirect(route('scadenzario.index'));
        $this->assertDatabaseHas('adempimenti_anni', ['adempimento_id' => $rinnovoCariche->id, 'anno' => 2026, 'completato_da' => $this->segretario->id]);
        $this->artisan('adempimenti:promemoria', ['--mese' => 5])->expectsOutput('Promemoria adempimenti inviati: 0')->assertSuccessful();
        Notification::assertSentToTimes($this->presidente, PromemoriaAdempimentoNotification::class, 1);

        $this->get(route('decisioni.index'))->assertOk()
            ->assertSee('Elezione del consiglio direttivo 2026-2029')
            ->assertSee('Favorevoli 42');
    }

    // ==================================================================
    // 6. Scadenzario e adempimenti di un anno intero
    // ==================================================================

    public function test_buro_06_scadenzario_e_adempimenti_di_un_anno_intero_i_promemoria_arrivano_una_volta_sola_e_al_responsabile_giusto(): void
    {
        $this->vai('2026-01-02 09:00:00');

        // Il presidente prepara la checklist dell'anno
        $this->come($this->presidente)->post(route('adempimenti.seed'))->assertSessionHas('success', 'Aggiunti 4 adempimenti suggeriti.');
        $this->post(route('adempimenti.seed'))->assertSessionHas('success', 'Tutti gli adempimenti suggeriti sono già presenti.');
        $this->post(route('adempimenti.store'), ['titolo' => 'Invio modello EAS', 'mese_scadenza' => 3, 'attivo' => '1'])->assertRedirect(route('adempimenti.index'));
        $this->assertSame(5, Adempimento::query()->count());
        $bilancio = Adempimento::query()->where('titolo', 'Approvazione bilancio annuale')->sole();
        $cariche = Adempimento::query()->where('titolo', 'Rinnovo cariche sociali')->sole();

        $nuovaScadenza = function (string $titolo, string $data, int $giorni, ?Utente $assegnato) {
            $this->come($this->presidente)->post(route('scadenze.store'), [
                'titolo' => $titolo,
                'data_scadenza' => $data,
                'tipo_scadenza' => 'Documenti',
                'priorita' => 'Alta',
                'stato' => Scadenza::STATO_ATTIVA,
                'assegnato_a' => $assegnato?->id ?? '',
                'promemoria_giorni' => $giorni,
            ])->assertSessionHasNoErrors()->assertRedirect(route('scadenze.index'));

            return Scadenza::query()->where('titolo', $titolo)->sole();
        };
        $polizza = $nuovaScadenza('Polizza volontari', '2026-01-31', 10, $this->segretario);
        $dominio = $nuovaScadenza('Rinnovo dominio', '2026-06-30', 30, null);
        $runts = $nuovaScadenza('Deposito bilancio RUNTS', '2026-06-30', 15, $this->tesoriere);

        $notificheScadenza = [];
        $scadenzeOggi = function (string $giorno, int $attesi) use (&$notificheScadenza) {
            $this->vai($giorno.' 06:30:00');
            $this->artisan('scadenze:invia-promemoria')->expectsOutput("Promemoria inviati: {$attesi}")->assertSuccessful();
        };
        $adempimentiDelMese = function (int $mese, int $attesi) {
            $this->vai(sprintf('2026-%02d-01 07:00:00', $mese));
            $this->artisan('adempimenti:promemoria')->expectsOutput("Promemoria adempimenti inviati: {$attesi}")->assertSuccessful();
        };

        // Gennaio: assicurazione; la polizza entra nei 10 giorni il 21
        $adempimentiDelMese(1, 1);
        $scadenzeOggi('2026-01-20', 0);
        $scadenzeOggi('2026-01-21', 1);
        $scadenzeOggi('2026-01-22', 0);
        Notification::assertSentTo($this->segretario, PromemoriaScadenzaNotification::class, fn ($n) => $n->scadenza->is($polizza));
        Notification::assertNotSentTo([$this->presidente, $this->tesoriere], PromemoriaScadenzaNotification::class);

        // Febbraio: niente in calendario; il segretario rinnova la polizza
        $adempimentiDelMese(2, 0);
        $this->vai('2026-02-03 10:00:00');
        $this->come($this->segretario)->post(route('scadenze.completa', $polizza))->assertSessionHas('success');

        // Marzo: EAS. Aprile: bilancio, spuntato dal tesoriere a metà mese
        $adempimentiDelMese(3, 1);
        $adempimentiDelMese(4, 1);
        $this->vai('2026-04-10 17:00:00');
        $this->come($this->tesoriere)->from(route('scadenzario.index'))
            ->post(route('adempimenti.toggle-anno', $bilancio), ['anno' => 2026])
            ->assertRedirect(route('scadenzario.index'));
        $this->vai('2026-04-15 07:00:00');
        $this->artisan('adempimenti:promemoria')->expectsOutput('Promemoria adempimenti inviati: 0')->assertSuccessful();

        // Maggio: cariche, spuntate dopo l'assemblea
        $adempimentiDelMese(5, 1);
        $this->vai('2026-05-20 18:00:00');
        $this->come($this->segretario)->from(route('scadenzario.index'))->post(route('adempimenti.toggle-anno', $cariche), ['anno' => 2026]);
        $this->artisan('adempimenti:promemoria', ['--mese' => 5])->expectsOutput('Promemoria adempimenti inviati: 0')->assertSuccessful();

        // Fine maggio/giugno: dominio (30 gg) a tutti gli admin, RUNTS (15 gg) al tesoriere
        $scadenzeOggi('2026-05-31', 1);
        $scadenzeOggi('2026-06-01', 0);
        $scadenzeOggi('2026-06-15', 1);
        $scadenzeOggi('2026-06-16', 0);

        foreach ([6, 7, 8, 9, 10, 11] as $mese) {
            $adempimentiDelMese($mese, 0);
        }
        // Il dominio viene rinnovato in ritardo, a luglio
        $this->vai('2026-07-02 11:00:00');
        $this->come($this->presidente)->post(route('scadenze.completa', $dominio))->assertSessionHas('success');

        // Dicembre: libro soci
        $adempimentiDelMese(12, 1);
        $adempimentiDelMese(12, 0);

        // Bilancio degli avvisi dell'anno
        foreach ([$this->presidente, $this->segretario] as $admin) {
            Notification::assertSentToTimes($admin, PromemoriaAdempimentoNotification::class, 5);
        }
        Notification::assertNotSentTo($this->tesoriere, PromemoriaAdempimentoNotification::class);
        $this->assertSame(
            [202601, 202603, 202604, 202605, 202612],
            PromemoriaInviato::query()->where('tipo', PromemoriaInviato::TIPO_ADEMPIMENTO)->orderBy('step')->pluck('step')->map(fn ($s) => (int) $s)->all()
        );

        Notification::assertSentToTimes($this->segretario, PromemoriaScadenzaNotification::class, 2); // polizza + dominio
        Notification::assertSentToTimes($this->presidente, PromemoriaScadenzaNotification::class, 1); // dominio
        Notification::assertSentToTimes($this->tesoriere, PromemoriaScadenzaNotification::class, 1); // RUNTS
        Notification::assertSentTo($this->tesoriere, PromemoriaScadenzaNotification::class, fn ($n) => $n->scadenza->is($runts));
        $this->assertSame('2026-01-21', $polizza->fresh()->promemoria_inviato_at->toDateString());
        $this->assertSame('2026-05-31', $dominio->fresh()->promemoria_inviato_at->toDateString());
        $this->assertSame('2026-06-15', $runts->fresh()->promemoria_inviato_at->toDateString());

        $this->assertSame([$this->segretario->id, '2026-02-03'], [$polizza->fresh()->completata_da, $polizza->fresh()->completata_at->toDateString()]);
        $this->assertSame([$this->presidente->id, '2026-07-02'], [$dominio->fresh()->completata_da, $dominio->fresh()->completata_at->toDateString()]);
        $this->assertSame(Scadenza::STATO_ATTIVA, $runts->fresh()->stato);

        // A fine anno: export CSV, scadenzario e checklist
        $this->vai('2026-12-30 16:00:00');
        $csv = $this->come($this->presidente)->get(route('scadenze.esporta-csv'))->assertOk()->streamedContent();
        $righe = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(4, $righe, 'Intestazione + 3 scadenze');
        $this->assertStringContainsString('"Polizza volontari";;31/01/2026;Documenti;Alta;Completata;"Sergio Segretari";10', $csv);
        $this->assertStringContainsString('"Rinnovo dominio";;30/06/2026;Documenti;Alta;Completata;"Non assegnato";30', $csv);
        $this->assertStringContainsString('"Deposito bilancio RUNTS";;30/06/2026;Documenti;Alta;Attiva;"Teresa Tesorieri";15', $csv);

        $voci = $this->get(route('scadenzario.index', ['dal' => '2026-06-01', 'al' => '2026-12-31']))->assertOk()->viewData('voci');
        $this->assertTrue($voci->contains(fn ($v) => $v['titolo'] === 'Deposito bilancio RUNTS'));
        $this->assertFalse($voci->contains(fn ($v) => $v['titolo'] === 'Rinnovo dominio'));

        $this->get(route('adempimenti.index'))->assertOk()->assertSee('Invio modello EAS')->assertSee('Aggiornamento libro soci');
        $this->assertSame(2, AdempimentoAnno::query()->where('anno', 2026)->whereNotNull('completato_at')->count());
        $this->assertTrue($bilancio->fresh()->isCompletatoPerAnno(2026));
        $this->assertSame($this->tesoriere->id, AdempimentoAnno::query()->where('adempimento_id', $bilancio->id)->sole()->completato_da);
    }

    // ==================================================================
    // 7. Esclusione per morosità
    // ==================================================================

    public function test_buro_07_esclusione_per_morosita_deliberata_dal_direttivo_dopo_solleciti_documentati_con_libro_soci_e_statistiche_aggiornati(): void
    {
        $this->vai('2026-01-10 09:00:00');

        $soci = [];
        foreach (['Ada', 'Bea', 'Ciro', 'Dina', 'Elio', 'Febo', 'Gaia', 'Ivo'] as $nome) {
            $soci[$nome] = $this->creaSocio($nome, 'Rondinelli', ['email' => strtolower($nome).'@soci.test', 'data_iscrizione' => '2023-03-01']);
            $this->creaTessera($soci[$nome], 2026);
        }
        $this->carica($soci['Ada'], 'Presidente', '2025-01-01', '2027-12-31');
        $this->carica($soci['Bea'], 'Consigliere', '2025-01-01', '2027-12-31');

        $this->come($this->segretario)->post(route('apertura-anno.genera'), ['anno' => 2026])
            ->assertSessionHas('success', 'Anno 2026 aperto: generate 8 quote per i soci attivi.');
        $quote = collect($soci)->map(fn (Socio $s) => Quota::query()->where('socio_id', $s->id)->where('anno', 2026)->sole());

        // Cinque pagano subito; Febo, Gaia e Ivo no
        foreach (['Ada', 'Bea', 'Ciro', 'Dina', 'Elio'] as $i => $nome) {
            $this->vai('2026-02-'.(10 + $i).' 10:00:00');
            $this->pagaOffline($this->tesoriere, $quote[$nome], '2026-02-'.(10 + $i))->assertSessionHas('success');
        }
        $morosi = ['Febo', 'Gaia', 'Ivo'];

        // Primo e secondo giro di solleciti
        foreach (['2026-03-01', '2026-05-01'] as $giorno) {
            $this->vai($giorno.' 09:00:00');
            $this->come($this->tesoriere)->post(route('solleciti-quote.bulk'), [
                'quota_ids' => collect($morosi)->map(fn ($n) => $quote[$n]->id)->all(),
            ])->assertSessionHas('success', 'Solleciti inviati: 3.');
        }
        foreach ($morosi as $nome) {
            Notification::assertSentToTimes($soci[$nome], SollecitoQuotaNotification::class, 2);
        }
        $this->assertSame(6, PromemoriaInviato::query()->where('tipo', SollecitoQuoteService::TIPO_SOLLECITO_QUOTA)->where('step', 2026)->count());

        $solleciti = $this->come($this->tesoriere)->get(route('solleciti-quote.index'))->assertOk()->viewData('solleciti');
        foreach ($morosi as $nome) {
            $this->assertSame(2, $solleciti[$quote[$nome]->id]['conteggio']);
        }

        // Febo paga dopo il secondo sollecito
        $this->vai('2026-05-10 10:00:00');
        $this->pagaOffline($this->tesoriere, $quote['Febo'], '2026-05-10')->assertSessionHas('success');
        $this->assertTrue($quote['Febo']->fresh()->isPagata());
        $this->assertDatabaseHas('ricevute', ['quota_id' => $quote['Febo']->id]);
        $espulsi = [$soci['Gaia'], $soci['Ivo']];

        // Settembre: il direttivo delibera l'esclusione
        $this->vai('2026-09-10 09:00:00');
        $riunione = $this->creaRiunione($this->segretario, 'Direttivo di settembre', '2026-09-15T21:00', ['Esclusione soci morosi art. 7 dello statuto']);
        $this->post(route('riunioni.convoca', $riunione))->assertSessionHas('success');
        $this->vai('2026-09-15 23:00:00');
        $this->svolgiRiunione($this->segretario, $riunione, [$soci['Ada'], $soci['Bea']]);
        $this->vota($riunione, 0, VotazioneRiunione::ESITO_APPROVATO, 2, 0, 0, 'Esclusi Gaia e Ivo Rondinelli per mancato versamento della quota 2026.');
        $verbale = $this->generaVerbale($riunione);
        $this->come($this->presidente)->post(route('verbali.approva', $verbale))->assertSessionHas('success');

        $this->vai('2026-09-16 10:00:00');
        foreach ($espulsi as $socio) {
            $this->come($this->segretario)->post(route('cessazioni.store'), [
                'socio_id' => $socio->id,
                'tipo' => Cessazione::TIPO_DECADENZA,
                'data' => '2026-09-16',
                'motivo' => 'morosità: quota 2026 non versata dopo due solleciti',
                'delibera_numero' => $verbale->numero_completo,
                'delibera_data' => '2026-09-15',
            ])->assertSessionHasNoErrors()->assertRedirect();
        }

        $cessazioni = Cessazione::query()->get();
        $this->assertCount(2, $cessazioni);
        $this->assertTrue($cessazioni->every(fn (Cessazione $c) => $c->tipo === Cessazione::TIPO_DECADENZA
            && $c->delibera_numero === '1/2026'
            && $c->delibera_data->toDateString() === '2026-09-15'
            && $c->registrata_da === $this->segretario->id));
        foreach ($espulsi as $socio) {
            $this->assertSame(Socio::STATO_RADIATO, $socio->fresh()->stato);
            $this->assertSame(Tessera::STATO_ANNULLATA, Tessera::query()->where('socio_id', $socio->id)->where('anno_validita', 2026)->sole()->stato);
            // La quota del periodo resta non pagata: è il debito che ha motivato l'esclusione
            $this->assertFalse(Quota::query()->where('socio_id', $socio->id)->where('anno', 2026)->sole()->isPagata());
        }

        // Dicembre: i promemoria di rinnovo vanno solo ai sei rimasti
        $this->vai('2026-12-02 08:00:00');
        $this->artisan('rinnovi:promemoria')->expectsOutput('Promemoria rinnovo inviati: 6')->assertSuccessful();
        Notification::assertNotSentTo($espulsi, PromemoriaRinnovoNotification::class);

        // 31 dicembre: statistiche e libro soci
        $this->vai('2026-12-31 12:00:00');
        $dati = $this->come($this->presidente)->get(route('statistiche-annuali.index', ['anno' => 2026]))->assertOk()->viewData('dati');
        $this->assertSame(2, $dati['corrente']['cessazioni']);
        $this->assertEqualsWithDelta(6 * 30.0, $dati['corrente']['entrate'], 0.001);
        $this->assertSame(6, Socio::query()->where('stato', Socio::STATO_ATTIVO)->count());

        $righe = collect($this->get(route('libro-soci.index'))->assertOk()->assertSee('16/09/2026')->viewData('righe'));
        $this->assertCount(8, $righe);
        $conCessazione = $righe->filter(fn (array $r) => $r['cessazione'] !== null);
        $this->assertEqualsCanonicalizing([$soci['Gaia']->id, $soci['Ivo']->id], $conCessazione->map(fn ($r) => $r['socio']->id)->values()->all());
        $this->assertTrue($conCessazione->every(fn (array $r) => $r['cessazione']->data->toDateString() === '2026-09-16'));

        // Il tesoriere rilancia i solleciti selezionando tutto l'elenco dei morosi:
        // i soci esclusi per morosità non devono più ricevere solleciti di pagamento
        $elenco = collect($this->come($this->tesoriere)->get(route('solleciti-quote.index'))->assertOk()->viewData('quote')->items());
        $this->post(route('solleciti-quote.bulk'), ['quota_ids' => $elenco->pluck('id')->all()]);
        Notification::assertSentToTimes($soci['Febo'], SollecitoQuotaNotification::class, 2);
        $idEspulsi = [$soci['Gaia']->id, $soci['Ivo']->id];
        $this->assertSame(
            ['quote_radiati_in_elenco' => 0, 'solleciti_ricevuti_dai_radiati' => 4],
            [
                'quote_radiati_in_elenco' => $elenco->filter(fn (Quota $q) => in_array($q->socio_id, $idEspulsi, true))->count(),
                'solleciti_ricevuti_dai_radiati' => PromemoriaInviato::query()->where('tipo', SollecitoQuoteService::TIPO_SOLLECITO_QUOTA)->whereIn('socio_id', $idEspulsi)->count(),
            ],
            'Dopo l\'esclusione per morosità i soci radiati restano nell\'elenco dei solleciti e ricevono un terzo "la tua quota risulta ancora da pagare" '
            .'(SollecitoQuoteController::index e SollecitoQuoteService::invia non controllano lo stato del socio).'
        );
    }

    // ==================================================================
    // 8. La segreteria cambia di mano
    // ==================================================================

    public function test_buro_08_la_segreteria_cambia_di_mano_invito_del_nuovo_segretario_passaggio_di_consegne_e_revoca_del_vecchio_senza_perdere_lo_storico(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create(['email' => 'super@piattaforma.test']);
        $superAdmin->assignRole(Utente::RUOLO_SUPER_ADMIN);

        // Il vecchio segretario Luigi è un operatore con il ruolo associativo di segretario
        $this->vai('2026-01-15 10:00:00');
        $luigi = $this->creaStaff('Luigi', 'Uscenti', ['segretario']);

        $verbali = [];
        foreach (['Direttivo di gennaio', 'Direttivo di marzo'] as $i => $titolo) {
            $this->vai('2026-0'.(1 + 2 * $i).'-20 21:00:00');
            $this->come($luigi)->post(route('verbali.store'), [
                'titolo' => $titolo, 'data_riunione' => now()->toDateString(), 'tipo_riunione' => 'Consiglio Direttivo',
                'presenti' => 'Consiglio al completo', 'deliberazioni' => 'Approvato il programma.',
            ])->assertSessionHasNoErrors();
            $verbali[$i] = Verbale::query()->where('titolo', $titolo)->sole();
            $this->come($this->presidente)->post(route('verbali.approva', $verbali[$i]))->assertSessionHas('success');
        }
        $scadenze = [];
        foreach (['Rinnovo convenzione Comune' => '2026-06-25', 'Invio 5x1000' => '2026-07-10', 'Rendicontazione bando' => '2026-09-30'] as $titolo => $data) {
            $this->come($luigi)->post(route('scadenze.store'), [
                'titolo' => $titolo, 'data_scadenza' => $data, 'tipo_scadenza' => 'Generale', 'priorita' => 'Media',
                'stato' => Scadenza::STATO_ATTIVA, 'assegnato_a' => $luigi->id, 'promemoria_giorni' => 5,
            ])->assertSessionHasNoErrors();
            $scadenze[$titolo] = Scadenza::query()->where('titolo', $titolo)->sole();
        }
        $attivita = [];
        foreach (['Ritirare il nuovo timbro' => '2026-06-20', 'Aggiornare l\'albo dei volontari' => '2026-06-22'] as $titolo => $data) {
            $this->come($luigi)->post(route('attivita-admin.store'), [
                'titolo' => $titolo, 'scadenza' => $data, 'assegnatario_id' => $luigi->id, 'collegamento' => 'nessuno',
            ])->assertSessionHas('success', 'Attività creata.');
            $attivita[$titolo] = AttivitaAdmin::query()->where('titolo', $titolo)->sole();
        }
        $richiesta = $this->cittadinoChiedeIscrizione('Olga', 'Nuovissimi');

        // 1° giugno: la piattaforma invita Marta come amministratrice
        $this->vai('2026-06-01 09:00:00');
        $this->come($superAdmin)->withSession(['associazione_id' => $this->associazione->id])->post(route('amministratori.store'), [
            'nome' => 'Marta', 'cognome' => 'Subentri', 'email' => 'marta.subentri@larondine.test',
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE, 'associazione_id' => $this->associazione->id, 'invia_invito' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('amministratori.index'));
        $marta = Utente::query()->withoutGlobalScopes()->where('email', 'marta.subentri@larondine.test')->sole();

        // Marta non risponde: l'8 giugno l'invito viene reinviato
        $this->vai('2026-06-08 09:00:00');
        $this->post(route('amministratori.reinvia-invito', $marta))->assertSessionHas('success', 'Invito reinviato.');
        $urlInvito = null;
        Mail::assertQueued(InvitoAmministratoreMail::class, 2);
        Mail::assertQueued(InvitoAmministratoreMail::class, function (InvitoAmministratoreMail $m) use (&$urlInvito, $marta) {
            $urlInvito = $m->urlInvito;

            return $m->hasTo('marta.subentri@larondine.test') && $m->utente->is($marta);
        });

        $this->esci();
        $this->get((string) $urlInvito)->assertOk()->assertSee('marta.subentri@larondine.test');
        $this->post((string) $urlInvito, ['password' => 'Mirtillo-Fiordo-Tetto-61', 'password_confirmation' => 'Mirtillo-Fiordo-Tetto-61'])
            ->assertRedirect(route('login'));
        $this->esci();
        $this->post('/login', ['email' => 'marta.subentri@larondine.test', 'password' => 'Mirtillo-Fiordo-Tetto-61'])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($marta);
        $marta->refresh();
        $this->assertTrue($marta->attivo);
        $this->assertTrue($marta->hasRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE));
        $this->assertNotNull($marta->password_hash);

        // 10 giugno: passaggio di consegne
        $this->vai('2026-06-10 10:00:00');
        foreach ($scadenze as $scadenza) {
            $this->come($this->presidente)->put(route('scadenze.update', $scadenza), [
                'titolo' => $scadenza->titolo, 'data_scadenza' => $scadenza->data_scadenza->toDateString(), 'tipo_scadenza' => $scadenza->tipo_scadenza,
                'priorita' => $scadenza->priorita, 'stato' => Scadenza::STATO_ATTIVA, 'assegnato_a' => $marta->id, 'promemoria_giorni' => 5,
            ])->assertSessionHasNoErrors();
        }
        foreach ($attivita as $voce) {
            $this->put(route('attivita-admin.update', $voce), [
                'titolo' => $voce->titolo, 'scadenza' => $voce->scadenza->toDateString(), 'assegnatario_id' => $marta->id, 'collegamento' => 'nessuno',
            ])->assertSessionHas('success', 'Attività aggiornata.');
        }
        // Luigi passa a consigliere: sola consultazione
        $this->put(route('ruoli.update', $luigi), ['ruoli' => ['consigliere']])->assertRedirect(route('ruoli.index'));
        $this->come($luigi->fresh())->post(route('richieste-iscrizione.approva', $richiesta), ['genera_quota' => '0'])->assertForbidden();
        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiesta->fresh()->stato);

        // 15 giugno: l'account di Luigi viene disattivato (lo storico resta)
        $this->vai('2026-06-15 10:00:00');
        $this->come($superAdmin)->withSession(['associazione_id' => $this->associazione->id])->put(route('amministratori.update', $luigi), [
            'nome' => 'Luigi', 'cognome' => 'Uscenti', 'email' => $luigi->email, 'ruolo' => Utente::RUOLO_OPERATORE,
            'associazione_id' => $this->associazione->id, 'attivo' => '0',
        ])->assertSessionHasNoErrors()->assertRedirect(route('amministratori.index'));
        $this->assertFalse($luigi->fresh()->attivo);
        $this->esci();
        $this->post('/login', ['email' => $luigi->email, 'password' => self::PASSWORD])->assertSessionHasErrors();
        $this->assertGuest();

        $this->assertSame(3, Scadenza::query()->where('assegnato_a', $marta->id)->count());
        $this->assertSame(2, AttivitaAdmin::query()->where('assegnatario_id', $marta->id)->count());

        // Lo scheduler lavora per Marta
        $this->vai('2026-06-20 07:00:00');
        $this->artisan('scadenze:invia-promemoria')->expectsOutput('Promemoria inviati: 1')->assertSuccessful();
        $this->artisan('attivita:promemoria')->expectsOutput('Promemoria attività inviati: 1')->assertSuccessful();
        $this->vai('2026-06-22 07:00:00');
        $this->artisan('attivita:promemoria')->expectsOutput('Promemoria attività inviati: 1')->assertSuccessful();
        Notification::assertSentTo($marta, PromemoriaScadenzaNotification::class, fn ($n) => $n->scadenza->is($scadenze['Rinnovo convenzione Comune']));
        Notification::assertSentToTimes($marta, PromemoriaAttivitaNotification::class, 2);
        Notification::assertNotSentTo($luigi, PromemoriaScadenzaNotification::class);
        Notification::assertNotSentTo($luigi, PromemoriaAttivitaNotification::class);

        // Marta è operativa: approva la domanda pendente e verbalizza
        $this->come($marta)->post(route('richieste-iscrizione.approva', $richiesta), ['genera_quota' => '1'])->assertSessionHas('success');
        $this->assertSame($marta->id, $richiesta->fresh()->gestita_da);
        $this->post(route('verbali.store'), [
            'titolo' => 'Direttivo di giugno', 'data_riunione' => '2026-06-22', 'tipo_riunione' => 'Consiglio Direttivo',
            'deliberazioni' => 'Presa d\'atto del cambio di segreteria.',
        ])->assertSessionHas('success', 'Verbale n. 3/2026 aggiunto con successo!');

        // I verbali di Luigi restano con il loro storico
        $this->get(route('verbali.index'))->assertOk()->assertSee('Direttivo di gennaio')->assertSee('Direttivo di marzo');
        foreach ($verbali as $verbale) {
            $this->assertSame($this->presidente->id, $verbale->fresh()->approvato_da);
        }

        // La presidente controlla il registro modifiche
        $registro = $this->come($this->presidente)->get(route('registro-modifiche.index', ['modello' => 'Scadenza']))->assertOk();
        $voci = collect($registro->viewData('attivita')->items());
        $cambi = fn ($a) => collect($a->attribute_changes ?? $a->properties ?? []);
        $riassegnazioni = $voci->filter(fn ($a) => ($cambi($a)['attributes']['assegnato_a'] ?? null) === $marta->id);
        $this->assertCount(3, $riassegnazioni);
        $this->assertTrue($riassegnazioni->every(fn ($a) => $a->causer_id === $this->presidente->id));
        $this->assertTrue($riassegnazioni->every(fn ($a) => ($cambi($a)['old']['assegnato_a'] ?? null) === $luigi->id));
        $diPresidente = collect($this->get(route('registro-modifiche.index', ['utente_id' => $this->presidente->id]))->assertOk()->assertSee('Paola Presidenti')->viewData('attivita')->items());
        $this->assertTrue($diPresidente->every(fn ($a) => $a->causer_id === $this->presidente->id));
        $this->assertGreaterThanOrEqual(5, $diPresidente->count(), '3 scadenze + 2 attività riassegnate');
    }

    // ==================================================================
    // 9. Campagna rinnovi automatica dallo scheduler
    // ==================================================================

    public function test_buro_09_campagna_rinnovi_automatica_dallo_scheduler_con_template_email_del_tenant_coda_smaltita_e_log_per_ogni_socio(): void
    {
        $this->vai('2026-11-25 10:00:00');
        $this->configuraSmtp(null, ['auto_scadenza_tessera' => true, 'auto_scadenza_quota' => true]);
        $this->associazione->tesseramento()->aggiorna(['giorni_notifica_scadenza' => 30]);
        foreach ([
            'scadenza_tessera' => ['Tessera {NUMERO_TESSERA} in scadenza', '<p>Ciao {NOME}, la tua tessera {ANNO_VALIDITA} scade il {DATA_SCADENZA}. Rinnova: {LINK_RINNOVO}</p>'],
            'scadenza_quota' => ['Quota {ANNO} da versare', '<p>Ciao {NOME}, la quota {ANNO} di {IMPORTO} euro scade il {DATA_SCADENZA}.</p>'],
        ] as $codice => [$oggetto, $corpo]) {
            \App\Models\EmailTemplate::create([
                'associazione_id' => $this->associazione->id, 'codice' => $codice, 'nome' => $codice,
                'oggetto' => $oggetto, 'corpo_html' => $corpo, 'attivo' => true,
            ]);
        }

        $soci = [];
        foreach (range(1, 12) as $n) {
            $soci[$n] = $this->creaSocio('Socio'.$n, 'Tesserati', ['email' => "socio{$n}@soci.test"]);
            $this->creaTessera($soci[$n], 2026);
        }
        $soci[10]->update(['email_opt_out' => true]);
        $soci[11]->update(['email' => '']);
        $rinnovato = $soci[12];
        $this->creaTessera($rinnovato, 2027, ['data_emissione' => '2026-11-20']);
        // Tre soci devono ancora la quota 2026 (scadenza 31/12)
        $quoteAperte = [];
        foreach ([1, 2, 3] as $n) {
            $quoteAperte[$n] = $this->creaQuota($soci[$n], 2026, 30);
        }
        foreach ([4, 5, 6, 7, 8, 9, 10, 11, 12] as $n) {
            $this->creaQuota($soci[$n], 2026, 30, ['data_pagamento' => '2026-02-01', 'stato' => Quota::STATO_PAGATA]);
        }
        $this->come($this->segretario)->post(route('apertura-anno.genera'), ['anno' => 2027])->assertSessionHas('success');

        $contattabili = array_filter($soci, fn (Socio $s) => ! in_array($s->id, [$soci[10]->id, $soci[11]->id], true));
        $daAvvisare = array_filter($contattabili, fn (Socio $s) => $s->id !== $rinnovato->id);
        $this->assertCount(9, $daAvvisare);

        // 2 dicembre: lo scheduler accoda i promemoria
        $this->vai('2026-12-02 06:00:00');
        $this->artisan('emails:send-reminders')->assertSuccessful();

        $tessere = EmailQueue::query()->where('template_codice', 'scadenza_tessera')->get();
        $quote = EmailQueue::query()->where('template_codice', 'scadenza_quota')->get();
        $this->assertCount(3, $quote, 'Promemoria quota ai tre soci con quota 2026 aperta');
        $this->assertSame(1, $tessere->pluck('batch_id')->unique()->count());
        $this->assertSame(1, $quote->pluck('batch_id')->unique()->count());
        $this->assertNotSame($tessere->first()->batch_id, $quote->first()->batch_id);
        $this->assertStringContainsString('Ciao Socio1', $tessere->firstWhere('socio_id', $soci[1]->id)->body_html);
        $this->assertNull($tessere->firstWhere('socio_id', $soci[10]->id));
        $this->assertNull($tessere->firstWhere('socio_id', $soci[11]->id));
        $avvisatiTessera = $tessere->pluck('socio_id')->all();
        foreach ($daAvvisare as $socio) {
            $this->assertContains($socio->id, $avvisatiTessera);
        }
        foreach ($daAvvisare as $socio) {
            $socio->refresh();
            $this->assertNotNull($socio->rinnovo_token);
            $this->assertTrue($socio->rinnovo_token_expires->isFuture());
        }

        // 3 dicembre: lo scheduler ripassa, la finestra anti-duplicato blocca tutto
        $this->vai('2026-12-03 06:00:00');
        $righePrima = EmailQueue::query()->count();
        $this->artisan('emails:send-reminders')->expectsOutputToContain('Totale promemoria accodati: 0.')->assertSuccessful();
        $this->assertSame($righePrima, EmailQueue::query()->count());

        // La coda viene smaltita con l'SMTP del tenant
        $invii = [];
        $this->mailerFinto($invii);
        $this->artisan('emails:process-queue', ['--sync' => true])->assertSuccessful();
        $this->assertSame(0, EmailQueue::query()->where('status', EmailQueue::STATUS_PENDING)->count());
        $this->assertSame($righePrima, EmailQueue::query()->where('status', EmailQueue::STATUS_SENT)->count());
        $this->assertSame($righePrima, EmailLog::query()->where('status', EmailLog::STATUS_SENT)->count());
        $this->assertCount($righePrima, $invii);
        $this->assertNotContains('socio10@soci.test', $invii);
        foreach (EmailQueue::query()->get() as $riga) {
            $this->assertTrue(EmailLog::query()->where('queue_id', $riga->id)->exists(), 'Ogni riga inviata ha il suo log');
        }

        // 20 dicembre: due dei tre morosi pagano in sede
        $this->vai('2026-12-20 10:00:00');
        $this->pagaOffline($this->tesoriere, $quoteAperte[1], '2026-12-20')->assertSessionHas('success');
        $this->pagaOffline($this->tesoriere, $quoteAperte[2], '2026-12-20', 'bonifico')->assertSessionHas('success');

        // 5 gennaio: la quota 2026 del terzo è scaduta e torna nel promemoria (oltre la finestra di 30 giorni)
        $this->vai('2027-01-05 06:00:00');
        $this->artisan('emails:send-reminders')->assertSuccessful();
        $nuoveQuota = EmailQueue::query()->where('template_codice', 'scadenza_quota')->where('status', EmailQueue::STATUS_PENDING)->get();
        $this->assertSame([$soci[3]->id], $nuoveQuota->pluck('socio_id')->all());
        $this->assertSame(0, EmailQueue::query()->whereIn('socio_id', [$soci[10]->id, $soci[11]->id])->count(), 'Mai email a opt-out e senza email');

        // L'admin consulta log e coda
        $this->come($this->presidente)->get(route('email-log.index'))->assertOk()->assertSee('socio1@soci.test');
        $this->get(route('email-coda.index'))->assertOk()->assertSee('socio3@soci.test');

        // Comportamento documentato (spec email §9.1): il cron legacy seleziona per
        // tessera Attiva in scadenza + socio Attivo, senza guardare se esiste già
        // la tessera dell'anno dopo. Il socio già rinnovato riceve quindi il promemoria
        // (il PromemoriaRinnoviService, invece, lo esclude: vedi test 25).
        $this->assertContains($rinnovato->id, $avvisatiTessera);
        $this->assertCount(10, $avvisatiTessera);
    }

    // ==================================================================
    // 10. Chiusura dell'anno sociale
    // ==================================================================

    public function test_buro_10_chiusura_dell_anno_sociale_libro_soci_aggiornato_tessere_scadute_stampa_massiva_e_statistiche_di_confronto_tra_due_anni(): void
    {
        $this->vai('2025-01-10 10:00:00');
        $soci = [];
        foreach (range(1, 8) as $n) {
            $soci[] = $this->creaSocio('Storico'.$n, 'Fondatori', ['data_iscrizione' => '2023-0'.min($n, 9).'-01']);
        }
        $dimissionario = $this->creaSocio('Dario', 'Dimissionari', ['data_iscrizione' => '2022-05-01']);

        // Due ammissioni del 2025 con delibera
        foreach ([['Nadia', 'Nuovi', '2025-03-10'], ['Oscar', 'Nuovi', '2025-06-20']] as [$nome, $cognome, $data]) {
            $this->vai($data.' 10:00:00');
            $richiesta = $this->cittadinoChiedeIscrizione($nome, $cognome);
            $this->come($this->segretario)->post(route('richieste-iscrizione.approva', $richiesta), [
                'genera_quota' => '0', 'delibera_numero' => $data === '2025-03-10' ? '2/2025' : '5/2025', 'delibera_data' => $data,
            ])->assertSessionHas('success');
            $soci[] = Socio::query()->findOrFail($richiesta->fresh()->socio_id);
        }
        $this->assertCount(10, $soci, "soci");

        // Quote 2025 tutte pagate, tessere 2025 emesse
        $this->vai('2025-07-01 10:00:00');
        foreach ([...$soci, $dimissionario] as $socio) {
            $this->creaTessera($socio, 2025);
            $quota = $this->creaQuota($socio, 2025, 30);
            $this->pagaOffline($this->tesoriere, $quota, '2025-07-01')->assertSessionHas('success');
        }
        // Sei eventi con presenze (3 soci a evento)
        foreach (range(1, 6) as $n) {
            $evento = Evento::factory()->create(['associazione_id' => $this->associazione->id, 'titolo' => "Serata sociale {$n}", 'data_evento' => "2025-0{$n}-15 21:00:00"]);
            foreach (array_slice($soci, $n - 1, 3) as $socio) {
                EventoPartecipante::create(['associazione_id' => $this->associazione->id, 'evento_id' => $evento->id, 'socio_id' => $socio->id,
                    'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO, 'data_conferma' => $evento->data_evento]);
            }
        }
        // Dario si dimette a ottobre
        $this->vai('2025-10-05 10:00:00');
        $this->come($this->segretario)->post(route('cessazioni.store'), [
            'socio_id' => $dimissionario->id, 'tipo' => Cessazione::TIPO_DIMISSIONI, 'data' => '2025-10-05', 'motivo' => 'Trasferimento all\'estero',
        ])->assertSessionHasNoErrors();
        $this->post(route('adempimenti.seed'));
        $libroSoci = Adempimento::query()->where('titolo', 'Aggiornamento libro soci')->sole();

        // 2 gennaio 2026: apertura del nuovo anno
        $this->vai('2026-01-02 09:00:00');
        $this->come($this->segretario)->post(route('apertura-anno.genera'), ['anno' => 2026])
            ->assertSessionHas('success', 'Anno 2026 aperto: generate 10 quote per i soci attivi.');
        $paganti = array_slice($soci, 0, 6);
        foreach ($paganti as $i => $socio) {
            $this->vai('2026-01-'.sprintf('%02d', 5 + $i).' 10:00:00');
            $quota = Quota::query()->where('socio_id', $socio->id)->where('anno', 2026)->sole();
            $this->pagaOffline($this->tesoriere, $quota, now()->toDateString())->assertSessionHas('success');
        }

        // Le tessere 2025 non cambiano stato da sole: restano "Attiva" con la data passata
        $tessere2025 = Tessera::query()->where('anno_validita', 2025)->get();
        $this->assertSame(10, $tessere2025->where('stato', Tessera::STATO_ATTIVA)->count());
        $this->assertSame(1, $tessere2025->where('stato', Tessera::STATO_ANNULLATA)->count());
        $this->assertTrue($tessere2025->where('stato', Tessera::STATO_ATTIVA)->every(fn (Tessera $t) => $t->isScadutaEffettiva()));

        // 15 gennaio: generazione massiva delle tessere 2026
        $this->vai('2026-01-15 10:00:00');
        $giaEmesse = Tessera::query()->where('anno_validita', 2026)->pluck('numero_tessera')->all();
        $this->come($this->segretario)->post(route('tessere.genera-massivo'), ['anno_validita' => 2026])
            ->assertRedirect(route('tessere.index', ['anno' => 2026]))->assertSessionHas('success');
        $nuove = Tessera::query()->where('anno_validita', 2026)->whereNotIn('numero_tessera', $giaEmesse)->orderBy('numero_tessera')->get();
        // La generazione massiva emette la tessera a tutti i soci attivi, non solo a chi ha pagato
        $this->assertCount(10 - count($giaEmesse), $nuove, "nuove");
        $this->assertSame(10, Tessera::query()->where('anno_validita', 2026)->count());
        $numeri = Tessera::query()->where('anno_validita', 2026)->pluck('numero_tessera');
        $this->assertSame($numeri->count(), $numeri->unique()->count(), 'Numeri tessera senza collisioni');
        $this->assertTrue($numeri->every(fn ($n) => str_starts_with((string) $n, '2026')));
        $this->post(route('tessere.genera-massivo'), ['anno_validita' => 2026])->assertSessionHas('success', 'Generate 0 nuove tessere per l\'anno 2026.');
        $this->assertSame(10, Tessera::query()->where('anno_validita', 2026)->count());

        $stampa = $this->get(route('tessere.stampa-massiva', ['anno' => 2026]))->assertOk();
        $this->assertStringContainsString('pdf', (string) $stampa->headers->get('content-type'));

        // 31 gennaio: statistiche di confronto
        $this->vai('2026-01-31 18:00:00');
        $dati = $this->come($this->presidente)->get(route('statistiche-annuali.index', ['anno' => 2026]))->assertOk()->viewData('dati');
        $this->assertSame(2025, $dati['anno_precedente']);
        $this->assertSame(2, $dati['precedente']['nuovi_soci']);
        $this->assertSame(1, $dati['precedente']['cessazioni']);
        $this->assertSame(11, $dati['precedente']['tessere']);
        $this->assertSame(18, $dati['precedente']['partecipazioni']);
        $this->assertEqualsWithDelta(11 * 30.0, $dati['precedente']['entrate'], 0.001);
        $this->assertEqualsWithDelta(6 * 30.0, $dati['corrente']['entrate'], 0.001);
        $this->assertEqualsWithDelta(round((180 - 330) / 330 * 100, 1), $dati['variazioni']['entrate'], 0.001);
        $this->assertEqualsWithDelta(round((10 - 11) / 11 * 100, 1), $dati['variazioni']['tessere'], 0.001);
        $this->get(route('statistiche-annuali.pdf', ['anno' => 2026]))->assertOk();

        // Il segretario chiude l'adempimento del libro soci 2025 e scarica il registro
        $this->come($this->segretario)->from(route('scadenzario.index'))->post(route('adempimenti.toggle-anno', $libroSoci), ['anno' => 2025]);
        $this->assertTrue($libroSoci->fresh()->isCompletatoPerAnno(2025));
        $righe = collect($this->get(route('libro-soci.index', ['anno' => 2025]))->assertOk()->viewData('righe'));
        $this->assertSame(['Nadia' => 1, 'Oscar' => 2], $righe->mapWithKeys(fn ($r) => [$r['socio']->nome => $r['progressivo']])->all());
        $this->assertSame(['2/2025', '5/2025'], $righe->pluck('delibera_numero')->all());
        $tutte = collect($this->get(route('libro-soci.index'))->viewData('righe'));
        $this->assertSame('2025-10-05', $tutte->first(fn ($r) => $r['socio']->is($dimissionario))['cessazione']->data->toDateString());
        Excel::fake();
        $this->get(route('libro-soci.excel'))->assertOk();

        // 1° febbraio: il filtro "Scaduta" delle tessere 2025 mostra quelle non rinnovate
        $this->vai('2026-02-01 10:00:00');
        $scadute = $this->get(route('tessere.index', ['anno' => 2025, 'stato' => Tessera::STATO_SCADUTA]))->assertOk()->viewData('tessere');
        // "Scaduta" = data superata: compare anche la tessera annullata del dimissionario
        $this->assertCount(11, $scadute, 'scadute');
        $this->assertTrue($scadute->contains(fn (Tessera $t) => $t->socio_id === $dimissionario->id));
        $valide = $this->get(route('tessere.index', ['anno' => 2025, 'stato' => Tessera::STATO_ATTIVA]))->assertOk()->viewData('tessere');
        $this->assertCount(0, $valide);
    }

    // ==================================================================
    // 11. Newsletter mensile in un semestre
    // ==================================================================

    public function test_buro_11_newsletter_mensile_con_opt_out_gruppo_dinamico_e_storico_degli_invii_in_un_semestre(): void
    {
        $this->vai('2026-01-10 09:00:00');
        $this->configuraSmtp();

        $attivi = [];
        foreach (range(1, 15) as $n) {
            $attivi[$n] = $this->creaSocio('Lettore'.$n, 'Newsletter', [
                'email' => "lettore{$n}@soci.test",
                'citta' => $n <= 5 ? 'Milano' : 'Bologna',
                'email_opt_out_token' => str_pad((string) $n, 64, 'a'),
            ]);
        }
        $esclusi = [
            $this->creaSocio('Sospeso1', 'Newsletter', ['stato' => Socio::STATO_SOSPESO, 'citta' => 'Milano']),
            $this->creaSocio('Sospeso2', 'Newsletter', ['stato' => Socio::STATO_SOSPESO]),
            $this->creaSocio('Radiato', 'Newsletter', ['stato' => Socio::STATO_RADIATO, 'citta' => 'Milano']),
        ];
        $optOutFebbraio = $attivi[9];
        $optOutAprile = $attivi[12];

        $this->come($this->segretario)->post(route('config-gruppi.store'), [
            'nome_gruppo' => 'Soci di Milano', 'descrizione' => 'Gruppo territoriale', 'filtri' => ['citta' => 'Milano'],
        ])->assertSessionHasNoErrors();
        $gruppo = \App\Models\GruppoDinamico::query()->sole();
        $this->assertSame(['citta' => 'Milano'], is_array($gruppo->filtri_json) ? $gruppo->filtri_json : json_decode($gruppo->filtri_json, true));

        $invii = [];
        $this->mailerFinto($invii);
        $attesi = [];

        foreach (range(1, 6) as $mese) {
            $this->vai(sprintf('2026-%02d-05 10:00:00', $mese));

            if ($mese === 2) {
                $this->esci();
                $this->post(route('area-soci.optout.store', ['token' => $optOutFebbraio->email_opt_out_token]))->assertOk();
            }
            if ($mese === 4) {
                $this->esci();
                $this->post(route('area-soci.optout.store', ['token' => $optOutAprile->email_opt_out_token]))->assertOk();
            }

            $ids = [];
            foreach (['Calendario', 'Dal direttivo'] as $rubrica) {
                $titolo = "{$rubrica} di ".Carbon::create(2026, $mese)->locale('it')->monthName;
                $this->come($this->segretario)->post(route('annunci.store'), [
                    'titolo' => $titolo, 'tipo' => Annuncio::TIPO_COMUNICAZIONE, 'corpo_html' => "<p>{$titolo}: tutte le novità.</p>",
                    'pubblicato_dal' => now()->subHour()->format('Y-m-d\TH:i'), 'in_evidenza' => '0', 'solo_soci' => '0',
                ])->assertSessionHasNoErrors();
                $ids[] = Annuncio::query()->where('titolo', $titolo)->sole()->id;
            }

            $titoloNewsletter = sprintf('La Rondine informa — %02d/2026', $mese);
            $righePrima = EmailQueue::query()->count();
            $this->post(route('newsletter.anteprima'), ['titolo' => $titoloNewsletter, 'annunci' => $ids])->assertOk()->assertSee($titoloNewsletter);
            $this->assertSame($righePrima, EmailQueue::query()->count(), 'L\'anteprima non accoda nulla');

            $this->post(route('newsletter.store'), ['titolo' => $titoloNewsletter, 'annunci' => $ids])
                ->assertRedirect(route('newsletter.index'))->assertSessionHas('success');
            $attesi[$mese] = $mese === 1 ? 15 : ($mese <= 3 ? 14 : 13);

            if ($mese === 3) {
                // Comunicazione mirata al gruppo dinamico (la newsletter va sempre a tutti)
                $this->post(route('comunicazioni.store'), [
                    'subject' => 'Incontro dei soci di Milano',
                    'corpo_html' => '<p>Gentile {NOME}, ci vediamo in via Padova.</p>',
                    'recipient_type' => 'gruppo',
                    'recipient_filter_id' => $gruppo->id,
                ])->assertSessionHas('success');
            }

            $this->artisan('emails:process-queue', ['--sync' => true])->assertSuccessful();
        }

        $storico = NewsletterInviata::query()->orderBy('inviata_at')->get();
        $this->assertCount(6, $storico);
        $this->assertSame([15, 14, 14, 13, 13, 13], $storico->pluck('inviata_a')->map(fn ($n) => (int) $n)->all());
        $this->assertTrue($storico->every(fn ($n) => $n->inviata_da === $this->segretario->id && count($n->annunci_ids) === 2));

        $newsletter = EmailQueue::query()->where('template_codice', 'newsletter')->get();
        $this->assertSame(array_sum($attesi), $newsletter->count());
        foreach ($newsletter->groupBy('batch_id') as $batch) {
            $this->assertContains($batch->count(), [15, 14, 13]);
        }
        $this->assertSame(0, EmailQueue::query()->whereIn('socio_id', collect($esclusi)->pluck('id'))->count());
        $this->assertSame(3, $newsletter->where('socio_id', $optOutAprile->id)->count(), 'Gennaio-marzo, poi più nulla');
        $this->assertSame(1, $newsletter->where('socio_id', $optOutFebbraio->id)->count());

        $milano = EmailQueue::query()->where('subject', 'Incontro dei soci di Milano')->get();
        $this->assertEqualsCanonicalizing(collect(range(1, 5))->map(fn ($n) => $attivi[$n]->id)->all(), $milano->pluck('socio_id')->all());

        foreach ($newsletter->take(10) as $riga) {
            $socio = $riga->socio_id ? Socio::query()->find($riga->socio_id) : null;
            $this->assertStringContainsString($socio->email_opt_out_token, $riga->body_html, 'Link di disiscrizione personale');
        }
        $this->assertSame(EmailQueue::query()->count(), EmailLog::query()->where('status', EmailLog::STATUS_SENT)->count());
        $this->assertSame(0, EmailQueue::query()->where('status', EmailQueue::STATUS_PENDING)->count());

        $this->come($this->segretario)->get(route('newsletter.index'))->assertOk()
            ->assertSee('La Rondine informa — 01/2026')
            ->assertSee('La Rondine informa — 06/2026');
    }

    // ==================================================================
    // 12. Evento istituzionale a posti limitati con approvazione
    // ==================================================================

    public function test_buro_12_evento_istituzionale_con_posti_limitati_e_approvazione_della_segreteria_dalla_pubblicazione_al_foglio_presenze_e_al_rendiconto_dell_evento(): void
    {
        $this->vai('2026-09-01 10:00:00');

        $this->come($this->segretario)->post(route('eventi.store'), [
            'titolo' => 'Festa del socio',
            'descrizione' => 'Cena e premiazione dei volontari dell\'anno.',
            'data_evento' => '2026-09-20T19:30',
            'data_fine' => '2026-09-20T23:30',
            'luogo' => 'Cortile della sede',
            'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
            'capienza' => 3,
            'prezzo' => '10',
            'max_posti_per_prenotazione' => 1,
            'lista_attesa_attiva' => '1',
            'iscrizioni_aperte' => '1',
            'richiede_approvazione' => '1',
            'annullamento_entro_ore' => 24,
            'solo_soci' => '0',
        ])->assertSessionHasNoErrors()->assertRedirect(route('eventi.index'));
        $evento = Evento::query()->where('titolo', 'Festa del socio')->sole();
        $this->riuscito($this->put(route('eventi.economia.budget', $evento), ['budget' => '300']));

        // Cinque persone prenotano: tre soci e due simpatizzanti
        $persone = [];
        foreach (['Rosa', 'Sauro', 'Tina', 'Ugo', 'Vera'] as $i => $nome) {
            $socioId = $i < 3 ? $this->creaSocio($nome, 'Festaioli')->id : null;
            $persone[$nome] = Account::factory()->create([
                'associazione_id' => $this->associazione->id, 'nome' => $nome, 'cognome' => 'Festaioli',
                'email' => strtolower($nome).'@festa.test', 'socio_id' => $socioId,
            ]);
            $this->vai('2026-09-0'.(2 + $i).' 18:00:00');
            $this->esci();
            $this->actingAs($persone[$nome], 'account')
                ->post(route('pubblico.eventi.prenota', ['associazione' => $this->associazione->slug, 'evento' => $evento->id]), ['posti' => 1])
                ->assertSessionHasNoErrors();
        }
        $pren = collect($persone)->map(fn (Account $a) => \App\Models\Prenotazione::query()->where('account_id', $a->id)->sole());
        $this->assertSame(
            ['Rosa' => 'in_approvazione', 'Sauro' => 'in_approvazione', 'Tina' => 'in_approvazione', 'Ugo' => 'lista_attesa', 'Vera' => 'lista_attesa'],
            $pren->map(fn ($p) => $p->stato)->all()
        );
        Notification::assertSentToTimes($this->presidente, \App\Notifications\Prenotazioni\DaApprovareNotification::class, 3);
        foreach (['Rosa', 'Sauro', 'Tina'] as $nome) {
            Notification::assertSentTo($persone[$nome], \App\Notifications\Prenotazioni\InApprovazioneNotification::class);
        }

        // La segreteria decide: Rosa e Sauro sì, Tina no (posto liberato → Ugo promosso)
        $this->vai('2026-09-08 10:00:00');
        $url = fn (string $azione, $p) => route('eventi.prenotazioni.'.$azione, ['evento' => $evento->id, 'prenotazione' => $p->id]);
        $this->riuscito($this->come($this->segretario)->post($url('approva', $pren['Rosa'])));
        $this->riuscito($this->post($url('approva', $pren['Sauro'])));
        $this->riuscito($this->post($url('rifiuta', $pren['Tina']), ['motivo' => 'Precedenza ai soci con turni di volontariato.']));
        Notification::assertSentTo($persone['Tina'], \App\Notifications\Prenotazioni\RifiutataNotification::class);
        $this->assertSame(\App\Models\Prenotazione::STATO_IN_APPROVAZIONE, $pren['Ugo']->fresh()->stato, 'Ugo promosso dalla lista, da approvare');
        $this->riuscito($this->post($url('approva', $pren['Ugo']->fresh())));

        // Evento a pagamento: dopo l'approvazione si attende il pagamento, registrato in sede
        foreach (['Rosa', 'Sauro', 'Ugo'] as $nome) {
            $this->assertSame(\App\Models\Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $pren[$nome]->fresh()->stato);
            $this->riuscito($this->post($url('conferma-pagamento', $pren[$nome])));
            $this->assertSame(\App\Models\Prenotazione::STATO_CONFERMATA, $pren[$nome]->fresh()->stato);
            Notification::assertSentTo($persone[$nome], \App\Notifications\Prenotazioni\ConfermataNotification::class);
        }
        $this->assertSame(3, \App\Models\Prenotazione::postiOccupati($evento->id));
        Notification::assertSentTo($this->presidente, \App\Notifications\Prenotazioni\EventoEsauritoNotification::class);
        $this->assertSame(\App\Models\Prenotazione::STATO_LISTA_ATTESA, $pren['Vera']->fresh()->stato);
        $this->assertSame(\App\Models\Prenotazione::STATO_ANNULLATA, $pren['Tina']->fresh()->stato);

        // Promemoria il giorno prima, una sola volta
        $this->vai('2026-09-18 08:30:00');
        $this->artisan('prenotazioni:promemoria-eventi')->expectsOutput('Promemoria inviati: 0')->assertSuccessful();
        $this->vai('2026-09-19 08:30:00');
        $this->artisan('prenotazioni:promemoria-eventi')->expectsOutput('Promemoria inviati: 3')->assertSuccessful();
        $this->vai('2026-09-20 08:30:00');
        $this->artisan('prenotazioni:promemoria-eventi')->expectsOutput('Promemoria inviati: 0')->assertSuccessful();
        Notification::assertSentTimes(\App\Notifications\Prenotazioni\PromemoriaEventoNotification::class, 3);
        Notification::assertNotSentTo([$persone['Tina'], $persone['Vera']], \App\Notifications\Prenotazioni\PromemoriaEventoNotification::class);

        // La sera della festa: presenze, foglio firme, spese
        $this->vai('2026-09-20 20:00:00');
        $this->come($this->segretario)->post(route('eventi.presenze.store', $evento), [
            'prenotazioni' => [$pren['Rosa']->id, $pren['Sauro']->id, $pren['Ugo']->id],
        ])->assertSessionHas('success', 'Presenze registrate: 3.');
        $foglio = $this->get(route('eventi.presenze.foglio', $evento))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $foglio->getContent());

        // Il tesoriere (eventi in sola lettura) non registra le spese dell'evento: lo fa la segreteria
        $this->come($this->tesoriere)->post(route('eventi.economia.spese.store', $evento), ['descrizione' => 'Catering', 'importo' => '120.00', 'data' => '2026-09-20'])->assertForbidden();
        $this->come($this->segretario)->post(route('eventi.economia.spese.store', $evento), ['descrizione' => 'Catering', 'importo' => '120.00', 'data' => '2026-09-20'])->assertSessionHas('success');
        $this->post(route('eventi.economia.spese.store', $evento), ['descrizione' => 'Premi volontari', 'importo' => '45.00', 'data' => '2026-09-20'])->assertSessionHas('success');

        $this->assertSame(2, \App\Models\SpesaEvento::query()->where('evento_id', $evento->id)->count());
        $incassi = Movimento::query()->where('origine_type', (new \App\Models\Prenotazione)->getMorphClass())->get();
        $this->assertCount(3, $incassi);
        $this->assertEqualsWithDelta(30.0, (float) $incassi->sum('importo'), 0.001);

        $this->come($this->tesoriere)->get(route('eventi.economia.show', $evento))->assertOk()
            ->assertViewHas('incassi', 30.0)
            ->assertViewHas('totaleSpese', 165.0)
            ->assertViewHas('margine', 30.0 - 165.0)
            ->assertSee('300,00');
    }

    // ==================================================================
    // 13. Tre sedi, operatori limitati, riunione in uno spazio prenotato
    // ==================================================================

    public function test_buro_13_gestione_di_tre_sedi_operatori_limitati_report_per_sede_e_riunione_del_direttivo_in_uno_spazio_prenotato(): void
    {
        $this->vai('2026-02-01 09:00:00');
        foreach (['Sede Nord' => 'Bergamo', 'Sede Centro' => 'Bologna', 'Sede Sud' => 'Bari'] as $nome => $citta) {
            $this->come($this->presidente)->post(route('sedi.save'), ['nome' => $nome, 'citta' => $citta, 'provincia' => strtoupper(substr($citta, 0, 2)), 'cap' => '40100'])
                ->assertSessionHasNoErrors();
        }
        $sedi = \App\Models\Sede::query()->withoutGlobalScopes()->orderBy('nome')->get()->keyBy('nome');
        $this->post(route('spazi.store'), ['nome' => 'Sala riunioni', 'capienza' => 20, 'attivo' => '1'])->assertSessionHasNoErrors();
        $this->post(route('spazi.store'), ['nome' => 'Salone', 'capienza' => 80, 'attivo' => '1'])->assertSessionHasNoErrors();
        $sala = \App\Models\Spazio::query()->where('nome', 'Sala riunioni')->sole();

        $opNord = $this->creaStaff('Nadia', 'Nordini');
        $opSud = $this->creaStaff('Saverio', 'Sudati');
        $this->put(route('ruoli.update', $opNord), ['ruoli' => ['operatore'], 'sedi' => [$sedi['Sede Nord']->id]])->assertRedirect(route('ruoli.index'));
        $this->put(route('ruoli.update', $opSud), ['ruoli' => ['operatore'], 'sedi' => [$sedi['Sede Sud']->id]])->assertRedirect(route('ruoli.index'));
        $this->assertDatabaseHas('utente_sedi', ['utente_id' => $opNord->id, 'sede_id' => $sedi['Sede Nord']->id]);

        $quote = [];
        foreach ($sedi as $nomeSede => $sede) {
            foreach (range(1, 3) as $n) {
                $socio = $this->creaSocio('Socio'.$n, str_replace(' ', '', $nomeSede), ['sede_id' => $sede->id]);
                $quote[$nomeSede][] = $this->creaQuota($socio, 2026, 30);
            }
        }

        // Ogni operatore incassa le quote della propria sede
        foreach ($quote['Sede Nord'] as $quota) {
            $this->pagaOffline($opNord->fresh(), $quota, '2026-02-03')->assertSessionHas('success');
        }
        foreach ($quote['Sede Sud'] as $quota) {
            $this->pagaOffline($opSud->fresh(), $quota, '2026-02-04')->assertSessionHas('success');
        }
        // ...e non può toccare quelle di un'altra sede
        $this->pagaOffline($opNord->fresh(), $quote['Sede Centro'][0], '2026-02-03')->assertNotFound();
        $this->assertFalse($quote['Sede Centro'][0]->fresh()->isPagata());

        $movNord = Movimento::query()->withoutGlobalScopes()->where('sede_id', $sedi['Sede Nord']->id)->get();
        $this->assertCount(3, $movNord);

        $this->come($opNord->fresh());
        $json = $this->get(route('soci.index', ['tessera_stato' => 'all', 'ajax' => 1]))->assertOk()->json();
        $this->assertSame(3, $json['count']);
        $csv = $this->get(route('soci.export', ['format' => 'csv', 'tessera_stato' => 'all']))->assertOk();
        $contenuto = file_get_contents($csv->baseResponse->getFile()->getPathname());
        $this->assertSame(1 + 3, count(array_filter(preg_split('/\R/', trim($contenuto)))), 'Intestazione + 3 soci della sede: '.$contenuto);
        $this->assertStringContainsString('SedeNord', $contenuto);
        $this->assertStringNotContainsString('SedeCentro', $contenuto);
        $report = $this->get(route('report-economico.index', ['dal' => '2026-01-01', 'al' => '2026-12-31']))->assertOk();
        $this->assertEqualsWithDelta(90.0, $report->viewData('totaleEntrate'), 0.001, 'Il report dell\'operatore di sede mostra solo i suoi incassi');

        $this->come($this->presidente);
        $this->assertSame(9, $this->get(route('soci.index', ['tessera_stato' => 'all', 'ajax' => 1]))->json()['count']);
        $this->assertEqualsWithDelta(180.0, $this->get(route('report-economico.index', ['dal' => '2026-01-01', 'al' => '2026-12-31']))->viewData('totaleEntrate'), 0.001);

        // 20 febbraio: riunione del direttivo nella Sala riunioni
        $this->vai('2026-02-20 09:00:00');
        $riunione = $this->creaRiunione($this->segretario, 'Direttivo di febbraio', '2026-02-26T21:00', ['Bilancio di previsione'], ['spazio_id' => $sala->id]);
        $this->assertSame($sala->id, $riunione->spazio_id);

        // La sede viene chiusa: i suoi soci restano, senza sede
        $sociCentro = Socio::query()->where('sede_id', $sedi['Sede Centro']->id)->pluck('id');
        $this->come($this->presidente)->delete(route('sedi.destroy', $sedi['Sede Centro']))->assertSessionHas('success', 'Sede eliminata.');
        $this->assertNull(\App\Models\Sede::query()->withoutGlobalScopes()->find($sedi['Sede Centro']->id));
        $this->assertSame(3, Socio::query()->whereIn('id', $sociCentro)->whereNull('sede_id')->count());

        // Lo spazio della riunione (riunioni.spazio_id, f.17) è solo il luogo indicato
        // in convocazione: una riunione ha data_ora ma nessuna durata, quindi non
        // occupa la sala nel calendario delle prenotazioni. Chi vuole bloccarla crea
        // una prenotazione spazio, e da quel momento le sovrapposizioni sono rifiutate.
        $this->come($this->segretario)->from(route('spazi.prenotazioni.index'))->post(route('spazi.prenotazioni.store'), [
            'spazio_id' => $sala->id, 'titolo' => 'Direttivo di febbraio', 'dal' => '2026-02-26T21:00', 'al' => '2026-02-26T23:00',
        ])->assertSessionHasNoErrors();
        $this->from(route('spazi.prenotazioni.index'))->post(route('spazi.prenotazioni.store'), [
            'spazio_id' => $sala->id, 'titolo' => 'Corso di yoga', 'dal' => '2026-02-26T20:30', 'al' => '2026-02-26T22:30',
        ])->assertSessionHasErrors('dal');
        $this->assertTrue(\App\Models\PrenotazioneSpazio::query()->where('titolo', 'Direttivo di febbraio')->exists());
        $this->assertFalse(\App\Models\PrenotazioneSpazio::query()->where('titolo', 'Corso di yoga')->exists());
    }

    // ==================================================================
    // 14. Il modulo di iscrizione cambia a metà anno
    // ==================================================================

    public function test_buro_14_minisito_e_modulo_di_iscrizione_cambiano_a_meta_anno_le_richieste_arrivate_prima_e_dopo_vengono_gestite_entrambe(): void
    {
        $this->vai('2026-01-15 10:00:00');
        $this->configuraStripe();
        $modulo = function (string $modalita, bool $conCf) {
            $this->come($this->presidente)->post(route('configurazioni.modulo-iscrizione.salva'), [
                'iscrizioni_socio_aperte' => '1',
                'modalita_iscrizione' => $modalita,
                'campi_standard' => [
                    'telefono' => ['visibile' => '1', 'obbligatorio' => '1', 'ordine' => 1],
                    'codice_fiscale' => ['visibile' => $conCf ? '1' : '0', 'obbligatorio' => $conCf ? '1' : '0', 'ordine' => 2],
                    'data_nascita' => ['visibile' => '1', 'obbligatorio' => '1', 'ordine' => 3],
                    'indirizzo' => ['visibile' => '0', 'obbligatorio' => '0', 'ordine' => 4],
                    'citta' => ['visibile' => '1', 'obbligatorio' => '0', 'ordine' => 5],
                    'provincia' => ['visibile' => '0', 'obbligatorio' => '0', 'ordine' => 6],
                    'cap' => ['visibile' => '0', 'obbligatorio' => '0', 'ordine' => 7],
                ],
            ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Modulo di iscrizione salvato.');
        };
        $vediModulo = function () {
            $this->esci();
            $account = Account::factory()->create(['associazione_id' => $this->associazione->id]);

            return $this->actingAs($account, 'account')->get($this->urlTenant('iscriviti'))->assertOk();
        };

        // Gennaio: pre-iscrizione con codice fiscale obbligatorio
        $modulo(Associazione::MODALITA_PREISCRIZIONE, true);
        $vediModulo()->assertSee('name="codice_fiscale"', false);
        $vecchie = [
            $this->cittadinoChiedeIscrizione('Ilaria', 'Gennaini', ['codice_fiscale' => 'RSSMRA85T10A562S']),
        ];
        $this->vai('2026-01-20 10:00:00');
        $vecchie[] = $this->cittadinoChiedeIscrizione('Livio', 'Gennaini', ['codice_fiscale' => 'VRDGLI85T50F205P']);

        // 1° febbraio: iscrizione diretta, il codice fiscale non si chiede più
        $this->vai('2026-02-01 09:00:00');
        $modulo(Associazione::MODALITA_DIRETTA, false);
        $vediModulo()->assertDontSee('name="codice_fiscale"', false);

        $this->vai('2026-02-05 10:00:00');
        $diretta = $this->cittadinoChiedeIscrizione('Mirco', 'Febbrari');
        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $diretta->stato);
        $this->assertNull($diretta->gestita_da);
        $mirco = Socio::query()->findOrFail($diretta->socio_id);
        $this->assertSame(Socio::STATO_ATTIVO, $mirco->stato);
        $quotaMirco = Quota::query()->where('socio_id', $mirco->id)->where('anno', 2026)->sole();
        $this->assertSame('30.00', (string) $quotaMirco->importo);
        app(\App\Services\Pagamenti\PaymentService::class)->generatePaymentToken($quotaMirco);
        $this->assertTrue($quotaMirco->fresh()->hasValidPaymentToken());
        $this->stripePagaQuota($quotaMirco->fresh(), 'pi_mirco');
        $this->assertTrue($quotaMirco->fresh()->isPagata());
        $this->assertSame(1, Tessera::query()->where('socio_id', $mirco->id)->where('anno_validita', 2026)->count());

        // 10 febbraio: le due domande del vecchio regime vengono comunque deliberate
        $this->vai('2026-02-10 18:00:00');
        foreach ($vecchie as $richiesta) {
            $this->come($this->segretario)->post(route('richieste-iscrizione.approva', $richiesta), [
                'genera_quota' => '1', 'delibera_numero' => '2/2026', 'delibera_data' => '2026-02-10',
            ])->assertSessionHas('success');
        }

        // 15 febbraio: si torna alla pre-iscrizione (con codice fiscale)
        $this->vai('2026-02-15 09:00:00');
        $modulo(Associazione::MODALITA_PREISCRIZIONE, true);
        $vediModulo()->assertSee('name="codice_fiscale"', false);
        $this->vai('2026-02-20 10:00:00');
        $quarta = $this->cittadinoChiedeIscrizione('Nora', 'Febbrari', ['codice_fiscale' => 'BNCGLI88C52A944I']);
        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $quarta->stato);

        $this->assertSame(2, RichiestaIscrizione::query()->where('delibera_numero', '2/2026')->where('stato', RichiestaIscrizione::STATO_APPROVATA)->count());
        $this->assertSame(3, Socio::query()->where('stato', Socio::STATO_ATTIVO)->count());
        $this->assertSame(1, Socio::query()->where('stato', Socio::STATO_IN_ATTESA)->count());
        $this->assertSame('RSSMRA85T10A562S', Socio::query()->findOrFail($vecchie[0]->fresh()->socio_id)->codice_fiscale);
        // Gli admin sono avvisati di ogni domanda, anche di quella diretta
        Notification::assertSentToTimes($this->presidente, RichiestaIscrizioneRicevutaNotification::class, 4);

        $righe = collect($this->come($this->presidente)->get(route('libro-soci.index', ['anno' => 2026]))->assertOk()->viewData('righe'));
        $this->assertCount(3, $righe, 'La pre-iscrizione in attesa non entra nel libro soci');
        $this->assertSame(2, $righe->whereNotNull('delibera_numero')->count());
        $this->assertNull($righe->first(fn ($r) => $r['socio']->is($mirco))['delibera_numero']);
    }

    // ==================================================================
    // 15. Anagrafica pulita prima dell'assemblea
    // ==================================================================

    public function test_buro_15_anagrafica_pulita_prima_dell_assemblea_doppioni_uniti_dati_verificati_e_azioni_in_blocco_su_tag_e_categoria(): void
    {
        $this->vai('2026-03-01 09:00:00');
        $storico = $this->creaSocio('Giulia', 'Ferraresi', ['email' => 'giulia.ferraresi@soci.test', 'data_iscrizione' => '2019-04-01', 'numero_socio' => '001']);
        $this->creaTessera($storico, 2025);
        $this->creaTessera($storico, 2026);
        $this->creaQuota($storico, 2026, 30, ['data_pagamento' => '2026-01-10', 'stato' => Quota::STATO_PAGATA]);
        $radiato = $this->creaSocio('Ezio', 'Exsoci', ['stato' => Socio::STATO_RADIATO, 'numero_socio' => '002']);
        $this->creaTessera($radiato, 2024, ['stato' => Tessera::STATO_ANNULLATA]);
        $this->creaQuota($radiato, 2024, 30, ['data_pagamento' => '2024-02-01', 'stato' => Quota::STATO_PAGATA]);

        // Import dal foglio del vecchio segretario: dieci righe, una è Giulia con un altro nome
        $righeCsv = ['nome,cognome,email,data_nascita'];
        foreach (range(1, 9) as $n) {
            $righeCsv[] = "Importato{$n},Archivio,importato{$n}@soci.test,1980-01-0{$n}";
        }
        $righeCsv[] = 'Giulia Maria,Ferraresi,giulia.ferraresi.bis@soci.test,1980-02-02';
        $this->come($this->segretario)->post(route('soci.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('soci.csv', implode("\n", $righeCsv)),
        ])->assertRedirect(route('soci.import'))->assertSessionHas('success', 'Import completato: 10 soci importati, 0 righe scartate.');
        $this->assertSame(12, Socio::query()->count());
        $doppione = Socio::query()->where('email', 'giulia.ferraresi.bis@soci.test')->sole();

        // Il doppione viene unito al record storico
        $tessereStorico = Tessera::query()->where('socio_id', $storico->id)->count();
        $this->post(route('soci-duplicati.unisci'), ['principale_id' => $storico->id, 'duplicato_id' => $doppione->id])->assertSessionHas('success');
        $this->assertSoftDeleted('soci', ['id' => $doppione->id]);
        $this->assertSame($tessereStorico, Tessera::query()->where('socio_id', $storico->id)->count());
        $this->assertSame(1, Quota::query()->where('socio_id', $storico->id)->count());
        $this->assertDatabaseHas('storico_attivita_socio', ['socio_id' => $storico->id]);

        // Controllo automatico di un codice fiscale prima di correggere la scheda
        $this->getJson(route('soci.verifica-dati', ['codice_fiscale' => 'RSSMRA85T10A562S']))->assertOk()->assertJsonPath('codice_fiscale.valido', true);

        // Azioni in blocco: tag "Assemblea 2026" a tutti gli attivi, categoria "Fondatore" a tre
        $tag = \App\Models\Tag::create(['associazione_id' => $this->associazione->id, 'nome_tag' => 'Assemblea 2026', 'colore' => '#0D9488']);
        $fondatore = \App\Models\CategoriaSocio::create(['associazione_id' => $this->associazione->id, 'nome' => 'Fondatore']);
        $attivi = Socio::query()->where('stato', Socio::STATO_ATTIVO)->pluck('id')->all();
        $this->assertCount(10, $attivi);
        $this->from(route('soci.index'))->post(route('soci.azioni-blocco'), ['azione' => 'tag_assegna', 'valore' => $tag->id, 'soci_ids' => $attivi])
            ->assertSessionHas('success');
        $tre = array_slice($attivi, 0, 3);
        $this->from(route('soci.index'))->post(route('soci.azioni-blocco'), ['azione' => 'categoria', 'valore' => $fondatore->id, 'soci_ids' => $tre])
            ->assertSessionHas('success');
        $this->assertSame(10, \Illuminate\Support\Facades\DB::table('socio_tags')->where('tag_id', $tag->id)->count());
        $this->assertEqualsCanonicalizing($tre, Socio::query()->where('categoria_socio_id', $fondatore->id)->pluck('id')->all());
        Excel::fake();
        $this->post(route('soci.azioni-blocco.export'), ['soci_ids' => $tre])->assertOk();
        Excel::assertDownloaded('soci-selezione-'.now()->format('Ymd-His').'.xlsx', fn ($export) => $export->query()->count() === 3);

        // 20 marzo: convocazione dell'assemblea, una sola email per persona
        $this->vai('2026-03-20 10:00:00');
        $this->post(route('annunci.store'), [
            'titolo' => 'Assemblea dei soci 2026', 'tipo' => Annuncio::TIPO_CONVOCAZIONE, 'corpo_html' => '<p>Assemblea ordinaria.</p>',
            'data_assemblea' => '2026-04-20T18:00', 'luogo_assemblea' => 'Sede', 'pubblicato_dal' => now()->subHour()->format('Y-m-d\TH:i'),
            'in_evidenza' => '1', 'solo_soci' => '1',
        ])->assertSessionHasNoErrors();
        $this->post(route('annunci.invia-convocazione', Annuncio::query()->sole()))->assertSessionHas('success', 'Convocazione inviata a 10 soci.');
        Notification::assertSentToTimes($storico, ConvocazioneAssembleaNotification::class, 1);
        Notification::assertNotSentTo($doppione, ConvocazioneAssembleaNotification::class);

        // 1° aprile: un socio cestinato per errore, ripristinato il 5
        $this->vai('2026-04-01 10:00:00');
        $this->delete(route('soci.destroy', $storico))->assertRedirect(route('soci.index'));
        $this->vai('2026-04-05 10:00:00');
        $this->post(route('soci.restore', $storico->id))->assertSessionHas('success');
        $this->assertSame(Socio::STATO_ATTIVO, $storico->fresh()->stato);
        $this->assertSame($tessereStorico, Tessera::query()->where('socio_id', $storico->id)->count());

        // 10 aprile: l'ex socio radiato va nel cestino
        $this->vai('2026-04-10 10:00:00');
        $this->delete(route('soci.destroy', $radiato))->assertRedirect(route('soci.index'));
        $quotaRadiato = Quota::query()->withoutGlobalScopes()->where('socio_id', $radiato->id)->sole();
        $libro = collect($this->get(route('libro-soci.index'))->viewData('righe'));
        $this->assertFalse($libro->contains(fn ($r) => $r['socio']->id === $radiato->id), 'Il socio nel cestino esce dal libro soci');

        // Lo scheduler svuota il cestino solo dopo 90 giorni: il 5 luglio esce il doppione
        // unito il 1° marzo, l'ex socio cestinato il 10 aprile non ancora
        $movimentoRadiato = Movimento::query()->where('origine_id', $quotaRadiato->id)->first();
        $this->vai('2026-07-05 03:00:00');
        $this->artisan('cestino:svuota', ['--giorni' => 90])->expectsOutput('soci: 1 eliminati definitivamente')->assertSuccessful();
        $this->assertNull(Socio::withTrashed()->find($doppione->id));
        $this->assertNotNull(Socio::withTrashed()->find($radiato->id));
        $this->assertNotNull(Quota::query()->withoutGlobalScopes()->find($quotaRadiato->id));

        $this->vai('2026-07-10 03:00:00');
        $this->artisan('cestino:svuota', ['--giorni' => 90])->expectsOutput('soci: 1 eliminati definitivamente')->assertSuccessful();
        $this->assertNull(Socio::withTrashed()->find($radiato->id));
        // Tessere e quote seguono il socio (FK cascade)
        $this->assertSame(0, Tessera::query()->withoutGlobalScopes()->where('socio_id', $radiato->id)->count());
        $this->assertNull(Quota::query()->withoutGlobalScopes()->find($quotaRadiato->id));
        $this->assertNull($movimentoRadiato, 'La quota 2024 era registrata a mano, senza movimento di prima nota');
        $this->assertNotNull(Socio::query()->find($storico->id));
    }

    // ==================================================================
    // 16. Donazioni e 5x1000 nel rendiconto
    // ==================================================================

    public function test_buro_16_donazioni_e_cinque_per_mille_il_tesoriere_registra_le_entrate_straordinarie_e_il_rendiconto_le_separa_dalle_quote(): void
    {
        $this->vai('2026-01-10 10:00:00');
        $this->configuraStripe();
        $this->withoutMiddleware(ProtectAgainstSpam::class);
        $this->mock(StripeGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')->andReturn('https://checkout.stripe.test/dona');
            $mock->shouldReceive('constructWebhookEvent')->andReturnUsing(fn (string $payload) => json_decode($payload, true));
        });

        $this->come($this->tesoriere)->get(route('prima-nota.index'))->assertOk();
        $cinquePerMille = CategoriaMovimento::create(['associazione_id' => $this->associazione->id, 'nome' => '5x1000', 'tipo' => CategoriaMovimento::TIPO_ENTRATA]);
        $cat = fn (string $nome) => CategoriaMovimento::query()->where('nome', $nome)->sole()->id;

        // Tre donazioni dal mini-sito: due vanno a buon fine, la terza viene abbandonata al checkout
        $donazioni = [];
        foreach ([['2026-02-14', 'Marco Benefattori', 'marco@dona.test', '50.00', true], ['2026-05-20', 'Lucia Generosi', 'lucia@dona.test', '120.00', true], ['2026-06-01', 'Piero Indecisi', 'piero@dona.test', '30.00', false]] as [$giorno, $nome, $email, $importo, $paga]) {
            $this->vai($giorno.' 20:00:00');
            $this->esci();
            $this->post($this->urlTenant('dona'), ['importo' => $importo, 'donatore_nome' => $nome, 'donatore_email' => $email, 'gateway' => 'stripe'])
                ->assertRedirect('https://checkout.stripe.test/dona');
            $donazione = Donazione::query()->where('donatore_email', $email)->sole();
            if ($paga) {
                $this->postJson(route('webhooks.stripe'), [
                    'type' => 'checkout.session.completed',
                    'data' => ['object' => ['id' => 'cs_'.$donazione->id, 'payment_intent' => 'pi_'.$donazione->id, 'metadata' => [
                        'tipo' => 'donazione', 'pagabile_id' => $donazione->id, 'associazione_id' => $donazione->associazione_id,
                    ]]],
                ], ['Stripe-Signature' => 't=1,v1=abc'])->assertOk();
            }
            $donazioni[$email] = $donazione;
        }

        // Entrate e uscite registrate a mano dal tesoriere
        $manuali = [
            ['2026-03-08', Movimento::TIPO_ENTRATA, '40.00', 'Donazioni', 'Offerta in contanti alla festa della donna'],
            ['2026-07-28', Movimento::TIPO_ENTRATA, '812.40', '5x1000', 'Contributo 5x1000 anno finanziario 2024'],
            ['2026-02-01', Movimento::TIPO_USCITA, '300.00', 'Utenze e affitti', 'Affitto sede'],
            ['2026-04-01', Movimento::TIPO_USCITA, '95.00', 'Materiali e forniture', 'Materiale per laboratori'],
            ['2026-09-10', Movimento::TIPO_USCITA, '210.00', 'Altre uscite', 'Assicurazione volontari'],
            ['2026-11-15', Movimento::TIPO_USCITA, '60.00', 'Utenze e affitti', 'Bolletta luce'],
        ];
        foreach ($manuali as [$data, $tipo, $importo, $categoria, $descrizione]) {
            $this->vai($data.' 12:00:00');
            $this->come($this->tesoriere)->post(route('prima-nota.store'), [
                'tipo' => $tipo, 'importo' => $importo, 'data' => $data,
                'categoria_id' => $categoria === '5x1000' ? $cinquePerMille->id : $cat($categoria), 'descrizione' => $descrizione,
            ])->assertSessionHasNoErrors();
        }

        // Dieci quote pagate in sede durante l'anno
        $quote = [];
        foreach (range(1, 10) as $n) {
            $this->vai(sprintf('2026-%02d-15 10:00:00', $n));
            $quota = $this->creaQuota($this->creaSocio('Socio'.$n, 'Sostenitori'), 2026, 25);
            $this->pagaOffline($this->tesoriere, $quota, now()->toDateString())->assertSessionHas('success');
            $quote[] = $quota;
        }

        $this->assertSame(Donazione::STATO_PAGATA, $donazioni['marco@dona.test']->fresh()->stato);
        $this->assertSame(Donazione::STATO_PAGATA, $donazioni['lucia@dona.test']->fresh()->stato);
        $this->assertSame(Donazione::STATO_IN_ATTESA, $donazioni['piero@dona.test']->fresh()->stato);
        $this->assertNull(Movimento::query()->where('origine_id', $donazioni['piero@dona.test']->id)->first());
        Notification::assertSentOnDemandTimes(\App\Notifications\Economia\RicevutaDonazioneNotification::class, 2);

        $movimenti = Movimento::query()->get();
        $this->assertSame(2, $movimenti->where('origine_type', (new Donazione)->getMorphClass())->count());
        $this->assertSame(10, $movimenti->where('origine_type', (new Quota)->getMorphClass())->count());
        $this->assertSame(6, $movimenti->whereNull('origine_type')->count());

        // Dicembre: il tesoriere consulta e prepara il rendiconto
        $this->vai('2026-12-31 11:00:00');
        $this->come($this->tesoriere)->get(route('donazioni.index'))->assertOk()->assertSee('Marco Benefattori')->assertSee('Lucia Generosi');

        $report = $this->get(route('report-economico.index', ['dal' => '2026-01-01', 'al' => '2026-12-31']))->assertOk();
        $perCategoria = collect($report->viewData('perCategoria'))->keyBy(fn ($r) => $r['tipo'].'|'.$r['categoria']);
        $this->assertEqualsWithDelta(250.0, $perCategoria['entrata|'.PrimaNotaService::CATEGORIA_QUOTE]['totale'], 0.001);
        $this->assertEqualsWithDelta(50.0 + 120.0 + 40.0, $perCategoria['entrata|'.PrimaNotaService::CATEGORIA_DONAZIONI]['totale'], 0.001);
        $this->assertSame(3, $perCategoria['entrata|'.PrimaNotaService::CATEGORIA_DONAZIONI]['numero']);
        $this->assertEqualsWithDelta(812.40, $perCategoria['entrata|5x1000']['totale'], 0.001);
        $this->assertEqualsWithDelta(665.0, $report->viewData('totaleUscite'), 0.001);
        $this->assertEqualsWithDelta(250 + 210 + 812.40, $report->viewData('totaleEntrate'), 0.001);
        $this->assertEqualsWithDelta(812.40, $report->viewData('mensile')['2026-07']['entrate'] - 25.0, 0.001, 'Luglio: 5x1000 + una quota');

        $luglio = $this->get(route('prima-nota.index', ['dal' => '2026-07-01', 'al' => '2026-07-31', 'categoria_id' => $cinquePerMille->id]))->assertOk();
        $this->assertSame(1, $luglio->viewData('movimenti')->total());
        $this->assertEqualsWithDelta(812.40, $luglio->viewData('saldoTotale'), 0.001);
        $tutto = $this->get(route('prima-nota.index'))->assertOk();
        $this->assertEqualsWithDelta(250 + 210 + 812.40 - 665.0, $tutto->viewData('saldoTotale'), 0.001);

        // Il movimento automatico della quota non si tocca, quello manuale sì
        $automatico = $movimenti->firstWhere('origine_id', $quote[0]->id);
        $this->put(route('prima-nota.update', $automatico), ['tipo' => Movimento::TIPO_ENTRATA, 'importo' => '1.00', 'data' => '2026-01-15', 'categoria_id' => $automatico->categoria_id])
            ->assertSessionHas('error');
        $this->assertEqualsWithDelta(25.0, (float) $automatico->fresh()->importo, 0.001);
        $bolletta = Movimento::query()->where('descrizione', 'Bolletta luce')->sole();
        $this->delete(route('prima-nota.destroy', $bolletta))->assertSessionHas('success');
        $this->assertNull(Movimento::query()->find($bolletta->id));

        Excel::fake();
        $this->get(route('report-economico.export', ['dal' => '2026-01-01', 'al' => '2026-12-31']))->assertOk();
        Excel::assertDownloaded('report-economico_20260101-20261231.xlsx');
    }

    // ==================================================================
    // 17. Sondaggio consultivo prima dell'assemblea
    // ==================================================================

    public function test_buro_17_sondaggio_consultivo_prima_dell_assemblea_un_voto_per_socio_chiusura_automatica_e_risultato_riportato_nel_verbale(): void
    {
        $this->vai('2026-03-01 09:00:00');
        $this->come($this->segretario)->post(route('sondaggi.store'), [
            'domanda' => 'Dove organizziamo la festa sociale?',
            'opzioni_testo' => "Parco Lungofiume\nCortile della sede\nAgriturismo Le Querce",
            'aperto_dal' => '2026-03-01T08:00',
            'aperto_al' => '2026-03-10T23:59',
            'solo_soci' => '1',
        ])->assertSessionHasNoErrors();
        $sondaggio = Sondaggio::query()->sole();

        $soci = [];
        foreach (range(1, 8) as $n) {
            $soci[$n] = $this->creaSocio('Votante'.$n, 'Sondaggi', ['password_hash' => self::PASSWORD]);
        }
        $voti = [1 => 0, 2 => 0, 3 => 1, 4 => 0, 5 => 2, 6 => 1];
        foreach ($voti as $n => $opzione) {
            $this->vai('2026-03-0'.(1 + $n).' 20:00:00');
            $this->esci();
            $this->actingAs($soci[$n], 'socio')->post(route('area-soci.sondaggi.vota', $sondaggio), ['opzione' => $opzione])->assertSessionHas('success');
        }
        // Due soci votano dal mini-sito con l'account collegato
        foreach ([7 => 0, 8 => 2] as $n => $opzione) {
            $account = Account::factory()->create(['associazione_id' => $this->associazione->id, 'socio_id' => $soci[$n]->id]);
            $this->esci();
            $this->actingAs($account, 'account')->post($this->urlTenant('account/sondaggi/'.$sondaggio->id.'/vota'), ['opzione' => $opzione])->assertSessionHas('success');
        }
        // Votante1 ci riprova: il primo voto resta
        $this->esci();
        $this->actingAs($soci[1], 'socio')->post(route('area-soci.sondaggi.vota', $sondaggio), ['opzione' => 2])->assertSessionHas('warning');
        // Un simpatizzante senza tessera non è ammesso
        $simpatizzante = Account::factory()->create(['associazione_id' => $this->associazione->id]);
        $this->esci();
        $this->actingAs($simpatizzante, 'account')->post($this->urlTenant('account/sondaggi/'.$sondaggio->id.'/vota'), ['opzione' => 1]);
        $this->assertSame(0, RispostaSondaggio::query()->where('votante_id', $simpatizzante->id)->count());

        // 11 marzo: il sondaggio è chiuso, il ritardatario non vota
        $this->vai('2026-03-11 09:00:00');
        $ritardatario = $this->creaSocio('Ritardo', 'Sondaggi', ['password_hash' => self::PASSWORD]);
        $this->esci();
        $this->actingAs($ritardatario, 'socio')->post(route('area-soci.sondaggi.vota', $sondaggio), ['opzione' => 1])->assertSessionHas('warning');

        $risposte = RispostaSondaggio::query()->where('sondaggio_id', $sondaggio->id)->get();
        $this->assertCount(8, $risposte);
        $this->assertSame(8, $risposte->unique('votante_id')->count());
        $conteggi = $risposte->countBy('opzione')->sortKeys()->all();
        $this->assertSame([0 => 4, 1 => 2, 2 => 2], $conteggi);
        $this->come($this->segretario)->get(route('sondaggi.show', $sondaggio))->assertOk()->assertSee('8 voti totali')->assertSee('4 voti (50%)');

        // 15 marzo: il direttivo prende atto del risultato
        $this->vai('2026-03-12 09:00:00');
        $presidenteSocio = $soci[1];
        $this->carica($presidenteSocio, 'Presidente', '2025-01-01', '2027-12-31');
        $this->carica($soci[2], 'Segretario', '2025-01-01', '2027-12-31');
        $riunione = $this->creaRiunione($this->segretario, 'Direttivo di metà marzo', '2026-03-15T21:00', ['Esito sondaggio festa sociale']);
        $this->post(route('riunioni.convoca', $riunione))->assertSessionHas('success');
        $this->vai('2026-03-15 23:00:00');
        $this->svolgiRiunione($this->segretario, $riunione, [$soci[1], $soci[2]]);
        $this->vota($riunione, 0, VotazioneRiunione::ESITO_APPROVATO, 2);
        $verbale = $this->generaVerbale($riunione);
        $esito = 'Esito del sondaggio (8 votanti): Parco Lungofiume 4, Cortile della sede 2, Agriturismo Le Querce 2. La festa si terrà al Parco Lungofiume.';
        $this->put(route('verbali.update', $verbale), $this->datiVerbale($verbale, ['deliberazioni' => $verbale->deliberazioni."\n".$esito]))->assertSessionHas('success');
        $this->come($this->presidente)->post(route('verbali.approva', $verbale))->assertSessionHas('success');
        $this->assertStringContainsString('Parco Lungofiume 4', $verbale->fresh()->deliberazioni);
        $this->assertTrue($verbale->fresh()->approvato);

        // Una correzione successiva azzera l'approvazione, che va ripetuta
        $this->come($this->segretario)->put(route('verbali.update', $verbale), $this->datiVerbale($verbale->fresh(), ['presenti' => $verbale->fresh()->presenti."\nInvitato: il revisore dei conti"]))
            ->assertSessionHas('success', 'Verbale aggiornato con successo! L\'approvazione è stata azzerata: il verbale va riapprovato.');
        $this->assertFalse($verbale->fresh()->approvato);
        $this->assertNull($verbale->fresh()->approvato_da);
        $this->come($this->presidente)->post(route('verbali.approva', $verbale))->assertSessionHas('success');
        $this->assertTrue($verbale->fresh()->approvato);

        // Un socio sospeso non ha diritto di voto: neppure in un sondaggio riservato ai soci
        $this->vai('2026-04-01 09:00:00');
        $this->come($this->segretario)->post(route('sondaggi.store'), [
            'domanda' => 'Gita di primavera: quale data?', 'opzioni_testo' => "18 aprile\n25 aprile",
            'aperto_dal' => '2026-04-01T08:00', 'aperto_al' => '2026-04-10T23:59', 'solo_soci' => '1',
        ])->assertSessionHasNoErrors();
        $gita = Sondaggio::query()->where('domanda', 'Gita di primavera: quale data?')->sole();
        $sospeso = $this->creaSocio('Sospeso', 'Sondaggi', ['stato' => Socio::STATO_SOSPESO, 'password_hash' => self::PASSWORD]);
        $this->esci();
        $this->actingAs($sospeso, 'socio')->post(route('area-soci.sondaggi.vota', $gita), ['opzione' => 0]);
        $this->assertSame(
            0,
            RispostaSondaggio::query()->where('sondaggio_id', $gita->id)->where('votante_id', $sospeso->id)->count(),
            'Un socio sospeso vota nei sondaggi riservati ai soci (SondaggiVotoService::vota non controlla lo stato del socio).'
        );
    }

    // ==================================================================
    // 18. Giornata di volontariato: turni, promemoria, presenze, attestati
    // ==================================================================

    public function test_buro_18_assicurazione_e_registro_volontari_il_direttivo_organizza_la_giornata_di_volontariato_con_turni_promemoria_presenze_e_attestati(): void
    {
        $this->vai('2026-04-01 09:00:00');
        $this->come($this->presidente)->post(route('adempimenti.seed'));
        $assicurazione = Adempimento::query()->where('titolo', 'Rinnovo assicurazione volontari')->sole();
        $this->from(route('scadenzario.index'))->post(route('adempimenti.toggle-anno', $assicurazione), ['anno' => 2026]);
        $this->assertTrue($assicurazione->fresh()->isCompletatoPerAnno(2026));

        $this->come($this->segretario)->post(route('mansioni.store'), ['nome' => 'Accoglienza', 'descrizione' => 'Banco informazioni'])->assertRedirect();
        $this->post(route('mansioni.store'), ['nome' => 'Logistica', 'descrizione' => 'Montaggio gazebo'])->assertRedirect();
        $mansioni = \App\Models\Mansione::query()->pluck('id', 'nome');
        $turni = [];
        foreach ([['Montaggio', '08:00', '11:00', 'Logistica', false], ['Accoglienza mattina', '11:00', '14:00', 'Accoglienza', true], ['Smontaggio', '17:00', '20:00', 'Logistica', false]] as [$titolo, $dalle, $alle, $mansione, $auto]) {
            $this->post(route('turni.store'), [
                'titolo' => $titolo, 'data' => '2026-04-18', 'ora_inizio' => $dalle, 'ora_fine' => $alle, 'posti' => 3,
                'mansione_id' => $mansioni[$mansione], 'stato' => \App\Models\Turno::STATO_APERTO, 'auto_conferma' => $auto ? '1' : '0',
            ])->assertSessionHasNoErrors()->assertRedirect();
            $turni[$titolo] = \App\Models\Turno::query()->where('titolo', $titolo)->sole();
        }

        $volontari = [];
        foreach (range(1, 7) as $n) {
            $volontari[$n] = $this->creaSocio('Volontario'.$n, 'Giornata', ['password_hash' => self::PASSWORD, 'volontario_attivo' => $n <= 6]);
        }
        $candidature = [[1, 'Montaggio'], [2, 'Montaggio'], [3, 'Accoglienza mattina'], [4, 'Accoglienza mattina'], [5, 'Smontaggio'], [6, 'Smontaggio']];
        foreach ($candidature as [$n, $turno]) {
            $this->vai('2026-04-0'.(1 + $n).' 19:00:00');
            $this->esci();
            $this->actingAs($volontari[$n], 'socio')->post(route('area-soci.volontariato.candidati', $turni[$turno]))->assertSessionHas('success');
        }
        // L'admin è avvisato solo delle candidature da confermare: le due del turno con conferma automatica no
        Notification::assertSentToTimes($this->presidente, \App\Notifications\VolontariTurni\CandidaturaTurnoNotification::class, 4);
        $assegnazione = fn (int $n) => \App\Models\TurnoAssegnazione::query()->where('socio_id', $volontari[$n]->id)->sole();
        $this->assertSame(\App\Models\TurnoAssegnazione::STATO_CONFERMATO, $assegnazione(3)->stato, 'Turno con conferma automatica');
        $this->assertSame(\App\Models\TurnoAssegnazione::STATO_PROPOSTO, $assegnazione(1)->stato);

        // L'admin conferma le candidature e assegna un settimo volontario allo smontaggio
        $this->vai('2026-04-10 10:00:00');
        foreach ([1, 2, 5, 6] as $n) {
            $a = $assegnazione($n);
            $this->come($this->segretario)->post(route('turni.conferma', [$a->turno_id, $a]))->assertSessionHas('success', 'Candidatura confermata.');
        }
        $this->post(route('turni.assegna', $turni['Smontaggio']), ['socio_id' => $volontari[7]->id, 'conferma' => '1'])->assertSessionHas('success');

        // 15 aprile: Volontario2 rinuncia
        $this->vai('2026-04-15 12:00:00');
        $this->esci();
        $this->actingAs($volontari[2], 'socio')->post(route('area-soci.volontariato.rinuncia', $assegnazione(2)), ['motivo' => 'Influenza'])->assertSessionHas('success');
        Notification::assertSentToTimes($this->presidente, \App\Notifications\VolontariTurni\RinunciaTurnoNotification::class, 1);
        $stati = collect(range(1, 7))->mapWithKeys(fn ($n) => [$n => $assegnazione($n)->stato])->countBy()->all();
        $this->assertSame([\App\Models\TurnoAssegnazione::STATO_CONFERMATO => 6, \App\Models\TurnoAssegnazione::STATO_RINUNCIATO => 1], $stati);

        // Promemoria dei turni dalle 17 del giorno prima
        foreach (['2026-04-16' => 0, '2026-04-17' => 6] as $giorno => $attesi) {
            $this->vai($giorno.' 17:00:00');
            $this->artisan('turni:promemoria')->expectsOutput("Promemoria turni inviati: {$attesi}")->assertSuccessful();
        }
        $this->artisan('turni:promemoria')->expectsOutput('Promemoria turni inviati: 0')->assertSuccessful();
        Notification::assertSentTimes(\App\Notifications\VolontariTurni\PromemoriaTurnoNotification::class, 6);
        Notification::assertNotSentTo($volontari[2], \App\Notifications\VolontariTurni\PromemoriaTurnoNotification::class);
        $this->assertSame(6, PromemoriaInviato::query()->where('tipo', \App\Console\Commands\TurniPromemoria::TIPO)->count());

        // 18 aprile: presenze; Volontario6 non si presenta
        $this->vai('2026-04-18 21:00:00');
        $ore = ['Montaggio' => [1 => '3'], 'Accoglienza mattina' => [3 => '3', 4 => '2.5'], 'Smontaggio' => [5 => '3', 6 => '', 7 => '3']];
        foreach ($ore as $turno => $perVolontario) {
            $this->come($this->segretario)->post(route('turni.presenze', $turni[$turno]), [
                'ore' => collect($perVolontario)->mapWithKeys(fn ($h, $n) => [$assegnazione($n)->id => $h])->all(),
            ])->assertSessionHas('success');
        }
        $this->assertNull($assegnazione(6)->ore_effettive);
        $this->assertEqualsWithDelta(2.5, (float) $assegnazione(4)->ore_effettive, 0.001);

        // 30 aprile: ore manuali del presidente, registro e attestati
        $this->vai('2026-04-30 10:00:00');
        $presidenteVolontario = $this->creaSocio('Pietro', 'Presidenti');
        $this->post(route('registro-ore.manuali'), ['socio_id' => $presidenteVolontario->id, 'ore' => '4', 'data' => '2026-04-18', 'descrizione' => 'Coordinamento della giornata'])
            ->assertSessionHas('success');
        $registro = $this->get(route('registro-ore.index', ['anno' => 2026]))->assertOk()->assertSee('Pietro Presidenti');
        $this->assertEqualsWithDelta(3 + 3 + 2.5 + 3 + 3 + 4, $registro->viewData('totale'), 0.001);

        $attestato = $this->get(route('registro-ore.attestato', [$volontari[4], 'anno' => 2026]))->assertOk();
        $this->assertSame('application/pdf', $attestato->headers->get('content-type'));
        $this->get(route('registro-ore.attestato', [$presidenteVolontario, 'anno' => 2026]))->assertOk();
        $this->get(route('volontari.index'))->assertOk()->assertSee('Volontario1');
        $this->assertTrue($volontari[1]->fresh()->volontario_attivo);
    }

    // ==================================================================
    // 19. Trasparenza: documenti pubblicati, ritirati, link temporanei
    // ==================================================================

    public function test_buro_19_trasparenza_e_documenti_sociali_statuto_regolamento_bilanci_e_verbali_pubblicati_e_ritirati_nel_tempo_con_link_temporanei(): void
    {
        $this->vai('2026-01-10 10:00:00');
        $documenti = [];
        foreach (['statuto-2024.pdf' => 'Statuto', 'regolamento-interno.pdf' => 'Regolamenti', 'bilancio-2024.pdf' => 'Bilanci', 'bilancio-2025.pdf' => 'Bilanci', 'verbale-assemblea-2025.pdf' => 'Verbali'] as $file => $categoria) {
            $this->come($this->segretario)->post(route('documenti.store'), [
                'document_file' => UploadedFile::fake()->create($file, 50, 'application/pdf'),
                'categoria' => $categoria,
                'descrizione' => 'Documento '.$file,
            ])->assertRedirect(route('documenti.index'))->assertSessionHas('success');
            $documenti[$file] = Documento::query()->where('nome_file', $file)->sole();
        }
        foreach (['statuto-2024.pdf' => 'statuto', 'regolamento-interno.pdf' => 'regolamento', 'bilancio-2025.pdf' => 'bilancio'] as $file => $categoria) {
            $this->post(route('documenti.trasparenza', $documenti[$file]), ['categoria_pubblica' => $categoria])->assertSessionHas('success');
        }

        $this->esci();
        $pagina = $this->get(route('pubblico.trasparenza', $this->associazione->slug))->assertOk()
            ->assertSee('statuto-2024.pdf')->assertSee('regolamento-interno.pdf')->assertSee('bilancio-2025.pdf')
            ->assertDontSee('bilancio-2024.pdf')->assertDontSee('verbale-assemblea-2025.pdf');
        $this->get(route('pubblico.trasparenza.download', [$this->associazione->slug, $documenti['statuto-2024.pdf']->id]))->assertOk()->assertDownload('statuto-2024.pdf');
        $this->get(route('pubblico.trasparenza.download', [$this->associazione->slug, $documenti['bilancio-2024.pdf']->id]))->assertNotFound();

        // Link temporaneo del bilancio 2024 per il revisore
        $this->come($this->segretario)->from(route('documenti.index'))->post(route('documenti.link-temporaneo', $documenti['bilancio-2024.pdf']))->assertSessionHas('link_temporaneo');
        $link = (string) session('link_temporaneo');
        $this->esci();
        $this->get($link)->assertOk()->assertDownload('bilancio-2024.pdf');
        $this->vai('2026-01-10 11:01:00');
        $this->get($link)->assertForbidden();

        // Giugno: il bilancio 2025 viene ritirato e sostituito dalla versione corretta
        $this->vai('2026-06-01 09:00:00');
        $this->come($this->segretario)->from(route('documenti.index'))->post(route('documenti.trasparenza', $documenti['bilancio-2025.pdf']))->assertSessionHas('success', 'Documento rimosso dalla pagina Trasparenza.');
        $this->assertFalse($documenti['bilancio-2025.pdf']->fresh()->pubblico);
        $this->assertNull($documenti['bilancio-2025.pdf']->fresh()->categoria_pubblica);
        $this->post(route('documenti.store'), ['document_file' => UploadedFile::fake()->create('bilancio-2025-rev1.pdf', 55, 'application/pdf'), 'categoria' => 'Bilanci'])->assertSessionHas('success');
        $corretto = Documento::query()->where('nome_file', 'bilancio-2025-rev1.pdf')->sole();
        $this->post(route('documenti.trasparenza', $corretto), ['categoria_pubblica' => 'bilancio'])->assertSessionHas('success');
        $this->esci();
        $this->get(route('pubblico.trasparenza', $this->associazione->slug))->assertOk()->assertSee('bilancio-2025-rev1.pdf')->assertDontSee('bilancio-2025.pdf');
        $this->get(route('pubblico.trasparenza.download', [$this->associazione->slug, $documenti['bilancio-2025.pdf']->id]))->assertNotFound();

        $registro = Documento::query()->whereKey($documenti['bilancio-2025.pdf']->id)->first()->activities ?? null;
        $log = \Spatie\Activitylog\Models\Activity::query()->where('subject_id', $documenti['bilancio-2025.pdf']->id)->get();
        $this->assertGreaterThanOrEqual(2, $log->where('event', 'updated')->count(), 'Pubblicazione e ritiro tracciati nel registro modifiche');

        // Un documento cestinato per errore torna; quello vecchio viene eliminato dallo scheduler
        $this->come($this->segretario)->delete(route('documenti.destroy', $documenti['regolamento-interno.pdf']))->assertSessionHas('success');
        $this->post(route('documenti.ripristina', $documenti['regolamento-interno.pdf']->id))->assertSessionHas('success');
        $this->assertNull($documenti['regolamento-interno.pdf']->fresh()->deleted_at);

        $vecchio = $documenti['bilancio-2025.pdf']->fresh();
        $media = $vecchio->getFirstMedia(Documento::COLLEZIONE_FILE);
        $this->assertNotNull($media);
        $disco = $media->disk;
        $percorso = $media->getPathRelativeToRoot();
        Storage::disk($disco)->assertExists($percorso);
        $this->delete(route('documenti.destroy', $vecchio))->assertSessionHas('success');

        $this->vai('2026-08-15 03:00:00');
        $this->artisan('cestino:svuota', ['--giorni' => 90])->expectsOutput('documenti: 0 eliminati definitivamente')->assertSuccessful();
        $this->vai('2026-12-01 03:00:00');
        $this->artisan('cestino:svuota', ['--giorni' => 90])->expectsOutput('documenti: 1 eliminati definitivamente')->assertSuccessful();
        $this->assertNull(Documento::withTrashed()->find($vecchio->id));
        Storage::disk($disco)->assertMissing($percorso);
        $this->come($this->segretario)->get(route('documenti.index'))->assertOk()->assertSee('regolamento-interno.pdf');
    }

    // ==================================================================
    // 20. Tessere a scadenza annuale
    // ==================================================================

    public function test_buro_20_la_segreteria_affronta_il_cambio_d_anno_con_tessere_a_scadenza_annuale_promemoria_a_scaglioni_e_apertura_anno_in_regime_annuale(): void
    {
        $this->vai('2026-01-05 09:00:00');
        $this->configuraStripe();
        $this->come($this->presidente)->post(route('configurazioni.tesseramento'), ['tipo_scadenza_default' => 'annuale', 'giorni_notifica_scadenza' => 30])
            ->assertSessionHasNoErrors();

        $ammetti = function (string $nome, string $giorno) {
            $this->vai($giorno.' 10:00:00');
            $socio = $this->creaSocio($nome, 'Annuali', ['stato' => Socio::STATO_IN_ATTESA_DI_PAGAMENTO, 'data_iscrizione' => $giorno, 'password_hash' => self::PASSWORD]);
            $this->come($this->segretario)->from(route('soci.show', $socio))->post(route('soci.conferma-pagamento', $socio), [
                'importo' => '30.00', 'metodo' => 'contanti', 'tipo' => 'Quota Associativa',
            ])->assertSessionHas('success');

            return $socio->fresh();
        };
        $a = $ammetti('Alfa', '2026-01-10');
        $b = $ammetti('Beta', '2026-04-15');
        $c = $ammetti('Gamma', '2026-09-01');
        foreach ([[$a, '2027-01-10'], [$b, '2027-04-15'], [$c, '2027-09-01']] as [$socio, $scadenza]) {
            $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);
            $tessera = Tessera::query()->where('socio_id', $socio->id)->sole();
            $this->assertSame($scadenza, $tessera->data_scadenza->toDateString(), "Scadenza annuale di {$socio->nome}");
        }

        $giro = function (string $giorno, int $attesi) {
            $this->vai($giorno.' 07:00:00');
            $this->artisan('rinnovi:promemoria')->expectsOutput("Promemoria rinnovo inviati: {$attesi}")->assertSuccessful();
        };
        $giro('2026-12-11', 1);   // A: 30 giorni
        $giro('2026-12-26', 1);   // A: 15 giorni

        // 2 gennaio 2027: apertura dell'anno (quote 2027 per i tre soci)
        $this->vai('2027-01-02 09:00:00');
        $this->come($this->segretario)->post(route('apertura-anno.genera'), ['anno' => 2027])->assertSessionHas('success', 'Anno 2027 aperto: generate 3 quote per i soci attivi.');

        $giro('2027-01-07', 1);   // A: 3 giorni
        // 8 gennaio: Alfa rinnova dall'area soci e paga con carta
        $this->vai('2027-01-08 19:00:00');
        $this->esci();
        $this->actingAs($a, 'socio')->post(route('area-soci.rinnova'))->assertRedirect();
        $quotaA = Quota::query()->where('socio_id', $a->id)->where('anno', 2027)->sole();
        $this->stripePagaQuota($quotaA, 'pi_alfa_2027');
        $rinnovoA = Tessera::query()->where('socio_id', $a->id)->where('anno_validita', 2027)->sole();
        $this->assertSame(['2027-01-08', '2028-01-08'], [$rinnovoA->data_emissione->toDateString(), $rinnovoA->data_scadenza->toDateString()]);
        $giro('2027-01-09', 0);

        $giro('2027-03-16', 1);   // B: 30 giorni
        $giro('2027-04-12', 1);   // B: 3 giorni (15 tracciato insieme)
        // 20 aprile: Beta rinnova in sede
        $this->vai('2027-04-20 10:00:00');
        $quotaB = Quota::query()->where('socio_id', $b->id)->where('anno', 2027)->sole();
        $this->pagaOffline($this->tesoriere, $quotaB, '2027-04-20')->assertSessionHas('success');
        if (! Tessera::query()->where('socio_id', $b->id)->where('anno_validita', 2027)->exists()) {
            $this->come($this->segretario)->post(route('tessere.emetti', $b))->assertSessionHasNoErrors();
        }
        $rinnovoB = Tessera::query()->where('socio_id', $b->id)->where('anno_validita', 2027)->sole();
        $this->assertSame('2028-04-20', $rinnovoB->data_scadenza->toDateString());

        $giro('2027-08-02', 1);   // C: 30 giorni
        $giro('2027-08-10', 0);   // C: nulla finché non arriva ai 15 giorni

        Notification::assertSentToTimes($a, PromemoriaRinnovoNotification::class, 3);
        Notification::assertSentToTimes($b, PromemoriaRinnovoNotification::class, 2);
        Notification::assertSentToTimes($c, PromemoriaRinnovoNotification::class, 1);
        $this->assertSame([3, 15, 30], PromemoriaInviato::query()->where('socio_id', $b->id)->orderBy('step')->pluck('step')->map(fn ($x) => (int) $x)->all());
        Notification::assertSentTo($b, PromemoriaRinnovoNotification::class, fn ($n) => $n->step === 3);
        $this->assertSame(0, Tessera::query()->where('socio_id', $c->id)->where('anno_validita', 2027)->count());
        $this->assertSame(3, Quota::query()->where('anno', 2027)->count());
        $this->assertSame(2, Quota::query()->where('anno', 2027)->whereNotNull('data_pagamento')->count());

        $this->come($this->segretario)->get(route('soci.show', $b))->assertOk()->assertSee('15/04/2027')->assertSee('20/04/2028');
    }

    // ==================================================================
    // 21. Verbali numerati senza buchi in due anni
    // ==================================================================

    public function test_buro_21_verbali_numerati_senza_buchi_in_due_anni_consecutivi_tra_riunioni_del_direttivo_assemblee_e_verbali_inseriti_a_mano(): void
    {
        $presidenteSocio = $this->creaSocio('Pietro', 'Numeri');
        $segretariaSocio = $this->creaSocio('Silvia', 'Numeri');
        $this->carica($presidenteSocio, 'Presidente', '2025-01-01', '2028-12-31');
        $this->carica($segretariaSocio, 'Segretario', '2025-01-01', '2028-12-31');
        $daRiunione = function (string $titolo, string $quando, array $presenti) {
            $this->vai(Carbon::parse($quando)->subDays(5)->format('Y-m-d H:i:s'));
            $riunione = $this->creaRiunione($this->segretario, $titolo, Carbon::parse($quando)->format('Y-m-d\TH:i'), ['Programma attività', 'Varie ed eventuali']);
            $this->post(route('riunioni.convoca', $riunione))->assertSessionHas('success');
            $this->vai(Carbon::parse($quando)->addHours(2)->format('Y-m-d H:i:s'));
            $this->svolgiRiunione($this->segretario, $riunione, $presenti);
            $this->vota($riunione, 0, VotazioneRiunione::ESITO_APPROVATO, count($presenti));

            return [$riunione, $this->generaVerbale($riunione)];
        };
        $aMano = function (string $titolo, string $data, string $tipo = 'Assemblea Ordinaria') {
            $this->come($this->segretario)->post(route('verbali.store'), [
                'titolo' => $titolo, 'data_riunione' => $data, 'tipo_riunione' => $tipo, 'presenti' => 'Vedi foglio firme', 'deliberazioni' => 'Come da allegato.',
            ])->assertSessionHasNoErrors()->assertRedirect(route('verbali.index'));

            return Verbale::query()->where('titolo', $titolo)->sole();
        };

        [$riunioneFeb, $v1] = $daRiunione('Direttivo di febbraio', '2026-02-10 21:00', [$presidenteSocio, $segretariaSocio]);
        $this->vai('2026-03-06 10:00:00');
        $v2 = $aMano('Assemblea di marzo', '2026-03-05');
        [$riunioneMag, $v3] = $daRiunione('Direttivo di maggio', '2026-05-20 21:00', [$presidenteSocio]);
        $this->assertSame(['1/2026', '2/2026', '3/2026'], [$v1->numero_completo, $v2->numero_completo, $v3->numero_completo]);

        // Il verbale da riunione riporta presenti confermati e ordine del giorno
        $this->assertSame('Pietro Numeri (Presidente)', $v3->presenti);
        $this->assertSame("1. Programma attività\n2. Varie ed eventuali", $v3->ordine_del_giorno);

        // Doppio click su "genera verbale": nessun secondo verbale
        $this->post(route('riunioni.genera-verbale', $riunioneMag))->assertSessionHas('warning');
        $this->post(route('riunioni.genera-verbale', $riunioneMag))->assertSessionHas('warning');
        $this->assertSame($v3->id, $riunioneMag->fresh()->verbale_id);
        $this->assertSame(1, Verbale::query()->where('titolo', 'Direttivo di maggio')->count());

        // Settembre: si recupera un verbale di dicembre 2025 mai trascritto
        $this->vai('2026-09-10 10:00:00');
        $v2025 = $aMano('Direttivo di dicembre 2025', '2025-12-20', 'Consiglio Direttivo');
        $this->assertSame('1/2025', $v2025->numero_completo);

        // Novembre: la data dell'assemblea era sbagliata, è del 2027 → rinumerato nel nuovo anno
        $this->vai('2026-11-30 10:00:00');
        $this->put(route('verbali.update', $v2), $this->datiVerbale($v2, ['data_riunione' => '2027-01-05']))->assertSessionHas('success');
        $this->assertSame('1/2027', $v2->fresh()->numero_completo);

        [$riunioneGen, $v2027] = $daRiunione('Direttivo di gennaio 2027', '2027-01-15 21:00', [$presidenteSocio, $segretariaSocio]);
        $this->assertSame('2/2027', $v2027->numero_completo);

        $numeri = fn (int $anno) => Verbale::query()->where('anno', $anno)->orderBy('numero')->pluck('numero')->map(fn ($n) => (int) $n)->all();
        $this->assertSame([1], $numeri(2025));
        $this->assertSame([1, 3], $numeri(2026));
        $this->assertSame([1, 2], $numeri(2027));

        // Il 3/2026 finisce nel cestino e torna con il suo numero
        $this->come($this->segretario)->delete(route('verbali.destroy', $v3))->assertSessionHas('success');
        $this->post(route('verbali.ripristina', $v3->id))->assertSessionHas('success');
        $this->assertSame('3/2026', $v3->fresh()->numero_completo);

        // Elenco: numero di protocollo per ciascun verbale, PDF scaricabile
        $elenco = collect($this->get(route('verbali.index'))->assertOk()->assertSee('3/2026')->assertSee('1/2025')->viewData('verbali')->items());
        $this->assertSame(['2/2027', '1/2027', '3/2026', '1/2026', '1/2025'], $elenco->pluck('numero_completo')->all());
        $this->get(route('verbali.pdf', $v2027))->assertOk()->assertHeader('content-type', 'application/pdf');

        // Un verbale 2027 inserito e poi eliminato definitivamente: il numero si riusa (prossimoNumero = max + 1)
        $this->vai('2027-01-20 10:00:00');
        $errato = $aMano('Bozza errata', '2027-01-19');
        $this->assertSame('3/2027', $errato->numero_completo);
        $this->delete(route('verbali.destroy', $errato))->assertSessionHas('success');
        $this->delete(route('verbali.elimina-definitivamente', $errato->id))->assertSessionHas('success');
        $nuovo = $aMano('Commissione eventi', '2027-01-19', 'Commissione');
        $this->assertSame('3/2027', $nuovo->numero_completo);

        // Mentre il 3/2027 è nel cestino, la segreteria verbalizza un'altra seduta del 2027:
        // il numero assegnato non deve collidere con quello del verbale cestinato
        $this->delete(route('verbali.destroy', $nuovo))->assertSessionHas('success');
        $risposta = $this->post(route('verbali.store'), [
            'titolo' => 'Direttivo straordinario', 'data_riunione' => '2027-01-25', 'tipo_riunione' => 'Consiglio Direttivo', 'deliberazioni' => 'Urgenze.',
        ]);
        $this->assertSame(
            302,
            $risposta->status(),
            'Con un verbale nel cestino il nuovo verbale dello stesso anno non deve collidere con il suo numero (unique associazione, anno, numero).'
        );
        $this->assertSame('4/2027', Verbale::query()->where('titolo', 'Direttivo straordinario')->sole()->numero_completo);
        $this->post(route('verbali.ripristina', $nuovo->id))->assertSessionHas('success');
    }

    // ==================================================================
    // 22. Onboarding di una nuova associazione
    // ==================================================================

    public function test_buro_22_checklist_di_onboarding_di_una_nuova_associazione_dal_primo_accesso_al_primo_socio_passando_per_tutte_le_configurazioni_obbligatorie(): void
    {
        $this->vai('2026-01-02 09:00:00');
        $superAdmin = Utente::factory()->superAdmin()->create(['email' => 'super@piattaforma.test']);
        $superAdmin->assignRole(Utente::RUOLO_SUPER_ADMIN);
        $nuova = Associazione::factory()->create([
            'nome' => 'ODV Il Faro Nuovo', 'slug' => 'odv-il-faro-nuovo', 'email' => 'info@ilfaronuovo.test',
            'costo_tessera' => null, 'frontend_pubblico_attivo' => false,
        ]);
        $admin = $this->creaAdmin('Olga', 'Onboarding', $nuova);
        $passiFatti = function (int $attesi) use ($admin) {
            $this->come($admin)->get(route('dashboard'))->assertOk()->assertSee("{$attesi} di 7 passi completati");
        };

        $passiFatti(1); // lo slug è già impostato
        $this->esci();
        $this->get('/a/odv-il-faro-nuovo')->assertNotFound();

        $this->come($admin)->post(route('configurazioni.info'), [
            'nome' => 'ODV Il Faro Nuovo', 'email' => 'info@ilfaronuovo.test', 'codice_fiscale' => '91234560375', 'citta' => 'Rimini', 'provincia' => 'RN', 'cap' => '47921',
        ])->assertSessionHasNoErrors();
        $this->post(route('configurazioni.logo'), ['logo_file' => UploadedFile::fake()->image('logo-faro.png', 300, 300)])->assertSessionHasNoErrors();
        $passiFatti(2);

        $this->post(route('configurazioni.tesseramento'), ['tipo_scadenza_default' => 'solare', 'giorni_notifica_scadenza' => 30])->assertSessionHasNoErrors();
        $this->post(route('tipi-socio.store'), ['nome' => 'Volontario', 'costo_tessera' => '25'])->assertSessionHasNoErrors();
        $this->post(route('configurazioni.privacy-policy'), ['privacy_policy_html' => '<p>I dati dei soci sono trattati solo per finalità associative.</p>'])->assertSessionHasNoErrors();
        $this->post(route('sedi.save'), ['nome' => 'Sede principale', 'citta' => 'Rimini', 'provincia' => 'RN', 'cap' => '47921'])->assertSessionHasNoErrors();
        $this->post(route('email-impostazioni.store'), [
            'smtp_host' => 'smtp.ilfaronuovo.test', 'smtp_port' => 587, 'smtp_user' => 'posta', 'smtp_pass' => 'segreta-123', 'smtp_encryption' => 'tls',
            'from_email' => 'noreply@ilfaronuovo.test', 'from_name' => 'ODV Il Faro Nuovo', 'max_per_hour' => 100, 'auto_benvenuto' => '1',
        ])->assertSessionHasNoErrors();
        $passiFatti(3);
        $benvenuto = \App\Models\EmailTemplate::query()->withoutGlobalScopes()->where('associazione_id', $nuova->id)->where('codice', 'benvenuto')->first();
        $this->assertNotNull($benvenuto, 'Al salvataggio dell\'SMTP nascono i template predefiniti');
        $benvenuto->forceFill(['attivo' => true])->save();

        $this->post(route('configurazioni.modulo-iscrizione.salva'), [
            'iscrizioni_socio_aperte' => '1', 'modalita_iscrizione' => Associazione::MODALITA_PREISCRIZIONE,
            'campi_standard' => collect(\App\Services\Iscrizioni\FormIscrizioneService::CAMPI_STANDARD)->values()
                ->mapWithKeys(fn ($c, $i) => [$c => ['visibile' => '1', 'obbligatorio' => in_array($c, ['telefono', 'data_nascita'], true) ? '1' : '0', 'ordine' => $i + 1]])->all(),
        ])->assertSessionHasNoErrors();
        $passiFatti(4);

        $this->post(route('configurazioni.pagamenti'), [
            'stripe_enabled' => '1', 'stripe_public_key' => 'pk_test_faro', 'stripe_secret_key' => 'sk_test_faro', 'stripe_webhook_secret' => 'whsec_faro',
        ]);
        $gateway = PaymentGatewaySetting::query()->withoutGlobalScopes()->where('associazione_id', $nuova->id)->first();

        // Primo evento pubblicato e primo socio
        $this->post(route('eventi.store'), [
            'titolo' => 'Open day', 'data_evento' => '2026-01-20T17:00', 'luogo' => 'Sede', 'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
            'capienza' => 50, 'prezzo' => '0', 'max_posti_per_prenotazione' => 2, 'iscrizioni_aperte' => '1', 'richiede_approvazione' => '0', 'solo_soci' => '0',
        ])->assertSessionHasNoErrors();
        $passiFatti(5);

        $this->post(route('soci.store'), [
            'nome' => 'Primo', 'cognome' => 'Socio', 'email' => 'primo.socio@ilfaronuovo.test', 'data_nascita' => '1990-01-01',
            'data_iscrizione' => '2026-01-02', 'stato' => Socio::STATO_IN_ATTESA_DI_PAGAMENTO,
            'tipo_socio_id' => TipoSocio::query()->withoutGlobalScopes()->where('associazione_id', $nuova->id)->sole()->id,
        ])->assertSessionHasNoErrors();
        $primo = Socio::query()->withoutGlobalScopes()->where('email', 'primo.socio@ilfaronuovo.test')->sole();
        $this->assertNotEmpty($primo->numero_socio, 'Numero socio assegnato automaticamente');
        $this->from(route('soci.show', $primo))->post(route('soci.conferma-pagamento', $primo), ['importo' => '25.00', 'metodo' => 'contanti', 'tipo' => 'Quota Associativa'])
            ->assertSessionHas('success');
        $primo->refresh();
        $this->assertSame(Socio::STATO_ATTIVO, $primo->stato);
        $tesseraPrimo = Tessera::query()->withoutGlobalScopes()->where('socio_id', $primo->id)->sole();
        $this->assertSame(Tessera::STATO_ATTIVA, $tesseraPrimo->stato);
        $this->assertTrue(Quota::query()->withoutGlobalScopes()->where('socio_id', $primo->id)->sole()->isPagata());
        // Due benvenuti per spec (soci §1.3 punto 11 e §452): uno alla creazione, uno alla conferma pagamento
        $benvenuti = EmailQueue::query()->withoutGlobalScopes()->where('socio_id', $primo->id)->where('template_codice', 'benvenuto')->count();
        $this->assertSame(2, $benvenuti);
        $passiFatti(6);

        // L'ultimo passo, il costo della tessera dell'associazione, lo fissa la piattaforma
        $this->come($superAdmin)->withSession(['associazione_id' => $nuova->id])->put(route('associazioni.update', $nuova), [
            'nome' => 'ODV Il Faro Nuovo', 'email' => 'info@ilfaronuovo.test', 'costo_tessera' => '25', 'attiva' => '1', 'frontend_pubblico_attivo' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('25.00', (string) $nuova->fresh()->costo_tessera);
        $this->come($admin)->get(route('dashboard'))->assertOk()->assertDontSee('passi completati');
        $this->assertTrue($nuova->fresh()->attiva);

        // Impostazioni dei pagamenti salvate con le chiavi
        $this->assertNotNull($gateway, 'Impostazioni pagamenti salvate');

        // Infine: la prima tessera segue la numerazione annuale (2026 + progressivo) e il
        // mini-sito pubblico può essere acceso
        $this->assertSame(
            ['numero_prima_tessera' => '20260001', 'mini_sito_attivo' => true],
            ['numero_prima_tessera' => $tesseraPrimo->numero_tessera, 'mini_sito_attivo' => (bool) $nuova->fresh()->frontend_pubblico_attivo],
            'La tessera emessa alla conferma del pagamento usa NumeroProgressivoService ("0001", TesseraAutomaticaService::creaPerAnnoCorrente) invece della numerazione annuale di TesseraIssuer ("2026NNNN"); '
            .'frontend_pubblico_attivo non è gestito da nessun form (né configurazioni né UpdateAssociazioneRequest): il mini-sito non si può attivare.'
        );
        $this->esci();
        $this->get('/a/odv-il-faro-nuovo')->assertOk()->assertSee('ODV Il Faro Nuovo');
    }

    // ==================================================================
    // 23. Due associazioni sulla stessa piattaforma
    // ==================================================================

    public function test_buro_23_due_associazioni_sulla_stessa_piattaforma_vivono_lo_stesso_anno_sociale_senza_che_scheduler_e_segreterie_si_mescolino(): void
    {
        $this->vai('2026-01-10 08:00:00');
        $b = Associazione::factory()->create(['nome' => 'Circolo Il Gabbiano', 'slug' => 'circolo-il-gabbiano', 'frontend_pubblico_attivo' => true, 'costo_tessera' => 20]);
        $adminB = $this->creaAdmin('Bruno', 'Gabbiani', $b);

        // Stesse persone iscritte a entrambe le associazioni
        $sociA = $sociB = [];
        foreach (['Mario', 'Lucia', 'Franco'] as $i => $nome) {
            $dati = ['email' => strtolower($nome).'@entrambe.test', 'data_nascita' => $i === 0 ? '1980-01-10' : '1985-06-0'.($i + 1)];
            $sociA[$nome] = $this->creaSocio($nome, 'Doppi', $dati);
            $sociB[$nome] = $this->creaSocio($nome, 'Doppi', $dati, $b);
            $this->creaTessera($sociA[$nome], 2026);
            $this->creaTessera($sociB[$nome], 2026);
        }
        $this->carica($sociA['Mario'], 'Presidente', '2025-01-01', '2027-12-31');
        $this->carica($sociB['Lucia'], 'Presidente', '2025-01-01', '2027-12-31');

        // Solo A vuole gli auguri automatici
        $this->come($this->presidente)->post(route('configurazioni.comunicazione'), ['auguri_automatici' => '1'])->assertSessionHas('success');
        $this->assertTrue((bool) $this->associazione->fresh()->auguri_automatici);
        $this->assertFalse((bool) $b->fresh()->auguri_automatici);
        $this->artisan('compleanni:auguri')->assertSuccessful();
        Notification::assertSentTo($sociA['Mario'], \App\Notifications\Comunicazione\AuguriCompleannoNotification::class);
        Notification::assertNotSentTo($sociB['Mario'], \App\Notifications\Comunicazione\AuguriCompleannoNotification::class);

        // Checklist e scadenze in entrambe
        $this->come($this->presidente)->post(route('adempimenti.seed'))->assertSessionHas('success');
        $this->come($adminB)->post(route('adempimenti.seed'))->assertSessionHas('success');
        $this->assertSame(4, Adempimento::query()->withoutGlobalScopes()->where('associazione_id', $b->id)->count());
        foreach ([[$this->presidente, 'Rinnovo polizza A'], [$adminB, 'Rinnovo polizza B']] as [$chi, $titolo]) {
            $this->come($chi)->post(route('scadenze.store'), [
                'titolo' => $titolo, 'data_scadenza' => '2026-01-15', 'tipo_scadenza' => 'Documenti', 'priorita' => 'Alta',
                'stato' => Scadenza::STATO_ATTIVA, 'assegnato_a' => '', 'promemoria_giorni' => 7,
            ])->assertSessionHasNoErrors();
        }
        $this->artisan('adempimenti:promemoria', ['--mese' => 1])->expectsOutput('Promemoria adempimenti inviati: 2')->assertSuccessful();
        $this->artisan('scadenze:invia-promemoria')->expectsOutput('Promemoria inviati: 2')->assertSuccessful();

        Notification::assertSentTo($this->presidente, PromemoriaAdempimentoNotification::class, fn ($n) => $n->adempimento->associazione_id === $this->associazione->id);
        Notification::assertSentTo($adminB, PromemoriaAdempimentoNotification::class, fn ($n) => $n->adempimento->associazione_id === $b->id);
        Notification::assertNotSentTo($adminB, PromemoriaAdempimentoNotification::class, fn ($n) => $n->adempimento->associazione_id === $this->associazione->id);
        Notification::assertNotSentTo($this->presidente, PromemoriaAdempimentoNotification::class, fn ($n) => $n->adempimento->associazione_id === $b->id);
        Notification::assertSentTo($adminB, PromemoriaScadenzaNotification::class, fn ($n) => $n->scadenza->titolo === 'Rinnovo polizza B');
        Notification::assertNotSentTo($adminB, PromemoriaScadenzaNotification::class, fn ($n) => $n->scadenza->titolo === 'Rinnovo polizza A');
        Notification::assertNotSentTo($this->presidente, PromemoriaScadenzaNotification::class, fn ($n) => $n->scadenza->titolo === 'Rinnovo polizza B');

        // La segreteria di B prova a mettere le mani sull'associazione A
        $richiestaA = $this->cittadinoChiedeIscrizione('Carla', 'Contese');
        $riunioneA = $this->creaRiunione($this->segretario, 'Direttivo A', '2026-01-09T21:00', ['Bilancio'], ['stato' => Riunione::STATO_SVOLTA]);
        $this->come($adminB)->post(route('richieste-iscrizione.approva', $richiestaA), ['genera_quota' => '1'])->assertNotFound();
        $this->post(route('cessazioni.store'), ['socio_id' => $sociA['Franco']->id, 'tipo' => Cessazione::TIPO_ESPULSIONE, 'data' => '2026-01-10'])->assertSessionHasErrors('socio_id');
        $this->post(route('riunioni.genera-verbale', $riunioneA))->assertNotFound();
        $this->assertSame(RichiestaIscrizione::STATO_IN_ATTESA, $richiestaA->fresh()->stato);
        $this->assertSame(Socio::STATO_ATTIVO, $sociA['Franco']->fresh()->stato);
        $this->assertSame(0, Verbale::query()->withoutGlobalScopes()->count());
        $libroB = collect($this->get(route('libro-soci.index'))->assertOk()->viewData('righe'));
        $this->assertTrue($libroB->every(fn ($r) => $r['socio']->associazione_id === $b->id));
        $this->assertCount(3, $libroB);

        // Convocazione dell'assemblea di A: solo i soci di A
        $this->come($this->segretario)->post(route('annunci.store'), [
            'titolo' => 'Assemblea A', 'tipo' => Annuncio::TIPO_CONVOCAZIONE, 'corpo_html' => '<p>Assemblea.</p>', 'data_assemblea' => '2026-02-20T18:00',
            'pubblicato_dal' => now()->subHour()->format('Y-m-d\TH:i'), 'in_evidenza' => '0', 'solo_soci' => '1',
        ])->assertSessionHasNoErrors();
        $this->post(route('annunci.invia-convocazione', Annuncio::query()->sole()))->assertSessionHas('success', 'Convocazione inviata a 3 soci.');
        Notification::assertNotSentTo(array_values($sociB), ConvocazioneAssembleaNotification::class);

        // Fine anno: promemoria di rinnovo e apertura 2027 solo per A
        $this->vai('2026-12-02 07:00:00');
        $this->artisan('rinnovi:promemoria')->expectsOutput('Promemoria rinnovo inviati: 6')->assertSuccessful();
        foreach ([...array_values($sociA), ...array_values($sociB)] as $socio) {
            Notification::assertSentTo($socio, PromemoriaRinnovoNotification::class, fn ($n) => $n->tessera->associazione_id === $socio->associazione_id && $n->tessera->socio_id === $socio->id);
        }
        $this->artisan('rinnovi:apri-anno', ['anno' => 2027, '--associazione' => $this->associazione->id])->assertSuccessful();
        $this->assertSame(3, Quota::query()->withoutGlobalScopes()->where('anno', 2027)->where('associazione_id', $this->associazione->id)->count());
        $this->assertSame(0, Quota::query()->withoutGlobalScopes()->where('anno', 2027)->where('associazione_id', $b->id)->count());

        foreach (PromemoriaInviato::query()->withoutGlobalScopes()->get() as $riga) {
            if ($riga->socio_id !== null) {
                $this->assertSame(Socio::query()->withoutGlobalScopes()->find($riga->socio_id)->associazione_id, $riga->associazione_id);
            } else {
                $this->assertSame(Adempimento::query()->withoutGlobalScopes()->find($riga->riferimento_id)->associazione_id, $riga->associazione_id);
            }
        }
    }

    // ==================================================================
    // 24. Un mese tipo della segreteria
    // ==================================================================

    public function test_buro_24_attivita_della_segreteria_in_un_mese_tipo_attivita_admin_note_promemoria_quotidiani_e_registro_modifiche_consultato_dal_presidente(): void
    {
        $this->vai('2026-10-01 09:00:00');
        $soci = [];
        foreach (['Anselmo', 'Berta', 'Corrado'] as $nome) {
            $soci[$nome] = $this->creaSocio($nome, 'Schedati', ['telefono' => '3330000000', 'citta' => 'Modena']);
        }
        $nuova = function (string $titolo, ?string $scadenza, ?Utente $assegnatario, ?Socio $socio = null) {
            $this->come($this->segretario)->post(route('attivita-admin.store'), [
                'titolo' => $titolo, 'scadenza' => $scadenza, 'assegnatario_id' => $assegnatario?->id,
                'collegamento' => $socio ? 'socio' : 'nessuno', 'socio_id' => $socio?->id,
            ])->assertSessionHas('success', 'Attività creata.');

            return AttivitaAdmin::query()->where('titolo', $titolo)->sole();
        };
        $chiamata = $nuova('Richiamare Anselmo per il certificato', '2026-10-03', $this->segretario, $soci['Anselmo']);
        $ordine = $nuova('Ordinare le tessere nuove', '2026-10-03', $this->segretario);
        $bando = $nuova('Bozza domanda bando regionale', '2026-10-10', $this->segretario);
        $rendiconto = $nuova('Rendiconto contributo comunale', '2026-10-20', $this->tesoriere, $soci['Berta']);
        $libera = $nuova('Riordinare l\'archivio', '2026-10-10', null);
        $this->assertSame($soci['Anselmo']->id, $chiamata->notabile_id);

        // Il 2 ottobre l'ordine delle tessere è già fatto
        $this->vai('2026-10-02 16:00:00');
        $this->from(route('attivita-admin.index'))->post(route('attivita-admin.toggle', $ordine))->assertSessionHas('success', 'Attività completata.');
        $this->assertSame(AttivitaAdmin::STATO_COMPLETATA, $ordine->fresh()->stato);
        $this->assertSame('2026-10-02', $ordine->fresh()->completata_at->toDateString());

        $giro = function (string $giorno, int $attesi) {
            $this->vai($giorno.' 07:15:00');
            $this->artisan('attivita:promemoria')->expectsOutput("Promemoria attività inviati: {$attesi}")->assertSuccessful();
        };
        $giro('2026-10-03', 1);
        $giro('2026-10-03', 0);
        Notification::assertSentTo($this->segretario, PromemoriaAttivitaNotification::class, fn ($n) => $n->attivita->is($chiamata));
        Notification::assertNotSentTo($this->segretario, PromemoriaAttivitaNotification::class, fn ($n) => $n->attivita->is($ordine));

        // Durante il mese: note, schede aggiornate, una richiesta di modifica approvata
        $this->vai('2026-10-05 11:00:00');
        $this->come($this->segretario)->from(route('soci.show', $soci['Anselmo']))->post(route('soci.note.store', $soci['Anselmo']), ['testo' => 'Preferisce essere contattato la sera.', 'fissata' => '1'])->assertSessionHas('success');
        $this->post(route('soci.note.store', $soci['Anselmo']), ['testo' => 'Ha portato il certificato medico.'])->assertSessionHas('success');
        foreach ($soci as $nome => $socio) {
            $this->put(route('soci.update', $socio), [
                'nome' => $nome, 'cognome' => 'Schedati', 'email' => $socio->email, 'numero_socio' => $socio->numero_socio,
                'data_nascita' => $socio->data_nascita->toDateString(), 'data_iscrizione' => $socio->data_iscrizione->toDateString(),
                'stato' => Socio::STATO_ATTIVO, 'telefono' => '3389990000', 'citta' => 'Carpi',
            ])->assertSessionHasNoErrors()->assertRedirect(route('soci.show', $socio));
        }
        $richiesta = \App\Models\RichiestaModificaDati::factory()->create(['socio_id' => $soci['Corrado']->id, 'proposte' => ['telefono' => '3471112233']]);
        $this->post(route('richieste-modifica-dati.approva', $richiesta))->assertSessionHas('success');
        $this->assertSame('3471112233', $soci['Corrado']->fresh()->telefono);

        $giro('2026-10-10', 1);
        $giro('2026-10-20', 1);
        Notification::assertSentTo($this->segretario, PromemoriaAttivitaNotification::class, fn ($n) => $n->attivita->is($bando));
        Notification::assertSentTo($this->tesoriere, PromemoriaAttivitaNotification::class, fn ($n) => $n->attivita->is($rendiconto));
        Notification::assertSentToTimes($this->segretario, PromemoriaAttivitaNotification::class, 2);
        Notification::assertSentToTimes($this->tesoriere, PromemoriaAttivitaNotification::class, 1);
        Notification::assertNotSentTo($this->presidente, PromemoriaAttivitaNotification::class);
        $this->assertSame(AttivitaAdmin::STATO_APERTA, $libera->fresh()->stato);

        // 31 ottobre: il presidente controlla
        $this->vai('2026-10-31 18:00:00');
        $registro = $this->come($this->presidente)->get(route('registro-modifiche.index', ['modello' => 'Socio', 'utente_id' => $this->segretario->id]))->assertOk()
            ->assertSee('Sergio Segretari')->assertSee('Carpi');
        $voci = collect($registro->viewData('attivita')->items());
        $cambi = fn ($a) => collect($a->attribute_changes ?? $a->properties ?? []);
        $citta = $voci->filter(fn ($a) => ($cambi($a)['attributes']['citta'] ?? null) === 'Carpi');
        $this->assertCount(3, $citta);
        $this->assertTrue($citta->every(fn ($a) => $a->causer_id === $this->segretario->id && ($cambi($a)['old']['citta'] ?? null) === 'Modena'));

        $scheda = $this->get(route('soci.show', $soci['Anselmo']))->assertOk()
            ->assertSeeInOrder(['Preferisce essere contattato la sera.', 'Ha portato il certificato medico.']);
        $aperte = collect($this->get(route('attivita-admin.index'))->assertOk()->viewData('attivita')->items());
        $this->assertEqualsCanonicalizing([$chiamata->id, $bando->id, $rendiconto->id, $libera->id], $aperte->pluck('id')->all());
        $this->assertTrue($aperte->filter(fn ($a) => $a->scadenza !== null)->every(fn ($a) => $a->scadenza->isPast()), 'A fine mese tutte le aperte con scadenza sono scadute');
        $mieSegretario = collect($this->come($this->segretario)->get(route('attivita-admin.index', ['assegnatario' => 'mie']))->viewData('attivita')->items());
        $this->assertEqualsCanonicalizing([$chiamata->id, $bando->id], $mieSegretario->pluck('id')->all());
    }

    // ==================================================================
    // 25. Un anno sociale completo
    // ==================================================================

    public function test_buro_25_un_anno_sociale_completo_dal_1_gennaio_al_31_dicembre_con_tutti_gli_adempimenti_del_direttivo_e_il_bilancio_delle_notifiche_istituzionali(): void
    {
        $this->vai('2026-01-02 08:00:00');
        $this->configuraSmtp();
        $this->configuraStripe();
        $dueVolte = function (string $comando, array $parametri = []) {
            $this->artisan($comando, $parametri)->assertSuccessful();
            $this->artisan($comando, $parametri)->assertSuccessful();
        };
        $toggle = function (string $titolo, int $anno = 2026) {
            $this->come($this->segretario)->from(route('scadenzario.index'))
                ->post(route('adempimenti.toggle-anno', Adempimento::query()->where('titolo', $titolo)->sole()), ['anno' => $anno])
                ->assertRedirect(route('scadenzario.index'));
        };

        // Il corpo sociale: dieci soci con la tessera 2025, tre nel direttivo
        $soci = [];
        foreach (['Ada', 'Bruno', 'Carla', 'Dario', 'Elsa', 'Fabio', 'Greta', 'Ivo', 'Lea', 'Mauro'] as $nome) {
            $soci[$nome] = $this->creaSocio($nome, 'Annuari', ['email' => strtolower($nome).'@annuario.test', 'data_iscrizione' => '2022-02-01']);
            $this->creaTessera($soci[$nome], 2025);
        }
        $soci['Lea']->update(['email_opt_out' => true]);
        foreach ([['Ada', 'Presidente'], ['Bruno', 'Segretario'], ['Carla', 'Consigliere']] as [$nome, $ruolo]) {
            $this->carica($soci[$nome], $ruolo, '2023-05-10', '2026-05-09');
        }
        $morosi = ['Ivo', 'Mauro'];

        // 2 gennaio: apertura anno, checklist, scadenze dell'anno
        $this->come($this->segretario)->post(route('apertura-anno.genera'), ['anno' => 2026])->assertSessionHas('success', 'Anno 2026 aperto: generate 10 quote per i soci attivi.');
        $this->post(route('adempimenti.seed'))->assertSessionHas('success');
        foreach ([['Polizza RC', '2026-01-31', 10], ['Deposito RUNTS', '2026-06-30', 15], ['Rinnovo dominio', '2026-10-31', 20]] as [$titolo, $data, $giorni]) {
            $this->post(route('scadenze.store'), [
                'titolo' => $titolo, 'data_scadenza' => $data, 'tipo_scadenza' => 'Generale', 'priorita' => 'Media',
                'stato' => Scadenza::STATO_ATTIVA, 'assegnato_a' => '', 'promemoria_giorni' => $giorni,
            ])->assertSessionHasNoErrors();
        }
        $this->come($this->tesoriere)->get(route('prima-nota.index'))->assertOk();
        foreach ([['2026-01-31', 'Affitto sede', '400.00', 'Utenze e affitti'], ['2026-02-10', 'Polizza RC volontari', '250.00', 'Altre uscite']] as [$data, $descr, $importo, $categoria]) {
            $this->post(route('prima-nota.store'), [
                'tipo' => Movimento::TIPO_USCITA, 'importo' => $importo, 'data' => $data, 'descrizione' => $descr,
                'categoria_id' => CategoriaMovimento::query()->where('nome', $categoria)->sole()->id,
            ])->assertSessionHasNoErrors();
        }

        // Gennaio: promemoria dell'assicurazione e campagna rinnovi 2026
        $this->vai('2026-01-05 08:00:00');
        $dueVolte('adempimenti:promemoria');
        $toggle('Rinnovo assicurazione volontari');
        $this->come($this->segretario)->post(route('campagna-rinnovi.invia'), ['socio_ids' => collect($soci)->pluck('id')->all()])
            ->assertSessionHas('success', 'Campagna rinnovi 2026: 9 email accodate. 1 soci saltati (senza email, opt-out o già rinnovati).');
        $this->vai('2026-01-21 07:30:00');
        $dueVolte('scadenze:invia-promemoria');

        foreach (array_diff(array_keys($soci), $morosi) as $i => $nome) {
            $this->vai('2026-01-'.(10 + $i).' 10:00:00');
            $quota = Quota::query()->where('socio_id', $soci[$nome]->id)->where('anno', 2026)->sole();
            $this->pagaOffline($this->tesoriere, $quota, now()->toDateString())->assertSessionHas('success');
            $this->come($this->segretario)->post(route('tessere.emetti', $soci[$nome]))->assertRedirect();
        }

        // Febbraio: solleciti ai due morosi
        $this->vai('2026-02-15 09:00:00');
        $this->come($this->tesoriere)->post(route('solleciti-quote.bulk'), [
            'quota_ids' => Quota::query()->where('anno', 2026)->whereNull('data_pagamento')->pluck('id')->all(),
        ])->assertSessionHas('success', 'Solleciti inviati: 2.');

        // Marzo: tre domande e delibera di ammissione (verbale 1/2026)
        $richieste = [];
        foreach (['Nina', 'Oreste', 'Paride'] as $i => $nome) {
            $this->vai('2026-03-0'.(2 + $i).' 18:00:00');
            $richieste[$nome] = $this->cittadinoChiedeIscrizione($nome, 'Nuovissimi');
        }
        $riunione = $this->creaRiunione($this->segretario, 'Direttivo di marzo', '2026-03-12T21:00', ['Ammissione nuovi soci']);
        $this->post(route('riunioni.convoca', $riunione))->assertSessionHas('success');
        $this->vai('2026-03-12 23:00:00');
        $this->svolgiRiunione($this->segretario, $riunione, [$soci['Ada'], $soci['Bruno'], $soci['Carla']]);
        $this->vota($riunione, 0, VotazioneRiunione::ESITO_APPROVATO, 3);
        $verbale1 = $this->generaVerbale($riunione);
        $this->vai('2026-03-13 10:00:00');
        $nuovi = [];
        foreach ($richieste as $nome => $richiesta) {
            $this->come($this->segretario)->post(route('richieste-iscrizione.approva', $richiesta), [
                'genera_quota' => '1', 'delibera_numero' => $verbale1->numero_completo, 'delibera_data' => '2026-03-12',
            ])->assertSessionHas('success');
            $nuovi[$nome] = Socio::query()->findOrFail($richiesta->fresh()->socio_id);
            $this->pagaOffline($this->tesoriere, Quota::query()->where('socio_id', $nuovi[$nome]->id)->sole(), '2026-03-13')->assertSessionHas('success');
            $this->come($this->segretario)->post(route('tessere.emetti', $nuovi[$nome]))->assertRedirect();
        }

        // Aprile: assemblea ordinaria con rendiconto (verbale 2/2026), bilancio in trasparenza
        $this->vai('2026-04-01 08:00:00');
        $dueVolte('adempimenti:promemoria');
        $this->come($this->segretario)->post(route('annunci.store'), [
            'titolo' => 'Assemblea ordinaria 2026', 'tipo' => Annuncio::TIPO_CONVOCAZIONE, 'corpo_html' => '<p>Approvazione del rendiconto 2025.</p>',
            'data_assemblea' => '2026-04-25T18:00', 'luogo_assemblea' => 'Sede', 'pubblicato_dal' => now()->subHour()->format('Y-m-d\TH:i'),
            'in_evidenza' => '1', 'solo_soci' => '1',
        ])->assertSessionHasNoErrors();
        $this->post(route('annunci.invia-convocazione', Annuncio::query()->sole()))->assertSessionHas('success', 'Convocazione inviata a 12 soci.');
        $this->post(route('eventi.store'), [
            'titolo' => 'Assemblea ordinaria', 'data_evento' => '2026-04-25T18:00', 'luogo' => 'Sede', 'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
            'capienza' => 100, 'prezzo' => '0', 'max_posti_per_prenotazione' => 1, 'iscrizioni_aperte' => '0', 'richiede_approvazione' => '0', 'solo_soci' => '1',
        ])->assertSessionHasNoErrors();
        $assemblea = Evento::query()->where('titolo', 'Assemblea ordinaria')->sole();
        $assembleaRiunione = $this->creaRiunione($this->segretario, 'Assemblea ordinaria 2026', '2026-04-25T18:00', ['Approvazione rendiconto 2025']);
        $this->post(route('riunioni.convoca', $assembleaRiunione))->assertSessionHas('success');
        $this->post(route('documenti.store'), ['document_file' => UploadedFile::fake()->create('rendiconto-2025.pdf', 80, 'application/pdf'), 'categoria' => 'Bilanci'])->assertSessionHas('success');
        $rendiconto = Documento::query()->sole();

        $this->vai('2026-04-25 18:10:00');
        foreach (['Ada', 'Bruno', 'Carla', 'Dario', 'Elsa'] as $nome) {
            $this->come($this->segretario)->postJson(route('tessere.checkin.store'), ['evento_id' => $assemblea->id, 'numero_socio' => $soci[$nome]->numero_socio])
                ->assertOk()->assertJsonPath('success', true);
        }
        $this->vai('2026-04-25 21:00:00');
        $this->svolgiRiunione($this->segretario, $assembleaRiunione, [$soci['Ada'], $soci['Bruno'], $soci['Carla']]);
        $this->vota($assembleaRiunione, 0, VotazioneRiunione::ESITO_APPROVATO, 5);
        $verbale2 = $this->generaVerbale($assembleaRiunione);
        foreach ([$soci['Ada'], $soci['Bruno']] as $firmatario) {
            $this->post(route('verbali.firmatari.store', $verbale2), ['socio_id' => $firmatario->id, 'ruolo' => 'Firmatario'])->assertRedirect(route('verbali.index'));
        }
        foreach ($verbale2->firmatari()->get() as $firma) {
            $this->post(route('verbali.firmatari.firma', [$verbale2, $firma]))->assertSessionHas('success');
        }
        $this->come($this->presidente)->post(route('verbali.approva', $verbale2))->assertSessionHas('success');
        $this->post(route('documenti.trasparenza', $rendiconto), ['categoria_pubblica' => 'bilancio'])->assertSessionHas('success');
        $toggle('Approvazione bilancio annuale');

        // Maggio: rinnovo delle cariche (verbale 3/2026)
        $this->vai('2026-05-01 08:00:00');
        $dueVolte('adempimenti:promemoria');
        $elettiva = $this->creaRiunione($this->segretario, 'Assemblea elettiva', '2026-05-08T18:00', ['Elezione direttivo 2026-2029']);
        $this->post(route('riunioni.convoca', $elettiva))->assertSessionHas('success');
        $this->vai('2026-05-08 21:00:00');
        $this->svolgiRiunione($this->segretario, $elettiva, [$soci['Ada'], $soci['Bruno'], $soci['Carla']]);
        $this->vota($elettiva, 0, VotazioneRiunione::ESITO_APPROVATO, 10);
        $verbale3 = $this->generaVerbale($elettiva);
        $this->come($this->presidente)->post(route('verbali.approva', $verbale3))->assertSessionHas('success');
        $this->vai('2026-05-10 09:00:00');
        foreach ([['Ada', 'Presidente'], ['Bruno', 'Segretario'], ['Dario', 'Consigliere']] as [$nome, $ruolo]) {
            $this->come($this->segretario)->post(route('organi-sociali.store'), ['socio_id' => $soci[$nome]->id, 'ruolo' => $ruolo, 'inizio' => '2026-05-10', 'fine' => '2029-05-09'])
                ->assertSessionHasNoErrors();
        }
        $toggle('Rinnovo cariche sociali');
        $this->vai('2026-06-15 07:30:00');
        $dueVolte('scadenze:invia-promemoria');

        // Giugno: festa sociale a pagamento
        $this->come($this->segretario)->post(route('eventi.store'), [
            'titolo' => 'Festa sociale', 'data_evento' => '2026-06-27T19:00', 'luogo' => 'Parco', 'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
            'capienza' => 50, 'prezzo' => '5', 'max_posti_per_prenotazione' => 1, 'iscrizioni_aperte' => '1', 'richiede_approvazione' => '0', 'solo_soci' => '0',
        ])->assertSessionHasNoErrors();
        $festa = Evento::query()->where('titolo', 'Festa sociale')->sole();
        foreach (['ospite1@festa.test', 'ospite2@festa.test'] as $email) {
            $account = Account::factory()->create(['associazione_id' => $this->associazione->id, 'email' => $email]);
            $this->esci();
            $this->actingAs($account, 'account')->post(route('pubblico.eventi.prenota', ['associazione' => $this->associazione->slug, 'evento' => $festa->id]), ['posti' => 1])
                ->assertSessionHasNoErrors();
            $prenotazione = \App\Models\Prenotazione::query()->where('account_id', $account->id)->sole();
            $this->come($this->segretario)->post(route('eventi.prenotazioni.conferma-pagamento', ['evento' => $festa->id, 'prenotazione' => $prenotazione->id]))->assertSessionHas('success');
        }
        $this->vai('2026-06-28 10:00:00');
        $dueVolte('eventi:invia-feedback');

        // Settembre: Mauro paga, Ivo viene escluso (verbale 4/2026)
        $this->vai('2026-09-05 10:00:00');
        $this->pagaOffline($this->tesoriere, Quota::query()->where('socio_id', $soci['Mauro']->id)->where('anno', 2026)->sole(), '2026-09-05')->assertSessionHas('success');
        $this->come($this->segretario)->post(route('tessere.emetti', $soci['Mauro']))->assertRedirect();
        $esclusione = $this->creaRiunione($this->segretario, 'Direttivo di settembre', '2026-09-15T21:00', ['Esclusione soci morosi']);
        $this->post(route('riunioni.convoca', $esclusione))->assertSessionHas('success');
        $this->vai('2026-09-15 23:00:00');
        $this->svolgiRiunione($this->segretario, $esclusione, [$soci['Ada'], $soci['Bruno'], $soci['Dario']]);
        $this->vota($esclusione, 0, VotazioneRiunione::ESITO_APPROVATO, 3);
        $verbale4 = $this->generaVerbale($esclusione);
        $this->vai('2026-09-16 10:00:00');
        $this->come($this->segretario)->post(route('cessazioni.store'), [
            'socio_id' => $soci['Ivo']->id, 'tipo' => Cessazione::TIPO_DECADENZA, 'data' => '2026-09-16', 'motivo' => 'morosità',
            'delibera_numero' => $verbale4->numero_completo, 'delibera_data' => '2026-09-15',
        ])->assertSessionHasNoErrors();
        $this->vai('2026-10-15 07:30:00');
        $dueVolte('scadenze:invia-promemoria');

        // Ottobre: newsletter
        $this->vai('2026-10-05 10:00:00');
        $this->come($this->segretario)->post(route('annunci.store'), [
            'titolo' => 'Programma autunno', 'tipo' => Annuncio::TIPO_COMUNICAZIONE, 'corpo_html' => '<p>Corsi e laboratori.</p>',
            'pubblicato_dal' => now()->subHour()->format('Y-m-d\TH:i'), 'in_evidenza' => '0', 'solo_soci' => '0',
        ])->assertSessionHasNoErrors();
        $this->post(route('newsletter.store'), ['titolo' => 'Notizie di ottobre', 'annunci' => [Annuncio::query()->where('titolo', 'Programma autunno')->sole()->id]])
            ->assertSessionHas('success', 'Newsletter accodata a 11 soci. Verrà inviata dalla coda di invio.');

        // Novembre: apertura 2027 (la campagna lavora sull'anno solare: per il 2026 sono tutti in regola)
        $this->vai('2026-11-02 09:00:00');
        $this->come($this->segretario)->post(route('apertura-anno.genera'), ['anno' => 2027])->assertSessionHas('success', 'Anno 2027 aperto: generate 12 quote per i soci attivi.');
        $this->post(route('campagna-rinnovi.invia'), ['socio_ids' => Socio::query()->where('stato', Socio::STATO_ATTIVO)->pluck('id')->all()])
            ->assertSessionHas('success', 'Campagna rinnovi 2026: 0 email accodate. 12 soci saltati (senza email, opt-out o già rinnovati).');

        // Dicembre: promemoria di rinnovo a scaglioni; Elsa rinnova il 10
        $this->vai('2026-12-01 08:00:00');
        $dueVolte('adempimenti:promemoria');
        $this->vai('2026-12-02 07:00:00');
        $dueVolte('rinnovi:promemoria');
        $this->vai('2026-12-10 10:00:00');
        $this->pagaOffline($this->tesoriere, Quota::query()->where('socio_id', $soci['Elsa']->id)->where('anno', 2027)->sole(), '2026-12-10')->assertSessionHas('success');
        // Il pagamento offline della quota 2027 emette già la tessera 2027 (come
        // quello online): l'emissione manuale serve solo se manca
        if (! Tessera::query()->where('socio_id', $soci['Elsa']->id)->where('anno_validita', 2027)->exists()) {
            $this->come($this->segretario)->post(route('tessere.store'), [
                'socio_id' => $soci['Elsa']->id, 'numero_tessera' => '20270001', 'anno_validita' => 2027, 'data_emissione' => '2026-12-10', 'stato' => Tessera::STATO_ATTIVA,
            ])->assertSessionHasNoErrors();
        }
        $this->assertSame(1, Tessera::query()->where('socio_id', $soci['Elsa']->id)->where('anno_validita', 2027)->count());
        foreach (['2026-12-16', '2026-12-28'] as $giorno) {
            $this->vai($giorno.' 07:00:00');
            $dueVolte('rinnovi:promemoria');
        }
        $this->vai('2026-12-30 10:00:00');
        $toggle('Aggiornamento libro soci');

        // ── Il bilancio dell'anno ──────────────────────────────────────
        $admin = [$this->presidente, $this->segretario];
        foreach ($admin as $a) {
            Notification::assertSentToTimes($a, PromemoriaAdempimentoNotification::class, 4);
            Notification::assertSentToTimes($a, PromemoriaScadenzaNotification::class, 3);
        }
        $this->assertSame(3, Scadenza::query()->whereNotNull('promemoria_inviato_at')->count());
        // 4 riunioni convocate, ciascuna ai 3 componenti in carica e ai 2 amministratori
        Notification::assertSentTimes(ConvocazioneRiunioneNotification::class, 4 * (3 + 2));
        Notification::assertSentTo($soci['Carla'], ConvocazioneRiunioneNotification::class, fn ($n) => $n->riunione->is($elettiva));
        Notification::assertNotSentTo($soci['Carla'], ConvocazioneRiunioneNotification::class, fn ($n) => $n->riunione->is($esclusione));
        Notification::assertSentTimes(ConvocazioneAssembleaNotification::class, 12);
        Notification::assertSentTimes(SollecitoQuotaNotification::class, 2);
        // Rinnovi: 12 soci con tessera 2026 al 2/12, Elsa esce dopo aver rinnovato
        Notification::assertSentTimes(PromemoriaRinnovoNotification::class, 12 + 11 + 11);
        Notification::assertSentToTimes($soci['Elsa'], PromemoriaRinnovoNotification::class, 1);
        Notification::assertNotSentTo($soci['Ivo'], PromemoriaRinnovoNotification::class);

        $verbali = Verbale::query()->where('anno', 2026)->orderBy('numero')->get();
        $this->assertSame([1, 2, 3, 4], $verbali->pluck('numero')->map(fn ($n) => (int) $n)->all());
        $this->assertSame([false, true, true, false], $verbali->pluck('approvato')->map(fn ($a) => (bool) $a)->all());
        $this->assertSame(1, Cessazione::query()->count());
        $this->assertSame('4/2026', Cessazione::query()->sole()->delibera_numero);
        $this->assertSame(4, AdempimentoAnno::query()->where('anno', 2026)->whereNotNull('completato_at')->count());

        $righe = collect($this->come($this->presidente)->get(route('libro-soci.index', ['anno' => 2026]))->assertOk()->viewData('righe'));
        $this->assertSame([1, 2, 3], $righe->pluck('progressivo')->all());
        $this->assertTrue($righe->every(fn ($r) => $r['delibera_numero'] === '1/2026'));
        $tutto = collect($this->get(route('libro-soci.index'))->viewData('righe'));
        $this->assertSame('2026-09-16', $tutto->first(fn ($r) => $r['socio']->is($soci['Ivo']))['cessazione']->data->toDateString());

        $movimenti = Movimento::query()->get();
        $this->assertSame(13, $movimenti->where('origine_type', (new Quota)->getMorphClass())->count());
        $this->assertSame(2, $movimenti->where('origine_type', (new \App\Models\Prenotazione)->getMorphClass())->count());
        $this->assertSame(2, $movimenti->whereNull('origine_type')->count());

        $stat = $this->get(route('statistiche-annuali.index', ['anno' => 2026]))->assertOk()->viewData('dati')['corrente'];
        $this->assertSame(3, $stat['nuovi_soci']);
        $this->assertSame(1, $stat['cessazioni']);
        $this->assertEqualsWithDelta(13 * 30 + 2 * 5, $stat['entrate'], 0.001);
        $this->assertEqualsWithDelta(650.0, $stat['uscite'], 0.001);
        $this->assertSame(5, $stat['partecipazioni']);

        $promemoria = PromemoriaInviato::query()->get();
        $this->assertSame(
            $promemoria->count(),
            $promemoria->unique(fn ($p) => implode('|', [$p->socio_id, $p->tessera_id, $p->tipo, $p->step, $p->riferimento_id]))->count(),
            'Nessun promemoria registrato due volte'
        );
        $this->assertSame(9, EmailQueue::query()->where('template_codice', 'campagna_rinnovi_2026')->count());
        $this->assertSame(11, EmailQueue::query()->where('template_codice', 'newsletter')->count());
    }
}
