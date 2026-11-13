<?php

namespace Tests\Feature\Flussi;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\CategoriaMovimento;
use App\Models\Cessazione;
use App\Models\Donazione;
use App\Models\EmailQueue;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Movimento;
use App\Models\PaymentGatewaySetting;
use App\Models\Progetto;
use App\Models\PromemoriaInviato;
use App\Models\Quota;
use App\Models\Ricevuta;
use App\Models\Sede;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\TesseraCounter;
use App\Models\TipoSocio;
use App\Models\Utente;
use App\Notifications\Economia\RicevutaDonazioneNotification;
use App\Notifications\Economia\SollecitoQuotaNotification;
use App\Notifications\Quote\PagamentoQuotaNotification;
use App\Notifications\Quote\RicevutaQuotaNotification;
use App\Notifications\Rinnovi\PromemoriaRinnovoNotification;
use App\Services\Pagamenti\Gateways\PayPalGatewayInterface;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use App\Services\Pagamenti\Pagabile;
use App\Services\Rinnovi\PromemoriaRinnoviService;
use App\Services\Tessere\TesseraQrService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Spatie\Honeypot\ProtectAgainstSpam;
use Tests\TestCase;

/**
 * Flussi reali — TESSERAMENTO ED ECONOMIA.
 *
 * Tessere, quote, pagamenti offline e online (gateway Stripe/PayPal
 * sostituiti da fake che registrano le chiamate), webhook e loro replay,
 * ricevute numerate, solleciti, rinnovi self-service, apertura anno,
 * campagna rinnovi, prima nota e donazioni. Ogni test segue un flusso con
 * più attori (admin, socio nell'area soci, account del mini-sito, visitatore
 * anonimo, gateway) e verifica che i dati finiscano nel database con i
 * valori giusti. Complementare ai percorsi di Percorsi/02 (non li ripete).
 */
class Flussi02_TesseramentoEconomiaFlussiTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Associazione $altraAssociazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // Data di riferimento: primavera 2026, anno sociale in corso
        Carbon::setTestNow('2026-03-10 10:00:00');

        $this->seed(RolesSeeder::class);

        $this->associazione = Associazione::factory()->create([
            'nome' => 'Circolo La Lanterna',
            'slug' => 'circolo-lanterna',
            'costo_tessera' => 20.00,
        ]);

        $this->altraAssociazione = Associazione::factory()->create([
            'nome' => 'Associazione Vicina',
            'slug' => 'associazione-vicina',
            'costo_tessera' => 15.00,
        ]);

        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Marta',
            'cognome' => 'Tesoriera',
        ]);
        $this->admin->assignRole('admin_associazione');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Attori, fixture e gateway finti
    // ------------------------------------------------------------------

    /**
     * L'admin nel pannello (guard web esplicita: dopo un actingAs su
     * 'socio' o 'account' la guard di default cambierebbe).
     */
    private function comeAdmin(): static
    {
        return $this->actingAs($this->admin, 'web');
    }

    /**
     * Nessun utente autenticato (visitatore anonimo, gateway).
     */
    private function comeOspite(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function creaSocio(array $override = [], ?Associazione $associazione = null): Socio
    {
        return Socio::factory()->create(array_merge([
            'associazione_id' => ($associazione ?? $this->associazione)->id,
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
            'tipo' => 'Quota Associativa',
        ], $override));
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function creaTessera(Socio $socio, int $anno, string $numero, array $override = []): Tessera
    {
        return Tessera::factory()->create(array_merge([
            'socio_id' => $socio->id,
            'associazione_id' => $socio->associazione_id,
            'anno_validita' => $anno,
            'numero_tessera' => $numero,
            'data_emissione' => $anno.'-01-10',
            'data_scadenza' => $anno.'-12-31',
            'stato' => Tessera::STATO_ATTIVA,
        ], $override));
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function configuraStripe(array $override = []): PaymentGatewaySetting
    {
        return PaymentGatewaySetting::factory()->conStripe()->create(array_merge([
            'associazione_id' => $this->associazione->id,
            'auto_attivazione_pagamento' => true,
        ], $override));
    }

    private function configuraPayPal(): PaymentGatewaySetting
    {
        return PaymentGatewaySetting::factory()->conPayPal()->create([
            'associazione_id' => $this->associazione->id,
            'auto_attivazione_pagamento' => true,
        ]);
    }

    /**
     * Gateway Stripe finto: registra le sessioni di checkout richieste e
     * restituisce, in ordine, gli URL indicati (null = errore API); il
     * webhook è "firmato" se arriva l'header atteso.
     *
     * @param  list<string|null>  $risposteCheckout
     */
    private function fakeStripe(array $risposteCheckout = []): object
    {
        $fake = new class($risposteCheckout) implements StripeGatewayInterface
        {
            /** @var list<array{tipo: string, id: string, importo: float, success: string}> */
            public array $checkout = [];

            /** @var list<string> */
            public array $webhook = [];

            /** @param list<string|null> $risposte */
            public function __construct(public array $risposte) {}

            public function createCheckoutSession(PaymentGatewaySetting $config, Pagabile $pagabile, string $successUrl, string $cancelUrl): ?string
            {
                $indice = count($this->checkout);
                $this->checkout[] = [
                    'tipo' => $pagabile->getPagabileTipo(),
                    'id' => $pagabile->getPagabileId(),
                    'importo' => $pagabile->getImporto(),
                    'success' => $successUrl,
                ];

                return array_key_exists($indice, $this->risposte)
                    ? $this->risposte[$indice]
                    : 'https://checkout.stripe.test/sessione-'.($indice + 1);
            }

            public function constructWebhookEvent(string $payload, string $signatureHeader, string $webhookSecret): ?array
            {
                $this->webhook[] = $webhookSecret;

                if ($signatureHeader !== 't=1,v1=firma-valida') {
                    return null;
                }

                $evento = json_decode($payload, true);

                return is_array($evento) ? $evento : null;
            }
        };

        $this->app->instance(StripeGatewayInterface::class, $fake);

        return $fake;
    }

    /**
     * Gateway PayPal finto: esiti della verifica firma in sequenza e
     * risposta di capture fissa; registra ogni chiamata.
     *
     * @param  list<bool>  $firme
     * @param  array<string, mixed>|null  $capture
     */
    private function fakePayPal(array $firme, ?array $capture): object
    {
        $fake = new class($firme, $capture) implements PayPalGatewayInterface
        {
            public int $verifiche = 0;

            /** @var list<string> */
            public array $capture = [];

            /**
             * @param  list<bool>  $firme
             * @param  array<string, mixed>|null  $rispostaCapture
             */
            public function __construct(public array $firme, public ?array $rispostaCapture) {}

            public function createOrder(PaymentGatewaySetting $config, Pagabile $pagabile, string $returnUrl, string $cancelUrl): ?string
            {
                return 'https://paypal.test/approve/'.$pagabile->getPagabileId();
            }

            public function captureOrder(PaymentGatewaySetting $config, string $orderId): ?array
            {
                $this->capture[] = $orderId;

                return $this->rispostaCapture;
            }

            public function showOrderDetails(PaymentGatewaySetting $config, string $orderId): ?array
            {
                // Il capture finto riporta già il custom_id: i dettagli ordine non servono
                return null;
            }

            public function verifyWebhookSignature(PaymentGatewaySetting $config, array $headers, array $event): bool
            {
                return $this->firme[$this->verifiche++] ?? false;
            }
        };

        $this->app->instance(PayPalGatewayInterface::class, $fake);

        return $fake;
    }

    /**
     * Stripe richiama il webhook con la sessione di checkout completata.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function webhookStripe(array $metadata, string $transazione, string $firma = 't=1,v1=firma-valida'): TestResponse
    {
        return $this->postJson(route('webhooks.stripe'), [
            'id' => 'evt_'.$transazione,
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_'.$transazione,
                    'payment_intent' => $transazione,
                    'metadata' => $metadata,
                ],
            ],
        ], ['Stripe-Signature' => $firma]);
    }

    private function webhookStripeQuota(Quota $quota, string $transazione): TestResponse
    {
        return $this->webhookStripe([
            'tipo' => Pagabile::TIPO_QUOTA,
            'pagabile_id' => $quota->id,
            'quota_id' => $quota->id,
            'associazione_id' => $quota->associazione_id,
        ], $transazione);
    }

    private function tokenHex(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ------------------------------------------------------------------
    // 1. Pagamento offline → ricevuta, prima nota, KPI quote e dashboard
    // ------------------------------------------------------------------

    public function test_pagamento_offline_di_una_quota_aggiorna_dashboard_kpi_e_ricevuta_numerata(): void
    {
        $socio = $this->creaSocio(['nome' => 'Elio', 'cognome' => 'Contanti']);

        // L'admin crea a mano la quota 2026 del socio
        $this->comeAdmin()
            ->post(route('quote.store'), [
                'socio_id' => $socio->id,
                'anno' => 2026,
                'importo' => '25.00',
                'data_scadenza' => '2026-12-31',
                'tipo' => 'Quota Associativa',
            ])
            ->assertRedirect(route('quote.index'))
            ->assertSessionHas('success');

        /** @var Quota $quota */
        $quota = Quota::query()->where('socio_id', $socio->id)->firstOrFail();
        $this->assertSame(Quota::STATO_DA_PAGARE, $quota->stato);

        // Il socio paga in contanti allo sportello: l'admin registra il pagamento
        $this->comeAdmin()
            ->post(route('quote.pagamento', $quota), [
                'payment_method' => 'contanti',
                'payment_date' => '2026-03-10',
            ])
            ->assertRedirect(route('quote.index'))
            ->assertSessionHas('success', 'Pagamento registrato con successo.');

        $quota->refresh();
        $this->assertSame('2026-03-10', $quota->data_pagamento->toDateString());
        $this->assertSame('contanti', $quota->metodo_pagamento);
        $this->assertTrue($quota->isPagata());

        // Ricevuta numerata per anno: la prima del 2026
        $this->assertSame(1, Ricevuta::allTenants()->where('quota_id', $quota->id)->count());
        $this->assertDatabaseHas('ricevute', [
            'associazione_id' => $this->associazione->id,
            'quota_id' => $quota->id,
            'numero' => 1,
            'anno' => 2026,
            'importo' => '25.00',
        ]);

        // Prima nota: un movimento automatico di entrata "Quote associative"
        $movimenti = Movimento::allTenants()->where('associazione_id', $this->associazione->id)->get();
        $this->assertCount(1, $movimenti);
        $this->assertSame(Movimento::TIPO_ENTRATA, $movimenti[0]->tipo);
        $this->assertSame('25.00', $movimenti[0]->importo);
        $this->assertSame('2026-03-10', $movimenti[0]->data->toDateString());
        $this->assertSame('contanti', $movimenti[0]->metodo);
        $this->assertSame($quota->id, $movimenti[0]->origine_id);
        $this->assertSame('Quote associative', CategoriaMovimento::allTenants()->find($movimenti[0]->categoria_id)?->nome);

        Notification::assertSentTo($socio, PagamentoQuotaNotification::class);
        Notification::assertSentTo($socio, RicevutaQuotaNotification::class);

        // Elenco quote: i KPI dell'anno contano l'incasso
        $this->comeAdmin()
            ->get(route('quote.index'))
            ->assertOk()
            ->assertViewHas('kpi', fn (array $kpi) => $kpi['anno'] === 2026
                && $kpi['incassato'] === 25.0
                && $kpi['pagate'] === 1
                && $kpi['aperte'] === 0
                && $kpi['da_incassare'] === 0.0);

        // Dashboard: l'incasso dell'anno e il numero di quote pagate devono
        // essere gli stessi dell'elenco quote (stessa fonte: data_pagamento)
        $risposta = $this->comeAdmin()->get(route('dashboard'))->assertOk();

        $this->assertSame(
            ['quote_pagate' => 1, 'incassato_anno' => 25.0, 'da_incassare' => 0.0],
            [
                'quote_pagate' => $risposta->viewData('stats')['quote_pagate'],
                'incassato_anno' => $risposta->viewData('panoramica')['incassi']['incassato_anno'],
                'da_incassare' => $risposta->viewData('panoramica')['incassi']['da_incassare'],
            ],
            'Dashboard incoerente con il pagamento offline registrato: la quota risulta ancora non pagata.'
        );
    }

    // ------------------------------------------------------------------
    // 2. Quota di rinnovo pagata offline → tessera dell'anno, niente doppia quota
    // ------------------------------------------------------------------

    public function test_pagamento_offline_di_una_quota_di_rinnovo_emette_la_tessera_dell_anno_e_rinnova_non_crea_una_seconda_quota(): void
    {
        // Dicembre 2025: socio attivo con la tessera 2025, l'admin apre il 2026
        Carbon::setTestNow('2025-12-01 09:00:00');
        $socio = $this->creaSocio(['nome' => 'Rita', 'cognome' => 'Rinnovo', 'password_hash' => 'Lanterna-Accesa-2025']);
        $this->creaTessera($socio, 2025, '20250004');

        $this->comeAdmin()
            ->post(route('apertura-anno.genera'), ['anno' => 2026])
            ->assertRedirect(route('apertura-anno.index', ['anno' => 2026]))
            ->assertSessionHas('success');

        /** @var Quota $quota */
        $quota = Quota::query()->where('socio_id', $socio->id)->where('anno', 2026)->firstOrFail();
        $this->assertSame('20.00', $quota->importo);

        // Gennaio 2026: Rita paga la quota con bonifico, l'admin lo registra
        Carbon::setTestNow('2026-01-15 10:00:00');

        $this->comeAdmin()
            ->post(route('quote.pagamento', $quota), [
                'payment_method' => 'bonifico',
                'payment_date' => '2026-01-14',
            ])
            ->assertSessionHas('success');

        $this->assertSame('bonifico', $quota->fresh()->metodo_pagamento);
        $tessere2026 = Tessera::allTenants()->where('socio_id', $socio->id)->where('anno_validita', 2026)->count();

        // Poi entra nell'area soci e prova il rinnovo self-service
        $risposta = $this->actingAs($socio->fresh(), 'socio')
            ->post(route('area-soci.rinnova'));

        $quote2026 = Quota::allTenants()->where('socio_id', $socio->id)->where('anno', 2026)->count();

        $this->assertSame(
            ['tessere_2026' => 1, 'quote_2026' => 1, 'redirect' => route('area-soci.dashboard')],
            ['tessere_2026' => $tessere2026, 'quote_2026' => $quote2026, 'redirect' => $risposta->headers->get('Location')],
            'La quota di rinnovo pagata offline deve emettere la tessera dell\'anno e "rinnova" non deve creare una seconda quota.'
        );
        $risposta->assertSessionHas('warning');
    }

    // ------------------------------------------------------------------
    // 3. Webhook Stripe: attivazione automatica, tessera, ricevuta, replay
    // ------------------------------------------------------------------

    public function test_webhook_stripe_finto_paga_la_quota_attiva_il_socio_in_attesa_di_pagamento_e_genera_tessera_e_ricevuta_una_sola_volta(): void
    {
        $config = $this->configuraStripe();
        $stripe = $this->fakeStripe();

        $socio = $this->creaSocio([
            'nome' => 'Nadia',
            'cognome' => 'Online',
            'stato' => Socio::STATO_IN_ATTESA_DI_PAGAMENTO,
            'data_iscrizione' => '2026-02-01',
        ]);
        $quota = $this->creaQuota($socio, 2026, 30.00);

        // L'admin genera il link di pagamento e lo manda al socio
        $this->comeAdmin()
            ->post(route('quote.payment-link', $quota))
            ->assertRedirect(route('quote.index'))
            ->assertSessionHas('payment_link');

        $quota->refresh();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $quota->payment_token);

        // Il socio apre il link e sceglie Stripe
        $this->comeOspite();
        $this->get(route('pagamento.show', ['token' => $quota->payment_token]))
            ->assertOk()
            ->assertViewHas('hasStripe', true)
            ->assertSee('30,00');

        $this->post(route('pagamento.checkout', ['token' => $quota->payment_token]), ['gateway' => 'stripe'])
            ->assertRedirect('https://checkout.stripe.test/sessione-1');

        $this->assertCount(1, $stripe->checkout);
        $this->assertSame(['tipo' => 'quota', 'id' => $quota->id, 'importo' => 30.0], array_slice($stripe->checkout[0], 0, 3));

        // Stripe conferma il pagamento
        $this->webhookStripeQuota($quota, 'pi_flusso_3')
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertSame([$config->stripe_webhook_secret], $stripe->webhook);

        $quota->refresh();
        $this->assertSame(Quota::STATO_PAGATA, $quota->stato);
        $this->assertSame('stripe', $quota->gateway);
        $this->assertSame('stripe', $quota->metodo_pagamento);
        $this->assertSame('pi_flusso_3', $quota->gateway_transaction_id);
        $this->assertSame('2026-03-10', $quota->data_pagamento->toDateString());

        $socio->refresh();
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);
        $this->assertSame('2026-03-10', $socio->data_iscrizione->toDateString());

        $this->assertSame(1, Tessera::allTenants()->where('socio_id', $socio->id)->where('anno_validita', 2026)->count());
        $this->assertSame(1, Ricevuta::allTenants()->where('quota_id', $quota->id)->count());
        $this->assertSame(1, Movimento::allTenants()->where('origine_id', $quota->id)->count());
        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Attivazione automatica',
        ]);
        Notification::assertSentTo($socio, PagamentoQuotaNotification::class);

        // Replay del webhook (Stripe ritenta): ack senza nuove righe
        $conteggi = fn () => [
            'tessere' => Tessera::allTenants()->where('socio_id', $socio->id)->count(),
            'ricevute' => Ricevuta::allTenants()->count(),
            'movimenti' => Movimento::allTenants()->count(),
            'storico' => DB::table('storico_attivita_socio')->where('socio_id', $socio->id)->count(),
        ];
        $prima = $conteggi();

        $this->webhookStripeQuota($quota, 'pi_flusso_3')->assertOk()->assertJson(['status' => 'ok']);

        $this->assertSame($prima, $conteggi());
        Notification::assertSentToTimes($socio, PagamentoQuotaNotification::class, 1);

        // Firma non valida: rifiutato, nulla cambia
        $this->webhookStripe(['quota_id' => $quota->id, 'associazione_id' => $this->associazione->id], 'pi_falso', 't=1,v1=falsa')
            ->assertStatus(400);
        $this->assertSame('pi_flusso_3', $quota->fresh()->gateway_transaction_id);
    }

    // ------------------------------------------------------------------
    // 4. Webhook PayPal: firma non valida ignorata, firma valida → capture
    // ------------------------------------------------------------------

    public function test_webhook_paypal_finto_con_firma_non_valida_viene_ignorato_e_con_firma_valida_completa_il_capture(): void
    {
        $this->configuraPayPal();

        $socio = $this->creaSocio(['nome' => 'Paolo', 'cognome' => 'Paypal']);
        $quota = $this->creaQuota($socio, 2026, 40.00);

        $paypal = $this->fakePayPal([false, true], [
            'id' => 'ORDER-77',
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'payments' => ['captures' => [['id' => 'CAP-77', 'custom_id' => $quota->id, 'status' => 'COMPLETED']]],
            ]],
        ]);

        $evento = [
            'id' => 'WH-77',
            'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => [
                'id' => 'ORDER-77',
                'purchase_units' => [['custom_id' => $quota->id, 'amount' => ['value' => '40.00']]],
            ],
        ];
        $headers = [
            'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            'PAYPAL-CERT-URL' => 'https://api.paypal.test/cert',
            'PAYPAL-TRANSMISSION-ID' => 'tx-1',
            'PAYPAL-TRANSMISSION-SIG' => 'firma',
            'PAYPAL-TRANSMISSION-TIME' => '2026-03-10T10:00:00Z',
        ];

        // Primo invio con firma non valida: nessun capture, quota invariata
        $this->postJson(route('webhooks.paypal'), $evento, $headers)->assertStatus(400);

        $quota->refresh();
        $this->assertNull($quota->data_pagamento);
        $this->assertNull($quota->gateway_transaction_id);
        $this->assertSame([], $paypal->capture);
        $this->assertSame(0, Ricevuta::allTenants()->count());
        Notification::assertNotSentTo($socio, PagamentoQuotaNotification::class);

        // Secondo invio con firma valida: capture server-side e incasso
        $this->postJson(route('webhooks.paypal'), $evento, $headers)
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertSame(2, $paypal->verifiche);
        $this->assertSame(['ORDER-77'], $paypal->capture);

        $quota->refresh();
        $this->assertSame(Quota::STATO_PAGATA, $quota->stato);
        $this->assertSame('paypal', $quota->gateway);
        $this->assertSame('CAP-77', $quota->gateway_transaction_id);
        $this->assertSame('2026-03-10', $quota->data_pagamento->toDateString());

        $this->assertDatabaseHas('ricevute', ['quota_id' => $quota->id, 'numero' => 1, 'anno' => 2026, 'importo' => '40.00']);
        $this->assertDatabaseHas('movimenti', ['origine_id' => $quota->id, 'metodo' => 'paypal', 'importo' => '40.00']);
        Notification::assertSentTo($socio, PagamentoQuotaNotification::class, fn ($n) => $n->quota->id === $quota->id);
        Notification::assertSentTo($socio, RicevutaQuotaNotification::class);
    }

    // ------------------------------------------------------------------
    // 5. Link scaduto: checkout bloccato; il sollecito rigenera il token
    // ------------------------------------------------------------------

    public function test_link_di_pagamento_scaduto_non_permette_il_checkout_e_il_sollecito_rigenera_un_token_valido(): void
    {
        $this->configuraStripe();
        $stripe = $this->fakeStripe();

        $socio = $this->creaSocio(['nome' => 'Sara', 'cognome' => 'Ritardo']);
        $vecchioToken = $this->tokenHex();
        $quota = $this->creaQuota($socio, 2026, 20.00, [
            'payment_token' => $vecchioToken,
            'payment_token_expires' => now()->subDay(),
        ]);

        // Il socio apre il vecchio link: pagina con link scaduto
        $this->get(route('pagamento.show', ['token' => $vecchioToken]))
            ->assertOk()
            ->assertViewHas('tokenExpired', true);

        // Forzando il checkout il server rimanda alla pagina senza chiamare il gateway
        $this->post(route('pagamento.checkout', ['token' => $vecchioToken]), ['gateway' => 'stripe'])
            ->assertRedirect(route('pagamento.show', ['token' => $vecchioToken]));
        $this->assertCount(0, $stripe->checkout);

        // L'admin invia il sollecito
        $this->comeAdmin()
            ->post(route('solleciti-quote.invia', $quota))
            ->assertRedirect(route('solleciti-quote.index'))
            ->assertSessionHas('success', 'Sollecito inviato.');

        $quota->refresh();
        $this->assertNotSame($vecchioToken, $quota->payment_token);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $quota->payment_token);
        $this->assertTrue($quota->payment_token_expires->isFuture());

        $this->assertDatabaseHas('promemoria_inviati', [
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
            'tessera_id' => null,
            'tipo' => 'sollecito_quota',
            'step' => 2026,
        ]);

        $nuovoUrl = route('pagamento.show', ['token' => $quota->payment_token]);
        Notification::assertSentTo($socio, SollecitoQuotaNotification::class,
            fn (SollecitoQuotaNotification $n) => $n->quota->id === $quota->id && $n->paymentUrl === $nuovoUrl);

        // Con il nuovo link il checkout parte davvero
        $this->comeOspite();
        $this->get($nuovoUrl)->assertOk()->assertViewHas('tokenExpired', false);
        $this->post(route('pagamento.checkout', ['token' => $quota->payment_token]), ['gateway' => 'stripe'])
            ->assertRedirect('https://checkout.stripe.test/sessione-1');
        $this->assertCount(1, $stripe->checkout);

        // Il vecchio token non esiste più
        $this->get(route('pagamento.show', ['token' => $vecchioToken]))->assertNotFound();
    }

    // ------------------------------------------------------------------
    // 6. Solleciti in blocco: esclusioni e tracciamento
    // ------------------------------------------------------------------

    public function test_solleciti_bulk_saltano_quote_pagate_soci_senza_email_e_opt_out_e_tracciano_ogni_invio(): void
    {
        $pagante = $this->creaSocio(['cognome' => 'Pagante']);
        $senzaEmail = $this->creaSocio(['cognome' => 'Senzamail', 'email' => '']);
        $optOut = $this->creaSocio(['cognome' => 'Optout', 'email_opt_out' => true]);
        $sollecitabile = $this->creaSocio(['cognome' => 'Sollecitabile']);

        $qPagata = $this->creaQuota($pagante, 2026, 20.00, [
            'data_pagamento' => '2026-02-01',
            'stato' => Quota::STATO_PAGATA,
            'metodo_pagamento' => 'contanti',
        ]);
        $qSenzaEmail = $this->creaQuota($senzaEmail, 2026, 20.00);
        $qOptOut = $this->creaQuota($optOut, 2026, 20.00);
        $qOk = $this->creaQuota($sollecitabile, 2026, 20.00);

        $this->comeAdmin()
            ->post(route('solleciti-quote.bulk'), [
                'quota_ids' => [$qPagata->id, $qSenzaEmail->id, $qOptOut->id, $qOk->id],
            ])
            ->assertRedirect(route('solleciti-quote.index'))
            ->assertSessionHas('success', 'Solleciti inviati: 1 — saltati: 2 (senza email o con opt-out).');

        $tracciati = PromemoriaInviato::allTenants()->where('tipo', 'sollecito_quota')->get();
        $this->assertCount(1, $tracciati);
        $this->assertSame($sollecitabile->id, $tracciati[0]->socio_id);
        $this->assertSame(2026, (int) $tracciati[0]->step);

        Notification::assertSentTo($sollecitabile, SollecitoQuotaNotification::class);
        Notification::assertNotSentTo($senzaEmail, SollecitoQuotaNotification::class);
        Notification::assertNotSentTo($optOut, SollecitoQuotaNotification::class);
        Notification::assertNotSentTo($pagante, SollecitoQuotaNotification::class);

        // Solo la quota sollecitata ha il token di pagamento generato
        $this->assertNotNull($qOk->fresh()->payment_token);
        $this->assertNull($qSenzaEmail->fresh()->payment_token);

        // L'elenco solleciti: la pagata non compare, il conteggio è per quota
        $this->comeAdmin()
            ->get(route('solleciti-quote.index'))
            ->assertOk()
            ->assertViewHas('quote', fn ($p) => ! collect($p->items())->contains('id', $qPagata->id)
                && collect($p->items())->contains('id', $qOk->id))
            ->assertViewHas('solleciti', fn (array $s) => $s[$qOk->id]['conteggio'] === 1
                && $s[$qOk->id]['ultimo'] !== null
                && $s[$qSenzaEmail->id]['conteggio'] === 0
                && $s[$qOptOut->id]['conteggio'] === 0);
    }

    // ------------------------------------------------------------------
    // 7. Eliminazione quote: online bloccata, offline ammessa (e la prima nota?)
    // ------------------------------------------------------------------

    public function test_eliminazione_quota_pagata_online_e_bloccata_mentre_quota_offline_si_puo_eliminare(): void
    {
        $socioOnline = $this->creaSocio(['cognome' => 'Online']);
        $quotaOnline = $this->creaQuota($socioOnline, 2026, 30.00, [
            'data_pagamento' => '2026-03-01',
            'stato' => Quota::STATO_PAGATA,
            'metodo_pagamento' => 'stripe',
            'gateway' => 'stripe',
            'gateway_transaction_id' => 'pi_traccia_7',
        ]);

        $this->comeAdmin()
            ->delete(route('quote.destroy', $quotaOnline))
            ->assertRedirect(route('quote.index'))
            ->assertSessionHas('error', 'Impossibile eliminare una quota pagata online (tracciabilità contabile).');
        $this->assertDatabaseHas('quote', ['id' => $quotaOnline->id, 'gateway_transaction_id' => 'pi_traccia_7']);

        // Quota pagata in contanti: genera ricevuta e movimento di prima nota
        $socioOffline = $this->creaSocio(['cognome' => 'Sportello']);
        $quotaOffline = $this->creaQuota($socioOffline, 2026, 15.00);

        $this->comeAdmin()
            ->post(route('quote.pagamento', $quotaOffline), ['payment_method' => 'contanti', 'payment_date' => '2026-03-09'])
            ->assertSessionHas('success');

        /** @var Movimento $movimento */
        $movimento = Movimento::allTenants()->where('origine_id', $quotaOffline->id)->firstOrFail();
        $this->assertSame(1, Ricevuta::allTenants()->where('quota_id', $quotaOffline->id)->count());

        $this->comeAdmin()
            ->delete(route('quote.destroy', $quotaOffline))
            ->assertRedirect(route('quote.index'))
            ->assertSessionHas('success', 'Quota eliminata con successo.');
        $this->assertDatabaseMissing('quote', ['id' => $quotaOffline->id]);

        // Documentato: la ricevuta numerata segue la quota (FK cascade)
        $this->assertSame(0, Ricevuta::allTenants()->where('quota_id', $quotaOffline->id)->count());

        // Il movimento automatico NON ha FK sull'origine polimorfica: resta in
        // prima nota puntando a una quota inesistente. Se resta, l'admin deve
        // almeno poterlo gestire (non è più collegato a nessun pagamento).
        if (Movimento::allTenants()->whereKey($movimento->id)->exists()) {
            $this->comeAdmin()
                ->delete(route('prima-nota.destroy', $movimento))
                ->assertRedirect(route('prima-nota.index'));

            $this->assertFalse(Movimento::allTenants()->whereKey($movimento->id)->exists(),
                'Movimento orfano della quota eliminata rimasto in prima nota e non eliminabile (origine_id valorizzato).');
        }
    }

    // ------------------------------------------------------------------
    // 8. Modifica di una quota pagata: campi salvati, ricevuta "congelata"
    // ------------------------------------------------------------------

    public function test_modifica_quota_pagata_cambiando_anno_importo_e_socio_viene_salvata_e_la_ricevuta_resta_coerente(): void
    {
        $socioA = $this->creaSocio(['cognome' => 'Primo']);
        $socioB = $this->creaSocio(['cognome' => 'Secondo']);
        $estraneo = $this->creaSocio(['cognome' => 'Estraneo'], $this->altraAssociazione);

        $quota = $this->creaQuota($socioA, 2026, 25.00);
        $this->comeAdmin()
            ->post(route('quote.pagamento', $quota), ['payment_method' => 'contanti', 'payment_date' => '2026-03-01'])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('ricevute', ['quota_id' => $quota->id, 'importo' => '25.00', 'anno' => 2026]);

        // L'admin corregge la quota: altro socio, anno, importo e tipo
        $this->comeAdmin()
            ->put(route('quote.update', $quota), [
                'socio_id' => $socioB->id,
                'anno' => 2027,
                'importo' => '40.00',
                'data_scadenza' => '2027-12-31',
                'tipo' => 'Quota Sostenitore',
            ])
            ->assertRedirect(route('quote.index'))
            ->assertSessionHas('success', 'Quota aggiornata.');

        $quota->refresh();
        $this->assertSame($socioB->id, $quota->socio_id);
        $this->assertSame(2027, $quota->anno);
        $this->assertSame('40.00', $quota->importo);
        $this->assertSame('2027-12-31', $quota->data_scadenza->toDateString());
        $this->assertSame('Quota Sostenitore', $quota->tipo);
        // Il pagamento resta registrato
        $this->assertSame('2026-03-01', $quota->data_pagamento->toDateString());
        $this->assertSame('contanti', $quota->metodo_pagamento);

        // Documentato: ricevuta e movimento restano con i dati del pagamento
        // originale (importo 25, anno 2026) mentre la quota ora dice 40/2027
        $ricevuta = Ricevuta::allTenants()->where('quota_id', $quota->id)->firstOrFail();
        $this->assertSame('25.00', $ricevuta->importo);
        $this->assertSame(2026, (int) $ricevuta->anno);
        // Issue #20: anche intestatario e tipo quota restano quelli
        // dell'emissione (socio A), non quelli della quota corretta
        $this->assertSame('Primo', $ricevuta->intestatario_cognome);
        $this->assertSame('contanti', $ricevuta->metodo_pagamento);
        $stampa = $ricevuta->datiStampa($quota, $socioB);
        $this->assertStringContainsString('Primo', (string) $stampa['intestatario']);
        $this->assertNotSame('Quota Sostenitore', $stampa['tipo_quota']);
        $this->assertSame('25.00', Movimento::allTenants()->where('origine_id', $quota->id)->value('importo'));

        // Il socio di un'altra associazione non è assegnabile
        $this->comeAdmin()
            ->from(route('quote.index'))
            ->put(route('quote.update', $quota), [
                'socio_id' => $estraneo->id,
                'anno' => 2027,
                'importo' => '40.00',
                'data_scadenza' => '2027-12-31',
                'tipo' => 'Quota Sostenitore',
            ])
            ->assertRedirect(route('quote.index'))
            ->assertSessionHasErrors('socio_id');
        $this->assertSame($socioB->id, $quota->fresh()->socio_id);
    }

    // ------------------------------------------------------------------
    // 9. Tessera manuale con numero libero + generazione massiva col contatore
    // ------------------------------------------------------------------

    public function test_emissione_tessera_manuale_con_numero_libero_e_poi_massiva_usa_il_counter_senza_collisioni(): void
    {
        $sostenitore = TipoSocio::create(['associazione_id' => $this->associazione->id, 'nome' => 'Sostenitore', 'costo_tessera' => 50]);
        $a = $this->creaSocio(['cognome' => 'Alfa']);
        $b = $this->creaSocio(['cognome' => 'Beta', 'tipo_socio_id' => $sostenitore->id]);
        $c = $this->creaSocio(['cognome' => 'Gamma']);
        $d = $this->creaSocio(['cognome' => 'Delta']);
        $sospeso = $this->creaSocio(['cognome' => 'Sospeso', 'stato' => Socio::STATO_SOSPESO]);

        // Tessera manuale con numero libero
        $this->comeAdmin()
            ->post(route('tessere.store'), [
                'socio_id' => $a->id,
                'numero_tessera' => 'ABC-1',
                'anno_validita' => 2026,
                'data_emissione' => '2026-03-10',
                'stato' => Tessera::STATO_ATTIVA,
            ])
            ->assertRedirect(route('tessere.index', ['anno' => 2026]))
            ->assertSessionHas('success', 'Tessera creata con successo.');

        /** @var Tessera $manuale */
        $manuale = Tessera::query()->where('socio_id', $a->id)->firstOrFail();
        $this->assertSame('ABC-1', $manuale->numero_tessera);
        $this->assertSame('2026-12-31', $manuale->data_scadenza->toDateString());
        $this->assertSame(Tessera::TIPO_SCADENZA_SOLARE, $manuale->tipo_scadenza);
        $this->assertSame('Default', $manuale->template_tessera);
        $this->assertStringContainsString('signature=', (string) $manuale->qr_code_url);

        // Generazione massiva 2026 per i soci attivi senza tessera
        $this->comeAdmin()
            ->post(route('tessere.genera-massivo'), ['anno_validita' => 2026])
            ->assertRedirect(route('tessere.index', ['anno' => 2026]))
            ->assertSessionHas('success', "Generate 3 nuove tessere per l'anno 2026.");

        $this->assertSame(4, Tessera::query()->count());
        $this->assertSame(0, Tessera::query()->where('socio_id', $sospeso->id)->count());

        $generate = Tessera::query()->whereIn('socio_id', [$b->id, $c->id, $d->id])->get();
        $this->assertSame(['20260001', '20260002', '20260003'], $generate->pluck('numero_tessera')->sort()->values()->all());
        $this->assertSame('Sostenitore', $generate->firstWhere('socio_id', $b->id)->template_tessera);
        foreach ($generate as $tessera) {
            $this->assertNotNull($tessera->template_tessera);
            $this->assertStringContainsString('signature=', (string) $tessera->qr_code_url);
            $this->assertSame('2026-12-31', $tessera->data_scadenza->toDateString());
        }

        $this->assertSame(3, (int) TesseraCounter::query()->withoutGlobalScopes()
            ->where('associazione_id', $this->associazione->id)->where('anno', 2026)->value('ultimo_numero'));

        // PDF della tessera
        $pdf = $this->comeAdmin()->get(route('tessere.pdf', $generate->first()));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));

        // Seconda generazione: nessun duplicato
        $this->comeAdmin()
            ->post(route('tessere.genera-massivo'), ['anno_validita' => 2026])
            ->assertSessionHas('success', "Generate 0 nuove tessere per l'anno 2026.");
        $this->assertSame(4, Tessera::query()->count());

        // Il numero libero già usato non è riutilizzabile
        $this->comeAdmin()
            ->from(route('tessere.index'))
            ->post(route('tessere.store'), [
                'socio_id' => $b->id,
                'numero_tessera' => 'ABC-1',
                'anno_validita' => 2027,
                'data_emissione' => '2026-03-10',
                'stato' => Tessera::STATO_ATTIVA,
            ])
            ->assertSessionHasErrors('numero_tessera');
    }

    // ------------------------------------------------------------------
    // 10. Numerazione tessere tra attivazione automatica ed emissione on-demand
    // ------------------------------------------------------------------

    public function test_tessera_creata_da_attivazione_automatica_e_poi_emissione_on_demand_mantengono_numeri_univoci_e_formato(): void
    {
        $this->configuraStripe();
        $this->fakeStripe();

        // Storico: una tessera 2025 emessa con la numerazione unificata
        $veterano = $this->creaSocio(['cognome' => 'Veterano']);
        $this->creaTessera($veterano, 2025, '20250007');

        // X: pre-iscritto che paga online → attivazione automatica
        $x = $this->creaSocio(['cognome' => 'Xeno', 'stato' => Socio::STATO_IN_ATTESA_DI_PAGAMENTO]);
        $this->webhookStripeQuota($this->creaQuota($x, 2026, 20.00), 'pi_x')->assertOk();

        // Y: socio attivo, l'admin emette la tessera on-demand
        $y = $this->creaSocio(['cognome' => 'Ypsilon']);
        $this->comeAdmin()->post(route('tessere.emetti', $y))->assertRedirect();

        // Z: altro pre-iscritto che paga online
        $z = $this->creaSocio(['cognome' => 'Zeta', 'stato' => Socio::STATO_IN_ATTESA_DI_PAGAMENTO]);
        $this->webhookStripeQuota($this->creaQuota($z, 2026, 20.00), 'pi_z')->assertOk();

        $tessere = collect([$x, $y, $z])->mapWithKeys(fn (Socio $s) => [
            $s->cognome => Tessera::allTenants()->where('socio_id', $s->id)->where('anno_validita', 2026)->firstOrFail(),
        ]);

        // Numeri distinti (unique per associazione rispettato)
        $numeri = $tessere->pluck('numero_tessera')->all();
        $this->assertCount(3, array_unique($numeri));
        $this->assertSame(0, Tessera::allTenants()
            ->where('associazione_id', $this->associazione->id)
            ->select('numero_tessera')->groupBy('numero_tessera')->havingRaw('count(*) > 1')->count());

        // Tutti i flussi devono usare la stessa numerazione {anno}{NNNN},
        // l'etichetta del template e il QR firmato di TesseraIssuer
        $rilevato = $tessere->map(fn (Tessera $t) => [
            'formato_anno' => (bool) preg_match('/^2026\d{4}$/', $t->numero_tessera),
            'template' => $t->template_tessera !== null,
            'qr_firmato' => str_contains((string) $t->qr_code_url, 'signature='),
        ])->all();
        $atteso = array_fill_keys(array_keys($rilevato), ['formato_anno' => true, 'template' => true, 'qr_firmato' => true]);

        $this->assertSame($atteso, $rilevato, 'Numeri emessi: '.json_encode($numeri));
    }

    // ------------------------------------------------------------------
    // 11. Verifica pubblica della tessera: firma valida, manomessa, annullata
    // ------------------------------------------------------------------

    public function test_verifica_pubblica_tessera_con_firma_valida_manomessa_e_tessera_annullata(): void
    {
        $socio = $this->creaSocio(['nome' => 'Vera', 'cognome' => 'Verificata']);

        $this->comeAdmin()->post(route('tessere.emetti', $socio))->assertRedirect();
        /** @var Tessera $tessera */
        $tessera = Tessera::query()->where('socio_id', $socio->id)->firstOrFail();
        $this->assertNotNull($tessera->qr_code_url);

        // Chiunque scansioni il QR, senza login
        $this->comeOspite();
        $this->get($tessera->qr_code_url)
            ->assertOk()
            ->assertViewHas('stato', 'valida')
            ->assertSee('Vera')
            ->assertSee('Verificata');

        // Firma manomessa → trattata come tessera inesistente
        $manomesso = preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $tessera->qr_code_url);
        $this->assertNotSame($tessera->qr_code_url, $manomesso);
        $this->get($manomesso)
            ->assertOk()
            ->assertViewHas('stato', 'not_found')
            ->assertDontSee('Verificata');

        // L'admin annulla la tessera
        $this->comeAdmin()
            ->put(route('tessere.update', $tessera), [
                'socio_id' => $socio->id,
                'numero_tessera' => $tessera->numero_tessera,
                'anno_validita' => 2026,
                'data_emissione' => $tessera->data_emissione->toDateString(),
                'data_scadenza' => $tessera->data_scadenza->toDateString(),
                'stato' => Tessera::STATO_ANNULLATA,
            ])
            ->assertRedirect(route('tessere.index', ['anno' => 2026]))
            ->assertSessionHas('success');
        $this->assertSame(Tessera::STATO_ANNULLATA, $tessera->fresh()->stato);

        // Lo scanner pubblico (con la firma del QR, obbligatoria: audit run-1)
        // la vede non più valida
        $this->comeOspite();
        $qr = app(TesseraQrService::class);
        $this->getJson(route('tessere.verifica.json', ['tessera' => $tessera->id, 'signature' => $qr->signature($tessera)]))
            ->assertOk()
            ->assertJson([
                'stato' => 'scaduta',
                'tessera' => [
                    'numero_tessera' => $tessera->numero_tessera,
                    'stato_tessera' => Tessera::STATO_ANNULLATA,
                    'socio_cognome' => 'Verificata',
                ],
            ]);

        // UUID inesistente (firma corretta per quell'UUID)
        $this->getJson(route('tessere.verifica.json', ['tessera' => '00000000-0000-4000-8000-000000000000', 'signature' => $qr->signature('00000000-0000-4000-8000-000000000000')]))
            ->assertOk()
            ->assertExactJson(['stato' => 'not_found']);
    }

    // ------------------------------------------------------------------
    // 12. Check-in evento con tessera scaduta / socio senza tessere
    // ------------------------------------------------------------------

    public function test_checkin_tessera_rifiuta_socio_con_sola_tessera_scaduta_e_accetta_socio_senza_tessere_per_numero_socio(): void
    {
        $evento = Evento::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Cena sociale',
            'data_evento' => '2026-03-20 20:00:00',
        ]);

        $scaduto = $this->creaSocio(['cognome' => 'Scaduto', 'numero_socio' => '101']);
        $this->creaTessera($scaduto, 2024, '20240015');

        $nuovo = $this->creaSocio(['cognome' => 'Nuovo', 'numero_socio' => '102']);

        // Tessera 2024 scaduta: check-in rifiutato
        $this->comeAdmin()
            ->postJson(route('tessere.checkin.store'), [
                'evento_id' => $evento->id,
                'numero_tessera' => '20240015',
            ])
            ->assertStatus(400)
            ->assertJson(['success' => false, 'code' => 'tessera_non_valida']);
        $this->assertDatabaseMissing('eventi_partecipanti', ['evento_id' => $evento->id, 'socio_id' => $scaduto->id]);

        // Socio senza tessere: identificato per numero socio
        $this->comeAdmin()
            ->postJson(route('tessere.checkin.store'), [
                'evento_id' => $evento->id,
                'numero_socio' => '102',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'already_checked_in' => false,
                'socio' => ['cognome' => 'Nuovo', 'numero_socio' => '102'],
                'evento' => 'Cena sociale',
            ]);

        /** @var EventoPartecipante $partecipante */
        $partecipante = EventoPartecipante::query()->where('evento_id', $evento->id)->where('socio_id', $nuovo->id)->firstOrFail();
        $this->assertSame(EventoPartecipante::STATO_CONFERMATO, $partecipante->stato_partecipazione);
        $this->assertNotNull($partecipante->data_conferma);
        $this->assertSame($this->associazione->id, $partecipante->associazione_id);

        // Secondo passaggio al varco: già registrato, nessuna riga in più
        $this->comeAdmin()
            ->postJson(route('tessere.checkin.store'), [
                'evento_id' => $evento->id,
                'numero_socio' => '102',
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'already_checked_in' => true]);
        $this->assertSame(1, EventoPartecipante::query()->where('evento_id', $evento->id)->count());

        // Evento di un'altra associazione: validazione
        $eventoAltrui = Evento::factory()->create(['associazione_id' => $this->altraAssociazione->id]);
        $this->comeAdmin()
            ->postJson(route('tessere.checkin.store'), ['evento_id' => $eventoAltrui->id, 'numero_socio' => '102'])
            ->assertStatus(422)
            ->assertJson(['code' => 'validation_error']);
    }

    // ------------------------------------------------------------------
    // 13. Apertura anno in regime annuale
    // ------------------------------------------------------------------

    public function test_apertura_anno_in_regime_annuale_genera_quote_con_importo_per_tipo_e_scadenza_coerente(): void
    {
        $this->associazione->forceFill(['tipo_scadenza_default' => Tessera::TIPO_SCADENZA_ANNUALE, 'costo_tessera' => 20.00])->save();
        $sostenitore = TipoSocio::create(['associazione_id' => $this->associazione->id, 'nome' => 'Sostenitore', 'costo_tessera' => 50.00]);

        $s1 = $this->creaSocio(['cognome' => 'Generoso', 'tipo_socio_id' => $sostenitore->id]);
        $s2 = $this->creaSocio(['cognome' => 'Ordinario']);
        $s3 = $this->creaSocio(['cognome' => 'Semplice']);
        $sospeso = $this->creaSocio(['cognome' => 'Fermo', 'stato' => Socio::STATO_SOSPESO]);
        // Socio di un'altra associazione: mai coinvolto
        $estraneo = $this->creaSocio(['cognome' => 'Estraneo'], $this->altraAssociazione);

        // Anteprima (anno di default = anno prossimo)
        $this->comeAdmin()
            ->get(route('apertura-anno.index'))
            ->assertOk()
            ->assertViewHas('anno', 2027)
            ->assertViewHas('anteprima', function (array $a) {
                $perTipo = collect($a['per_tipo'])->keyBy('tipo');

                return $a['soci_attivi'] === 3
                    && $a['gia_aperte'] === 0
                    && $a['da_generare'] === 3
                    && $a['importo_totale'] === 90.0
                    && $perTipo->count() === 2
                    && $perTipo['Sostenitore']['soci'] === 1 && $perTipo['Sostenitore']['importo_totale'] === 50.0
                    && $perTipo['Senza tipo']['soci'] === 2 && $perTipo['Senza tipo']['importo_unitario'] === 20.0;
            });
        $this->assertSame(0, Quota::allTenants()->count(), 'L\'anteprima non scrive nulla.');

        // Generazione
        $this->comeAdmin()
            ->post(route('apertura-anno.genera'), ['anno' => 2027])
            ->assertRedirect(route('apertura-anno.index', ['anno' => 2027]))
            ->assertSessionHas('success', 'Anno 2027 aperto: generate 3 quote per i soci attivi.');

        $quote = Quota::allTenants()->where('anno', 2027)->get();
        $this->assertCount(3, $quote);
        $this->assertSame('50.00', $quote->firstWhere('socio_id', $s1->id)->importo);
        $this->assertSame('20.00', $quote->firstWhere('socio_id', $s2->id)->importo);
        $this->assertSame('20.00', $quote->firstWhere('socio_id', $s3->id)->importo);
        $this->assertNull($quote->firstWhere('socio_id', $sospeso->id));
        $this->assertNull($quote->firstWhere('socio_id', $estraneo->id));

        foreach ($quote as $quota) {
            $this->assertSame($this->associazione->id, $quota->associazione_id);
            $this->assertSame(Quota::STATO_DA_PAGARE, $quota->stato);
            $this->assertNull($quota->data_pagamento);
            $this->assertSame('Quota Associativa', $quota->tipo);
            // Documentato: anche in regime annuale la scadenza della quota
            // generata in bulk è il 31/12 dell'anno (createQuotaForSocio
            // userebbe invece oggi + 1 anno): scelta del modulo apertura anno
            $this->assertSame('2027-12-31', $quota->data_scadenza->toDateString());
        }

        // Rigenerazione idempotente
        $this->comeAdmin()
            ->post(route('apertura-anno.genera'), ['anno' => 2027])
            ->assertSessionHas('success', "Nessuna quota da generare per l'anno 2027: tutti i soci attivi hanno già la quota.");
        $this->assertSame(3, Quota::allTenants()->where('anno', 2027)->count());

        $this->comeAdmin()
            ->get(route('apertura-anno.index', ['anno' => 2027]))
            ->assertViewHas('anteprima', fn (array $a) => $a['da_generare'] === 0 && $a['gia_aperte'] === 3);
    }

    // ------------------------------------------------------------------
    // 14. Campagna rinnovi: email con link, niente quote doppie
    // ------------------------------------------------------------------

    public function test_campagna_rinnovi_accoda_email_con_link_pagamento_e_segna_inviata_senza_duplicare_quote(): void
    {
        $conQuota = $this->creaSocio(['nome' => 'Anna', 'cognome' => 'Conquota', 'email' => 'anna.conquota@example.com']);
        $quotaEsistente = $this->creaQuota($conQuota, 2026, 20.00);
        $senzaQuota = $this->creaSocio(['nome' => 'Bruno', 'cognome' => 'Senzaquota', 'email' => 'bruno.senzaquota@example.com']);
        $rinnovato = $this->creaSocio(['nome' => 'Carla', 'cognome' => 'Rinnovata']);
        $this->creaTessera($rinnovato, 2026, '20260090');

        $this->comeAdmin()
            ->get(route('campagna-rinnovi.index'))
            ->assertOk()
            ->assertViewHas('soci', fn ($soci) => $soci->pluck('id')->sort()->values()->all() === collect([$conQuota->id, $senzaQuota->id])->sort()->values()->all())
            ->assertViewHas('contatori', fn (array $c) => $c['da_rinnovare'] === 2 && $c['inviate'] === 0);

        $this->comeAdmin()
            ->post(route('campagna-rinnovi.invia'), ['socio_ids' => [$conQuota->id, $senzaQuota->id, $rinnovato->id]])
            ->assertRedirect(route('campagna-rinnovi.index'))
            ->assertSessionHas('success', 'Campagna rinnovi 2026: 2 email accodate. 1 soci saltati (senza email, opt-out o già rinnovati).');

        // Quote: quella esistente riusata, l'altra creata (una sola per socio)
        $this->assertSame(1, Quota::allTenants()->where('socio_id', $conQuota->id)->where('anno', 2026)->count());
        $this->assertSame(1, Quota::allTenants()->where('socio_id', $senzaQuota->id)->where('anno', 2026)->count());
        $this->assertSame(0, Quota::allTenants()->where('socio_id', $rinnovato->id)->count());

        $quotaEsistente->refresh();
        $this->assertTrue($quotaEsistente->hasValidPaymentToken());
        $quotaNuova = Quota::allTenants()->where('socio_id', $senzaQuota->id)->firstOrFail();
        $this->assertSame('20.00', $quotaNuova->importo);
        $this->assertTrue($quotaNuova->hasValidPaymentToken());

        // Coda email: due righe col template della campagna e il link personale
        $email = EmailQueue::allTenants()->where('template_codice', 'campagna_rinnovi_2026')->get()->keyBy('socio_id');
        $this->assertCount(2, $email);
        $this->assertSame('anna.conquota@example.com', $email[$conQuota->id]->to_email);
        $this->assertSame(EmailQueue::STATUS_PENDING, $email[$conQuota->id]->status);
        $this->assertStringContainsString(e(route('pagamento.show', ['token' => $quotaEsistente->payment_token])), $email[$conQuota->id]->body_html);
        $this->assertStringContainsString(e(route('pagamento.show', ['token' => $quotaNuova->payment_token])), $email[$senzaQuota->id]->body_html);
        $this->assertFalse($email->has($rinnovato->id));

        // Il link dell'email porta davvero alla pagina di pagamento
        $this->get(route('pagamento.show', ['token' => $quotaNuova->payment_token]))->assertOk()->assertSee('20,00');

        // Ricaricando la pagina: contatori aggiornati, il rinnovato non c'è
        $this->comeAdmin()
            ->get(route('campagna-rinnovi.index'))
            ->assertOk()
            ->assertViewHas('contatori', fn (array $c) => $c['da_rinnovare'] === 2 && $c['inviate'] === 2 && $c['pagate'] === 0 && $c['rinnovate'] === 1)
            ->assertViewHas('soci', fn ($soci) => ! $soci->contains('id', $rinnovato->id)
                && $soci->every(fn (Socio $s) => $s->campagna_inviata === true))
            ->assertDontSee('Rinnovata');
    }

    // ------------------------------------------------------------------
    // 15. Rinnovo dall'account pubblico → webhook → tessera nell'area account
    // ------------------------------------------------------------------

    public function test_rinnovo_dall_account_pubblico_porta_al_pagamento_e_dopo_il_webhook_la_tessera_dell_anno_compare_nell_area_account(): void
    {
        $this->associazione->forceFill(['frontend_pubblico_attivo' => true])->save();
        $this->configuraStripe();
        $stripe = $this->fakeStripe();

        $socio = $this->creaSocio(['nome' => 'Ugo', 'cognome' => 'Accountato']);
        $this->creaTessera($socio, 2025, '20250011', ['data_scadenza' => '2025-12-31']);
        $account = Account::factory()->collegatoASocio($socio)->create(['associazione_id' => $this->associazione->id]);

        $risposta = $this->actingAs($account->fresh(), 'account')
            ->post(route('pubblico.account.rinnova', $this->associazione));

        /** @var Quota $quota */
        $quota = Quota::allTenants()->where('socio_id', $socio->id)->where('anno', 2026)->firstOrFail();
        $risposta->assertRedirect(route('pagamento.show', ['token' => $quota->payment_token]));
        $this->assertSame('20.00', $quota->importo);
        $this->assertSame(Quota::STATO_DA_PAGARE, $quota->stato);

        // Pagina pubblica → checkout Stripe → webhook
        $this->get(route('pagamento.show', ['token' => $quota->payment_token]))->assertOk();
        $this->post(route('pagamento.checkout', ['token' => $quota->payment_token]), ['gateway' => 'stripe'])
            ->assertRedirect('https://checkout.stripe.test/sessione-1');
        $this->assertSame($quota->id, $stripe->checkout[0]['id']);

        $this->webhookStripeQuota($quota, 'pi_account_15')->assertOk();

        $quota->refresh();
        $this->assertSame(Quota::STATO_PAGATA, $quota->stato);
        $this->assertSame('pi_account_15', $quota->gateway_transaction_id);

        /** @var Tessera $tessera */
        $tessera = Tessera::allTenants()->where('socio_id', $socio->id)->where('anno_validita', 2026)->firstOrFail();
        $this->assertSame('20260001', $tessera->numero_tessera);
        $this->assertSame('2026-12-31', $tessera->data_scadenza->toDateString());
        $this->assertSame(Tessera::STATO_ATTIVA, $tessera->stato);

        // L'account vede la tessera 2026 e la quota pagata
        $this->actingAs($account->fresh(), 'account')
            ->get(route('pubblico.account.tessera', $this->associazione))
            ->assertOk()
            ->assertSee('20260001')
            ->assertSee('Pagata');

        // Un secondo rinnovo non è più disponibile
        $this->actingAs($account->fresh(), 'account')
            ->post(route('pubblico.account.rinnova', $this->associazione))
            ->assertRedirect(route('pubblico.account.tessera', $this->associazione))
            ->assertSessionHas('warning');
        $this->assertSame(1, Quota::allTenants()->where('socio_id', $socio->id)->where('anno', 2026)->count());

        // Ricevuta scaricabile dall'area account
        $pdf = $this->actingAs($account->fresh(), 'account')
            ->get(route('pubblico.account.quote.ricevuta', ['associazione' => $this->associazione->slug, 'quota' => $quota->id]));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringContainsString('ricevuta-1-2026.pdf', (string) $pdf->headers->get('Content-Disposition'));
    }

    // ------------------------------------------------------------------
    // 16. Conferma pagamento della pre-iscrizione: online vs offline
    // ------------------------------------------------------------------

    public function test_conferma_pagamento_preiscrizione_con_metodo_online_crea_quota_con_token_e_offline_attiva_subito(): void
    {
        $this->configuraStripe();

        $online = $this->creaSocio(['cognome' => 'Linkato', 'stato' => Socio::STATO_IN_ATTESA_DI_PAGAMENTO, 'data_iscrizione' => '2026-02-20']);
        $offline = $this->creaSocio(['cognome' => 'Sportellista', 'stato' => Socio::STATO_IN_ATTESA_DI_PAGAMENTO, 'data_iscrizione' => '2026-02-20']);

        // Metodo online: quota da pagare + link
        $rispostaOnline = $this->comeAdmin()
            ->from(route('soci.show', $online))
            ->post(route('soci.conferma-pagamento', $online), [
                'importo' => '25.00',
                'metodo' => 'stripe',
                'tipo' => 'Quota Associativa',
            ])
            ->assertRedirect(route('soci.show', $online))
            ->assertSessionHas('success')
            ->assertSessionHas('payment_link');
        $linkPagamento = (string) $rispostaOnline->getSession()->get('payment_link');

        // Metodo offline: attivazione immediata
        $this->comeAdmin()
            ->from(route('soci.show', $offline))
            ->post(route('soci.conferma-pagamento', $offline), [
                'importo' => '25.00',
                'metodo' => 'contanti',
                'tipo' => 'Quota Associativa',
                'data_pagamento' => '2026-03-10',
            ])
            ->assertRedirect(route('soci.show', $offline))
            ->assertSessionHas('success');

        // --- socio offline ---
        $offline->refresh();
        $this->assertSame(Socio::STATO_ATTIVO, $offline->stato);
        $this->assertSame('2026-03-10', $offline->data_iscrizione->toDateString());
        $quotaOffline = Quota::query()->where('socio_id', $offline->id)->firstOrFail();
        $this->assertSame(Quota::STATO_PAGATA, $quotaOffline->stato);
        $this->assertSame('25.00', $quotaOffline->importo);
        $this->assertSame('contanti', $quotaOffline->metodo_pagamento);
        $this->assertSame('2026-03-10', $quotaOffline->data_pagamento->toDateString());
        $this->assertSame(1, Tessera::query()->where('socio_id', $offline->id)->where('anno_validita', 2026)->count());
        $this->assertDatabaseHas('storico_attivita_socio', ['socio_id' => $offline->id, 'tipo_attivita' => 'Pagamento confermato']);

        // --- socio online ---
        $online->refresh();
        $this->assertSame(Socio::STATO_IN_ATTESA_DI_PAGAMENTO, $online->stato);
        $this->assertSame(0, Tessera::query()->where('socio_id', $online->id)->count());
        $quotaOnline = Quota::query()->where('socio_id', $online->id)->firstOrFail();
        $this->assertSame(Quota::STATO_DA_PAGARE, $quotaOnline->stato);
        $this->assertNull($quotaOnline->data_pagamento);
        $this->assertSame('25.00', $quotaOnline->importo);
        $this->assertSame('2026-03-17 10:00:00', $quotaOnline->payment_token_expires->format('Y-m-d H:i:s'));
        $this->assertSame(route('pagamento.show', ['token' => $quotaOnline->payment_token]), $linkPagamento);

        // Difetti verificati insieme (un'unica asserzione per vederli tutti):
        // - il link deve essere apribile (la rotta pubblica accetta solo 64 hex);
        // - l'incasso offline confermato deve finire in prima nota come ogni
        //   altro pagamento di quota (registerOfflinePayment / webhook).
        $this->comeOspite();
        $statoLink = $this->get($linkPagamento)->getStatusCode();

        $this->assertSame(
            ['token_esadecimale' => true, 'link_pagamento_http' => 200, 'movimenti_prima_nota_offline' => 1],
            [
                'token_esadecimale' => (bool) preg_match('/^[a-f0-9]{64}$/', (string) $quotaOnline->payment_token),
                'link_pagamento_http' => $statoLink,
                'movimenti_prima_nota_offline' => Movimento::allTenants()->where('origine_id', $quotaOffline->id)->count(),
            ],
            'Token della conferma online: '.$quotaOnline->payment_token
        );
    }

    // ------------------------------------------------------------------
    // 17. Conferma pagamento con gateway non abilitato
    // ------------------------------------------------------------------

    public function test_conferma_pagamento_con_metodo_stripe_disabilitato_viene_rifiutata_dalla_validazione(): void
    {
        $socio = $this->creaSocio(['cognome' => 'Attesa', 'stato' => Socio::STATO_IN_ATTESA_DI_PAGAMENTO, 'data_iscrizione' => '2026-02-20']);

        $this->comeAdmin()
            ->from(route('soci.show', $socio))
            ->post(route('soci.conferma-pagamento', $socio), [
                'importo' => '25.00',
                'metodo' => 'stripe',
            ])
            ->assertRedirect(route('soci.show', $socio))
            ->assertSessionHasErrors('metodo');

        // Anche con le impostazioni salvate ma Stripe spento
        PaymentGatewaySetting::factory()->create(['associazione_id' => $this->associazione->id, 'stripe_enabled' => false]);

        $this->comeAdmin()
            ->from(route('soci.show', $socio))
            ->post(route('soci.conferma-pagamento', $socio), [
                'importo' => '25.00',
                'metodo' => 'stripe',
            ])
            ->assertRedirect(route('soci.show', $socio))
            ->assertSessionHasErrors('metodo');

        $this->assertSame(0, Quota::allTenants()->where('socio_id', $socio->id)->count());
        $socio->refresh();
        $this->assertSame(Socio::STATO_IN_ATTESA_DI_PAGAMENTO, $socio->stato);
        $this->assertSame('2026-02-20', $socio->data_iscrizione->toDateString());
        $this->assertSame(0, Tessera::allTenants()->where('socio_id', $socio->id)->count());
    }

    // ------------------------------------------------------------------
    // 18. Prima nota manuale: CRUD, coerenza categoria, saldo
    // ------------------------------------------------------------------

    public function test_prima_nota_manuale_crea_modifica_ed_elimina_con_categoria_coerente_e_saldo_progressivo(): void
    {
        $sede = Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Sede del porto', 'citta' => 'Livorno']);
        $progetto = Progetto::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Festa d\'estate']);

        // Primo accesso: categorie seedate per il tenant
        $this->comeAdmin()->get(route('prima-nota.index'))->assertOk();
        $this->assertSame(8, CategoriaMovimento::allTenants()->where('associazione_id', $this->associazione->id)->count());
        $this->assertSame(0, CategoriaMovimento::allTenants()->where('associazione_id', $this->altraAssociazione->id)->count());

        $categoria = fn (string $nome) => CategoriaMovimento::allTenants()
            ->where('associazione_id', $this->associazione->id)->where('nome', $nome)->value('id');

        // Entrata: donazione ricevuta in contanti
        $this->comeAdmin()
            ->post(route('prima-nota.store'), [
                'tipo' => Movimento::TIPO_ENTRATA,
                'importo' => '100.00',
                'data' => '2026-03-02',
                'categoria_id' => $categoria('Donazioni'),
                'descrizione' => 'Offerta lotteria',
                'metodo' => 'contanti',
            ])
            ->assertRedirect(route('prima-nota.index'))
            ->assertSessionHas('success', 'Movimento registrato.');

        // Uscita: spesa evento imputata a progetto e sede
        $this->comeAdmin()
            ->post(route('prima-nota.store'), [
                'tipo' => Movimento::TIPO_USCITA,
                'importo' => '40.00',
                'data' => '2026-03-05',
                'categoria_id' => $categoria('Spese eventi'),
                'descrizione' => 'Noleggio gazebo',
                'metodo' => 'bonifico',
                'progetto_id' => $progetto->id,
                'sede_id' => $sede->id,
            ])
            ->assertSessionHas('success');

        $entrata = Movimento::query()->where('tipo', Movimento::TIPO_ENTRATA)->firstOrFail();
        $uscita = Movimento::query()->where('tipo', Movimento::TIPO_USCITA)->firstOrFail();

        $this->assertSame($this->admin->id, $entrata->registrato_da);
        $this->assertSame('100.00', $entrata->importo);
        $this->assertNull($entrata->origine_id);
        $this->assertSame($this->associazione->id, $uscita->associazione_id);
        $this->assertSame($this->admin->id, $uscita->registrato_da);
        $this->assertSame($sede->id, $uscita->sede_id);
        $this->assertSame($progetto->id, $uscita->progetto_id);
        $this->assertSame('Noleggio gazebo', $uscita->descrizione);
        $this->assertSame('bonifico', $uscita->metodo);
        $this->assertSame('2026-03-05', $uscita->data->toDateString());

        $this->comeAdmin()->get(route('prima-nota.index'))
            ->assertViewHas('saldoTotale', 60.0)
            ->assertViewHas('saldi', fn (array $s) => $s[$uscita->id] === 60.0 && $s[$entrata->id] === 100.0);

        // Correzione dell'uscita: 30 euro
        $datiUscita = [
            'tipo' => Movimento::TIPO_USCITA,
            'importo' => '30.00',
            'data' => '2026-03-05',
            'categoria_id' => $categoria('Spese eventi'),
            'descrizione' => 'Noleggio gazebo (sconto)',
            'metodo' => 'bonifico',
            'progetto_id' => $progetto->id,
            'sede_id' => $sede->id,
        ];
        $this->comeAdmin()
            ->put(route('prima-nota.update', $uscita), $datiUscita)
            ->assertRedirect(route('prima-nota.index'))
            ->assertSessionHas('success', 'Movimento aggiornato.');

        $uscita->refresh();
        $this->assertSame('30.00', $uscita->importo);
        $this->assertSame('Noleggio gazebo (sconto)', $uscita->descrizione);
        $this->assertSame($progetto->id, $uscita->progetto_id);
        $this->assertSame($this->admin->id, $uscita->registrato_da);

        // Categoria di entrata su un'uscita: rifiutata
        $this->comeAdmin()
            ->from(route('prima-nota.index'))
            ->put(route('prima-nota.update', $uscita), [...$datiUscita, 'importo' => '35.00', 'categoria_id' => $categoria('Donazioni')])
            ->assertSessionHasErrors('categoria_id');
        $this->assertSame('30.00', $uscita->fresh()->importo);

        // Categoria di un altro tenant: rifiutata
        $this->comeAdmin()->get(route('prima-nota.index')); // (seed solo per il tenant corrente)
        $categoriaAltrui = CategoriaMovimento::allTenants()->create([
            'associazione_id' => $this->altraAssociazione->id, 'nome' => 'Spese eventi', 'tipo' => CategoriaMovimento::TIPO_USCITA,
        ]);
        $this->comeAdmin()
            ->from(route('prima-nota.index'))
            ->post(route('prima-nota.store'), [...$datiUscita, 'categoria_id' => $categoriaAltrui->id])
            ->assertSessionHasErrors('categoria_id');

        // Eliminazione dell'entrata
        $this->comeAdmin()
            ->delete(route('prima-nota.destroy', $entrata))
            ->assertRedirect(route('prima-nota.index'))
            ->assertSessionHas('success', 'Movimento eliminato.');
        $this->assertDatabaseMissing('movimenti', ['id' => $entrata->id]);

        $this->comeAdmin()->get(route('prima-nota.index'))
            ->assertViewHas('saldoTotale', -30.0)
            ->assertViewHas('totaleEntrate', 0.0)
            ->assertViewHas('totaleUscite', 30.0);

        $this->comeAdmin()->get(route('report-economico.index', ['dal' => '2026-01-01', 'al' => '2026-12-31']))
            ->assertOk()
            ->assertViewHas('totaleEntrate', 0.0)
            ->assertViewHas('totaleUscite', 30.0)
            ->assertViewHas('saldo', -30.0)
            ->assertViewHas('numeroMovimenti', 1)
            ->assertViewHas('mensile', fn (array $m) => $m['2026-03']['uscite'] === 30.0);
    }

    // ------------------------------------------------------------------
    // 19. Movimenti automatici (quota, donazione) in sola lettura
    // ------------------------------------------------------------------

    public function test_movimenti_automatici_da_quota_e_donazione_non_sono_modificabili_ne_eliminabili(): void
    {
        $this->configuraStripe();
        $this->fakeStripe();

        $socio = $this->creaSocio(['cognome' => 'Automatico']);
        $quota = $this->creaQuota($socio, 2026, 35.00);
        $this->comeAdmin()
            ->post(route('quote.pagamento', $quota), ['payment_method' => 'carta', 'payment_date' => '2026-03-08'])
            ->assertSessionHas('success');

        /** @var Movimento $movimento */
        $movimento = Movimento::query()->where('origine_id', $quota->id)->firstOrFail();
        $this->assertSame(Quota::class, $movimento->origine_type);
        $this->assertSame('35.00', $movimento->importo);
        $this->assertSame('carta', $movimento->metodo);

        $categoriaQuote = $movimento->categoria_id;

        $this->comeAdmin()
            ->put(route('prima-nota.update', $movimento), [
                'tipo' => Movimento::TIPO_ENTRATA,
                'importo' => '1.00',
                'data' => '2026-03-08',
                'categoria_id' => $categoriaQuote,
            ])
            ->assertRedirect(route('prima-nota.index'))
            ->assertSessionHas('error', 'I movimenti generati automaticamente dai pagamenti non sono modificabili.');
        $this->assertSame('35.00', $movimento->fresh()->importo);

        $this->comeAdmin()
            ->delete(route('prima-nota.destroy', $movimento))
            ->assertRedirect(route('prima-nota.index'))
            ->assertSessionHas('error', 'I movimenti generati automaticamente dai pagamenti non sono eliminabili.');
        $this->assertDatabaseHas('movimenti', ['id' => $movimento->id]);

        // Donazione completata via webhook finto
        $donazione = Donazione::factory()->create([
            'associazione_id' => $this->associazione->id,
            'donatore_nome' => 'Mecenate Gentile',
            'importo' => 75.00,
        ]);
        $metadata = [
            'tipo' => Pagabile::TIPO_DONAZIONE,
            'pagabile_id' => $donazione->id,
            'associazione_id' => $this->associazione->id,
        ];
        $this->comeOspite();
        $this->webhookStripe($metadata, 'pi_dono_19')->assertOk();

        /** @var Movimento $movDono */
        $movDono = Movimento::allTenants()->where('origine_id', $donazione->id)->firstOrFail();
        $this->assertSame(Donazione::class, $movDono->origine_type);
        $this->assertSame('75.00', $movDono->importo);
        $this->assertSame('stripe', $movDono->metodo);
        $this->assertSame('Donazioni', CategoriaMovimento::allTenants()->find($movDono->categoria_id)?->nome);
        $this->assertSame($this->associazione->id, $movDono->associazione_id);

        // Replay: nessun movimento duplicato
        $this->webhookStripe($metadata, 'pi_dono_19')->assertOk();
        $this->assertSame(1, Movimento::allTenants()->where('origine_id', $donazione->id)->count());
        $this->assertSame(2, Movimento::allTenants()->count());

        $this->comeAdmin()
            ->delete(route('prima-nota.destroy', $movDono))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('movimenti', ['id' => $movDono->id]);
    }

    // ------------------------------------------------------------------
    // 20. Donazione pubblica: checkout fallito e riuscito
    // ------------------------------------------------------------------

    public function test_donazione_pubblica_con_gateway_finto_che_fallisce_non_lascia_righe_orfane_e_con_successo_registra_pagamento_e_ricevuta_email(): void
    {
        $this->withoutMiddleware(ProtectAgainstSpam::class);
        $this->associazione->forceFill(['frontend_pubblico_attivo' => true])->save();
        $this->configuraStripe();
        $stripe = $this->fakeStripe([null, 'https://checkout.stripe.test/dono']);

        $dati = [
            'importo' => '30.00',
            'donatore_nome' => 'Lia Benefattrice',
            'donatore_email' => 'lia.benefattrice@example.com',
            'gateway' => 'stripe',
        ];

        // Primo tentativo: il gateway non crea la sessione
        $this->post(route('pubblico.dona.store', $this->associazione->slug), $dati)
            ->assertRedirect(route('pubblico.dona.show', $this->associazione->slug))
            ->assertSessionHas('checkout_error');
        $this->assertSame(0, Donazione::allTenants()->count());

        // Secondo tentativo: redirect al checkout
        $this->post(route('pubblico.dona.store', $this->associazione->slug), $dati)
            ->assertRedirect('https://checkout.stripe.test/dono');
        $this->assertCount(2, $stripe->checkout);
        $this->assertSame(30.0, $stripe->checkout[1]['importo']);

        /** @var Donazione $donazione */
        $donazione = Donazione::allTenants()->sole();
        $this->assertSame(Donazione::STATO_IN_ATTESA, $donazione->stato);
        $this->assertSame($this->associazione->id, $donazione->associazione_id);
        $this->assertSame('lia.benefattrice@example.com', $donazione->donatore_email);
        $this->assertSame('30.00', $donazione->importo);
        $this->assertSame('donazione', $stripe->checkout[1]['tipo']);
        $this->assertSame($donazione->id, $stripe->checkout[1]['id']);

        // Il webhook conferma
        $this->webhookStripe([
            'tipo' => Pagabile::TIPO_DONAZIONE,
            'pagabile_id' => $donazione->id,
            'associazione_id' => $this->associazione->id,
        ], 'pi_dono_20')->assertOk();

        $donazione->refresh();
        $this->assertSame(Donazione::STATO_PAGATA, $donazione->stato);
        $this->assertSame('stripe', $donazione->gateway);
        $this->assertSame('pi_dono_20', $donazione->transaction_id);
        $this->assertSame('2026-03-10 10:00:00', $donazione->pagata_at->format('Y-m-d H:i:s'));

        Notification::assertSentOnDemand(RicevutaDonazioneNotification::class,
            fn ($n, $canali, $notifiable) => $notifiable->routes['mail'] === 'lia.benefattrice@example.com');

        // Pagina di esito: solo con l'URL firmato consegnato al gateway
        // (audit run-1); il solo UUID della donazione non basta
        $urlEsito = $stripe->checkout[1]['success'];
        $this->assertStringContainsString('signature=', $urlEsito);
        $this->get($urlEsito)->assertOk()->assertSee('Grazie di cuore');
        $this->get(route('pubblico.dona.esito', ['associazione' => $this->associazione->slug, 'donazione' => $donazione->id, 'esito' => 'success']))
            ->assertForbidden();

        // Elenco admin con il totale delle donazioni pagate
        $this->comeAdmin()
            ->get(route('donazioni.index'))
            ->assertOk()
            ->assertViewHas('totalePagate', 30.0)
            ->assertViewHas('numeroPagate', 1);
    }

    // ------------------------------------------------------------------
    // 21. Ricevuta dall'area soci e dal link pubblico
    // ------------------------------------------------------------------

    public function test_ricevuta_quota_dall_area_soci_e_dal_link_pubblico_usa_lo_stesso_numero_e_non_e_disponibile_se_non_pagata(): void
    {
        $socio = $this->creaSocio(['cognome' => 'Ricevente', 'password_hash' => 'Lanterna-Accesa-2026']);
        $token = $this->tokenHex();
        $quota = $this->creaQuota($socio, 2026, 22.00, [
            'payment_token' => $token,
            'payment_token_expires' => now()->addDays(3),
        ]);
        // Un'altra ricevuta 2026 del tenant esiste già? No: la prima sarà la n. 1

        // Quota non pagata: nessuna ricevuta da nessuna via
        $this->actingAs($socio->fresh(), 'socio')
            ->get(route('area-soci.quote.ricevuta', $quota))
            ->assertNotFound();
        $this->comeOspite();
        $this->get(route('pagamento.ricevuta', ['token' => $token]))
            ->assertRedirect(route('pagamento.show', ['token' => $token]));
        $this->assertSame(0, Ricevuta::allTenants()->count());

        // L'admin registra il pagamento
        $this->comeAdmin()
            ->post(route('quote.pagamento', $quota), ['payment_method' => 'bonifico', 'payment_date' => '2026-03-09'])
            ->assertSessionHas('success');

        // Area soci: PDF della ricevuta numerata
        $pdfSoci = $this->actingAs($socio->fresh(), 'socio')->get(route('area-soci.quote.ricevuta', $quota));
        $pdfSoci->assertOk();
        $this->assertSame('application/pdf', $pdfSoci->headers->get('Content-Type'));
        $this->assertStringContainsString('ricevuta-1-2026.pdf', (string) $pdfSoci->headers->get('Content-Disposition'));

        // Un socio di un'altra associazione non accede alla quota
        $estraneo = $this->creaSocio(['cognome' => 'Curioso', 'password_hash' => 'Lanterna-Spenta-2026'], $this->altraAssociazione);
        $this->actingAs($estraneo, 'socio')
            ->get(route('area-soci.quote.ricevuta', $quota))
            ->assertNotFound();

        // Né un altro socio della stessa associazione
        $vicino = $this->creaSocio(['cognome' => 'Vicino', 'password_hash' => 'Lanterna-Vicina-2026']);
        $this->actingAs($vicino, 'socio')
            ->get(route('area-soci.quote.ricevuta', $quota))
            ->assertNotFound();

        $this->assertSame(1, Ricevuta::allTenants()->count());

        // Link pubblico di pagamento: deve dare la STESSA ricevuta numerata
        $this->comeOspite();
        $pdfPubblico = $this->get(route('pagamento.ricevuta', ['token' => $token]));
        $pdfPubblico->assertOk();
        $this->assertSame(1, Ricevuta::allTenants()->count());
        $this->assertStringContainsString('ricevuta-1-2026.pdf', (string) $pdfPubblico->headers->get('Content-Disposition'),
            'La ricevuta del link pubblico non è quella numerata (f.21).');
    }

    // ------------------------------------------------------------------
    // 22. Impostazioni pagamenti: salvataggio e secret preservate
    // ------------------------------------------------------------------

    public function test_impostazioni_pagamenti_salvano_tutti_i_campi_e_le_secret_vuote_non_cancellano_quelle_salvate(): void
    {
        $this->comeAdmin()
            ->put(route('quote.impostazioni.update'), [
                'stripe_enabled' => '1',
                'stripe_publishable_key' => 'pk_test_lanterna',
                'stripe_secret_key' => 'sk_test_segreta',
                'stripe_webhook_secret' => 'whsec_segreto',
                'paypal_enabled' => '1',
                'paypal_client_id' => 'pp_client_lanterna',
                'paypal_client_secret' => 'pp_secret_segreto',
                'paypal_mode' => 'sandbox',
                'paypal_webhook_id' => 'WH-ID-123',
                'auto_attivazione_pagamento' => '1',
                'valuta' => 'eur',
            ])
            ->assertRedirect(route('quote.impostazioni.edit'))
            ->assertSessionHas('success', 'Impostazioni pagamenti salvate.');

        $this->assertSame(1, PaymentGatewaySetting::allTenants()->where('associazione_id', $this->associazione->id)->count());
        $grezzo = DB::table('payment_gateway_settings')->where('associazione_id', $this->associazione->id)->first();
        $this->assertNotSame('sk_test_segreta', $grezzo->stripe_secret_key_encrypted);
        $this->assertNotSame('whsec_segreto', $grezzo->stripe_webhook_secret_encrypted);
        $this->assertNotSame('pp_secret_segreto', $grezzo->paypal_client_secret_encrypted);
        $this->assertStringNotContainsString('segret', (string) $grezzo->stripe_secret_key_encrypted);

        /** @var PaymentGatewaySetting $settings */
        $settings = PaymentGatewaySetting::forTenant($this->associazione->id);
        $this->assertTrue($settings->stripe_enabled);
        $this->assertSame('pk_test_lanterna', $settings->stripe_publishable_key);
        $this->assertSame('sk_test_segreta', $settings->stripe_secret_key);
        $this->assertSame('whsec_segreto', $settings->stripe_webhook_secret);
        $this->assertTrue($settings->paypal_enabled);
        $this->assertSame('pp_client_lanterna', $settings->paypal_client_id);
        $this->assertSame('pp_secret_segreto', $settings->paypal_client_secret);
        $this->assertSame('WH-ID-123', $settings->paypal_webhook_id);
        $this->assertSame('EUR', $settings->valuta);
        $this->assertTrue($settings->auto_attivazione_pagamento);

        // Secondo salvataggio con secret vuote e PayPal live
        $this->comeAdmin()
            ->put(route('quote.impostazioni.update'), [
                'stripe_enabled' => '1',
                'stripe_publishable_key' => 'pk_live_lanterna',
                'stripe_secret_key' => '',
                'stripe_webhook_secret' => '',
                'paypal_enabled' => '1',
                'paypal_client_id' => 'pp_client_lanterna',
                'paypal_client_secret' => '',
                'paypal_mode' => 'live',
                'paypal_webhook_id' => 'WH-ID-123',
                'valuta' => 'EUR',
                // auto_attivazione_pagamento non spuntata
            ])
            ->assertSessionHas('success');

        $this->assertSame(1, PaymentGatewaySetting::allTenants()->where('associazione_id', $this->associazione->id)->count());
        $settings = PaymentGatewaySetting::forTenant($this->associazione->id);
        $this->assertSame('pk_live_lanterna', $settings->stripe_publishable_key);
        $this->assertSame('sk_test_segreta', $settings->stripe_secret_key);
        $this->assertSame('whsec_segreto', $settings->stripe_webhook_secret);
        $this->assertSame('pp_secret_segreto', $settings->paypal_client_secret);
        $this->assertSame('live', $settings->paypal_mode);
        $this->assertFalse($settings->auto_attivazione_pagamento);
        $this->assertTrue($settings->isStripeOperational());
        $this->assertTrue($settings->isPayPalOperational());

        // La pagina di modifica non ri-mostra le secret in chiaro
        $this->comeAdmin()
            ->get(route('quote.impostazioni.edit'))
            ->assertOk()
            ->assertSee('pk_live_lanterna')
            ->assertDontSee('sk_test_segreta')
            ->assertDontSee('whsec_segreto')
            ->assertDontSee('pp_secret_segreto');

        // Valori non validi
        $this->comeAdmin()
            ->from(route('quote.impostazioni.edit'))
            ->put(route('quote.impostazioni.update'), ['paypal_mode' => 'produzione', 'valuta' => 'EURO'])
            ->assertSessionHasErrors(['paypal_mode', 'valuta']);
        $this->assertSame('live', PaymentGatewaySetting::forTenant($this->associazione->id)->paypal_mode);

        // L'altra associazione non ha impostazioni
        $this->assertNull(PaymentGatewaySetting::forTenant($this->altraAssociazione->id));
    }

    // ------------------------------------------------------------------
    // 23. Quota a mano: socio di altro tenant o cestinato rifiutato
    // ------------------------------------------------------------------

    public function test_quota_creata_a_mano_per_socio_di_altro_tenant_o_cestinato_viene_rifiutata(): void
    {
        $estraneo = $this->creaSocio(['cognome' => 'Estraneo'], $this->altraAssociazione);
        $cestinato = $this->creaSocio(['cognome' => 'Cestinato']);
        $cestinato->delete();
        $valido = $this->creaSocio(['cognome' => 'Valido']);

        $dati = fn (string $socioId) => [
            'socio_id' => $socioId,
            'anno' => 2026,
            'importo' => '27.50',
            'data_scadenza' => '2026-06-30',
            'tipo' => 'Quota Giovani',
        ];

        $this->comeAdmin()->from(route('quote.index'))->post(route('quote.store'), $dati($estraneo->id))
            ->assertRedirect(route('quote.index'))
            ->assertSessionHasErrors(['socio_id' => 'Il socio selezionato non esiste in questa associazione.']);

        $this->comeAdmin()->from(route('quote.index'))->post(route('quote.store'), $dati($cestinato->id))
            ->assertRedirect(route('quote.index'))
            ->assertSessionHasErrors('socio_id');

        $this->assertSame(0, Quota::allTenants()->count());

        $this->comeAdmin()->post(route('quote.store'), $dati($valido->id))
            ->assertRedirect(route('quote.index'))
            ->assertSessionHas('success', 'Quota creata.');

        /** @var Quota $quota */
        $quota = Quota::allTenants()->sole();
        $this->assertSame($valido->id, $quota->socio_id);
        $this->assertSame($this->associazione->id, $quota->associazione_id);
        $this->assertSame(2026, $quota->anno);
        $this->assertSame('27.50', $quota->importo);
        $this->assertSame('2026-06-30', $quota->data_scadenza->toDateString());
        $this->assertSame('Quota Giovani', $quota->tipo);
        $this->assertSame(Quota::STATO_DA_PAGARE, $quota->stato);
        $this->assertNull($quota->data_pagamento);
        $this->assertNull($quota->payment_token);

        // Validazioni numeriche
        $this->comeAdmin()->from(route('quote.index'))->post(route('quote.store'), [...$dati($valido->id), 'importo' => '0', 'anno' => 1999])
            ->assertSessionHasErrors(['importo', 'anno']);
        $this->assertSame(1, Quota::allTenants()->count());
    }

    // ------------------------------------------------------------------
    // 24. Cessazione: tessere annullate, fuori da apertura anno e campagna
    // ------------------------------------------------------------------

    public function test_cessazione_del_socio_annulla_le_tessere_lo_esclude_da_apertura_anno_e_campagna_e_resta_nel_libro_soci(): void
    {
        $uscente = $this->creaSocio(['nome' => 'Dino', 'cognome' => 'Dimissionario', 'numero_socio' => '201']);
        $tessera = $this->creaTessera($uscente, 2026, '20260050');
        $this->creaQuota($uscente, 2026, 20.00);
        $restante = $this->creaSocio(['nome' => 'Rosa', 'cognome' => 'Restante', 'numero_socio' => '202']);

        $this->comeAdmin()
            ->post(route('cessazioni.store'), [
                'socio_id' => $uscente->id,
                'tipo' => Cessazione::TIPO_DIMISSIONI,
                'data' => '2026-03-09',
                'motivo' => 'Trasferimento all\'estero',
                'delibera_numero' => '12/2026',
                'delibera_data' => '2026-03-08',
            ])
            ->assertRedirect(route('cessazioni.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('cessazioni', [
            'associazione_id' => $this->associazione->id,
            'socio_id' => $uscente->id,
            'tipo' => Cessazione::TIPO_DIMISSIONI,
            'delibera_numero' => '12/2026',
            'registrata_da' => $this->admin->id,
        ]);
        $cessazione = Cessazione::query()->where('socio_id', $uscente->id)->sole();
        $this->assertSame('2026-03-09', $cessazione->data->toDateString());
        $this->assertSame('2026-03-08', $cessazione->delibera_data->toDateString());

        $this->assertSame(Tessera::STATO_ANNULLATA, $tessera->fresh()->stato);
        $this->assertSame(Socio::STATO_RADIATO, $uscente->fresh()->stato);
        $this->assertDatabaseHas('storico_attivita_socio', ['socio_id' => $uscente->id, 'tipo_attivita' => 'Cessazione']);

        // Apertura 2027: solo il socio rimasto
        $this->comeAdmin()->post(route('apertura-anno.genera'), ['anno' => 2027])->assertSessionHas('success');
        $this->assertSame(0, Quota::query()->where('socio_id', $uscente->id)->where('anno', 2027)->count());
        $this->assertSame(1, Quota::query()->where('socio_id', $restante->id)->where('anno', 2027)->count());

        // Campagna rinnovi 2026: il cessato non compare
        $this->comeAdmin()
            ->get(route('campagna-rinnovi.index'))
            ->assertOk()
            ->assertViewHas('soci', fn ($soci) => ! $soci->contains('id', $uscente->id) && $soci->contains('id', $restante->id));

        // Né è più tra i soci per cui registrare una cessazione
        $this->comeAdmin()
            ->get(route('cessazioni.index'))
            ->assertOk()
            ->assertViewHas('sociCessabili', fn ($soci) => ! $soci->contains('id', $uscente->id));

        // Libro soci: resta, con la cessazione
        $this->comeAdmin()
            ->get(route('libro-soci.index'))
            ->assertOk()
            ->assertSee('Dimissionario')
            ->assertSee('09/03/2026')
            ->assertSee('Dimissioni');

        // Seconda cessazione: rifiutata
        $this->comeAdmin()
            ->post(route('cessazioni.store'), [
                'socio_id' => $uscente->id,
                'tipo' => Cessazione::TIPO_ESPULSIONE,
                'data' => '2026-03-10',
            ])
            ->assertRedirect(route('cessazioni.index'))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'già in stato'));
        $this->assertSame(1, Cessazione::query()->where('socio_id', $uscente->id)->count());
        $this->assertSame(Socio::STATO_RADIATO, $uscente->fresh()->stato);
    }

    // ------------------------------------------------------------------
    // 25. Promemoria rinnovo a step + export storico tesseramento
    // ------------------------------------------------------------------

    public function test_storico_tesseramento_export_e_promemoria_rinnovi_scadenza_inviano_uno_step_per_esecuzione(): void
    {
        $this->associazione->forceFill(['promemoria_rinnovo_config' => [30, 7]])->save();

        $socio = $this->creaSocio(['nome' => 'Pia', 'cognome' => 'Promemoria']);
        $this->creaTessera($socio, 2025, '20250021', ['data_scadenza' => '2025-12-31']);
        $tessera = $this->creaTessera($socio, 2026, '20260021', [
            'data_emissione' => '2025-03-20',
            'data_scadenza' => '2026-03-20', // tra 10 giorni
        ]);
        // Socio sospeso con tessera in scadenza: niente promemoria
        $sospeso = $this->creaSocio(['cognome' => 'Sospeso', 'stato' => Socio::STATO_SOSPESO]);
        $this->creaTessera($sospeso, 2026, '20260022', ['data_scadenza' => '2026-03-18']);

        $servizio = app(PromemoriaRinnoviService::class);

        // Prima esecuzione: step 30 (10 giorni residui)
        $this->assertSame(1, $servizio->processa($this->associazione->fresh()));
        Notification::assertSentToTimes($socio, PromemoriaRinnovoNotification::class, 1);
        Notification::assertSentTo($socio, PromemoriaRinnovoNotification::class,
            fn ($n) => $n->tessera->id === $tessera->id && $n->step === 30);
        Notification::assertNotSentTo($sospeso, PromemoriaRinnovoNotification::class);

        $step = fn () => PromemoriaInviato::allTenants()
            ->where('tessera_id', $tessera->id)
            ->where('tipo', PromemoriaInviato::TIPO_RINNOVO_TESSERA)
            ->pluck('step')->map(fn ($s) => (int) $s)->sort()->values()->all();
        $this->assertSame([30], $step());

        // Seconda esecuzione lo stesso giorno: nulla
        $this->assertSame(0, $servizio->processa($this->associazione->fresh()));
        Notification::assertSentToTimes($socio, PromemoriaRinnovoNotification::class, 1);

        // Cinque giorni dopo: step 7
        Carbon::setTestNow('2026-03-15 10:00:00');
        $this->assertSame(1, $servizio->processa($this->associazione->fresh()));
        Notification::assertSentToTimes($socio, PromemoriaRinnovoNotification::class, 2);
        Notification::assertSentTo($socio, PromemoriaRinnovoNotification::class, fn ($n) => $n->step === 7);
        $this->assertSame([7, 30], $step());

        // Export Excel dello storico tesseramento
        $export = $this->comeAdmin()->get(route('soci.storico-tesseramento.export', $socio));
        $export->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $export->headers->get('Content-Disposition'));
        $this->assertStringContainsString('storico-tesseramento_promemoria-pia', (string) $export->headers->get('Content-Disposition'));

        // Export di un socio di un'altra associazione: 404
        $estraneo = $this->creaSocio([], $this->altraAssociazione);
        $this->comeAdmin()->get(route('soci.storico-tesseramento.export', $estraneo))->assertNotFound();
    }
}
