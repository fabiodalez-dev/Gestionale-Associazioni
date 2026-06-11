<?php

namespace Tests\Feature\Prenotazioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Utente;
use App\Notifications\Accounts\BenvenutoAccount;
use App\Notifications\Prenotazioni\AnnullataNotification;
use App\Notifications\Prenotazioni\ConfermataNotification;
use App\Notifications\Prenotazioni\EventoEsauritoNotification;
use App\Notifications\Prenotazioni\InAttesaPagamentoNotification;
use App\Notifications\Prenotazioni\InListaAttesaNotification;
use App\Notifications\Prenotazioni\PagamentoScadutoNotification;
use App\Notifications\Prenotazioni\PromemoriaEventoNotification;
use App\Notifications\Prenotazioni\PromozioneListaAttesaNotification;
use App\Services\Prenotazioni\PrenotazioneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Notifiche del modulo prenotazioni (fase Rifiniture): invio nei punti
 * chiave della macchina a stati (conferma, attesa pagamento, lista
 * d'attesa, promozione, scadenza, annullo, promemoria), avvisi agli
 * admin del tenant (evento esaurito) e benvenuto post-verifica email.
 *
 * Si usa il PrenotazioneService REALE (non la Spia): qui si verifica
 * che gli hook siano collegati alle Notification vere.
 */
class NotifichePrenotazioniTest extends TestCase
{
    use RefreshDatabase;

    protected PrenotazioneService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->service = new PrenotazioneService;
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    protected function creaAccount(Evento $evento, array $attributi = []): Account
    {
        return Account::factory()->create(array_merge(
            ['associazione_id' => $evento->associazione_id],
            $attributi,
        ));
    }

    // ------------------------------------------------------------------
    // Creazione
    // ------------------------------------------------------------------

    public function test_conferma_gratuita_invia_confermata_notification(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create();
        $account = $this->creaAccount($evento);

        $prenotazione = $this->service->crea($account, $evento, 2);

        Notification::assertSentTo(
            $account,
            ConfermataNotification::class,
            fn (ConfermataNotification $n) => $n->prenotazione->id === $prenotazione->id
                && $n->via($account) === ['database', 'mail']
        );
    }

    public function test_prenotazione_a_pagamento_invia_in_attesa_pagamento_notification(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->aPagamento(12.50)->create();
        $account = $this->creaAccount($evento);

        $prenotazione = $this->service->crea($account, $evento, 2);

        Notification::assertSentTo(
            $account,
            InAttesaPagamentoNotification::class,
            fn (InAttesaPagamentoNotification $n) => $n->prenotazione->id === $prenotazione->id
                && $n->toArray($account)['scadenza_pagamento_at'] !== null
        );
        Notification::assertNotSentTo($account, ConfermataNotification::class);
    }

    public function test_evento_pieno_invia_in_lista_attesa_notification_con_posizione(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(1)->create();
        $this->service->crea($this->creaAccount($evento), $evento, 1);

        $inCoda = $this->creaAccount($evento);
        $this->service->crea($inCoda, $evento, 1);

        Notification::assertSentTo(
            $inCoda,
            InListaAttesaNotification::class,
            fn (InListaAttesaNotification $n) => $n->toArray($inCoda)['posizione_coda'] === 1
        );
    }

    // ------------------------------------------------------------------
    // Evento esaurito (admin del tenant)
    // ------------------------------------------------------------------

    public function test_capienza_raggiunta_notifica_gli_admin_attivi_del_tenant(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(2)->create();

        $adminAttivo = Utente::factory()->adminAssociazione()
            ->create(['associazione_id' => $evento->associazione_id]);
        $adminDisattivo = Utente::factory()->adminAssociazione()->disattivo()
            ->create(['associazione_id' => $evento->associazione_id]);
        $operatore = Utente::factory()->operatore()
            ->create(['associazione_id' => $evento->associazione_id]);
        $adminAltroTenant = Utente::factory()->adminAssociazione()->create();

        // Consuma esattamente gli ultimi posti → evento esaurito
        $this->service->crea($this->creaAccount($evento), $evento, 2);

        Notification::assertSentTo(
            $adminAttivo,
            EventoEsauritoNotification::class,
            fn (EventoEsauritoNotification $n) => $n->evento->id === $evento->id
        );
        Notification::assertNotSentTo($adminDisattivo, EventoEsauritoNotification::class);
        Notification::assertNotSentTo($operatore, EventoEsauritoNotification::class);
        Notification::assertNotSentTo($adminAltroTenant, EventoEsauritoNotification::class);
    }

    public function test_prenotazione_che_non_esaurisce_non_notifica_gli_admin(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(5)->create();
        $admin = Utente::factory()->adminAssociazione()
            ->create(['associazione_id' => $evento->associazione_id]);

        $this->service->crea($this->creaAccount($evento), $evento, 2);

        Notification::assertNotSentTo($admin, EventoEsauritoNotification::class);
    }

    // ------------------------------------------------------------------
    // Annullamento → promozione FIFO
    // ------------------------------------------------------------------

    public function test_annullo_invia_annullata_e_promozione_al_primo_in_coda(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(1)->create([
            'data_evento' => now()->addMonth(),
        ]);

        $titolare = $this->creaAccount($evento);
        $prenotazione = $this->service->crea($titolare, $evento, 1);

        $inCoda = $this->creaAccount($evento);
        $prenotazioneCoda = $this->service->crea($inCoda, $evento, 1);

        $this->service->annulla($prenotazione, $titolare);

        Notification::assertSentTo(
            $titolare,
            AnnullataNotification::class,
            fn (AnnullataNotification $n) => $n->prenotazione->id === $prenotazione->id
        );

        // Promosso (gratuito → confermata) con la notifica di promozione
        Notification::assertSentTo(
            $inCoda,
            PromozioneListaAttesaNotification::class,
            fn (PromozioneListaAttesaNotification $n) => $n->prenotazione->id === $prenotazioneCoda->id
                && $n->prenotazione->stato === Prenotazione::STATO_CONFERMATA
        );
    }

    // ------------------------------------------------------------------
    // Scadenza pagamento (scheduler)
    // ------------------------------------------------------------------

    public function test_scadenza_pagamento_invia_pagamento_scaduto_notification(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->aPagamento(15.00)->create();
        $account = $this->creaAccount($evento);
        $prenotazione = $this->service->crea($account, $evento, 1);

        $this->travel(31)->minutes();

        $scadute = $this->service->scadiPagamenti();

        $this->assertSame(1, $scadute);
        Notification::assertSentTo(
            $account,
            PagamentoScadutoNotification::class,
            fn (PagamentoScadutoNotification $n) => $n->prenotazione->id === $prenotazione->id
        );
    }

    public function test_account_disattivato_non_riceve_notifiche(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->aPagamento(15.00)->create();
        $account = $this->creaAccount($evento);
        $this->service->crea($account, $evento, 1);

        // L'account viene disattivato mentre la finestra di pagamento scorre
        $account->forceFill(['attivo' => false])->save();
        $this->travel(31)->minutes();

        $this->service->scadiPagamenti();

        Notification::assertNotSentTo($account, PagamentoScadutoNotification::class);
    }

    // ------------------------------------------------------------------
    // Promemoria eventi (comando giornaliero)
    // ------------------------------------------------------------------

    public function test_comando_promemoria_notifica_le_confermate_con_preferenza_attiva(): void
    {
        $evento = Evento::factory()->pubblicato()->conCapienza(10)->create([
            'data_evento' => now()->addHours(30),
        ]);

        $conPreferenza = $this->creaAccount($evento); // default: promemoria attivo
        $prenotazione = $this->service->crea($conPreferenza, $evento, 1);

        $senzaPreferenza = $this->creaAccount($evento, [
            'preferenze' => ['promemoria_eventi' => false],
        ]);
        $this->service->crea($senzaPreferenza, $evento, 1);

        $this->artisan('prenotazioni:promemoria-eventi')
            ->expectsOutputToContain('Promemoria inviati: 1')
            ->assertSuccessful();

        Notification::assertSentTo(
            $conPreferenza,
            PromemoriaEventoNotification::class,
            fn (PromemoriaEventoNotification $n) => $n->prenotazione->id === $prenotazione->id
        );
        Notification::assertNotSentTo($senzaPreferenza, PromemoriaEventoNotification::class);
    }

    // ------------------------------------------------------------------
    // Benvenuto post-verifica email
    // ------------------------------------------------------------------

    public function test_verifica_email_invia_benvenuto_account(): void
    {
        $associazione = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);

        /** @var Account $account */
        $account = Account::factory()->nonVerificato()->create([
            'associazione_id' => $associazione->id,
        ]);

        $url = URL::temporarySignedRoute(
            'pubblico.verifica-email.verifica',
            now()->addMinutes(60),
            [
                'associazione' => $associazione->slug,
                'id' => $account->id,
                'hash' => sha1($account->email),
            ]
        );

        $this->get($url)->assertRedirect();

        Notification::assertSentTo($account, BenvenutoAccount::class);

        // Seconda visita (già verificato): nessun doppio benvenuto
        $this->get($url);
        Notification::assertSentToTimes($account, BenvenutoAccount::class, 1);
    }
}
