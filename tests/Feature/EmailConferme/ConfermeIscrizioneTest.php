<?php

namespace Tests\Feature\EmailConferme;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\RichiestaIscrizione;
use App\Models\Tessera;
use App\Notifications\Iscrizioni\ConfermaRichiestaInviataNotification;
use App\Notifications\Iscrizioni\IscrizioneApprovataNotification;
use App\Services\Iscrizioni\ApprovazioneIscrizioneService;
use Illuminate\Support\Facades\Notification;
use Spatie\Honeypot\ProtectAgainstSpam;

/**
 * f.19 — Conferme iscrizione complete: email immediata "richiesta ricevuta"
 * al richiedente (account o ospite) con riepilogo dei dati inviati;
 * all'approvazione email "benvenuto socio" con PDF tessera allegato
 * e prossimi passi.
 */
class ConfermeIscrizioneTest extends EmailConfermeTestCase
{
    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['honeypot.enabled' => false]);
        $this->withoutMiddleware(ProtectAgainstSpam::class);

        $this->associazione->update([
            'frontend_pubblico_attivo' => true,
            'iscrizioni_socio_aperte' => true,
            'modalita_iscrizione' => Associazione::MODALITA_PREISCRIZIONE,
        ]);

        $this->account = Account::factory()->verificato()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    private function urlIscriviti(): string
    {
        return '/a/'.$this->associazione->slug.'/iscriviti';
    }

    /** @return array<string, mixed> */
    private function datiAccount(): array
    {
        return [
            'telefono' => '3331234567',
            'codice_fiscale' => 'RSSMRA85T10A562S',
            'data_nascita' => '1990-05-10',
            'citta' => 'Milano',
        ];
    }

    // ------------------------------------------------------------------
    // Conferma "richiesta ricevuta"
    // ------------------------------------------------------------------

    public function test_account_riceve_la_conferma_con_riepilogo_dati(): void
    {
        $this->actingAs($this->account, 'account')
            ->post($this->urlIscriviti(), $this->datiAccount())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        Notification::assertSentTo(
            $this->account,
            ConfermaRichiestaInviataNotification::class,
            function (ConfermaRichiestaInviataNotification $notifica): bool {
                $riepilogo = implode(' | ', $notifica->riepilogo());

                return str_contains($riepilogo, 'Telefono: 3331234567')
                    && str_contains($riepilogo, 'Codice fiscale: RSSMRA85T10A562S')
                    && str_contains($riepilogo, 'Città: Milano')
                    && str_contains($riepilogo, 'Email: '.$this->account->email);
            }
        );
    }

    public function test_la_mail_di_conferma_contiene_il_riepilogo_e_lo_stato_in_attesa(): void
    {
        $this->actingAs($this->account, 'account')
            ->post($this->urlIscriviti(), $this->datiAccount())
            ->assertSessionHasNoErrors();

        $richiesta = RichiestaIscrizione::allTenants()
            ->where('account_id', $this->account->id)
            ->sole();

        $notifica = new ConfermaRichiestaInviataNotification($richiesta);

        $this->assertSame(['database', 'mail'], $notifica->via($this->account));

        $mail = $notifica->toMail($this->account);
        $this->assertStringContainsString('Richiesta di iscrizione ricevuta', (string) $mail->subject);

        $testo = implode(' | ', $mail->introLines);
        $this->assertStringContainsString('Riepilogo dei dati inviati', $testo);
        $this->assertStringContainsString('Telefono: 3331234567', $testo);
        $this->assertStringContainsString('valuterà', $testo);
    }

    public function test_ospite_riceve_la_conferma_via_mail_on_demand(): void
    {
        $this->post($this->urlIscriviti(), [
            'nome' => 'Giulia',
            'cognome' => 'Neri',
            'email' => 'giulia.neri@example.com',
            'telefono' => '3330000001',
            'data_nascita' => '1992-03-15',
            'privacy' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        Notification::assertSentOnDemand(
            ConfermaRichiestaInviataNotification::class,
            function (ConfermaRichiestaInviataNotification $notifica, array $channels, object $notifiable): bool {
                return ($notifiable->routes['mail'] ?? null) === 'giulia.neri@example.com'
                    && $channels === ['mail'];
            }
        );
    }

    public function test_la_conferma_non_viene_inviata_ad_altri_account(): void
    {
        $estraneo = Account::factory()->verificato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->post($this->urlIscriviti(), $this->datiAccount())
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($this->account, ConfermaRichiestaInviataNotification::class);
        Notification::assertNotSentTo($estraneo, ConfermaRichiestaInviataNotification::class);
    }

    // ------------------------------------------------------------------
    // Benvenuto socio all'approvazione (PDF tessera + prossimi passi)
    // ------------------------------------------------------------------

    public function test_approvazione_con_tessera_invia_il_benvenuto_con_tessera_id(): void
    {
        $this->actingAs($this->account, 'account')
            ->post($this->urlIscriviti(), $this->datiAccount())
            ->assertSessionHasNoErrors();

        $richiesta = RichiestaIscrizione::allTenants()
            ->where('account_id', $this->account->id)
            ->sole();

        app(ApprovazioneIscrizioneService::class)
            ->approva($richiesta, $this->admin, generaTessera: true);

        Notification::assertSentTo(
            $this->account,
            IscrizioneApprovataNotification::class,
            fn (IscrizioneApprovataNotification $n): bool => filled($n->tesseraId) && filled($n->numeroTessera)
        );
    }

    public function test_il_benvenuto_allega_il_pdf_tessera_e_indica_i_prossimi_passi(): void
    {
        $this->actingAs($this->account, 'account')
            ->post($this->urlIscriviti(), $this->datiAccount())
            ->assertSessionHasNoErrors();

        $richiesta = RichiestaIscrizione::allTenants()
            ->where('account_id', $this->account->id)
            ->sole();

        app(ApprovazioneIscrizioneService::class)
            ->approva($richiesta, $this->admin, generaTessera: true);

        $tessera = Tessera::allTenants()
            ->where('socio_id', $richiesta->fresh()->socio_id)
            ->sole();

        $mail = (new IscrizioneApprovataNotification($richiesta->fresh(), $tessera->numero_tessera, $tessera->id))
            ->toMail($this->account);

        $this->assertNotEmpty($mail->rawAttachments, 'Il PDF della tessera deve essere allegato');
        $this->assertSame('tessera-'.$tessera->numero_tessera.'.pdf', $mail->rawAttachments[0]['name']);
        $this->assertStringContainsString('%PDF', substr((string) $mail->rawAttachments[0]['data'], 0, 8));

        $testo = implode(' | ', array_merge($mail->introLines, $mail->outroLines));
        $this->assertStringContainsString('Prossimi passi', $testo);
    }

    public function test_il_benvenuto_senza_tessera_non_ha_allegati(): void
    {
        $this->actingAs($this->account, 'account')
            ->post($this->urlIscriviti(), $this->datiAccount())
            ->assertSessionHasNoErrors();

        $richiesta = RichiestaIscrizione::allTenants()
            ->where('account_id', $this->account->id)
            ->sole();

        app(ApprovazioneIscrizioneService::class)
            ->approva($richiesta, $this->admin, generaTessera: false);

        Notification::assertSentTo(
            $this->account,
            IscrizioneApprovataNotification::class,
            fn (IscrizioneApprovataNotification $n): bool => $n->tesseraId === null
        );

        $mail = (new IscrizioneApprovataNotification($richiesta->fresh()))->toMail($this->account);
        $this->assertEmpty($mail->rawAttachments);
    }
}
