<?php

namespace Tests\Feature\Flussi;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\CategoriaMovimento;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\FeedbackEvento;
use App\Models\Movimento;
use App\Models\PaymentGatewaySetting;
use App\Models\Prenotazione;
use App\Models\PrenotazioneSpazio;
use App\Models\Progetto;
use App\Models\Sede;
use App\Models\Socio;
use App\Models\Spazio;
use App\Models\SpesaEvento;
use App\Models\Utente;
use App\Notifications\EventiVita\FeedbackEventoNotification;
use App\Notifications\Prenotazioni\AnnullataNotification;
use App\Notifications\Prenotazioni\BigliettoPrenotazioneNotification;
use App\Notifications\Prenotazioni\ConfermataNotification;
use App\Notifications\Prenotazioni\DaApprovareNotification;
use App\Notifications\Prenotazioni\InApprovazioneNotification;
use App\Notifications\Prenotazioni\InAttesaPagamentoNotification;
use App\Notifications\Prenotazioni\PagamentoScadutoNotification;
use App\Notifications\Prenotazioni\PromemoriaEventoNotification;
use App\Notifications\Prenotazioni\PromozioneListaAttesaNotification;
use App\Notifications\Prenotazioni\RifiutataNotification;
use App\Services\EventiVita\FeedbackEventiService;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use App\Services\Pagamenti\Pagabile;
use App\Services\Prenotazioni\BigliettoService;
use App\Services\Prenotazioni\PrenotazioneService;
use Carbon\Carbon;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * Flussi reali — EVENTI E PRENOTAZIONI.
 *
 * Capienza e posti massimi, lista d'attesa FIFO stretta, approvazione e
 * rifiuto, finestra di pagamento e scadenza, pagamento offline e online
 * (gateway Stripe finto + webhook e replay), biglietti QR firmati e
 * scanner, presenze e kiosk, partecipanti soci, serie ricorrenti,
 * feedback post-evento, economia dell'evento, conflitti di spazio,
 * calendari .ics, galleria foto, perimetro sedi e isolamento tra account e
 * associazioni. Ogni test segue un flusso con più attori (admin del
 * pannello, account del mini-sito, ospite anonimo, scanner, gateway,
 * scheduler) e verifica che i dati finiscano nel database con i valori
 * giusti. Complementare ai percorsi di Percorsi/04 (non li ripete).
 *
 * Il tempo è governato da Carbon::setTestNow (lunedì 4 maggio 2026, ore 10).
 */
class Flussi03_EventiPrenotazioniFlussiTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Associazione $altraAssociazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-04 10:00:00');

        Notification::fake();

        $this->seed(RolesSeeder::class);

        $this->associazione = Associazione::factory()->create([
            'nome' => 'Circolo Arci La Fenice',
            'slug' => 'circolo-fenice',
            'frontend_pubblico_attivo' => true,
        ]);

        $this->altraAssociazione = Associazione::factory()->create([
            'nome' => 'Pro Loco Vicina',
            'slug' => 'pro-loco-vicina',
            'frontend_pubblico_attivo' => true,
        ]);

        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Elisa',
            'cognome' => 'Segretaria',
        ]);
        $this->admin->assignRole('admin_associazione');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Attori
    // ------------------------------------------------------------------

    /**
     * Nessun utente su nessuna guard, come un nuovo browser.
     */
    private function esci(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->flushSession();
        app()->forgetInstance('tenant.pubblico');
    }

    private function comeAdmin(?Utente $utente = null): static
    {
        $this->esci();

        return $this->actingAs($utente ?? $this->admin, 'web');
    }

    private function comeAccount(Account $account): static
    {
        $this->esci();

        return $this->actingAs($account, 'account');
    }

    private function comeOspite(): static
    {
        $this->esci();

        return $this;
    }

    private function visitatore(string $nome, string $cognome, ?Associazione $associazione = null): Account
    {
        return Account::factory()->verificato()->create([
            'associazione_id' => ($associazione ?? $this->associazione)->id,
            'nome' => $nome,
            'cognome' => $cognome,
            'email' => strtolower($nome.'.'.$cognome).'@esempio.test',
        ]);
    }

    // ------------------------------------------------------------------
    // Azioni ricorrenti
    // ------------------------------------------------------------------

    /**
     * Form "nuovo evento" del pannello: serata gratuita a 10 posti il 23/5.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function datiEvento(array $override = []): array
    {
        return array_merge([
            'titolo' => 'Serata di cinema all\'aperto',
            'descrizione' => 'Proiezione nel cortile del circolo.',
            'data_evento' => '2026-05-23T21:00',
            'data_fine' => '2026-05-23T23:30',
            'luogo' => 'Cortile del circolo',
            'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
            'capienza' => 10,
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

        return Evento::allTenants()->where('titolo', $dati['titolo'])->latest('created_at')->firstOrFail();
    }

    /**
     * L'account prenota dal sito pubblico senza errori e torna la riga salvata.
     */
    private function prenota(Account $account, Evento $evento, int $posti = 1): Prenotazione
    {
        $this->comeAccount($account)
            ->post($this->urlPubblica('eventi.prenota', ['evento' => $evento->id]), ['posti' => $posti])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return Prenotazione::allTenants()
            ->where('account_id', $account->id)
            ->where('evento_id', $evento->id)
            ->latest('created_at')
            ->firstOrFail();
    }

    /**
     * Tentativo di prenotazione che deve fallire con un errore sul campo posti.
     */
    private function prenotaConErrore(Account $account, Evento $evento, int $posti, string $frammento): void
    {
        $risposta = $this->comeAccount($account)
            ->from($this->urlPubblica('eventi.show', ['evento' => $evento->id]))
            ->post($this->urlPubblica('eventi.prenota', ['evento' => $evento->id]), ['posti' => $posti])
            ->assertSessionHasErrors('posti');

        $this->assertStringContainsString($frammento, (string) session('errors')->first('posti'));
        $risposta->assertRedirect($this->urlPubblica('eventi.show', ['evento' => $evento->id]));
    }

    /**
     * @param  array<string, mixed>  $parametri
     */
    private function urlPubblica(string $nome, array $parametri = [], ?Associazione $associazione = null): string
    {
        return route('pubblico.'.$nome, ['associazione' => ($associazione ?? $this->associazione)->slug] + $parametri);
    }

    private function urlAdminPrenotazione(string $azione, Prenotazione $prenotazione): string
    {
        return route('eventi.prenotazioni.'.$azione, [
            'evento' => $prenotazione->evento_id,
            'prenotazione' => $prenotazione->id,
        ]);
    }

    private function configuraStripe(): PaymentGatewaySetting
    {
        return PaymentGatewaySetting::factory()->conStripe()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    /**
     * Gateway Stripe finto: registra le sessioni di checkout e accetta solo
     * i webhook con la firma attesa.
     */
    private function fakeStripe(): object
    {
        $fake = new class implements StripeGatewayInterface
        {
            /** @var list<array{tipo: string, id: string, importo: float}> */
            public array $checkout = [];

            public function createCheckoutSession(PaymentGatewaySetting $config, Pagabile $pagabile, string $successUrl, string $cancelUrl): ?string
            {
                $this->checkout[] = [
                    'tipo' => $pagabile->getPagabileTipo(),
                    'id' => $pagabile->getPagabileId(),
                    'importo' => $pagabile->getImporto(),
                ];

                return 'https://checkout.stripe.test/sessione-'.count($this->checkout);
            }

            public function constructWebhookEvent(string $payload, string $signatureHeader, string $webhookSecret): ?array
            {
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
     * Stripe richiama il webhook con la sessione di checkout completata
     * di una prenotazione.
     */
    private function webhookPrenotazione(Prenotazione $prenotazione, string $transazione): TestResponse
    {
        $this->comeOspite();

        return $this->postJson(route('webhooks.stripe'), [
            'id' => 'evt_'.$transazione,
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_'.$transazione,
                    'payment_intent' => $transazione,
                    'metadata' => [
                        'tipo' => Pagabile::TIPO_PRENOTAZIONE,
                        'pagabile_id' => $prenotazione->id,
                        'associazione_id' => $prenotazione->associazione_id,
                    ],
                ],
            ],
        ], ['Stripe-Signature' => 't=1,v1=firma-valida']);
    }

    private function servizio(): PrenotazioneService
    {
        return app(PrenotazioneService::class);
    }

    private function movimentoDi(Prenotazione $prenotazione): ?Movimento
    {
        return Movimento::allTenants()
            ->where('origine_type', $prenotazione->getMorphClass())
            ->where('origine_id', $prenotazione->id)
            ->first();
    }

    /**
     * Righe dati di un CSV esportato (senza BOM, header e righe vuote).
     *
     * @return list<string>
     */
    private function righeCsv(string $csv): array
    {
        $righe = array_values(array_filter(
            explode("\n", str_replace("\xEF\xBB\xBF", '', $csv)),
            fn (string $r) => trim($r) !== ''
        ));

        return array_slice($righe, 1);
    }

    // ------------------------------------------------------------------
    // 1. Creazione evento completa e modifica parziale
    // ------------------------------------------------------------------

    public function test_creazione_evento_con_tutti_i_campi_e_prezzo_con_virgola_salva_ogni_colonna_e_la_modifica_le_preserva(): void
    {
        $sede = Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Sede di via Roma']);
        $spazio = Spazio::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Sala Teatro']);
        $progetto = Progetto::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Progetto Musica']);

        $this->comeAdmin()
            ->post(route('eventi.store'), [
                'titolo' => 'Rassegna jazz di primavera',
                'descrizione' => 'Tre gruppi locali sul palco della sala teatro.',
                'data_evento' => '2026-06-10T21:00',
                'data_fine' => '2026-06-10T23:30',
                'luogo' => 'Sala Teatro, via Roma 12',
                'sede_id' => $sede->id,
                'spazio_id' => $spazio->id,
                'progetto_id' => $progetto->id,
                'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
                'capienza' => 50,
                'prezzo' => '12,50',
                'iscrizioni_dal' => '2026-05-01T09:00',
                'iscrizioni_al' => '2026-06-09T20:00',
                'max_posti_per_prenotazione' => 3,
                'lista_attesa_attiva' => '0',
                'iscrizioni_aperte' => '1',
                'richiede_approvazione' => '1',
                'annullamento_entro_ore' => 48,
                'solo_soci' => '1',
                'immagine_url' => 'https://img.esempio.test/jazz.jpg',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('eventi.index'))
            ->assertSessionHas('success', 'Evento creato con successo.')
            ->assertSessionMissing('warning');

        $evento = Evento::allTenants()->where('titolo', 'Rassegna jazz di primavera')->firstOrFail();

        $this->assertDatabaseHas('eventi', [
            'id' => $evento->id,
            'associazione_id' => $this->associazione->id,
            'sede_id' => $sede->id,
            'spazio_id' => $spazio->id,
            'progetto_id' => $progetto->id,
            'luogo' => 'Sala Teatro, via Roma 12',
            'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
            'capienza' => 50,
            'max_posti_per_prenotazione' => 3,
            'annullamento_entro_ore' => 48,
            'immagine_url' => 'https://img.esempio.test/jazz.jpg',
            'serie_id' => null,
        ]);
        $this->assertSame('12.50', (string) $evento->prezzo);
        $this->assertSame('Tre gruppi locali sul palco della sala teatro.', $evento->descrizione);
        $this->assertSame('2026-06-10 21:00', $evento->data_evento->format('Y-m-d H:i'));
        $this->assertSame('2026-06-10 23:30', $evento->data_fine->format('Y-m-d H:i'));
        $this->assertSame('2026-05-01 09:00', $evento->iscrizioni_dal->format('Y-m-d H:i'));
        $this->assertSame('2026-06-09 20:00', $evento->iscrizioni_al->format('Y-m-d H:i'));
        $this->assertFalse($evento->lista_attesa_attiva);
        $this->assertTrue($evento->iscrizioni_aperte);
        $this->assertTrue($evento->richiede_approvazione);
        $this->assertTrue($evento->solo_soci);

        // Il form di modifica si apre precompilato
        $this->get(route('eventi.index', ['edit' => $evento->id]))
            ->assertOk()
            ->assertViewHas('editEvento', fn (?Evento $e) => $e !== null && $e->is($evento))
            ->assertSee('Rassegna jazz di primavera');

        // Modifica del solo titolo da un client che non invia i checkbox né
        // le select: nessun reset involontario
        $this->put(route('eventi.update', $evento), [
            'titolo' => 'Rassegna jazz di primavera — 2a edizione',
            'data_evento' => '2026-06-10T21:00',
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('eventi.index'))
            ->assertSessionHas('success', 'Evento aggiornato con successo.');

        $evento->refresh();
        $this->assertSame('Rassegna jazz di primavera — 2a edizione', $evento->titolo);
        $this->assertSame('12.50', (string) $evento->prezzo);
        $this->assertSame(Evento::STATO_PUBBLICATO, $evento->stato_pubblicazione);
        $this->assertSame(50, $evento->capienza);
        $this->assertSame(3, $evento->max_posti_per_prenotazione);
        $this->assertSame(48, $evento->annullamento_entro_ore);
        $this->assertFalse($evento->lista_attesa_attiva);
        $this->assertTrue($evento->iscrizioni_aperte);
        $this->assertTrue($evento->richiede_approvazione);
        $this->assertTrue($evento->solo_soci);
        $this->assertSame($sede->id, $evento->sede_id);
        $this->assertSame($spazio->id, $evento->spazio_id);
        $this->assertSame($progetto->id, $evento->progetto_id);
        $this->assertSame('2026-06-10 23:30', $evento->data_fine->format('Y-m-d H:i'));
        $this->assertSame('2026-05-01 09:00', $evento->iscrizioni_dal->format('Y-m-d H:i'));
        $this->assertSame('https://img.esempio.test/jazz.jpg', $evento->immagine_url);
        $this->assertSame('Tre gruppi locali sul palco della sala teatro.', $evento->descrizione);
    }

    // ------------------------------------------------------------------
    // 2. Capienza non riducibile e posti massimi
    // ------------------------------------------------------------------

    public function test_capienza_non_puo_scendere_sotto_i_posti_occupati_e_posti_massimi_per_prenotazione_vengono_applicati(): void
    {
        $evento = $this->adminCreaEvento([
            'titolo' => 'Cena del tesseramento',
            'capienza' => 10,
            'max_posti_per_prenotazione' => 2,
        ]);

        $anna = $this->visitatore('Anna', 'Galli');
        $bruno = $this->visitatore('Bruno', 'Sala');
        $carla = $this->visitatore('Carla', 'Neri');

        $pAnna = $this->prenota($anna, $evento, 2);
        $pBruno = $this->prenota($bruno, $evento, 2);

        foreach ([$pAnna, $pBruno] as $p) {
            $this->assertSame(Prenotazione::STATO_CONFERMATA, $p->stato);
            $this->assertSame(2, $p->posti);
            $this->assertMatchesRegularExpression('/^PRN-[2-9A-HJKMNP-TV-Z]{6}$/', $p->codice);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $p->qr_token);
            $this->assertSame($this->associazione->id, $p->associazione_id);
        }
        $this->assertNotSame($pAnna->codice, $pBruno->codice);

        // Carla chiede 3 posti: oltre il massimo per prenotazione
        $this->prenotaConErrore($carla, $evento, 3, 'Puoi prenotare da 1 a 2 posti per questo evento.');
        $this->assertSame(0, Prenotazione::allTenants()->where('account_id', $carla->id)->count());

        // L'admin prova a ridurre la capienza sotto i 4 posti occupati
        $this->comeAdmin()
            ->put(route('eventi.update', $evento), $this->datiEvento([
                'titolo' => 'Cena del tesseramento',
                'capienza' => 3,
                'max_posti_per_prenotazione' => 2,
            ]))
            ->assertSessionHasErrors('capienza');
        $this->assertStringContainsString('4 posti occupati', (string) session('errors')->first('capienza'));
        $this->assertSame(10, $evento->fresh()->capienza);

        // A 4 (esattamente gli occupati) è ammessa
        $this->put(route('eventi.update', $evento), $this->datiEvento([
            'titolo' => 'Cena del tesseramento',
            'capienza' => 4,
            'max_posti_per_prenotazione' => 2,
        ]))->assertSessionHasNoErrors()->assertRedirect(route('eventi.index'));
        $this->assertSame(4, $evento->fresh()->capienza);
        $this->assertSame(0, $evento->fresh()->postiDisponibili());

        // Carla ora finisce in lista d'attesa con 1 posto
        $pCarla = $this->prenota($carla, $evento, 1);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $pCarla->stato);
        $this->assertSame(4, Prenotazione::postiOccupati($evento->id));
    }

    // ------------------------------------------------------------------
    // 3. Lista d'attesa FIFO stretta
    // ------------------------------------------------------------------

    public function test_lista_d_attesa_fifo_stretta_con_posti_diversi_blocca_la_coda_sul_primo_che_non_entra(): void
    {
        $a = $this->visitatore('Aldo', 'Primi');
        $z = $this->visitatore('Zeno', 'Ultimi');
        $b = $this->visitatore('Bice', 'Secondi');
        $c = $this->visitatore('Ciro', 'Terzi');
        $d = $this->visitatore('Dora', 'Nuovi');

        // --- Parte 1: si liberano 3 posti, entrano entrambe le prenotazioni in coda
        $evento = $this->adminCreaEvento(['titolo' => 'Laboratorio di pasta fresca', 'capienza' => 4]);

        $pA = $this->prenota($a, $evento, 3);
        $this->travel(1)->minutes();
        $pZ = $this->prenota($z, $evento, 1);
        $this->travel(1)->minutes();
        $pB = $this->prenota($b, $evento, 2);
        $this->travel(1)->minutes();
        $pC = $this->prenota($c, $evento, 1);

        $this->assertSame(Prenotazione::STATO_CONFERMATA, $pA->stato);
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $pZ->stato);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $pB->stato);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $pC->stato);

        $this->comeAccount($a)
            ->post($this->urlPubblica('account.prenotazioni.annulla', ['prenotazione' => $pA->id]))
            ->assertSessionHas('success', 'Prenotazione annullata.');

        $pB->refresh();
        $pC->refresh();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $pB->stato);
        $this->assertNotNull($pB->promossa_at);
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $pC->stato);
        $this->assertNotNull($pC->promossa_at);
        $this->assertSame(4, Prenotazione::postiOccupati($evento->id));
        Notification::assertSentTo($b, PromozioneListaAttesaNotification::class);
        Notification::assertSentTo($c, PromozioneListaAttesaNotification::class);

        // La coda è vuota: la promozione manuale non trova nulla
        $this->comeAdmin()
            ->post(route('eventi.prenotazioni.promuovi', $evento))
            ->assertSessionHas('warning');

        // --- Parte 2: un solo posto libero e in testa alla coda c'è chi ne chiede 3
        $evento2 = $this->adminCreaEvento(['titolo' => 'Laboratorio di ceramica', 'capienza' => 4]);

        $this->travel(1)->minutes();
        $this->prenota($a, $evento2, 3);
        $this->travel(1)->minutes();
        $pZ2 = $this->prenota($z, $evento2, 1);
        $this->travel(1)->minutes();
        $pB2 = $this->prenota($b, $evento2, 3);
        $this->travel(1)->minutes();
        $pC2 = $this->prenota($c, $evento2, 1);

        $this->comeAdmin()
            ->post($this->urlAdminPrenotazione('annulla', $pZ2), ['motivo' => 'Richiesta dell\'interessato'])
            ->assertSessionHas('success');

        // FIFO stretto: B (3) non entra in 1 posto e blocca anche C (1)
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $pB2->fresh()->stato);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $pC2->fresh()->stato);
        $this->assertNull($pC2->fresh()->promossa_at);

        $this->post(route('eventi.prenotazioni.promuovi', $evento2))
            ->assertRedirect(route('eventi.prenotazioni.index', $evento2))
            ->assertSessionHas('warning', 'Nessuna prenotazione promuovibile: lista d\'attesa vuota o posti insufficienti per la prima in coda.');
        $this->assertSame(3, Prenotazione::postiOccupati($evento2->id));

        // Arriva Dora, che chiede 1 posto. Scelta di design del servizio
        // (vedi PrenotazioneServiceTest::test_capienza_esatta_no_oversell_con_posti_multipli):
        // il FIFO stretto governa solo la PROMOZIONE dalla coda; una nuova
        // prenotazione che entra nei posti liberi viene confermata subito,
        // così il posto non resta vuoto. La coda (B, C) non cambia e non si
        // supera mai la capienza.
        $this->travel(1)->minutes();
        $pD = $this->prenota($d, $evento2, 1);
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $pD->stato);
        $this->assertNull($pD->promossa_at);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $pB2->fresh()->stato);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $pC2->fresh()->stato);
        $this->assertSame(4, Prenotazione::postiOccupati($evento2->id));
        $this->assertSame(0, $evento2->fresh()->postiDisponibili());

        // Con l'evento di nuovo pieno la promozione manuale non trova nulla
        $this->comeAdmin()
            ->post(route('eventi.prenotazioni.promuovi', $evento2))
            ->assertSessionHas('warning');
    }

    // ------------------------------------------------------------------
    // 4. Approvazione, finestra di pagamento, scadenza e incasso offline
    // ------------------------------------------------------------------

    public function test_evento_a_pagamento_con_approvazione_scadenza_pagamento_dopo_30_minuti_e_riconferma_offline_post_scadenza(): void
    {
        $evento = $this->adminCreaEvento([
            'titolo' => 'Degustazione vini del territorio',
            'prezzo' => '10',
            'richiede_approvazione' => '1',
        ]);
        $marta = $this->visitatore('Marta', 'Vigna');

        $p = $this->prenota($marta, $evento, 1);
        $this->assertSame(Prenotazione::STATO_IN_APPROVAZIONE, $p->stato);
        $this->assertNull($p->scadenza_pagamento_at);
        $this->assertSame('10.00', (string) $p->importo_totale);
        Notification::assertSentTo($marta, InApprovazioneNotification::class);
        Notification::assertSentTo($this->admin, DaApprovareNotification::class);

        // Ancora nessun pagamento possibile
        $this->get($this->urlPubblica('prenotazioni.paga', ['prenotazione' => $p->id]))
            ->assertRedirect($this->urlPubblica('prenotazioni.esito', ['prenotazione' => $p->id]));

        // Approvazione dopo 5 minuti: parte ORA la finestra di 30 minuti
        $this->travel(5)->minutes();
        $this->comeAdmin()
            ->post($this->urlAdminPrenotazione('approva', $p))
            ->assertSessionHas('success', "Prenotazione {$p->codice} approvata: l'utente ha 30 minuti per completare il pagamento.");

        $p->refresh();
        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $p->stato);
        $this->assertSame('2026-05-04 10:35', $p->scadenza_pagamento_at->format('Y-m-d H:i'));
        $this->assertSame('2026-05-04 10:05', $p->approvata_at->format('Y-m-d H:i'));
        $this->assertSame($this->admin->id, $p->approvata_da);
        Notification::assertSentTo($marta, InAttesaPagamentoNotification::class);

        // Marta non paga in tempo: lo scheduler marca la scadenza
        $this->travel(31)->minutes();
        $this->assertSame(1, $this->servizio()->scadiPagamenti());

        $p->refresh();
        $this->assertSame(Prenotazione::STATO_SCADUTA, $p->stato);
        $this->assertNull($p->pagata_at);
        $this->assertSame(0, Prenotazione::postiOccupati($evento->id));
        Notification::assertSentTo($marta, PagamentoScadutoNotification::class);
        $this->assertSame(0, $this->servizio()->scadiPagamenti(), 'Una seconda esecuzione non trova altro');

        // Marta passa in sede e paga in contanti: l'admin registra l'incasso
        $this->comeAdmin()
            ->post($this->urlAdminPrenotazione('conferma-pagamento', $p))
            ->assertSessionHas('success', "Pagamento registrato: prenotazione {$p->codice} confermata.");

        $p->refresh();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $p->stato);
        $this->assertSame('offline', $p->gateway);
        $this->assertStringStartsWith('manuale-'.$this->admin->id.'-', (string) $p->gateway_transaction_id);
        $this->assertNotNull($p->pagata_at);
        Notification::assertSentTo($marta, ConfermataNotification::class);

        $movimento = $this->movimentoDi($p);
        $this->assertNotNull($movimento);
        $this->assertSame(Movimento::TIPO_ENTRATA, $movimento->tipo);
        $this->assertEqualsWithDelta(10.0, (float) $movimento->importo, 0.001);
        $this->assertSame('offline', $movimento->metodo);
        $this->assertSame(
            'Eventi',
            CategoriaMovimento::allTenants()->find($movimento->categoria_id)?->nome
        );
        $this->assertSame(1, Prenotazione::postiOccupati($evento->id));
    }

    // ------------------------------------------------------------------
    // 5. Rifiuto con motivo e promozione in approvazione
    // ------------------------------------------------------------------

    public function test_rifiuto_prenotazione_in_approvazione_salva_motivo_libera_i_posti_e_promuove_la_coda(): void
    {
        $evento = $this->adminCreaEvento([
            'titolo' => 'Visita guidata al museo',
            'capienza' => 1,
            'richiede_approvazione' => '1',
        ]);
        $alberto = $this->visitatore('Alberto', 'Rossi');
        $bianca = $this->visitatore('Bianca', 'Verdi');

        $pA = $this->prenota($alberto, $evento, 1);
        $this->travel(1)->minutes();
        $pB = $this->prenota($bianca, $evento, 1);

        $this->assertSame(Prenotazione::STATO_IN_APPROVAZIONE, $pA->stato);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $pB->stato);

        $this->comeAdmin()
            ->post($this->urlAdminPrenotazione('rifiuta', $pA), ['motivo' => 'Visita riservata agli under 30'])
            ->assertRedirect(route('eventi.prenotazioni.index', $evento))
            ->assertSessionHas('success');

        $pA->refresh();
        $this->assertSame(Prenotazione::STATO_ANNULLATA, $pA->stato);
        $this->assertSame('Visita riservata agli under 30', $pA->motivo_rifiuto);
        $this->assertSame('Richiesta non approvata: Visita riservata agli under 30', $pA->motivo_annullamento);
        $this->assertNotNull($pA->annullata_at);
        Notification::assertSentTo($alberto, RifiutataNotification::class);

        // Bianca esce dalla coda ma deve passare dalla revisione
        $pB->refresh();
        $this->assertSame(Prenotazione::STATO_IN_APPROVAZIONE, $pB->stato);
        $this->assertNotNull($pB->promossa_at);
        $this->assertNull($pB->scadenza_pagamento_at);
        Notification::assertSentTo($bianca, InApprovazioneNotification::class);
        Notification::assertNotSentTo($bianca, ConfermataNotification::class);

        // Gli admin sono avvisati due volte: richiesta di Alberto e di Bianca
        Notification::assertSentToTimes($this->admin, DaApprovareNotification::class, 2);

        // Un secondo rifiuto della stessa richiesta è rifiutato
        $this->post($this->urlAdminPrenotazione('rifiuta', $pA), ['motivo' => 'Di nuovo'])
            ->assertSessionHas('error', 'La prenotazione non è in attesa di approvazione.');
        $this->assertSame('Visita riservata agli under 30', $pA->fresh()->motivo_rifiuto);

        $this->assertSame(1, Prenotazione::postiOccupati($evento->id));
        $tab = $this->get(route('eventi.prenotazioni.index', $evento))->assertOk();
        $this->assertSame(1, $tab->viewData('conteggi')['numero_da_approvare']);
        $this->assertSame(0, $tab->viewData('conteggi')['numero_in_coda']);
    }

    // ------------------------------------------------------------------
    // 6. Evento riservato ai soci
    // ------------------------------------------------------------------

    public function test_evento_riservato_ai_soci_accetta_solo_account_collegati_a_socio_attivo(): void
    {
        $evento = $this->adminCreaEvento(['titolo' => 'Assemblea conviviale dei soci', 'solo_soci' => '1']);

        $esterno = $this->visitatore('Ettore', 'Esterno');

        $socioSospeso = Socio::factory()->sospeso()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Silvia',
            'cognome' => 'Sospesa',
        ]);
        $collegato = $this->visitatore('Silvia', 'Sospesa');
        $collegato->forceFill(['socio_id' => $socioSospeso->id])->save();

        $this->prenotaConErrore($esterno, $evento, 1, 'riservato ai soci');
        $this->prenotaConErrore($collegato, $evento, 1, 'riservato ai soci');
        $this->assertSame(0, Prenotazione::allTenants()->where('evento_id', $evento->id)->count());

        // L'admin riattiva il socio dalla scheda
        $this->comeAdmin()
            ->put(route('soci.update', $socioSospeso), [
                'numero_socio' => $socioSospeso->numero_socio,
                'nome' => $socioSospeso->nome,
                'cognome' => $socioSospeso->cognome,
                'email' => $socioSospeso->email,
                'telefono' => $socioSospeso->telefono,
                'codice_fiscale' => $socioSospeso->codice_fiscale,
                'data_nascita' => $socioSospeso->data_nascita?->toDateString(),
                'data_iscrizione' => $socioSospeso->data_iscrizione?->toDateString(),
                'stato' => Socio::STATO_ATTIVO,
                'indirizzo' => $socioSospeso->indirizzo,
                'citta' => $socioSospeso->citta,
                'provincia' => $socioSospeso->provincia,
                'cap' => $socioSospeso->cap,
                'privacy_consenso' => $socioSospeso->privacy_consenso ? '1' : '0',
                'tipo_socio_id' => $socioSospeso->tipo_socio_id,
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(Socio::STATO_ATTIVO, $socioSospeso->fresh()->stato);

        $p = $this->prenota($collegato, $evento, 1);
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $p->stato);

        // L'esterno resta escluso
        $this->prenotaConErrore($esterno, $evento, 1, 'riservato ai soci');
        $this->assertSame(1, Prenotazione::allTenants()->where('evento_id', $evento->id)->count());
    }

    // ------------------------------------------------------------------
    // 7. Pagamento online con Stripe finto e webhook replay
    // ------------------------------------------------------------------

    public function test_pagamento_prenotazione_con_stripe_finto_e_webhook_replay_conferma_una_sola_volta_e_registra_prima_nota(): void
    {
        $this->configuraStripe();
        $stripe = $this->fakeStripe();

        $evento = $this->adminCreaEvento(['titolo' => 'Concerto d\'organo', 'prezzo' => '10']);
        $luca = $this->visitatore('Luca', 'Organi');

        $p = $this->prenota($luca, $evento, 1);
        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $p->stato);

        $this->get($this->urlPubblica('prenotazioni.paga', ['prenotazione' => $p->id]))
            ->assertOk()
            ->assertViewHas('hasStripe', true)
            ->assertViewHas('pagabile', true);

        $this->post($this->urlPubblica('prenotazioni.checkout', ['prenotazione' => $p->id, 'gateway' => 'stripe']))
            ->assertRedirect('https://checkout.stripe.test/sessione-1');

        $this->assertSame([[
            'tipo' => Pagabile::TIPO_PRENOTAZIONE,
            'id' => $p->id,
            'importo' => 10.0,
        ]], $stripe->checkout);

        // Stripe conferma
        $this->travel(3)->minutes();
        $this->webhookPrenotazione($p, 'pi_prn_7')->assertOk()->assertJson(['status' => 'ok']);

        $p->refresh();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $p->stato);
        $this->assertSame('stripe', $p->gateway);
        $this->assertSame('pi_prn_7', $p->gateway_transaction_id);
        $this->assertSame('2026-05-04 10:03', $p->pagata_at->format('Y-m-d H:i'));

        // Replay del webhook (anche con un id transazione diverso): nessun effetto
        $this->travel(2)->minutes();
        $this->webhookPrenotazione($p, 'pi_prn_7_bis')->assertOk()->assertJson(['status' => 'ok']);

        $p->refresh();
        $this->assertSame('pi_prn_7', $p->gateway_transaction_id);
        $this->assertSame('2026-05-04 10:03', $p->pagata_at->format('Y-m-d H:i'));
        Notification::assertSentToTimes($luca, ConfermataNotification::class, 1);

        $this->assertSame(1, Movimento::allTenants()->where('origine_id', $p->id)->count());
        $this->assertEqualsWithDelta(10.0, (float) $this->movimentoDi($p)->importo, 0.001);
        $this->assertSame('stripe', $this->movimentoDi($p)->metodo);

        // Il link "paga" ora porta all'esito
        $this->comeAccount($luca)
            ->get($this->urlPubblica('prenotazioni.paga', ['prenotazione' => $p->id]))
            ->assertRedirect($this->urlPubblica('prenotazioni.esito', ['prenotazione' => $p->id]));

        $this->comeAdmin()
            ->get(route('eventi.economia.show', $evento))
            ->assertOk()
            ->assertViewHas('incassi', 10.0)
            ->assertViewHas('margine', 10.0);
    }

    // ------------------------------------------------------------------
    // 8. Termine di annullamento
    // ------------------------------------------------------------------

    public function test_annullo_utente_oltre_il_termine_viene_rifiutato_mentre_l_admin_puo_sempre_annullare(): void
    {
        // Evento stasera alle 20, annullabile fino a 24 ore prima
        $evento = $this->adminCreaEvento([
            'titolo' => 'Aperitivo letterario',
            'data_evento' => '2026-05-04T20:00',
            'data_fine' => '2026-05-04T22:00',
            'annullamento_entro_ore' => 24,
        ]);
        $giorgio = $this->visitatore('Giorgio', 'Libri');

        $p = $this->prenota($giorgio, $evento, 2);
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $p->stato);

        $this->get($this->urlPubblica('account.prenotazioni.show', ['prenotazione' => $p->id]))
            ->assertOk()
            ->assertViewHas('annullabile', false);

        $this->post($this->urlPubblica('account.prenotazioni.annulla', ['prenotazione' => $p->id]))
            ->assertSessionHasErrors('annulla');
        $this->assertStringContainsString('termine per l\'annullamento è scaduto', (string) session('errors')->first('annulla'));
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $p->fresh()->stato);
        $this->assertSame(2, Prenotazione::postiOccupati($evento->id));

        // L'admin può sempre annullare, con un motivo
        $this->comeAdmin()
            ->post($this->urlAdminPrenotazione('annulla', $p), ['motivo' => 'Ospite indisposto, avvisato al telefono'])
            ->assertRedirect(route('eventi.prenotazioni.index', $evento))
            ->assertSessionHas('success', "Prenotazione {$p->codice} annullata.");

        $p->refresh();
        $this->assertSame(Prenotazione::STATO_ANNULLATA, $p->stato);
        $this->assertSame('Ospite indisposto, avvisato al telefono', $p->motivo_annullamento);
        $this->assertSame('2026-05-04 10:00', $p->annullata_at->format('Y-m-d H:i'));
        $this->assertSame(0, Prenotazione::postiOccupati($evento->id));
        Notification::assertSentTo($giorgio, AnnullataNotification::class);

        // Un secondo annullo dall'admin è rifiutato
        $this->post($this->urlAdminPrenotazione('annulla', $p))->assertSessionHas('error');
    }

    // ------------------------------------------------------------------
    // 9. Biglietto, verifica pubblica e scanner
    // ------------------------------------------------------------------

    public function test_biglietto_pdf_qr_firmato_verifica_pubblica_e_checkin_scanner_con_evento_sbagliato_e_doppio_passaggio(): void
    {
        $concerto = $this->adminCreaEvento(['titolo' => 'Concerto del coro']);
        $mostra = $this->adminCreaEvento([
            'titolo' => 'Inaugurazione mostra',
            'data_evento' => '2026-05-24T18:00',
            'data_fine' => '2026-05-24T20:00',
        ]);
        $irene = $this->visitatore('Irene', 'Canti');

        $p = $this->prenota($irene, $concerto, 2);

        $pdf = $this->get($this->urlPubblica('account.prenotazioni.biglietto', ['prenotazione' => $p->id]))->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        $url = app(BigliettoService::class)->signedUrl($p);
        $this->comeOspite()->get($url)->assertOk()->assertViewHas('stato', 'valida');

        // Il token senza firma non basta
        $this->get(route('biglietti.verifica', ['token' => $p->qr_token]))
            ->assertOk()
            ->assertViewHas('stato', 'non_valida');

        Carbon::setTestNow('2026-05-23 20:45:00');

        // Scanner impostato sull'evento sbagliato
        $this->comeAdmin()
            ->postJson(route('biglietti.checkin'), ['qr_token' => $p->qr_token, 'evento_id' => $mostra->id])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'code' => 'evento_mismatch']);
        $this->assertNull($p->fresh()->checkin_at);

        $this->postJson(route('biglietti.checkin'), ['qr_token' => $p->qr_token, 'evento_id' => $concerto->id])
            ->assertOk()
            ->assertJson(['success' => true, 'already_checked_in' => false, 'biglietto' => ['posti' => 2]]);

        $p->refresh();
        $this->assertSame('2026-05-23 20:45', $p->checkin_at->format('Y-m-d H:i'));
        $this->assertSame($this->admin->id, $p->checkin_utente_id);

        $this->travel(10)->minutes();
        $this->postJson(route('biglietti.checkin'), ['codice' => strtolower($p->codice), 'evento_id' => $concerto->id])
            ->assertOk()
            ->assertJson(['success' => true, 'already_checked_in' => true]);
        $this->assertSame('2026-05-23 20:45', $p->fresh()->checkin_at->format('Y-m-d H:i'));

        $this->comeOspite()->get($url)->assertOk()->assertViewHas('stato', 'gia_usato');

        // Dopo il check-in l'account non può più annullare
        $this->comeAccount($irene)
            ->post($this->urlPubblica('account.prenotazioni.annulla', ['prenotazione' => $p->id]))
            ->assertSessionHasErrors('annulla');
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $p->fresh()->stato);
    }

    // ------------------------------------------------------------------
    // 10. Presenze in blocco e kiosk
    // ------------------------------------------------------------------

    public function test_presenze_in_blocco_e_kiosk_contano_solo_confermate_e_sommano_posti_e_partecipanti_soci(): void
    {
        $evento = $this->adminCreaEvento(['titolo' => 'Torneo di burraco', 'capienza' => 4]);

        $p1 = $this->prenota($this->visitatore('Paola', 'Carte'), $evento, 2);
        $p2 = $this->prenota($this->visitatore('Pietro', 'Mazzi'), $evento, 2);
        $p3 = $this->prenota($this->visitatore('Rita', 'Attesa'), $evento, 1);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $p3->stato);

        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id, 'cognome' => 'Fedeli']);
        $this->comeAdmin()
            ->post(route('eventi.partecipanti.store', $evento), [
                'socio_id' => $socio->id,
                'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
            ])->assertSessionHas('success');

        Carbon::setTestNow('2026-05-23 21:05:00');

        $this->comeAdmin()
            ->post(route('eventi.presenze.store', $evento), ['prenotazioni' => [$p1->id, $p2->id, $p3->id]])
            ->assertRedirect(route('eventi.presenze.index', $evento))
            ->assertSessionHas('success', 'Presenze registrate: 2.');

        foreach ([$p1, $p2] as $p) {
            $p->refresh();
            $this->assertSame('2026-05-23 21:05', $p->checkin_at->format('Y-m-d H:i'));
            $this->assertSame($this->admin->id, $p->checkin_utente_id);
        }
        $this->assertNull($p3->fresh()->checkin_at);

        // Ripetere non registra di nuovo
        $this->post(route('eventi.presenze.store', $evento), ['prenotazioni' => [$p1->id]])
            ->assertSessionHas('success', 'Presenze registrate: 0.');

        $this->get(route('checkin-kiosk.contatore', $evento))
            ->assertOk()
            ->assertExactJson(['presenze' => 5]);

        $this->get(route('checkin-kiosk.show', $evento))->assertOk()->assertViewHas('presenze', 5);

        $foglio = $this->get(route('eventi.presenze.foglio', $evento))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $foglio->getContent());
    }

    // ------------------------------------------------------------------
    // 11. Partecipanti soci
    // ------------------------------------------------------------------

    public function test_partecipanti_soci_aggiunta_singola_tutti_e_aggiornamento_stato_non_duplica_righe(): void
    {
        $evento = $this->adminCreaEvento(['titolo' => 'Pranzo sociale']);

        $soci = collect(['Abate', 'Bassi', 'Conte', 'Donati'])->map(fn (string $cognome, int $i) => Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => $cognome,
            'nome' => 'Socio'.$i,
            'numero_socio' => '10'.$i,
            'stato' => Socio::STATO_ATTIVO,
        ]));
        Socio::factory()->sospeso()->create(['associazione_id' => $this->associazione->id, 'cognome' => 'Sospesini']);
        // Un socio attivo di un'altra associazione non deve mai entrare
        Socio::factory()->create(['associazione_id' => $this->altraAssociazione->id, 'cognome' => 'Forestieri', 'stato' => Socio::STATO_ATTIVO]);

        $abate = $soci[0];

        $this->comeAdmin()
            ->post(route('eventi.partecipanti.store', $evento), [
                'socio_id' => $abate->id,
                'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
                'note' => 'Porta il dolce',
            ])->assertSessionHas('success', 'Socio aggiunto all\'evento!');

        $this->post(route('eventi.partecipanti.storeAll', $evento))
            ->assertSessionHas('success', 'Aggiunti 3 soci all\'evento!');

        $this->assertSame(4, EventoPartecipante::allTenants()->where('evento_id', $evento->id)->count());
        $this->assertDatabaseMissing('eventi_partecipanti', [
            'evento_id' => $evento->id,
            'socio_id' => Socio::allTenants()->where('cognome', 'Sospesini')->value('id'),
        ]);
        // storeAll non tocca chi c'era già
        $riga = EventoPartecipante::allTenants()->where('socio_id', $abate->id)->firstOrFail();
        $this->assertSame(EventoPartecipante::STATO_CONFERMATO, $riga->stato_partecipazione);
        $this->assertSame('Porta il dolce', $riga->note);
        $this->assertDatabaseHas('eventi_partecipanti', [
            'evento_id' => $evento->id,
            'socio_id' => $soci[1]->id,
            'associazione_id' => $this->associazione->id,
            'stato_partecipazione' => EventoPartecipante::STATO_NON_PARTECIPA,
            'data_conferma' => null,
        ]);

        $this->travel(1)->hours();
        $this->put(route('eventi.partecipanti.update', ['evento' => $evento->id, 'partecipante' => $riga->id]), [
            'stato_partecipazione' => EventoPartecipante::STATO_FORSE,
            'note' => 'Forse arriva tardi',
        ])->assertSessionHas('success', 'Partecipazione aggiornata con successo!');
        $riga->refresh();
        $this->assertSame(EventoPartecipante::STATO_FORSE, $riga->stato_partecipazione);
        $this->assertSame('Forse arriva tardi', $riga->note);
        $this->assertSame('2026-05-04 11:00', $riga->data_conferma->format('Y-m-d H:i'));

        // Riaggiunto dal form singolo: aggiorna la stessa riga
        $this->post(route('eventi.partecipanti.store', $evento), [
            'socio_id' => $abate->id,
            'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
        ])->assertSessionHas('success');

        $this->assertSame(1, EventoPartecipante::allTenants()->where('evento_id', $evento->id)->where('socio_id', $abate->id)->count());
        $this->assertSame(4, EventoPartecipante::allTenants()->where('evento_id', $evento->id)->count());
        $this->assertSame(EventoPartecipante::STATO_CONFERMATO, $riga->fresh()->stato_partecipazione);

        // Un secondo "aggiungi tutti" non inserisce nulla
        $this->post(route('eventi.partecipanti.storeAll', $evento))
            ->assertSessionHas('success', 'Aggiunti 0 soci all\'evento!');

        $csv = $this->get(route('eventi.partecipanti.export', $evento))->assertOk()->streamedContent();
        $righe = $this->righeCsv($csv);
        $this->assertCount(4, $righe);
        $this->assertStringContainsString('Abate;Socio0;100;', $righe[0]);
        $this->assertStringNotContainsString('Sospesini', $csv);
        $this->assertStringNotContainsString('Forestieri', $csv);
    }

    // ------------------------------------------------------------------
    // 12. Serie ricorrente: campi su ogni occorrenza e campi di serie
    // ------------------------------------------------------------------

    public function test_serie_settimanale_salva_tutti_i_campi_su_ogni_occorrenza_e_aggiorna_serie_propaga_solo_i_campi_serie(): void
    {
        $sede = Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Sede nord']);
        $spazio = Spazio::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Palestrina']);

        $this->comeAdmin()
            ->post(route('eventi-ricorrenti.store'), $this->datiEvento([
                'titolo' => 'Corso di yoga',
                'data_evento' => '2026-05-07T18:30',
                'data_fine' => '2026-05-07T20:00',
                'capienza' => 15,
                'sede_id' => $sede->id,
                'spazio_id' => $spazio->id,
                'max_posti_per_prenotazione' => 2,
                'annullamento_entro_ore' => 12,
                'prezzo' => '5',
                'frequenza' => 'settimanale',
                'fino_al' => '2026-05-28',
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Serie ricorrente creata: 4 eventi in calendario.');

        $occorrenze = Evento::allTenants()->orderBy('data_evento')->get();
        $this->assertCount(4, $occorrenze);
        $this->assertCount(1, $occorrenze->pluck('serie_id')->unique());
        $this->assertNotNull($occorrenze[0]->serie_id);

        foreach ($occorrenze as $i => $o) {
            $giorno = Carbon::parse('2026-05-07')->addWeeks($i)->format('Y-m-d');
            $this->assertSame($giorno.' 18:30', $o->data_evento->format('Y-m-d H:i'));
            $this->assertSame($giorno.' 20:00', $o->data_fine->format('Y-m-d H:i'));
            $this->assertSame($spazio->id, $o->spazio_id);
            $this->assertSame($sede->id, $o->sede_id);
            $this->assertSame(15, $o->capienza);
            $this->assertSame(2, $o->max_posti_per_prenotazione);
            $this->assertSame(12, $o->annullamento_entro_ore);
            $this->assertSame('5.00', (string) $o->prezzo);
            $this->assertSame($this->associazione->id, $o->associazione_id);
        }

        [$prima, $seconda, $terza] = [$occorrenze[0], $occorrenze[1], $occorrenze[2]];

        // Modifica dell'intera serie (solo i campi di serie)
        $this->put(route('eventi.serie.update', $prima), [
            'titolo' => 'Corso di yoga — livello base',
            'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
            'capienza' => 20,
            'prezzo' => '7,50',
            'iscrizioni_aperte' => '1',
            'richiede_approvazione' => '0',
            'solo_soci' => '0',
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('eventi.serie.show', $prima))
            ->assertSessionHas('success', 'Serie aggiornata: 4 occorrenze.');

        foreach (Evento::allTenants()->orderBy('data_evento')->get() as $i => $o) {
            $this->assertSame('Corso di yoga — livello base', $o->titolo);
            $this->assertSame('7.50', (string) $o->prezzo);
            $this->assertSame(20, $o->capienza);
            // Fuori da CAMPI_SERIE: restano quelli di ogni occorrenza
            $this->assertSame(2, $o->max_posti_per_prenotazione);
            $this->assertSame(12, $o->annullamento_entro_ore);
            $this->assertSame($spazio->id, $o->spazio_id);
            $this->assertSame($sede->id, $o->sede_id);
            $this->assertSame('Cortile del circolo', $o->luogo);
            $this->assertSame($occorrenze[$i]->data_evento->format('Y-m-d H:i'), $o->data_evento->format('Y-m-d H:i'));
        }

        // Modifica di una sola occorrenza
        $this->put(route('eventi.update', $seconda), [
            'titolo' => 'Corso di yoga — livello base',
            'data_evento' => $seconda->data_evento->format('Y-m-d\TH:i'),
            'max_posti_per_prenotazione' => 5,
        ])->assertSessionHasNoErrors();

        $this->assertSame(5, $seconda->fresh()->max_posti_per_prenotazione);
        $this->assertSame($prima->serie_id, $seconda->fresh()->serie_id);
        $this->assertSame([2, 5, 2, 2], Evento::allTenants()->orderBy('data_evento')->pluck('max_posti_per_prenotazione')->all());

        // Su un'occorrenza ci sono 2 posti prenotati: la serie non può
        // scendere a capienza 1 (stessa regola della modifica singola)
        $prenotazione = $this->prenota($this->visitatore('Yuri', 'Asana'), $terza, 2);
        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $prenotazione->stato);

        $this->comeAdmin()
            ->put(route('eventi.serie.update', $prima), [
                'titolo' => 'Corso di yoga — livello base',
                'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
                'capienza' => 1,
                'prezzo' => '7,50',
                'iscrizioni_aperte' => '1',
                'richiede_approvazione' => '0',
                'solo_soci' => '0',
            ])->assertSessionHasErrors('capienza');

        $this->assertSame(20, $terza->fresh()->capienza, 'La capienza non deve scendere sotto i posti occupati');
    }

    // ------------------------------------------------------------------
    // 13. Eliminazione della serie con prenotazioni
    // ------------------------------------------------------------------

    public function test_eliminazione_serie_con_prenotazioni_elimina_solo_le_occorrenze_e_le_prenotazioni_restano_collegate_a_eventi_cestinati(): void
    {
        $this->comeAdmin()
            ->post(route('eventi-ricorrenti.store'), $this->datiEvento([
                'titolo' => 'Gruppo di lettura',
                'data_evento' => '2026-05-06T18:00',
                'data_fine' => '2026-05-06T19:30',
                'frequenza' => 'settimanale',
                'fino_al' => '2026-05-20',
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Serie ricorrente creata: 3 eventi in calendario.');

        // Un evento singolo che NON fa parte della serie
        $singolo = $this->adminCreaEvento(['titolo' => 'Presentazione libro']);

        $occorrenze = Evento::allTenants()->whereNotNull('serie_id')->orderBy('data_evento')->get();
        $this->assertCount(3, $occorrenze);
        $seconda = $occorrenze[1];

        $lettore = $this->visitatore('Lorenzo', 'Pagine');
        $p = $this->prenota($lettore, $seconda, 1);
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $p->stato);

        $this->comeAdmin()
            ->delete(route('eventi.serie.destroy', $occorrenze[0]))
            ->assertRedirect(route('eventi.index'))
            ->assertSessionHas('success', 'Serie annullata: 3 eventi eliminati.');

        $this->assertSame(0, Evento::allTenants()->whereNotNull('serie_id')->count());
        $this->assertSame(3, Evento::allTenants()->onlyTrashed()->where('serie_id', $seconda->serie_id)->count());
        $this->assertNull($singolo->fresh()->deleted_at);

        $p->refresh();
        $this->assertNotNull($p);
        $eventoCestinato = $p->evento()->withoutGlobalScopes()->withTrashed()->first();
        $this->assertNotNull($eventoCestinato);
        $this->assertTrue($eventoCestinato->trashed());

        // La scheda account in admin elenca ancora la prenotazione
        $this->get(route('accounts.show', $lettore))
            ->assertOk()
            ->assertSee($p->codice)
            ->assertSee('Gruppo di lettura')
            ->assertViewHas('prenotazioni', fn ($elenco) => collect($elenco->items())->pluck('id')->all() === [$p->id]);

        // Sul sito la data non esiste più; l'area account non va in errore
        $this->comeOspite()
            ->get($this->urlPubblica('eventi.show', ['evento' => $seconda->id]))
            ->assertNotFound();

        $this->comeAccount($lettore)
            ->get($this->urlPubblica('account.prenotazioni.index'))
            ->assertOk()
            ->assertSee($p->codice);
        $this->get($this->urlPubblica('account.prenotazioni.show', ['prenotazione' => $p->id]))->assertOk();

        // Nessun promemoria per un evento cancellato
        Carbon::setTestNow('2026-05-12 10:00:00');
        $this->assertSame(0, $this->servizio()->inviaPromemoriaEventi());
        Notification::assertNotSentTo($lettore, PromemoriaEventoNotification::class);

        // Il biglietto di una data annullata non può risultare valido all'ingresso
        $this->comeOspite()
            ->get(app(BigliettoService::class)->signedUrl($p))
            ->assertOk()
            ->assertViewHas('stato', 'non_valida');
    }

    // ------------------------------------------------------------------
    // 14. Feedback post-evento
    // ------------------------------------------------------------------

    public function test_feedback_post_evento_inviato_solo_a_chi_ha_fatto_checkin_compilabile_una_volta_e_statistiche_corrette(): void
    {
        Carbon::setTestNow('2026-05-01 09:00:00');

        $evento = $this->adminCreaEvento([
            'titolo' => 'Serata di stand-up comedy',
            'data_evento' => '2026-05-03T21:00',
            'data_fine' => '2026-05-03T23:00',
        ]);

        $uno = $this->visitatore('Ugo', 'Risate');
        $due = $this->visitatore('Dalia', 'Sorrisi');
        $tre = $this->visitatore('Tito', 'Assente');
        $p1 = $this->prenota($uno, $evento, 1);
        $p2 = $this->prenota($due, $evento, 2);
        $p3 = $this->prenota($tre, $evento, 1);

        Carbon::setTestNow('2026-05-03 20:50:00');
        foreach ([$p1, $p2] as $p) {
            $this->comeAdmin()
                ->postJson(route('biglietti.checkin'), ['qr_token' => $p->qr_token, 'evento_id' => $evento->id])
                ->assertOk()
                ->assertJson(['success' => true]);
        }

        Carbon::setTestNow('2026-05-04 08:00:00');
        $servizio = app(FeedbackEventiService::class);
        $this->assertSame(2, $servizio->inviaRichieste());
        $this->assertSame(0, $servizio->inviaRichieste(), 'La seconda esecuzione non duplica gli inviti');

        $this->assertSame(2, FeedbackEvento::allTenants()->where('evento_id', $evento->id)->count());
        $f1 = FeedbackEvento::allTenants()->where('prenotazione_id', $p1->id)->firstOrFail();
        $f2 = FeedbackEvento::allTenants()->where('prenotazione_id', $p2->id)->firstOrFail();
        $this->assertTrue(FeedbackEvento::allTenants()->where('prenotazione_id', $p3->id)->doesntExist());
        foreach ([$f1, $f2] as $f) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $f->token);
            $this->assertSame($this->associazione->id, $f->associazione_id);
            $this->assertSame('2026-05-04 08:00', $f->inviato_at->format('Y-m-d H:i'));
            $this->assertNull($f->compilato_at);
        }
        Notification::assertSentToTimes($uno, FeedbackEventoNotification::class, 1);
        Notification::assertSentToTimes($due, FeedbackEventoNotification::class, 1);
        Notification::assertNotSentTo($tre, FeedbackEventoNotification::class);

        // Compilazione dai link firmati
        Carbon::setTestNow('2026-05-04 12:00:00');
        $this->comeOspite()
            ->get(URL::signedRoute('feedback-eventi.show', ['token' => $f1->token]))
            ->assertOk()
            ->assertSee('Serata di stand-up comedy');

        $this->post(route('feedback-eventi.store', ['token' => $f1->token]), ['voto' => 4, 'commento' => 'Divertente, audio da migliorare'])
            ->assertOk();
        $this->post(route('feedback-eventi.store', ['token' => $f2->token]), ['voto' => 5])
            ->assertOk();

        $f1->refresh();
        $this->assertSame(4, $f1->voto);
        $this->assertSame('Divertente, audio da migliorare', $f1->commento);
        $this->assertSame('2026-05-04 12:00', $f1->compilato_at->format('Y-m-d H:i'));
        $this->assertSame(5, $f2->fresh()->voto);
        $this->assertNull($f2->fresh()->commento);

        // Secondo invio: rifiutato, voto invariato
        $this->post(route('feedback-eventi.store', ['token' => $f1->token]), ['voto' => 1, 'commento' => 'Ripensamento'])
            ->assertSessionHasErrors('voto');
        $this->assertSame(4, $f1->fresh()->voto);
        $this->assertSame('Divertente, audio da migliorare', $f1->fresh()->commento);

        // Voto fuori scala respinto dalla validazione
        $this->post(route('feedback-eventi.store', ['token' => $f2->token]), ['voto' => 9])->assertSessionHasErrors('voto');

        $stats = $this->comeAdmin()
            ->get(route('eventi.feedback.index', $evento))
            ->assertOk()
            ->assertSee('Divertente, audio da migliorare')
            ->viewData('stats');

        $this->assertSame(4.5, $stats['media']);
        $this->assertSame(2, $stats['totale_compilati']);
        $this->assertSame(2, $stats['totale_inviati']);
        $this->assertSame([1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 1], $stats['distribuzione']);
        $this->assertCount(1, $stats['commenti']);
    }

    // ------------------------------------------------------------------
    // 15. Economia dell'evento e isolamento delle spese
    // ------------------------------------------------------------------

    public function test_budget_e_spese_evento_calcolano_margine_e_spesa_di_altro_tenant_non_e_cancellabile(): void
    {
        $this->configuraStripe();
        $this->fakeStripe();

        $evento = $this->adminCreaEvento(['titolo' => 'Festa d\'estate', 'prezzo' => '15']);

        $this->comeAdmin()
            ->put(route('eventi.economia.budget', $evento), ['budget' => '500'])
            ->assertRedirect(route('eventi.economia.show', $evento))
            ->assertSessionHas('success', 'Budget aggiornato.');
        $this->assertSame('500.00', (string) $evento->fresh()->budget);

        foreach ([['Service audio', '120.50'], ['Allestimento', '80']] as [$descrizione, $importo]) {
            $this->post(route('eventi.economia.spese.store', $evento), [
                'descrizione' => $descrizione,
                'importo' => $importo,
                'data' => '2026-05-20',
            ])->assertSessionHas('success', 'Spesa registrata.');
        }

        $spese = SpesaEvento::allTenants()->where('evento_id', $evento->id)->orderBy('importo')->get();
        $this->assertCount(2, $spese);
        $this->assertSame(['80.00', '120.50'], $spese->map(fn (SpesaEvento $s) => (string) $s->importo)->all());
        $this->assertSame([$this->associazione->id], $spese->pluck('associazione_id')->unique()->values()->all());
        $this->assertSame('2026-05-20', $spese[0]->data->format('Y-m-d'));

        // Un ospite prenota 2 posti e paga con Stripe
        $ospite = $this->visitatore('Olga', 'Festa');
        $p = $this->prenota($ospite, $evento, 2);
        $this->assertSame('30.00', (string) $p->importo_totale);
        $this->webhookPrenotazione($p, 'pi_festa')->assertOk();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $p->fresh()->stato);

        // Spesa di un evento di un'altra associazione: invisibile e non cancellabile
        $eventoAltrui = Evento::factory()->pubblicato()->create(['associazione_id' => $this->altraAssociazione->id]);
        $spesaAltrui = SpesaEvento::factory()->create(['evento_id' => $eventoAltrui->id, 'importo' => 99]);

        $this->comeAdmin()
            ->delete(route('eventi.economia.spese.destroy', $spesaAltrui->id))
            ->assertNotFound();
        $this->assertNotNull(SpesaEvento::allTenants()->find($spesaAltrui->id));
        $this->get(route('eventi.economia.show', $eventoAltrui->id))->assertNotFound();

        $this->get(route('eventi.economia.show', $evento))
            ->assertOk()
            ->assertViewHas('incassi', 30.0)
            ->assertViewHas('totaleSpese', 200.5)
            ->assertViewHas('margine', -170.5)
            ->assertViewHas('spese', fn ($s) => count($s) === 2)
            ->assertSee('Service audio')
            ->assertSee('200,50');
    }

    // ------------------------------------------------------------------
    // 16. Conflitto di spazio
    // ------------------------------------------------------------------

    public function test_evento_su_spazio_gia_prenotato_viene_salvato_con_avviso_di_conflitto(): void
    {
        $sala = Spazio::factory()->create(['associazione_id' => $this->associazione->id, 'nome' => 'Sala riunioni']);
        PrenotazioneSpazio::factory()->create([
            'spazio_id' => $sala->id,
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Prove del coro',
            'dal' => '2026-05-20 18:00:00',
            'al' => '2026-05-20 20:00:00',
        ]);

        $risposta = $this->comeAdmin()
            ->post(route('eventi.store'), $this->datiEvento([
                'titolo' => 'Riunione del direttivo',
                'data_evento' => '2026-05-20T19:00',
                'data_fine' => '2026-05-20T21:00',
                'spazio_id' => $sala->id,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('eventi.index'))
            ->assertSessionHas('success', 'Evento creato con successo.')
            ->assertSessionHas('warning');

        $avviso = (string) session('warning');
        $this->assertStringContainsString('Prove del coro', $avviso);
        $this->assertStringContainsString('20/05/2026 18:00', $avviso);
        $this->assertNotNull($risposta);
        $this->assertDatabaseHas('eventi', ['titolo' => 'Riunione del direttivo', 'spazio_id' => $sala->id]);

        // Subito dopo, dalle 21 alle 22: nessun conflitto
        $this->comeAdmin()
            ->post(route('eventi.store'), $this->datiEvento([
                'titolo' => 'Corso di scacchi',
                'data_evento' => '2026-05-20T21:00',
                'data_fine' => '2026-05-20T22:00',
                'spazio_id' => $sala->id,
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('warning');

        $this->assertSame(2, Evento::allTenants()->where('spazio_id', $sala->id)->count());

        // Uno spazio di un'altra associazione non è assegnabile
        $salaAltrui = Spazio::factory()->create(['associazione_id' => $this->altraAssociazione->id]);
        $this->post(route('eventi.store'), $this->datiEvento([
            'titolo' => 'Evento con sala altrui',
            'spazio_id' => $salaAltrui->id,
        ]))->assertSessionHasErrors('spazio_id');
        $this->assertDatabaseMissing('eventi', ['titolo' => 'Evento con sala altrui']);
    }

    // ------------------------------------------------------------------
    // 17. Eventi non prenotabili
    // ------------------------------------------------------------------

    public function test_evento_chiuso_alle_iscrizioni_passato_o_bozza_non_e_prenotabile_dal_pubblico(): void
    {
        $chiuso = $this->adminCreaEvento(['titolo' => 'Gita chiusa', 'iscrizioni_aperte' => '0']);
        $passato = $this->adminCreaEvento([
            'titolo' => 'Festa di aprile',
            'data_evento' => '2026-04-25T16:00',
            'data_fine' => '2026-04-25T19:00',
        ]);
        $bozza = $this->adminCreaEvento(['titolo' => 'Evento in preparazione', 'stato_pubblicazione' => Evento::STATO_BOZZA]);
        $finestraChiusa = $this->adminCreaEvento([
            'titolo' => 'Iscrizioni terminate',
            'iscrizioni_al' => '2026-05-03T23:59',
        ]);
        $finestraFutura = $this->adminCreaEvento([
            'titolo' => 'Iscrizioni non ancora aperte',
            'iscrizioni_dal' => '2026-05-10T09:00',
        ]);

        $account = $this->visitatore('Nadia', 'Curiosa');

        foreach ([$chiuso, $passato, $finestraChiusa, $finestraFutura] as $evento) {
            $this->prenotaConErrore($account, $evento, 1, 'non sono aperte');
        }

        $this->comeAccount($account)
            ->post($this->urlPubblica('eventi.prenota', ['evento' => $bozza->id]), ['posti' => 1])
            ->assertNotFound();

        $this->assertSame(0, Prenotazione::allTenants()->count());

        // Riapertura delle iscrizioni dall'admin: ora si può prenotare
        $this->comeAdmin()
            ->put(route('eventi.update', $chiuso), ['titolo' => 'Gita chiusa', 'data_evento' => '2026-05-23T21:00', 'iscrizioni_aperte' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $this->prenota($account, $chiuso, 1)->stato);
    }

    // ------------------------------------------------------------------
    // 18. Doppia prenotazione
    // ------------------------------------------------------------------

    public function test_doppia_prenotazione_attiva_bloccata_ma_dopo_annullo_si_puo_riprenotare(): void
    {
        $evento = $this->adminCreaEvento(['titolo' => 'Corso di fotografia']);
        $fabio = $this->visitatore('Fabio', 'Scatti');

        $prima = $this->prenota($fabio, $evento, 1);

        $this->prenotaConErrore($fabio, $evento, 2, 'già una prenotazione attiva');
        $this->assertSame(1, Prenotazione::allTenants()->where('account_id', $fabio->id)->count());

        $this->comeAccount($fabio)
            ->post($this->urlPubblica('account.prenotazioni.annulla', ['prenotazione' => $prima->id]))
            ->assertSessionHas('success', 'Prenotazione annullata.');

        $this->travel(1)->minutes();
        $seconda = $this->prenota($fabio, $evento, 3);

        $this->assertNotSame($prima->id, $seconda->id);
        $this->assertNotSame($prima->codice, $seconda->codice);
        $this->assertNotSame($prima->qr_token, $seconda->qr_token);
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $seconda->stato);
        $this->assertSame(3, $seconda->posti);
        $this->assertSame(Prenotazione::STATO_ANNULLATA, $prima->fresh()->stato);
        $this->assertSame(2, Prenotazione::allTenants()->where('account_id', $fabio->id)->count());
        $this->assertSame(3, Prenotazione::postiOccupati($evento->id));
    }

    // ------------------------------------------------------------------
    // 19. Promemoria eventi
    // ------------------------------------------------------------------

    public function test_promemoria_eventi_rispetta_la_preferenza_dell_account_e_l_account_disattivato(): void
    {
        // Evento domani alle 16: tra 30 ore
        $evento = $this->adminCreaEvento([
            'titolo' => 'Torneo di scacchi',
            'data_evento' => '2026-05-05T16:00',
            'data_fine' => '2026-05-05T19:00',
        ]);
        // Evento tra 3 giorni: fuori finestra
        $lontano = $this->adminCreaEvento(['titolo' => 'Evento lontano']);

        $normale = $this->visitatore('Nora', 'Normale');
        $senzaPromemoria = $this->visitatore('Sara', 'Silenzio');
        $disattivato = $this->visitatore('Dario', 'Disattivo');

        $pNormale = $this->prenota($normale, $evento);
        $this->prenota($senzaPromemoria, $evento);
        $this->prenota($disattivato, $evento);
        $this->prenota($normale, $lontano);

        // Sara spegne i promemoria dalle preferenze
        $this->comeAccount($senzaPromemoria)
            ->patch($this->urlPubblica('account.preferenze.aggiorna'), ['newsletter' => '1', 'promemoria_eventi' => '0'])
            ->assertSessionHas('success', 'Preferenze salvate.');
        $this->assertFalse($senzaPromemoria->fresh()->preferenze['promemoria_eventi']);
        $this->assertTrue($senzaPromemoria->fresh()->preferenze['newsletter']);

        // L'admin disattiva l'account di Dario
        $this->comeAdmin()
            ->patch(route('accounts.disattiva', $disattivato))
            ->assertSessionHas('success', 'Account disattivato.');
        $this->assertFalse($disattivato->fresh()->attivo);

        $this->assertSame(1, $this->servizio()->inviaPromemoriaEventi());

        Notification::assertSentToTimes($normale, PromemoriaEventoNotification::class, 1);
        Notification::assertSentTo($normale, PromemoriaEventoNotification::class, fn (PromemoriaEventoNotification $n) => $n->prenotazione->is($pNormale));
        Notification::assertNotSentTo($senzaPromemoria, PromemoriaEventoNotification::class);
        Notification::assertNotSentTo($disattivato, PromemoriaEventoNotification::class);
    }

    // ------------------------------------------------------------------
    // 20. Calendari .ics pubblici
    // ------------------------------------------------------------------

    public function test_calendario_pubblico_ics_e_ics_singolo_contengono_solo_eventi_pubblicati_del_tenant(): void
    {
        $pubblicato = $this->adminCreaEvento(['titolo' => 'Mercatino del riuso']);
        $bozza = $this->adminCreaEvento(['titolo' => 'Bozza riservata', 'stato_pubblicazione' => Evento::STATO_BOZZA]);
        $archiviato = $this->adminCreaEvento(['titolo' => 'Evento archiviato', 'stato_pubblicazione' => Evento::STATO_ARCHIVIATO]);
        $altrui = Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->altraAssociazione->id,
            'titolo' => 'Sagra della Pro Loco',
            'data_evento' => '2026-05-30 12:00:00',
        ]);

        $feed = $this->comeOspite()
            ->get($this->urlPubblica('calendario.ics'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=UTF-8')
            ->getContent();
        $feed = str_replace(["\r\n ", "\r\n\t"], '', (string) $feed); // unfolding RFC 5545

        $this->assertSame(1, substr_count($feed, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('UID:'.$pubblicato->id.'@', $feed);
        $this->assertStringContainsString('SUMMARY:Mercatino del riuso', $feed);
        $this->assertStringContainsString('URL:'.$this->urlPubblica('eventi.show', ['evento' => $pubblicato->id]), $feed);
        $this->assertStringNotContainsString('Bozza riservata', $feed);
        $this->assertStringNotContainsString('Evento archiviato', $feed);
        $this->assertStringNotContainsString('Sagra della Pro Loco', $feed);
        $this->assertStringNotContainsString($bozza->id, $feed);

        $singolo = $this->get($this->urlPubblica('eventi.ics', ['evento' => $pubblicato->id]))->assertOk()->getContent();
        $this->assertStringContainsString('UID:'.$pubblicato->id.'@', (string) $singolo);

        $this->get($this->urlPubblica('eventi.ics', ['evento' => $bozza->id]))->assertNotFound();
        $this->get($this->urlPubblica('eventi.ics', ['evento' => $archiviato->id]))->assertNotFound();
        $this->get($this->urlPubblica('eventi.ics', ['evento' => $altrui->id]))->assertNotFound();

        // Il feed dell'altra associazione contiene solo il suo evento
        $feedAltrui = (string) $this->get($this->urlPubblica('calendario.ics', [], $this->altraAssociazione))->assertOk()->getContent();
        $this->assertSame(1, substr_count($feedAltrui, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('Sagra della Pro Loco', $feedAltrui);
        $this->assertStringNotContainsString('Mercatino del riuso', $feedAltrui);

        // Admin: export ics di un evento di un'altra associazione → 404
        $this->comeAdmin()->get(route('eventi.ics', $altrui->id))->assertNotFound();
    }

    // ------------------------------------------------------------------
    // 21. Galleria foto: limite e visibilità
    // ------------------------------------------------------------------

    public function test_galleria_foto_limite_20_file_e_visibilita_pubblica_solo_se_evento_pubblicato(): void
    {
        Storage::fake('media_private');

        $evento = $this->adminCreaEvento(['titolo' => 'Carnevale dei bambini']);
        $altro = $this->adminCreaEvento(['titolo' => 'Laboratorio maschere']);

        $foto = fn (int $n, string $prefisso) => array_map(
            fn (int $i) => UploadedFile::fake()->image("{$prefisso}-{$i}.jpg", 20, 20),
            range(1, $n)
        );

        $this->comeAdmin()
            ->post(route('eventi.galleria.store', $evento), ['foto' => $foto(19, 'festa')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '19 foto caricate.');

        // Ne restano solo 1 disponibile: delle 3 nuove ne entra una
        $this->post(route('eventi.galleria.store', $evento), ['foto' => $foto(3, 'extra')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Foto caricata.');

        $media = $evento->fresh()->getMedia(Evento::COLLEZIONE_GALLERIA);
        $this->assertCount(20, $media);
        $this->assertSame(20, Media::query()
            ->where('model_id', $evento->id)->where('collection_name', 'galleria')->count());
        $this->assertSame(1, $media->filter(fn ($m) => str_starts_with($m->file_name, 'extra-'))->count());

        // Galleria piena: nuovo caricamento rifiutato
        $this->post(route('eventi.galleria.store', $evento), ['foto' => $foto(1, 'oltre')])
            ->assertSessionHasErrors('foto');
        $this->assertCount(20, $evento->fresh()->getMedia(Evento::COLLEZIONE_GALLERIA));

        // Resa pubblica: il file si scarica dal sito
        $this->post(route('eventi.galleria.visibilita', $evento))->assertSessionHas('success');
        $this->assertTrue($evento->fresh()->galleria_pubblica);

        $urlPubblico = $this->urlPubblica('eventi.galleria.file', ['evento' => $evento->id, 'media' => $media[0]->id]);
        $this->comeOspite()->get($urlPubblico)->assertOk();

        // Un media di un altro evento non si scarica passando da questo evento
        Storage::fake('media_private');
        $this->comeAdmin()->post(route('eventi.galleria.store', $altro), ['foto' => $foto(1, 'maschera')])->assertSessionHas('success');
        $mediaAltro = $altro->fresh()->getMedia(Evento::COLLEZIONE_GALLERIA)->first();
        $this->comeOspite()
            ->get($this->urlPubblica('eventi.galleria.file', ['evento' => $evento->id, 'media' => $mediaAltro->id]))
            ->assertNotFound();
        $this->comeAdmin()
            ->delete(route('eventi.galleria.destroy', ['evento' => $evento->id, 'media' => $mediaAltro->id]))
            ->assertNotFound();
        $this->assertNotNull(Media::query()->find($mediaAltro->id));

        // L'evento torna in bozza: niente più file pubblici
        $this->put(route('eventi.update', $evento), [
            'titolo' => 'Carnevale dei bambini',
            'data_evento' => '2026-05-23T21:00',
            'stato_pubblicazione' => Evento::STATO_BOZZA,
        ])->assertSessionHasNoErrors();
        $this->assertTrue($evento->fresh()->galleria_pubblica);

        $this->comeOspite()->get($urlPubblico)->assertNotFound();
    }

    // ------------------------------------------------------------------
    // 22. Export prenotazioni e reinvio biglietto
    // ------------------------------------------------------------------

    public function test_export_prenotazioni_csv_e_reinvio_biglietto_solo_per_confermate_con_account_attivo(): void
    {
        $evento = $this->adminCreaEvento(['titolo' => 'Cena a tema medievale', 'capienza' => 2]);
        $vera = $this->visitatore('Vera', 'Conferma');
        $coda = $this->visitatore('Ciro', 'Coda');

        $pConfermata = $this->prenota($vera, $evento, 2);
        $pCoda = $this->prenota($coda, $evento, 1);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $pCoda->stato);

        $this->comeAdmin()
            ->post($this->urlAdminPrenotazione('reinvia', $pConfermata))
            ->assertSessionHas('success', "Biglietto {$pConfermata->codice} reinviato a {$vera->email}.");
        Notification::assertSentToTimes($vera, BigliettoPrenotazioneNotification::class, 1);

        $this->post($this->urlAdminPrenotazione('reinvia', $pCoda))
            ->assertSessionHas('error', 'Il biglietto si può reinviare solo per le prenotazioni confermate.');
        Notification::assertNotSentTo($coda, BigliettoPrenotazioneNotification::class);

        $this->patch(route('accounts.disattiva', $vera))->assertSessionHas('success');

        $this->comeAdmin()
            ->post($this->urlAdminPrenotazione('reinvia', $pConfermata))
            ->assertSessionHas('error', 'L\'account della prenotazione non è più raggiungibile via email.');
        Notification::assertSentToTimes($vera, BigliettoPrenotazioneNotification::class, 1);

        $csv = $this->get(route('eventi.prenotazioni.export', $evento))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Codice;Cognome;Nome;Email;Posti;Stato;Importo;Gateway;"Pagata il";Check-in;"Operatore check-in";"Creata il"', $csv);
        $righe = $this->righeCsv($csv);
        $this->assertCount(2, $righe);
        $this->assertStringContainsString($pConfermata->codice.';Conferma;Vera;vera.conferma@esempio.test;2;confermata;0,00', $csv);
        $this->assertStringContainsString($pCoda->codice.';Coda;Ciro;ciro.coda@esempio.test;1;lista_attesa', $csv);

        // Filtro per stato
        $soloCoda = $this->get(route('eventi.prenotazioni.export', ['evento' => $evento->id, 'stato' => Prenotazione::STATO_LISTA_ATTESA]))->streamedContent();
        $this->assertCount(1, $this->righeCsv($soloCoda));
    }

    // ------------------------------------------------------------------
    // 23. Operatore limitato a una sede
    // ------------------------------------------------------------------

    public function test_operatore_limitato_a_una_sede_vede_e_modifica_solo_eventi_della_sua_sede_o_senza_sede(): void
    {
        $x = Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Sede Ovest']);
        $y = Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Sede Est']);

        $eventoX = $this->adminCreaEvento(['titolo' => 'Festa della sede ovest', 'sede_id' => $x->id]);
        $eventoY = $this->adminCreaEvento(['titolo' => 'Festa della sede est', 'sede_id' => $y->id]);
        $generale = $this->adminCreaEvento(['titolo' => 'Assemblea di tutti']);

        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $operatore->assignRole('operatore');
        $operatore->assegnaSedi([$x->id]);

        $this->comeAdmin($operatore)
            ->get(route('eventi.index'))
            ->assertOk()
            ->assertSee('Festa della sede ovest')
            ->assertSee('Assemblea di tutti')
            ->assertDontSee('Festa della sede est');

        // Evento di un'altra sede: fuori dal perimetro (non esiste per lui)
        $this->put(route('eventi.update', $eventoY), [
            'titolo' => 'Manomesso',
            'data_evento' => '2026-05-23T21:00',
            'sede_id' => $x->id,
        ])->assertNotFound();
        $this->assertSame('Festa della sede est', $eventoY->fresh()->titolo);

        // Evento di tutta l'associazione: visibile ma modificabile solo dagli admin
        $this->put(route('eventi.update', $generale), [
            'titolo' => 'Assemblea modificata',
            'data_evento' => '2026-05-23T21:00',
            'sede_id' => $x->id,
        ])->assertForbidden();
        $this->assertSame('Assemblea di tutti', $generale->fresh()->titolo);
        $this->assertNull($generale->fresh()->sede_id);

        // Evento della sua sede: modificabile, ma non spostabile nell'altra
        $this->put(route('eventi.update', $eventoX), [
            'titolo' => 'Festa della sede ovest — rinviata',
            'data_evento' => '2026-05-30T21:00',
            'sede_id' => $y->id,
        ])->assertSessionHasErrors(['sede_id' => 'Sede non valida.']);
        $this->assertSame($x->id, $eventoX->fresh()->sede_id);

        $this->put(route('eventi.update', $eventoX), [
            'titolo' => 'Festa della sede ovest — rinviata',
            'data_evento' => '2026-05-30T21:00',
            'sede_id' => $x->id,
        ])->assertSessionHasNoErrors()->assertRedirect(route('eventi.index'));
        $this->assertSame('Festa della sede ovest — rinviata', $eventoX->fresh()->titolo);
        $this->assertSame('2026-05-30 21:00', $eventoX->fresh()->data_evento->format('Y-m-d H:i'));

        // Nuovo evento: solo nella propria sede (e la sede è obbligatoria)
        $this->post(route('eventi.store'), $this->datiEvento(['titolo' => 'Evento in sede est', 'sede_id' => $y->id]))
            ->assertSessionHasErrors(['sede_id' => 'Sede non valida.']);
        $this->post(route('eventi.store'), $this->datiEvento(['titolo' => 'Evento senza sede']))
            ->assertSessionHasErrors('sede_id');
        $this->post(route('eventi.store'), $this->datiEvento(['titolo' => 'Evento in sede ovest', 'sede_id' => $x->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('eventi', ['titolo' => 'Evento in sede est']);
        $this->assertDatabaseMissing('eventi', ['titolo' => 'Evento senza sede']);
        $this->assertDatabaseHas('eventi', ['titolo' => 'Evento in sede ovest', 'sede_id' => $x->id]);
    }

    // ------------------------------------------------------------------
    // 24. Pagamento tardivo su prenotazione scaduta (overbooking)
    // ------------------------------------------------------------------

    public function test_prenotazione_pagata_dopo_la_scadenza_viene_riconfermata_e_conta_nei_posti_anche_oltre_capienza(): void
    {
        $this->configuraStripe();
        $stripe = $this->fakeStripe();

        $evento = $this->adminCreaEvento(['titolo' => 'Posto unico in barca a vela', 'capienza' => 1, 'prezzo' => '40']);
        $andrea = $this->visitatore('Andrea', 'Vela');
        $beatrice = $this->visitatore('Beatrice', 'Onda');
        $cesare = $this->visitatore('Cesare', 'Porto');

        // Andrea prenota e apre il checkout, ma il pagamento arriva tardi
        $pA = $this->prenota($andrea, $evento, 1);
        $this->post($this->urlPubblica('prenotazioni.checkout', ['prenotazione' => $pA->id, 'gateway' => 'stripe']))
            ->assertRedirect('https://checkout.stripe.test/sessione-1');

        $this->travel(31)->minutes();
        $this->assertSame(1, $this->servizio()->scadiPagamenti());
        $this->assertSame(Prenotazione::STATO_SCADUTA, $pA->fresh()->stato);

        // Dopo la scadenza Andrea non può avviare un nuovo checkout
        $this->comeAccount($andrea)
            ->post($this->urlPubblica('prenotazioni.checkout', ['prenotazione' => $pA->id, 'gateway' => 'stripe']))
            ->assertRedirect($this->urlPubblica('prenotazioni.paga', ['prenotazione' => $pA->id]));
        $this->assertCount(1, $stripe->checkout);

        // Beatrice prende il posto liberato e paga
        $pB = $this->prenota($beatrice, $evento, 1);
        $this->assertSame(Prenotazione::STATO_IN_ATTESA_PAGAMENTO, $pB->stato);
        $this->webhookPrenotazione($pB, 'pi_beatrice')->assertOk();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $pB->fresh()->stato);

        // Arriva il webhook del vecchio checkout di Andrea: il denaro è incassato
        $this->webhookPrenotazione($pA, 'pi_andrea_tardivo')->assertOk();
        $pA->refresh();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $pA->stato);
        $this->assertSame('pi_andrea_tardivo', $pA->gateway_transaction_id);
        Notification::assertSentTo($andrea, ConfermataNotification::class);
        $this->assertNotNull($this->movimentoDi($pA));

        // Overbooking accettato per design: 2 posti occupati su 1
        $this->assertSame(2, Prenotazione::postiOccupati($evento->id));
        $this->assertSame(0, $evento->fresh()->postiDisponibili());

        $tab = $this->comeAdmin()->get(route('eventi.prenotazioni.index', $evento))->assertOk();
        $this->assertSame(2, $tab->viewData('conteggi')['posti_confermati']);
        $this->assertSame(1, $tab->viewData('conteggi')['capienza']);

        // Chi arriva ora finisce in coda
        $pC = $this->prenota($cesare, $evento, 1);
        $this->assertSame(Prenotazione::STATO_LISTA_ATTESA, $pC->stato);

        // L'evento in overbooking resta modificabile dal form del pannello,
        // che reinvia sempre la capienza corrente (1): la capienza NON viene
        // ridotta, quindi la modifica del solo titolo deve essere accettata
        $this->comeAdmin()
            ->put(route('eventi.update', $evento), $this->datiEvento([
                'titolo' => 'Posto unico in barca a vela — orario confermato',
                'capienza' => 1,
                'prezzo' => '40',
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Posto unico in barca a vela — orario confermato', $evento->fresh()->titolo);
        $this->assertSame(1, $evento->fresh()->capienza);
    }

    // ------------------------------------------------------------------
    // 25. Isolamento tra account e tra associazioni
    // ------------------------------------------------------------------

    public function test_account_vede_solo_le_proprie_prenotazioni_e_lo_storico_e_non_quelle_di_altri_account_o_tenant(): void
    {
        $vicino = $this->adminCreaEvento([
            'titolo' => 'Merenda di primavera',
            'data_evento' => '2026-05-06T16:00',
            'data_fine' => '2026-05-06T18:00',
        ]);
        $lontano = $this->adminCreaEvento(['titolo' => 'Concerto di giugno', 'data_evento' => '2026-06-20T21:00', 'data_fine' => '2026-06-20T23:00']);

        $alice = $this->visitatore('Alice', 'Mia');
        $bob = $this->visitatore('Bob', 'Altro');

        $pAlice = $this->prenota($alice, $vicino, 1);
        $pBob = $this->prenota($bob, $vicino, 1);

        // Prenotazione di un account dell'altra associazione
        $eventoAltrui = Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->altraAssociazione->id,
            'data_evento' => '2026-05-25 21:00:00',
        ]);
        $carlo = $this->visitatore('Carlo', 'Estero', $this->altraAssociazione);
        $pCarlo = Prenotazione::factory()->create(['evento_id' => $eventoAltrui->id, 'account_id' => $carlo->id]);

        $indice = $this->comeAccount($alice)
            ->get($this->urlPubblica('account.prenotazioni.index'))
            ->assertOk()
            ->assertSee($pAlice->codice)
            ->assertDontSee($pBob->codice)
            ->assertDontSee($pCarlo->codice);
        $this->assertSame(1, $indice->viewData('prenotazioni')->total());

        $this->get($this->urlPubblica('account.prenotazioni.show', ['prenotazione' => $pBob->id]))->assertForbidden();
        $this->get($this->urlPubblica('account.prenotazioni.biglietto', ['prenotazione' => $pBob->id]))->assertForbidden();
        $this->post($this->urlPubblica('account.prenotazioni.annulla', ['prenotazione' => $pBob->id]))->assertForbidden();
        $this->get($this->urlPubblica('prenotazioni.esito', ['prenotazione' => $pBob->id]))->assertForbidden();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $pBob->fresh()->stato);

        $this->get($this->urlPubblica('account.prenotazioni.show', ['prenotazione' => $pCarlo->id]))->assertNotFound();
        $this->get($this->urlPubblica('account.prenotazioni.biglietto', ['prenotazione' => $pCarlo->id]))->assertNotFound();
        $this->post($this->urlPubblica('account.prenotazioni.annulla', ['prenotazione' => $pCarlo->id]))->assertNotFound();
        $this->post($this->urlPubblica('eventi.prenota', ['evento' => $eventoAltrui->id]), ['posti' => 1])->assertNotFound();
        $this->assertSame(Prenotazione::STATO_CONFERMATA, $pCarlo->fresh()->stato);

        // Alice prenota anche il concerto di giugno; poi la merenda passa
        $pAliceGiugno = $this->prenota($alice, $lontano, 1);
        Carbon::setTestNow('2026-05-07 10:00:00');

        $storico = $this->comeAccount($alice)
            ->get($this->urlPubblica('account.storico'))
            ->assertOk()
            ->viewData('storico');
        $this->assertSame([$pAlice->id], $storico->pluck('id')->all());

        $indice = $this->get($this->urlPubblica('account.prenotazioni.index'))->assertOk();
        $this->assertEqualsCanonicalizing([$pAlice->id, $pAliceGiugno->id], collect($indice->viewData('prenotazioni')->items())->pluck('id')->all());

        // Lo scanner dell'admin non trova biglietti dell'altra associazione
        $this->comeAdmin()
            ->postJson(route('biglietti.checkin'), ['qr_token' => $pCarlo->qr_token])
            ->assertNotFound()
            ->assertJson(['code' => 'biglietto_not_found']);
        $this->assertNull($pCarlo->fresh()->checkin_at);
    }
}
