<?php

namespace Tests\Feature\Percorsi;

use App\Models\Associazione;
use App\Models\Cessazione;
use App\Models\EmailQueue;
use App\Models\Evento;
use App\Models\PaymentGatewaySetting;
use App\Models\PromemoriaInviato;
use App\Models\Quota;
use App\Models\Ricevuta;
use App\Models\Sede;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\TipoSocio;
use App\Models\Utente;
use App\Notifications\Economia\SollecitoQuotaNotification;
use App\Notifications\Quote\PagamentoQuotaNotification;
use App\Notifications\Quote\RicevutaQuotaNotification;
use App\Notifications\Rinnovi\PromemoriaRinnovoNotification;
use App\Services\Economia\SollecitoQuoteService;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Percorsi reali — TESSERAMENTO, QUOTE E RINNOVI.
 *
 * Ogni test segue passo per passo la vita di un tesseramento dentro
 * un'associazione: l'admin e l'operatore nel pannello, il socio nell'area
 * soci, il visitatore sulla pagina pubblica di pagamento o di verifica
 * tessera. Più richieste HTTP in sequenza, con verifiche sul database,
 * sulle notifiche e sul contenuto delle pagine; il tempo scorre con
 * Carbon::setTestNow (scadenze, fine anno, anno nuovo).
 */
class Percorso02_TesseramentoQuoteTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Remo-Verde-Barca-77';

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // Data di riferimento: primavera 2026, tessere e quote dell'anno in corso
        Carbon::setTestNow('2026-03-10 10:00:00');

        $this->seed(RolesSeeder::class);

        $this->associazione = Associazione::factory()->create([
            'nome' => 'Polisportiva Il Faro',
            'costo_tessera' => 20.00,
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

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Attori e fixture di supporto
    // ------------------------------------------------------------------

    private function creaOperatore(): Utente
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Luca',
            'cognome' => 'Segreteria',
        ]);
        $operatore->assignRole('operatore');

        return $operatore;
    }

    private function creaTipoSocio(string $nome, float $costoTessera): TipoSocio
    {
        return TipoSocio::create([
            'associazione_id' => $this->associazione->id,
            'nome' => $nome,
            'costo_tessera' => $costoTessera,
        ]);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function creaSocio(array $override = []): Socio
    {
        return Socio::factory()->create(array_merge([
            'associazione_id' => $this->associazione->id,
        ], $override));
    }

    /**
     * Tessera di un anno, con scadenza solare al 31/12 di quell'anno.
     *
     * @param  array<string, mixed>  $override
     */
    private function creaTessera(Socio $socio, int $anno, array $override = []): Tessera
    {
        return Tessera::factory()->create(array_merge([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno_validita' => $anno,
            'numero_tessera' => $anno.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
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
            'associazione_id' => $this->associazione->id,
            'anno' => $anno,
            'importo' => $importo,
            'data_scadenza' => $anno.'-12-31',
            'tipo' => 'Quota Associativa',
        ], $override));
    }

    private function configuraStripe(): PaymentGatewaySetting
    {
        return PaymentGatewaySetting::factory()->conStripe()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    /**
     * Il gateway conferma il pagamento: Stripe richiama il webhook del
     * tenant con la sessione di checkout completata (firma verificata dal
     * gateway, qui sostituito da un mock che restituisce il payload).
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

    // ------------------------------------------------------------------
    // 1. Nuovo socio a mano: approvazione, tessera dell'anno, PDF
    // ------------------------------------------------------------------

    public function test_percorso_1_admin_crea_socio_a_mano_lo_approva_ed_emette_la_tessera_dell_anno(): void
    {
        // L'associazione ha già un socio (n. 001) e configura tipo socio e sede
        $this->creaSocio(['numero_socio' => '001', 'cognome' => 'Preesistente']);
        $ordinario = $this->creaTipoSocio('Ordinario', 30.00);
        $sede = Sede::create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Sede centrale',
            'citta' => 'Livorno',
        ]);

        // L'admin registra a mano una nuova pre-iscrizione arrivata allo sportello
        $this->actingAs($this->admin)
            ->post(route('soci.store'), [
                'nome' => 'Giulia',
                'cognome' => 'Marinai',
                'email' => 'giulia.marinai@example.com',
                'data_nascita' => '1991-04-22',
                'data_iscrizione' => '2026-03-10',
                'stato' => Socio::STATO_IN_ATTESA,
                'telefono' => '3471234567',
                'privacy_consenso' => '1',
                'tipo_socio_id' => $ordinario->id,
                'sede_id' => $sede->id,
                'genera_tessera' => '0', // la tessera arriva con il pagamento, non prima
            ])
            ->assertSessionHasNoErrors();

        /** @var Socio $giulia */
        $giulia = Socio::query()->where('email', 'giulia.marinai@example.com')->firstOrFail();

        $this->assertSame('002', $giulia->numero_socio, 'Il numero socio è il progressivo dell\'associazione.');
        $this->assertSame(Socio::STATO_IN_ATTESA, $giulia->stato);
        $this->assertSame($ordinario->id, $giulia->tipo_socio_id);
        $this->assertSame($sede->id, $giulia->sede_id);
        $this->assertSame(0, $giulia->tessere()->count());

        // Approvazione della pre-iscrizione: resta da incassare la quota
        $this->actingAs($this->admin)
            ->from(route('soci.show', $giulia))
            ->post(route('soci.approva', $giulia))
            ->assertRedirect(route('soci.show', $giulia));

        $this->assertSame(Socio::STATO_IN_ATTESA_DI_PAGAMENTO, $giulia->fresh()->stato);

        // Giulia paga in contanti allo sportello: attivazione + tessera dell'anno
        $this->actingAs($this->admin)
            ->from(route('soci.show', $giulia))
            ->post(route('soci.conferma-pagamento', $giulia), [
                'importo' => '30.00',
                'metodo' => 'contanti',
                'tipo' => 'Quota Associativa',
                'data_pagamento' => '2026-03-10',
            ])
            ->assertRedirect(route('soci.show', $giulia))
            ->assertSessionHas('success');

        $giulia->refresh();
        $this->assertSame(Socio::STATO_ATTIVO, $giulia->stato);

        $this->assertDatabaseHas('quote', [
            'socio_id' => $giulia->id,
            'anno' => 2026,
            'importo' => 30.00,
            'stato' => Quota::STATO_PAGATA,
            'metodo_pagamento' => 'contanti',
        ]);

        /** @var Tessera $tessera */
        $tessera = $giulia->tessere()->firstOrFail();
        $this->assertSame(2026, $tessera->anno_validita);
        $this->assertSame(Tessera::STATO_ATTIVA, $tessera->stato);
        $this->assertTrue($tessera->isValida());
        $this->assertSame('2026-12-31', $tessera->data_scadenza->toDateString());
        $this->assertNotEmpty($tessera->qr_code_url);

        // Storico del socio: ogni passo è tracciato
        foreach (['Iscrizione', 'Approvazione pre-iscrizione', 'Pagamento confermato', 'Generazione Tessera'] as $tipo) {
            $this->assertDatabaseHas('storico_attivita_socio', [
                'socio_id' => $giulia->id,
                'tipo_attivita' => $tipo,
            ]);
        }

        // Dall'elenco tessere l'admin chiede la tessera di Giulia: quella
        // dell'anno esiste già, quindi nessun duplicato e via al PDF
        $this->actingAs($this->admin)
            ->post(route('tessere.emetti', $giulia))
            ->assertRedirect(route('tessere.pdf', $tessera));

        $this->assertSame(1, $giulia->tessere()->count(), 'L\'emissione on-demand non duplica la tessera dell\'anno.');

        $pdf = $this->actingAs($this->admin)->get(route('tessere.pdf', $tessera));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));

        // La scheda socio riporta numero socio, stato e tessera
        $this->actingAs($this->admin)
            ->get(route('soci.show', $giulia))
            ->assertOk()
            ->assertSee('Marinai')
            ->assertSee('002')
            ->assertSee($tessera->numero_tessera)
            ->assertSee('Generazione Tessera');

        // Anche l'operatore vede il nuovo socio nell'elenco dei soci attivi
        $this->actingAs($this->creaOperatore())
            ->get(route('soci.index'))
            ->assertOk()
            ->assertSee('Marinai');
    }

    // ------------------------------------------------------------------
    // 2. Quota dell'anno, pagamento in contanti, ricevuta, storico
    // ------------------------------------------------------------------

    public function test_percorso_2_quota_dell_anno_pagata_in_contanti_con_ricevuta_e_storico_aggiornato(): void
    {
        $socio = $this->creaSocio([
            'numero_socio' => '014',
            'nome' => 'Marco',
            'cognome' => 'Lanterna',
            'email' => 'marco.lanterna@example.com',
        ]);

        // L'admin crea la quota 2026 del socio dal pannello quote
        $this->actingAs($this->admin)
            ->post(route('quote.store'), [
                'socio_id' => $socio->id,
                'anno' => 2026,
                'importo' => '35.00',
                'data_scadenza' => '2026-04-05',
                'tipo' => 'Quota Associativa',
            ])
            ->assertRedirect(route('quote.index'))
            ->assertSessionHas('success', 'Quota creata.');

        /** @var Quota $quota */
        $quota = Quota::query()->where('socio_id', $socio->id)->firstOrFail();

        $this->assertSame($this->associazione->id, $quota->associazione_id, 'La quota nasce nel tenant dell\'admin.');
        $this->assertFalse($quota->isPagata());
        $this->assertSame(Quota::STATO_DA_PAGARE, $quota->stato);
        // Scade entro 30 giorni: in scadenza, non ancora morosa
        $this->assertSame(Quota::STATO_IN_SCADENZA, $quota->statoCalcolato());

        $this->actingAs($this->admin)
            ->get(route('quote.index'))
            ->assertOk()
            ->assertSee('Lanterna')
            ->assertSee('35,00');

        // La ricevuta non esiste finché la quota non è pagata
        $this->actingAs($this->admin)
            ->get(route('quote.ricevuta', $quota))
            ->assertRedirect(route('quote.index'))
            ->assertSessionHas('error', 'La ricevuta è disponibile solo per quote pagate.');

        // Il socio passa in sede e paga in contanti: l'admin registra il pagamento
        $this->actingAs($this->admin)
            ->post(route('quote.pagamento', $quota), [
                'payment_method' => 'contanti',
                'payment_date' => '2026-03-12',
            ])
            ->assertRedirect(route('quote.index'))
            ->assertSessionHas('success', 'Pagamento registrato con successo.');

        $quota->refresh();
        $this->assertTrue($quota->isPagata());
        $this->assertSame('2026-03-12', $quota->data_pagamento->toDateString());
        $this->assertSame('contanti', $quota->metodo_pagamento);
        $this->assertSame(Quota::STATO_PAGATA, $quota->statoCalcolato());

        // Ricevuta numerata dell'anno emessa al pagamento, con email al socio
        /** @var Ricevuta $ricevuta */
        $ricevuta = Ricevuta::query()->where('quota_id', $quota->id)->firstOrFail();
        $this->assertSame(1, (int) $ricevuta->numero);
        $this->assertSame(2026, (int) $ricevuta->anno);
        $this->assertSame('35.00', (string) $ricevuta->importo);

        Notification::assertSentTo($socio, PagamentoQuotaNotification::class);
        Notification::assertSentTo($socio, RicevutaQuotaNotification::class);

        // Storico attività del socio: pagamento registrato dall'admin
        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'utente_id' => $this->admin->id,
            'tipo_attivita' => 'Pagamento Quota',
        ]);

        $this->actingAs($this->admin)
            ->get(route('soci.show', $socio))
            ->assertOk()
            ->assertSee('Pagamento Quota')
            ->assertSee('35,00');

        // L'admin scarica la ricevuta in PDF (idempotente: stessa ricevuta n. 1)
        $pdf = $this->actingAs($this->admin)->get(route('quote.ricevuta', $quota));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $pdf->headers->get('Content-Disposition'));
        $this->assertSame(1, Ricevuta::query()->where('quota_id', $quota->id)->count());

        // Un secondo pagamento sulla stessa quota viene rifiutato
        $this->actingAs($this->admin)
            ->post(route('quote.pagamento', $quota), [
                'payment_method' => 'bonifico',
                'payment_date' => '2026-03-13',
            ])
            ->assertRedirect(route('quote.index'))
            ->assertSessionHas('error', 'Questa quota risulta già pagata.');

        $this->assertSame('contanti', $quota->fresh()->metodo_pagamento);
    }

    // ------------------------------------------------------------------
    // 3. Link di pagamento e pagina pubblica, con e senza gateway
    // ------------------------------------------------------------------

    public function test_percorso_3_link_di_pagamento_e_pagina_pubblica_con_gateway_poi_disattivato(): void
    {
        $socio = $this->creaSocio([
            'nome' => 'Sara',
            'cognome' => 'Boe',
            'email' => 'sara.boe@example.com',
        ]);
        $quota = $this->creaQuota($socio, 2026, 30.00);

        // Senza gateway configurato il link non si può generare: messaggio chiaro
        $this->actingAs($this->admin)
            ->post(route('quote.payment-link', $quota))
            ->assertRedirect(route('quote.index'))
            ->assertSessionHas('error', 'Nessun metodo di pagamento online configurato. Configura Stripe o PayPal nelle impostazioni pagamenti.');

        $this->assertNull($quota->fresh()->payment_token);

        // L'associazione attiva Stripe e l'admin genera il link personale
        $gateway = $this->configuraStripe();

        $risposta = $this->actingAs($this->admin)
            ->post(route('quote.payment-link', $quota))
            ->assertRedirect(route('quote.index'))
            ->assertSessionHas('payment_link');

        $quota->refresh();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $quota->payment_token);
        $this->assertTrue($quota->hasValidPaymentToken());
        $this->assertTrue($quota->payment_token_expires->isFuture());

        $link = session('payment_link');
        $this->assertSame(route('pagamento.show', ['token' => $quota->payment_token]), $link);

        // Il pannello mostra il link pronto da copiare al socio
        $this->followRedirects($risposta)
            ->assertOk()
            ->assertSee($quota->payment_token);

        // Sara, senza alcun login, apre il link ricevuto
        $this->get($link)
            ->assertOk()
            ->assertSee('Polisportiva Il Faro')
            ->assertSee('EUR 30,00')
            ->assertSee('Pagamento quota associativa')
            ->assertDontSee('Nessun metodo di pagamento online configurato');

        // Un token inventato non apre nulla
        $this->get(route('pagamento.show', ['token' => str_repeat('a', 64)]))->assertNotFound();

        // Nel frattempo l'associazione disattiva Stripe (chiave revocata)
        $gateway->update(['stripe_enabled' => false]);

        // La pagina resta leggibile ma avvisa che non si può pagare online
        $this->get($link)
            ->assertOk()
            ->assertSee('Polisportiva Il Faro')
            ->assertSee('EUR 30,00')
            ->assertSee('Nessun metodo di pagamento online configurato');

        // Un checkout forzato non parte: si torna alla pagina con l'errore
        $this->post(route('pagamento.checkout', ['token' => $quota->payment_token]), ['gateway' => 'stripe'])
            ->assertRedirect(route('pagamento.show', ['token' => $quota->payment_token]))
            ->assertSessionHas('checkout_error', 'Errore nella creazione del pagamento Stripe. Riprova.');

        $this->post(route('pagamento.checkout', ['token' => $quota->payment_token]), ['gateway' => 'paypal'])
            ->assertRedirect(route('pagamento.show', ['token' => $quota->payment_token]))
            ->assertSessionHas('checkout_error', 'Errore nella creazione del pagamento PayPal. Riprova.');

        $this->assertFalse($quota->fresh()->isPagata());
        $this->assertNull($quota->fresh()->gateway_transaction_id);
    }

    // ------------------------------------------------------------------
    // 4. Fine anno: promemoria e campagna rinnovi del nuovo anno
    // ------------------------------------------------------------------

    public function test_percorso_4_fine_anno_promemoria_di_scadenza_e_campagna_rinnovi_saltano_chi_ha_gia_rinnovato(): void
    {
        $ordinario = $this->creaTipoSocio('Ordinario', 30.00);

        // Tre soci attivi con la tessera 2026, un quarto che ha rifiutato le email
        $aldo = $this->creaSocio(['nome' => 'Aldo', 'cognome' => 'Remi', 'email' => 'aldo.remi@example.com', 'tipo_socio_id' => $ordinario->id]);
        $bea = $this->creaSocio(['nome' => 'Bea', 'cognome' => 'Scogli', 'email' => 'bea.scogli@example.com']);
        $carla = $this->creaSocio(['nome' => 'Carla', 'cognome' => 'Timoni', 'email' => 'carla.timoni@example.com']);
        $dino = $this->creaSocio(['nome' => 'Dino', 'cognome' => 'Bussola', 'email' => 'dino.bussola@example.com', 'email_opt_out' => true]);

        foreach ([$aldo, $bea, $carla, $dino] as $socio) {
            $this->creaTessera($socio, 2026);
        }

        // Metà dicembre: le tessere scadono il 31/12, parte il promemoria automatico
        Carbon::setTestNow('2026-12-15 08:00:00');

        $this->artisan('rinnovi:promemoria')->assertSuccessful();

        Notification::assertSentTo($aldo, PromemoriaRinnovoNotification::class);
        Notification::assertSentTo($bea, PromemoriaRinnovoNotification::class);
        Notification::assertSentTo($carla, PromemoriaRinnovoNotification::class);

        $this->assertGreaterThanOrEqual(3, PromemoriaInviato::query()
            ->where('tipo', PromemoriaInviato::TIPO_RINNOVO_TESSERA)
            ->count(), 'Ogni promemoria inviato viene registrato.');

        // Carla rinnova subito allo sportello: l'admin le emette la tessera 2027
        $this->actingAs($this->admin)
            ->post(route('tessere.store'), [
                'socio_id' => $carla->id,
                'numero_tessera' => '20270001',
                'anno_validita' => 2027,
                'data_emissione' => '2026-12-20',
                'stato' => Tessera::STATO_ATTIVA,
            ])
            ->assertRedirect(route('tessere.index', ['anno' => 2027]));

        /** @var Tessera $tesseraCarla */
        $tesseraCarla = Tessera::query()->where('socio_id', $carla->id)->where('anno_validita', 2027)->firstOrFail();
        $this->assertSame('2027-12-31', $tesseraCarla->data_scadenza->toDateString(), 'Scadenza solare calcolata dall\'anno di validità.');
        $this->assertSame(Tessera::STATO_ATTIVA, $tesseraCarla->stato);

        // Anno nuovo: l'admin apre la campagna rinnovi 2027
        Carbon::setTestNow('2027-01-10 09:00:00');

        $this->actingAs($this->admin)
            ->get(route('campagna-rinnovi.index'))
            ->assertOk()
            ->assertSee('Remi')
            ->assertSee('Scogli')
            ->assertSee('Bussola')
            ->assertDontSee('Timoni')
            ->assertViewHas('anno', 2027)
            ->assertViewHas('contatori', fn (array $c) => $c['da_rinnovare'] === 3 && $c['inviate'] === 0 && $c['rinnovate'] === 1);

        // Invio massivo a tutti, Carla compresa per sbaglio
        $this->actingAs($this->admin)
            ->post(route('campagna-rinnovi.invia'), [
                'socio_ids' => [$aldo->id, $bea->id, $carla->id, $dino->id],
            ])
            ->assertRedirect(route('campagna-rinnovi.index'))
            ->assertSessionHas('success', 'Campagna rinnovi 2027: 2 email accodate. 2 soci saltati (senza email, opt-out o già rinnovati).');

        // Email di rinnovo in coda solo per Aldo e Bea, con il link di pagamento personale
        $codice = 'campagna_rinnovi_2027';
        $this->assertSame(2, EmailQueue::query()->where('template_codice', $codice)->count());

        /** @var EmailQueue $emailAldo */
        $emailAldo = EmailQueue::query()->where('template_codice', $codice)->where('socio_id', $aldo->id)->firstOrFail();
        $this->assertSame('aldo.remi@example.com', $emailAldo->to_email);
        $this->assertSame('Rinnovo tesseramento 2027 — Polisportiva Il Faro', $emailAldo->subject);
        $this->assertStringContainsString('30,00', $emailAldo->body_html);

        $this->assertDatabaseMissing('email_queue', ['template_codice' => $codice, 'socio_id' => $carla->id]);
        $this->assertDatabaseMissing('email_queue', ['template_codice' => $codice, 'socio_id' => $dino->id]);

        // Le quote 2027 nascono con la campagna: importo dal tipo socio o dall'associazione
        /** @var Quota $quotaAldo */
        $quotaAldo = Quota::query()->where('socio_id', $aldo->id)->where('anno', 2027)->firstOrFail();
        $this->assertSame('30.00', (string) $quotaAldo->importo);
        $this->assertTrue($quotaAldo->hasValidPaymentToken());
        $this->assertStringContainsString(route('pagamento.show', ['token' => $quotaAldo->payment_token]), $emailAldo->body_html);

        $this->assertSame('20.00', (string) Quota::query()->where('socio_id', $bea->id)->where('anno', 2027)->value('importo'));
        $this->assertDatabaseMissing('quote', ['socio_id' => $carla->id, 'anno' => 2027]);

        // Il pannello segna le email come inviate
        $this->actingAs($this->admin)
            ->get(route('campagna-rinnovi.index'))
            ->assertOk()
            ->assertViewHas('contatori', fn (array $c) => $c['da_rinnovare'] === 3 && $c['inviate'] === 2);

        // Un secondo invio non duplica le quote
        $this->actingAs($this->admin)->post(route('campagna-rinnovi.invia'), ['socio_ids' => [$aldo->id]]);
        $this->assertSame(1, Quota::query()->where('socio_id', $aldo->id)->where('anno', 2027)->count());
    }

    // ------------------------------------------------------------------
    // 5. Apertura del nuovo anno sociale: quote in blocco
    // ------------------------------------------------------------------

    public function test_percorso_5_apertura_del_nuovo_anno_genera_le_quote_dei_soci_attivi_senza_duplicati(): void
    {
        $ordinario = $this->creaTipoSocio('Ordinario', 30.00);
        $sostenitore = $this->creaTipoSocio('Sostenitore', 50.00);

        $anna = $this->creaSocio(['nome' => 'Anna', 'cognome' => 'Vela', 'tipo_socio_id' => $ordinario->id])->fresh();
        $bruno = $this->creaSocio(['nome' => 'Bruno', 'cognome' => 'Ancora', 'tipo_socio_id' => $sostenitore->id]);
        $cesare = $this->creaSocio(['nome' => 'Cesare', 'cognome' => 'Nodo', 'tipo_socio_id' => null]);
        $sospeso = $this->creaSocio(['nome' => 'Dario', 'cognome' => 'Secca', 'stato' => Socio::STATO_SOSPESO]);
        $elena = $this->creaSocio(['nome' => 'Elena', 'cognome' => 'Faro']);

        // Elena ha già pagato in anticipo la quota 2027
        $this->creaQuota($elena, 2027, 20.00, ['data_pagamento' => '2026-12-05', 'stato' => Quota::STATO_PAGATA]);

        // Fine dicembre: l'admin guarda l'anteprima dell'apertura 2027
        Carbon::setTestNow('2026-12-28 11:00:00');

        $this->actingAs($this->admin)
            ->get(route('apertura-anno.index', ['anno' => 2027]))
            ->assertOk()
            ->assertSee('Ordinario')
            ->assertSee('Sostenitore')
            ->assertViewHas('anteprima', fn (array $a) => $a['soci_attivi'] === 4
                && $a['gia_aperte'] === 1
                && $a['da_generare'] === 3
                && $a['importo_totale'] === 100.0);

        $this->actingAs($this->admin)
            ->post(route('apertura-anno.genera'), ['anno' => 2027])
            ->assertRedirect(route('apertura-anno.index', ['anno' => 2027]))
            ->assertSessionHas('success', 'Anno 2027 aperto: generate 3 quote per i soci attivi.');

        // Importi dal tipo socio, fallback sul costo tessera dell'associazione
        $this->assertDatabaseHas('quote', ['socio_id' => $anna->id, 'anno' => 2027, 'importo' => 30.00, 'stato' => Quota::STATO_DA_PAGARE]);
        $this->assertSame('2027-12-31', Quota::query()->where('socio_id', $anna->id)->where('anno', 2027)->firstOrFail()->data_scadenza->toDateString());
        $this->assertDatabaseHas('quote', ['socio_id' => $bruno->id, 'anno' => 2027, 'importo' => 50.00]);
        $this->assertDatabaseHas('quote', ['socio_id' => $cesare->id, 'anno' => 2027, 'importo' => 20.00]);
        $this->assertDatabaseMissing('quote', ['socio_id' => $sospeso->id, 'anno' => 2027]);
        $this->assertSame(1, Quota::query()->where('socio_id', $elena->id)->where('anno', 2027)->count());
        $this->assertSame(4, Quota::query()->where('anno', 2027)->count());

        // Rilanciata per sbaglio: nessun duplicato
        $this->actingAs($this->admin)
            ->post(route('apertura-anno.genera'), ['anno' => 2027])
            ->assertSessionHas('success', 'Nessuna quota da generare per l\'anno 2027: tutti i soci attivi hanno già la quota.');

        $this->assertSame(4, Quota::query()->where('anno', 2027)->count());

        $this->actingAs($this->admin)
            ->get(route('apertura-anno.index', ['anno' => 2027]))
            ->assertViewHas('anteprima', fn (array $a) => $a['da_generare'] === 0 && $a['gia_aperte'] === 4);

        // Anno nuovo: Anna entra nell'area soci e trova la quota 2027 da pagare
        Carbon::setTestNow('2027-01-07 18:00:00');
        $anna->forceFill(['password_hash' => self::PASSWORD])->save();

        $this->actingAs($anna, 'socio')
            ->get(route('area-soci.dashboard'))
            ->assertOk()
            ->assertSee('30,00');

        $this->actingAs($anna, 'socio')
            ->get(route('area-soci.quote'))
            ->assertOk()
            ->assertSee('2027')
            ->assertSee('30,00');

        // L'elenco quote dell'admin, filtrato sul 2027, mostra tutti e quattro
        $this->actingAs($this->admin)
            ->get(route('quote.index', ['year' => 2027]))
            ->assertOk()
            ->assertSee('Vela')
            ->assertSee('Ancora')
            ->assertSee('Nodo')
            ->assertSee('Faro')
            ->assertDontSee('Secca');
    }

    // ------------------------------------------------------------------
    // 6. Rinnovo self-service dall'area soci con pagamento online
    // ------------------------------------------------------------------

    public function test_percorso_6_il_socio_rinnova_dall_area_soci_paga_online_e_riceve_la_tessera_nuova(): void
    {
        $ordinario = $this->creaTipoSocio('Ordinario', 30.00);
        $socio = $this->creaSocio([
            'nome' => 'Paolo',
            'cognome' => 'Gabbiano',
            'email' => 'paolo.gabbiano@example.com',
            'tipo_socio_id' => $ordinario->id,
            'password_hash' => self::PASSWORD,
        ]);
        $tessera2026 = $this->creaTessera($socio, 2026, ['numero_tessera' => '20260007']);
        $this->configuraStripe();

        // Gennaio 2027: la tessera 2026 è scaduta, Paolo entra nell'area soci
        Carbon::setTestNow('2027-01-08 20:30:00');

        $this->assertTrue($tessera2026->fresh()->isScadutaEffettiva());

        $this->actingAs($socio, 'socio')
            ->get(route('area-soci.dashboard'))
            ->assertOk()
            ->assertSee('Rinnova per il 2027');

        // Avvia il rinnovo: quota 2027 con link di pagamento, poi alla pagina pubblica
        $risposta = $this->actingAs($socio, 'socio')->post(route('area-soci.rinnova'));

        /** @var Quota $quota */
        $quota = Quota::query()->where('socio_id', $socio->id)->where('anno', 2027)->firstOrFail();
        $this->assertSame('30.00', (string) $quota->importo);
        $this->assertFalse($quota->isPagata());
        $this->assertTrue($quota->hasValidPaymentToken());

        $risposta->assertRedirect(route('pagamento.show', ['token' => $quota->payment_token]));

        $this->followRedirects($risposta)
            ->assertOk()
            ->assertSee('Polisportiva Il Faro')
            ->assertSee('EUR 30,00');

        // Nessuna tessera 2027 finché il pagamento non è confermato
        $this->assertDatabaseMissing('tessere', ['socio_id' => $socio->id, 'anno_validita' => 2027]);

        // Paolo paga con carta: Stripe conferma via webhook
        $this->stripeConfermaPagamento($quota, 'pi_rinnovo_2027');

        $quota->refresh();
        $this->assertTrue($quota->isPagata());
        $this->assertSame('stripe', $quota->gateway);
        $this->assertSame('pi_rinnovo_2027', $quota->gateway_transaction_id);
        $this->assertSame('2027-01-08', $quota->data_pagamento->toDateString());

        /** @var Tessera $tessera2027 */
        $tessera2027 = Tessera::query()->where('socio_id', $socio->id)->where('anno_validita', 2027)->firstOrFail();
        $this->assertSame('20270001', $tessera2027->numero_tessera);
        $this->assertSame(Tessera::STATO_ATTIVA, $tessera2027->stato);
        $this->assertSame('2027-12-31', $tessera2027->data_scadenza->toDateString());
        $this->assertSame('Ordinario', $tessera2027->template_tessera);
        $this->assertTrue($tessera2027->isValida());

        // Email di conferma pagamento e ricevuta numerata
        Notification::assertSentTo($socio, PagamentoQuotaNotification::class);
        Notification::assertSentTo($socio, RicevutaQuotaNotification::class);
        $this->assertDatabaseHas('ricevute', ['quota_id' => $quota->id, 'anno' => 2027, 'numero' => 1]);
        $this->assertDatabaseHas('storico_attivita_socio', ['socio_id' => $socio->id, 'tipo_attivita' => 'Pagamento Online']);

        // Un webhook duplicato non cambia nulla
        $this->stripeConfermaPagamento($quota, 'pi_rinnovo_2027');
        $this->assertSame(1, Tessera::query()->where('socio_id', $socio->id)->where('anno_validita', 2027)->count());
        $this->assertSame(1, Ricevuta::query()->where('quota_id', $quota->id)->count());

        // Nell'area soci compare la tessera nuova e il pulsante di rinnovo sparisce
        $this->actingAs($socio, 'socio')
            ->get(route('area-soci.dashboard'))
            ->assertOk()
            ->assertSee('20270001')
            ->assertSee('Tessera socio 2027')
            ->assertDontSee('Rinnova per il 2027');

        $this->actingAs($socio, 'socio')
            ->get(route('area-soci.tessere'))
            ->assertOk()
            ->assertSee('20270001')
            ->assertSee('20260007');

        // Un secondo tentativo di rinnovo non è più disponibile
        $this->actingAs($socio, 'socio')
            ->post(route('area-soci.rinnova'))
            ->assertRedirect(route('area-soci.dashboard'))
            ->assertSessionHas('warning');

        // La pagina pubblica mostra il pagamento come già effettuato e offre la ricevuta
        $this->get(route('pagamento.show', ['token' => $quota->payment_token]))->assertOk();
        $ricevuta = $this->get(route('pagamento.ricevuta', ['token' => $quota->payment_token]));
        $ricevuta->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $ricevuta->headers->get('Content-Type'));
    }

    // ------------------------------------------------------------------
    // 7. Solleciti ai soci morosi
    // ------------------------------------------------------------------

    public function test_percorso_7_solleciti_quote_ai_soci_morosi_senza_toccare_chi_ha_pagato(): void
    {
        $moroso = $this->creaSocio(['nome' => 'Ugo', 'cognome' => 'Ritardo', 'email' => 'ugo.ritardo@example.com']);
        $optOut = $this->creaSocio(['nome' => 'Vera', 'cognome' => 'Silenzio', 'email' => 'vera.silenzio@example.com', 'email_opt_out' => true]);
        $puntuale = $this->creaSocio(['nome' => 'Zeno', 'cognome' => 'Puntuale', 'email' => 'zeno.puntuale@example.com']);
        $inRegola = $this->creaSocio(['nome' => 'Ida', 'cognome' => 'Attesa', 'email' => 'ida.attesa@example.com']);

        // Quote 2026 scadute il 31/03 per i primi tre, Zeno ha pagato; Ida scade a fine anno
        $quotaMoroso = $this->creaQuota($moroso, 2026, 30.00, ['data_scadenza' => '2026-03-31']);
        $quotaOptOut = $this->creaQuota($optOut, 2026, 30.00, ['data_scadenza' => '2026-03-31']);
        $quotaPagata = $this->creaQuota($puntuale, 2026, 30.00, ['data_scadenza' => '2026-03-31', 'data_pagamento' => '2026-03-02', 'stato' => Quota::STATO_PAGATA]);
        $quotaFutura = $this->creaQuota($inRegola, 2026, 30.00);

        // Metà aprile: l'operatore apre la pagina dei solleciti
        Carbon::setTestNow('2026-04-15 09:30:00');
        $operatore = $this->creaOperatore();

        $this->assertSame(Quota::STATO_SCADUTA, $quotaMoroso->fresh()->statoCalcolato());

        $this->actingAs($operatore)
            ->get(route('solleciti-quote.index'))
            ->assertOk()
            ->assertSee('Ritardo')
            ->assertSee('Silenzio')
            ->assertSee('Attesa')
            ->assertDontSee('Puntuale')
            ->assertViewHas('quote', fn ($quote) => $quote->total() === 3);

        // Sollecito singolo a Ugo
        $this->actingAs($operatore)
            ->post(route('solleciti-quote.invia', $quotaMoroso))
            ->assertRedirect(route('solleciti-quote.index'))
            ->assertSessionHas('success', 'Sollecito inviato.');

        Notification::assertSentTo($moroso, SollecitoQuotaNotification::class, function (SollecitoQuotaNotification $n) use ($quotaMoroso) {
            return $n->quota->id === $quotaMoroso->id;
        });

        // Il sollecito porta il link di pagamento personale e resta tracciato
        $this->assertTrue($quotaMoroso->fresh()->hasValidPaymentToken());
        $this->assertDatabaseHas('promemoria_inviati', [
            'socio_id' => $moroso->id,
            'tipo' => SollecitoQuoteService::TIPO_SOLLECITO_QUOTA,
            'step' => 2026,
        ]);

        // Sollecitare chi ha già pagato non ha effetto
        $this->actingAs($operatore)
            ->post(route('solleciti-quote.invia', $quotaPagata))
            ->assertRedirect(route('solleciti-quote.index'))
            ->assertSessionHas('error');

        Notification::assertNotSentTo($puntuale, SollecitoQuotaNotification::class);

        // Fine mese: sollecito in blocco a tutti i selezionati, pagata compresa
        Carbon::setTestNow('2026-04-30 09:30:00');

        $this->actingAs($operatore)
            ->post(route('solleciti-quote.bulk'), [
                'quota_ids' => [$quotaMoroso->id, $quotaOptOut->id, $quotaPagata->id],
            ])
            ->assertRedirect(route('solleciti-quote.index'))
            ->assertSessionHas('success', 'Solleciti inviati: 1 — saltati: 1 (senza email o con opt-out).');

        Notification::assertSentToTimes($moroso, SollecitoQuotaNotification::class, 2);
        Notification::assertNotSentTo($optOut, SollecitoQuotaNotification::class);
        Notification::assertNotSentTo($puntuale, SollecitoQuotaNotification::class);
        Notification::assertNotSentTo($inRegola, SollecitoQuotaNotification::class);

        // Log dei solleciti: due per Ugo, nessuno per gli altri
        $this->assertSame(2, PromemoriaInviato::query()
            ->where('tipo', SollecitoQuoteService::TIPO_SOLLECITO_QUOTA)
            ->where('socio_id', $moroso->id)
            ->count());
        $this->assertSame(2, PromemoriaInviato::query()->where('tipo', SollecitoQuoteService::TIPO_SOLLECITO_QUOTA)->count());

        $this->actingAs($operatore)
            ->get(route('solleciti-quote.index'))
            ->assertOk()
            ->assertViewHas('solleciti', fn (array $s) => $s[$quotaMoroso->id]['conteggio'] === 2
                && $s[$quotaOptOut->id]['conteggio'] === 0
                && $s[$quotaFutura->id]['conteggio'] === 0);

        // Ugo paga tramite il link del sollecito: esce dai morosi
        $this->configuraStripe();
        $this->stripeConfermaPagamento($quotaMoroso->fresh(), 'pi_sollecito_ugo');

        $this->actingAs($operatore)
            ->get(route('solleciti-quote.index'))
            ->assertOk()
            ->assertDontSee('Ritardo')
            ->assertViewHas('quote', fn ($quote) => $quote->total() === 2);
    }

    // ------------------------------------------------------------------
    // 8. Tessera scaduta e non rinnovata: filtri soci, verifica, check-in
    // ------------------------------------------------------------------

    public function test_percorso_8_tessera_scaduta_non_rinnovata_risulta_nei_filtri_e_il_check_in_viene_rifiutato(): void
    {
        $scaduto = $this->creaSocio(['nome' => 'Enzo', 'cognome' => 'Scaduto', 'numero_socio' => '021']);
        $rinnovato = $this->creaSocio(['nome' => 'Rita', 'cognome' => 'Rinnovata', 'numero_socio' => '022']);

        $tesseraVecchia = $this->creaTessera($scaduto, 2026, ['numero_tessera' => '20260021']);
        $this->creaTessera($rinnovato, 2026, ['numero_tessera' => '20260022']);

        // Durante il 2026 entrambi passano il check-in all'assemblea
        $assemblea = Evento::factory()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Assemblea 2026']);

        $this->actingAs($this->admin)
            ->postJson(route('tessere.checkin.store'), ['evento_id' => $assemblea->id, 'tessera_id' => $tesseraVecchia->id])
            ->assertOk()
            ->assertJson(['success' => true, 'already_checked_in' => false]);

        // Anno nuovo: Rita rinnova, Enzo no
        Carbon::setTestNow('2027-02-01 10:00:00');
        $tesseraNuova = $this->creaTessera($rinnovato, 2027, ['numero_tessera' => '20270022']);

        $this->assertTrue($tesseraVecchia->fresh()->isScadutaEffettiva());
        $this->assertSame(Tessera::STATO_SCADUTA, $tesseraVecchia->fresh()->statoVisualizzato());

        // Lista soci: di default solo chi ha la tessera in regola
        $this->actingAs($this->admin)
            ->get(route('soci.index'))
            ->assertOk()
            ->assertSee('Rinnovata')
            ->assertDontSee('Scaduto');

        // Filtro "tessere scadute": compare Enzo con il badge
        $this->actingAs($this->admin)
            ->get(route('soci.index', ['tessera_stato' => 'scadute']))
            ->assertOk()
            ->assertSee('Scaduto')
            ->assertSee('Scaduta')
            ->assertDontSee('Rinnovata');

        $this->actingAs($this->admin)
            ->get(route('soci.index', ['tessera_stato' => 'all', 'tessera_scadenza' => 'scadute']))
            ->assertOk()
            ->assertSee('Scaduto')
            ->assertDontSee('Rinnovata');

        // Anche la lista tessere 2026 le mostra come scadute, e quella 2027 ha solo Rita
        $this->actingAs($this->admin)
            ->get(route('tessere.index', ['anno' => 2026, 'stato' => Tessera::STATO_SCADUTA]))
            ->assertOk()
            ->assertSee('20260021');

        $this->actingAs($this->admin)
            ->get(route('tessere.index', ['anno' => 2027]))
            ->assertOk()
            ->assertSee('20270022')
            ->assertDontSee('20260021');

        // Verifica pubblica del QR di Enzo: tessera scaduta
        $this->get(route('tessere.verifica', $tesseraVecchia->id))
            ->assertOk()
            ->assertSee('Tessera Scaduta / Non Attiva')
            ->assertSee('Scaduto');

        $this->get(route('tessere.verifica.json', $tesseraVecchia->id))
            ->assertOk()
            ->assertJson(['stato' => 'scaduta', 'tessera' => ['numero_tessera' => '20260021']]);

        $this->get(route('tessere.verifica.json', $tesseraNuova->id))
            ->assertOk()
            ->assertJson(['stato' => 'valida']);

        // Check-in alla festa 2027: Enzo rifiutato con motivo, Rita accettata
        $festa = Evento::factory()->create(['associazione_id' => $this->associazione->id, 'titolo' => 'Festa di primavera 2027']);
        $operatore = $this->creaOperatore();

        $this->actingAs($operatore)
            ->postJson(route('tessere.checkin.store'), ['evento_id' => $festa->id, 'tessera_id' => $tesseraVecchia->id])
            ->assertStatus(400)
            ->assertJson([
                'success' => false,
                'code' => 'tessera_non_valida',
                'error' => 'Tessera scaduta o non rinnovata: il socio non ha una tessera valida.',
            ]);

        // Nemmeno digitando il numero socio a mano
        $this->actingAs($operatore)
            ->postJson(route('tessere.checkin.store'), ['evento_id' => $festa->id, 'numero_socio' => '021'])
            ->assertStatus(400)
            ->assertJson(['code' => 'tessera_non_valida']);

        $this->assertDatabaseMissing('eventi_partecipanti', ['evento_id' => $festa->id, 'socio_id' => $scaduto->id]);

        $this->actingAs($operatore)
            ->postJson(route('tessere.checkin.store'), ['evento_id' => $festa->id, 'tessera_id' => $tesseraNuova->id])
            ->assertOk()
            ->assertJson(['success' => true, 'socio' => ['cognome' => 'Rinnovata']]);

        $this->assertDatabaseHas('eventi_partecipanti', ['evento_id' => $festa->id, 'socio_id' => $rinnovato->id, 'stato_partecipazione' => 'Confermato']);
    }

    // ------------------------------------------------------------------
    // 9. Cessazione del socio: stato, libro soci, niente campagna
    // ------------------------------------------------------------------

    public function test_percorso_9_cessazione_del_socio_aggiorna_stato_libro_soci_e_lo_esclude_dai_rinnovi(): void
    {
        $dimissionario = $this->creaSocio([
            'nome' => 'Franco',
            'cognome' => 'Partente',
            'email' => 'franco.partente@example.com',
            'data_iscrizione' => '2024-03-01',
        ]);
        $fedele = $this->creaSocio([
            'nome' => 'Gina',
            'cognome' => 'Restante',
            'email' => 'gina.restante@example.com',
            'data_iscrizione' => '2024-05-20',
        ]);

        $tesseraFranco = $this->creaTessera($dimissionario, 2026, ['numero_tessera' => '20260031']);
        $this->creaTessera($fedele, 2026, ['numero_tessera' => '20260032']);

        // Giugno: Franco si trasferisce e presenta le dimissioni, l'admin le registra
        Carbon::setTestNow('2026-06-30 17:00:00');

        $this->actingAs($this->admin)
            ->get(route('cessazioni.index'))
            ->assertOk()
            ->assertSee('Partente');

        $this->actingAs($this->admin)
            ->post(route('cessazioni.store'), [
                'socio_id' => $dimissionario->id,
                'tipo' => Cessazione::TIPO_DIMISSIONI,
                'data' => '2026-06-30',
                'motivo' => 'Trasferimento in altra città',
                'delibera_numero' => '7/2026',
                'delibera_data' => '2026-06-28',
            ])
            ->assertRedirect(route('cessazioni.index'))
            ->assertSessionHas('success', 'Cessazione di Franco Partente registrata: stato aggiornato e tessere attive annullate.');

        $dimissionario->refresh();
        $this->assertSame(Socio::STATO_RADIATO, $dimissionario->stato);
        $this->assertSame(Tessera::STATO_ANNULLATA, $tesseraFranco->fresh()->stato);
        $this->assertFalse($tesseraFranco->fresh()->isValida());

        $this->assertDatabaseHas('cessazioni', [
            'socio_id' => $dimissionario->id,
            'tipo' => Cessazione::TIPO_DIMISSIONI,
            'motivo' => 'Trasferimento in altra città',
            'delibera_numero' => '7/2026',
            'registrata_da' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('storico_attivita_socio', ['socio_id' => $dimissionario->id, 'tipo_attivita' => 'Cessazione']);

        // Il registro delle cessazioni e il libro soci riportano data e motivo
        $this->actingAs($this->admin)
            ->get(route('cessazioni.index'))
            ->assertOk()
            ->assertSee('Partente')
            ->assertSee('30/06/2026');

        $this->actingAs($this->admin)
            ->get(route('libro-soci.index'))
            ->assertOk()
            ->assertSee('Partente')
            ->assertSee('Restante')
            ->assertSee('30/06/2026')
            ->assertSee('Dimissioni')
            ->assertSee('Trasferimento in altra città')
            ->assertSee(Socio::STATO_RADIATO);

        $pdf = $this->actingAs($this->admin)->get(route('libro-soci.pdf', ['anno' => 2024]));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));

        $excel = $this->actingAs($this->admin)->get(route('libro-soci.excel'));
        $excel->assertOk();
        $this->assertStringContainsString('libro-soci', (string) $excel->headers->get('Content-Disposition'));

        // Una seconda cessazione sullo stesso socio non è ammessa
        $this->actingAs($this->admin)
            ->post(route('cessazioni.store'), [
                'socio_id' => $dimissionario->id,
                'tipo' => Cessazione::TIPO_DECADENZA,
                'data' => '2026-07-01',
            ])
            ->assertRedirect(route('cessazioni.index'))
            ->assertSessionHas('error');

        $this->assertSame(1, Cessazione::query()->where('socio_id', $dimissionario->id)->count());

        // La verifica pubblica della sua tessera la dà come non attiva
        $this->get(route('tessere.verifica.json', $tesseraFranco->id))
            ->assertOk()
            ->assertJson(['stato' => 'scaduta']);

        // Anno nuovo: la campagna rinnovi non lo contempla più
        Carbon::setTestNow('2027-01-12 09:00:00');

        $this->actingAs($this->admin)
            ->get(route('campagna-rinnovi.index'))
            ->assertOk()
            ->assertSee('Restante')
            ->assertDontSee('Partente')
            ->assertViewHas('contatori', fn (array $c) => $c['da_rinnovare'] === 1);

        $this->actingAs($this->admin)
            ->post(route('campagna-rinnovi.invia'), ['socio_ids' => [$dimissionario->id, $fedele->id]])
            ->assertRedirect(route('campagna-rinnovi.index'))
            ->assertSessionHas('success', 'Campagna rinnovi 2027: 1 email accodate. 1 soci saltati (senza email, opt-out o già rinnovati).');

        $this->assertDatabaseHas('email_queue', ['template_codice' => 'campagna_rinnovi_2027', 'socio_id' => $fedele->id]);
        $this->assertDatabaseMissing('email_queue', ['template_codice' => 'campagna_rinnovi_2027', 'socio_id' => $dimissionario->id]);
        $this->assertDatabaseMissing('quote', ['socio_id' => $dimissionario->id, 'anno' => 2027]);

        // Nemmeno l'apertura dell'anno gli genera una quota
        $this->actingAs($this->admin)->post(route('apertura-anno.genera'), ['anno' => 2027]);
        $this->assertDatabaseMissing('quote', ['socio_id' => $dimissionario->id, 'anno' => 2027]);
    }

    // ------------------------------------------------------------------
    // 10. Import da CSV con una riga errata, poi unione del doppione
    // ------------------------------------------------------------------

    public function test_percorso_10_import_csv_con_riga_errata_poi_il_doppione_viene_individuato_e_unito(): void
    {
        // Anagrafica esistente: Giulia (con tessera e quota pagata) e Marco
        $giulia = $this->creaSocio([
            'numero_socio' => '001',
            'nome' => 'Giulia',
            'cognome' => 'Ferrari',
            'email' => 'giulia.ferrari@example.com',
            'data_nascita' => '1990-05-01',
        ]);
        $this->creaSocio([
            'numero_socio' => '002',
            'nome' => 'Marco',
            'cognome' => 'Esposito',
            'email' => 'marco.esposito@example.com',
        ]);
        $tesseraGiulia = $this->creaTessera($giulia, 2026, ['numero_tessera' => '20260001']);
        $quotaGiulia = $this->creaQuota($giulia, 2026, 20.00, ['data_pagamento' => '2026-02-01', 'stato' => Quota::STATO_PAGATA]);

        // L'operatore importa il file arrivato dal vecchio gestionale: 3 righe
        $csv = implode("\n", [
            'nome,cognome,email,data_nascita',
            'Luca,Moretti,luca.moretti@example.com,1988-02-14',
            'Giulia,Ferrari,g.ferrari@gmail.com,01/05/1990',               // stessa persona con un'altra email
            'Paolo,Conti,marco.esposito@example.com,1975-11-03',            // email già di Marco: riga scartata
        ]);

        $operatore = $this->creaOperatore();

        $risposta = $this->actingAs($operatore)->post(route('soci.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('soci.csv', $csv),
        ]);

        $risposta->assertRedirect(route('soci.import'));
        $risposta->assertSessionHas('warning', 'Import completato: 2 soci importati, 1 righe scartate.');

        $report = session('import_report');
        $this->assertSame(2, $report['importati']);
        $this->assertCount(1, $report['errori']);
        $this->assertSame(4, $report['errori'][0]['riga'], 'La riga errata è la terza di dati (quarta del file).');
        $this->assertStringContainsStringIgnoringCase('email', implode(' ', $report['errori'][0]['errori']));

        $this->followRedirects($risposta)
            ->assertOk()
            ->assertSee('2 soci importati')
            ->assertSee('1 righe scartate');

        // I due soci importati hanno il progressivo che prosegue da quello esistente
        /** @var Socio $luca */
        $luca = Socio::query()->where('email', 'luca.moretti@example.com')->firstOrFail();
        /** @var Socio $giuliaDoppia */
        $giuliaDoppia = Socio::query()->where('email', 'g.ferrari@gmail.com')->firstOrFail();

        $this->assertSame('003', $luca->numero_socio);
        $this->assertSame('004', $giuliaDoppia->numero_socio);
        $this->assertSame('1990-05-01', $giuliaDoppia->data_nascita->toDateString());
        $this->assertDatabaseMissing('soci', ['cognome' => 'Conti']);
        $this->assertSame(4, Socio::query()->count());

        // Per sbaglio l'operatore registra la quota 2026 anche sul doppione
        $this->actingAs($operatore)->post(route('quote.store'), [
            'socio_id' => $giuliaDoppia->id,
            'anno' => 2026,
            'importo' => '20.00',
            'data_scadenza' => '2026-12-31',
            'tipo' => 'Quota Associativa',
        ])->assertRedirect(route('quote.index'));

        $quotaDoppia = Quota::query()->where('socio_id', $giuliaDoppia->id)->firstOrFail();

        // La pagina dei possibili duplicati individua il doppione per anagrafica
        $this->actingAs($this->admin)
            ->get(route('soci-duplicati.index'))
            ->assertOk()
            ->assertSee('Stessi nome, cognome e data di nascita')
            ->assertSee('giulia.ferrari@example.com')
            ->assertSee('g.ferrari@gmail.com')
            ->assertDontSee('Moretti')
            ->assertViewHas('gruppi', fn (array $gruppi) => count($gruppi) === 1 && $gruppi[0]['soci']->count() === 2);

        // Unione: resta la Giulia storica, il doppione va nel cestino
        $this->actingAs($this->admin)
            ->post(route('soci-duplicati.unisci'), [
                'principale_id' => $giulia->id,
                'duplicato_id' => $giuliaDoppia->id,
            ])
            ->assertRedirect(route('soci-duplicati.index'))
            ->assertSessionHas('success');

        $this->assertStringContainsString('1 quote', (string) session('success'));
        $this->assertStringContainsString('Il duplicato è nel cestino', (string) session('success'));

        $this->assertSoftDeleted('soci', ['id' => $giuliaDoppia->id]);
        $this->assertNull(Socio::query()->find($giuliaDoppia->id));

        // Tessere e quote restano tutte sulla Giulia principale
        $giulia->refresh();
        $this->assertSame('001', $giulia->numero_socio);
        $this->assertSame(1, $giulia->tessere()->count());
        $this->assertSame($giulia->id, $tesseraGiulia->fresh()->socio_id);
        $this->assertSame(2, $giulia->quote()->count());
        $this->assertSame($giulia->id, $quotaGiulia->fresh()->socio_id);
        $this->assertSame($giulia->id, $quotaDoppia->fresh()->socio_id);
        $this->assertSame(0, Quota::query()->where('socio_id', $giuliaDoppia->id)->count());

        // Niente più doppioni, 3 soci attivi in anagrafica
        $this->actingAs($this->admin)
            ->get(route('soci-duplicati.index'))
            ->assertOk()
            ->assertViewHas('gruppi', fn (array $gruppi) => $gruppi === []);

        $this->assertSame(3, Socio::query()->count());

        $this->actingAs($this->admin)
            ->get(route('soci.show', $giulia))
            ->assertOk()
            ->assertSee('20260001')
            ->assertSee('Ferrari');
    }
}
