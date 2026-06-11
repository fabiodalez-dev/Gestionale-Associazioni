<?php

namespace Tests\Feature\Iscrizioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\RichiestaIscrizione;
use App\Models\Utente;
use App\Notifications\Iscrizioni\RichiestaIscrizioneRicevutaNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Notifica RichiestaIscrizioneRicevutaNotification (PIANO-ISCRIZIONI
 * §Notifiche): l'invio del form pubblico /a/{slug}/iscriviti avvisa gli
 * amministratori ATTIVI del tenant (database + mail, ShouldQueue,
 * best-effort fuori transazione). Le notifiche all'account
 * (IscrizioneApprovata/Rifiutata) sono coperte da
 * RichiesteIscrizioneAdminTest; quelle prenotazioni da
 * ApprovazionePrenotazioniTest e NotifichePrenotazioniTest.
 */
class NotificheIscrizioniTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Account $account;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['honeypot.enabled' => false]);

        $this->associazione = Associazione::factory()->create([
            'frontend_pubblico_attivo' => true,
        ]);

        $this->account = Account::factory()->verificato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    private function urlTenant(string $path = ''): string
    {
        return '/a/'.$this->associazione->slug.($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    /**
     * @return array<string, mixed> input valido per il form di default
     */
    private function datiValidi(): array
    {
        return [
            'telefono' => '3331234567',
            'codice_fiscale' => 'RSSMRA85T10A562S',
            'data_nascita' => '1990-05-10',
            'citta' => 'Milano',
        ];
    }

    private function inviaRichiesta(): void
    {
        $this->actingAs($this->account, 'account')
            ->post($this->urlTenant('iscriviti'), $this->datiValidi())
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    // ------------------------------------------------------------------
    // Invio agli admin del tenant
    // ------------------------------------------------------------------

    public function test_invio_form_notifica_gli_admin_attivi_del_tenant(): void
    {
        $this->inviaRichiesta();

        Notification::assertSentTo(
            $this->admin,
            RichiestaIscrizioneRicevutaNotification::class,
            function (RichiestaIscrizioneRicevutaNotification $notifica): bool {
                return $notifica->richiesta->account_id === $this->account->id
                    && $notifica->richiesta->isInAttesa();
            }
        );
    }

    public function test_admin_disattivato_non_riceve_la_notifica(): void
    {
        $disattivato = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
            'attivo' => false,
        ]);

        $this->inviaRichiesta();

        Notification::assertSentTo($this->admin, RichiestaIscrizioneRicevutaNotification::class);
        Notification::assertNotSentTo($disattivato, RichiestaIscrizioneRicevutaNotification::class);
    }

    public function test_admin_di_altro_tenant_non_riceve_la_notifica(): void
    {
        $altraAssociazione = Associazione::factory()->create();
        $adminEstraneo = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $altraAssociazione->id,
        ]);

        $this->inviaRichiesta();

        Notification::assertNotSentTo($adminEstraneo, RichiestaIscrizioneRicevutaNotification::class);
    }

    public function test_modalita_diretta_notifica_comunque_gli_admin(): void
    {
        $this->associazione->update([
            'modalita_iscrizione' => Associazione::MODALITA_DIRETTA,
        ]);

        $this->inviaRichiesta();

        Notification::assertSentTo(
            $this->admin,
            RichiestaIscrizioneRicevutaNotification::class,
            fn (RichiestaIscrizioneRicevutaNotification $notifica): bool => $notifica->richiesta->isApprovata()
        );
    }

    // ------------------------------------------------------------------
    // Contratto della notifica (pattern Prenotazioni: ShouldQueue,
    // database + mail per tenant)
    // ------------------------------------------------------------------

    public function test_notifica_e_accodata_su_canali_database_e_mail(): void
    {
        $this->inviaRichiesta();

        $richiesta = RichiestaIscrizione::allTenants()
            ->where('account_id', $this->account->id)
            ->sole();

        $notifica = new RichiestaIscrizioneRicevutaNotification($richiesta);

        $this->assertInstanceOf(ShouldQueue::class, $notifica);
        $this->assertSame(['database', 'mail'], $notifica->via($this->admin));
    }

    public function test_payload_database_e_mail_contengono_i_dati_della_richiesta(): void
    {
        $this->inviaRichiesta();

        $richiesta = RichiestaIscrizione::allTenants()
            ->where('account_id', $this->account->id)
            ->sole();

        $notifica = new RichiestaIscrizioneRicevutaNotification($richiesta);

        $payload = $notifica->toArray($this->admin);
        $this->assertSame('richiesta_iscrizione_ricevuta', $payload['tipo']);
        $this->assertSame($richiesta->id, $payload['richiesta_id']);
        $this->assertSame($this->account->id, $payload['account_id']);
        $this->assertSame($richiesta->socio_id, $payload['socio_id']);
        $this->assertSame($this->account->email, $payload['email']);

        $mail = $notifica->toMail($this->admin);
        $this->assertSame('Nuova richiesta di iscrizione socio', $mail->subject);
        $this->assertSame(route('richieste-iscrizione.index'), $mail->actionUrl);
    }
}
