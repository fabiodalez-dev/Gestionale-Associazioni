<?php

namespace Tests\Feature\Sicurezza;

use App\Actions\Fortify\ResetUserPassword;
use App\Mail\AreaSoci\ImpostaPasswordSocioMail;
use App\Models\Account;
use App\Models\Associazione;
use App\Models\DocumentoSocio;
use App\Models\Nucleo;
use App\Models\PaymentGatewaySetting;
use App\Models\PrenotazioneSpazio;
use App\Models\RichiestaIscrizione;
use App\Models\Sede;
use App\Models\SmtpSetting;
use App\Models\Socio;
use App\Models\Spazio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Notifications\Piattaforma\TentativiAccessoFallitiNotification;
use App\Services\Email\TenantNotificationMailer;
use App\Services\Pagamenti\Gateways\PayPalGateway;
use App\Services\Tessere\TesseraQrService;
use App\Support\TestoMarkdown;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Srmklive\PayPal\Services\PayPal as PayPalClient;
use Tests\TestCase;

/**
 * Regressioni dell'audit di sicurezza run-3: perimetro per sede, autorità sui
 * documenti dei soci, revoca di sessioni e token, limiti sulle mail pubbliche,
 * pulizia dei documenti all'eliminazione definitiva.
 */
class AuditRun3Test extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Sede $padova;

    private Sede $vicenza;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
        $this->associazione = Associazione::factory()->create(['frontend_pubblico_attivo' => true, 'slug' => 'audit-r3']);
        $this->padova = Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Padova']);
        $this->vicenza = Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Vicenza']);
    }

    private function staff(array $ruoli, ?Sede $sede = null): Utente
    {
        $utente = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $utente->syncRoles($ruoli);
        if ($sede !== null) {
            $utente->assegnaSedi([$sede->id]);
        }

        return $utente;
    }

    private function admin(): Utente
    {
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $admin->assignRole('admin_associazione');

        return $admin;
    }

    private function come(Utente $utente): self
    {
        return $this->actingAs($utente)->withSession(['associazione_id' => $this->associazione->id]);
    }

    private function socio(?Sede $sede = null, array $extra = []): Socio
    {
        return Socio::factory()->create(array_merge([
            'associazione_id' => $this->associazione->id,
            'sede_id' => $sede?->id,
            'stato' => Socio::STATO_ATTIVO,
        ], $extra));
    }

    /**
     * Ripristina lo stato del processo tra due richieste: guard rilette dal database
     * e guard di default tornata a "web" (in produzione ogni richiesta parte da zero).
     */
    private function nuovaRichiesta(): void
    {
        auth()->forgetGuards();
        auth()->shouldUse('web');
    }

    // ── Perimetro per sede ──────────────────────────────────────────

    public function test_prenotazioni_spazi_rispettano_il_perimetro_di_sede(): void
    {
        $spazioPadova = Spazio::factory()->create(['associazione_id' => $this->associazione->id, 'sede_id' => $this->padova->id]);
        $spazioVicenza = Spazio::factory()->create(['associazione_id' => $this->associazione->id, 'sede_id' => $this->vicenza->id]);
        $dati = fn (Spazio $s, string $titolo) => [
            'associazione_id' => $this->associazione->id, 'spazio_id' => $s->id, 'titolo' => $titolo,
            'dal' => now()->addDay(), 'al' => now()->addDay()->addHour(),
        ];
        $mia = PrenotazioneSpazio::create($dati($spazioPadova, 'Riunione di Padova'));
        $altrui = PrenotazioneSpazio::create($dati($spazioVicenza, 'Corso riservato di Vicenza'));

        $responsabile = $this->staff(['responsabile_sede'], $this->padova);

        $this->come($responsabile)->get('/spazi/prenotazioni')
            ->assertOk()
            ->assertSee('Riunione di Padova')
            ->assertDontSee('Corso riservato di Vicenza');

        $this->come($responsabile)->put('/spazi/prenotazioni/'.$altrui->id, [
            'spazio_id' => $spazioVicenza->id, 'titolo' => 'Manomessa',
            'dal' => now()->addDays(3)->format('Y-m-d H:i'), 'al' => now()->addDays(3)->addHour()->format('Y-m-d H:i'),
        ])->assertNotFound();
        $this->assertSame('Corso riservato di Vicenza', $altrui->fresh()->titolo);

        $this->come($responsabile)->post('/spazi/prenotazioni', [
            'spazio_id' => $spazioVicenza->id, 'titolo' => 'Intrusa',
            'dal' => now()->addDays(5)->format('Y-m-d H:i'), 'al' => now()->addDays(5)->addHour()->format('Y-m-d H:i'),
        ])->assertSessionHasErrors('spazio_id');
        $this->assertDatabaseMissing('prenotazioni_spazi', ['titolo' => 'Intrusa']);

        // La propria sede continua a funzionare
        $this->assertNotNull(PrenotazioneSpazio::query()->find($mia->id));
    }

    public function test_registro_modifiche_non_mostra_le_altre_sedi_a_chi_e_limitato(): void
    {
        $this->socio($this->padova, ['cognome' => 'Padovanissimo']);
        $this->socio($this->vicenza, ['cognome' => 'Vicentinissimo']);

        $segretario = $this->staff(['presidente'], $this->padova);

        $this->come($segretario)->get('/registro-modifiche?modello=Socio')
            ->assertOk()
            ->assertSee('Padovanissimo')
            ->assertDontSee('Vicentinissimo');

        // Chi vede tutte le sedi le vede entrambe
        $this->come($this->admin())->get('/registro-modifiche?modello=Socio')
            ->assertOk()
            ->assertSee('Padovanissimo')
            ->assertSee('Vicentinissimo');
    }

    public function test_sciogliere_un_nucleo_con_soci_di_altre_sedi_e_riservato_a_chi_le_vede_tutte(): void
    {
        $nucleo = Nucleo::factory()->create(['associazione_id' => $this->associazione->id]);
        $a = $this->socio($this->padova, ['nucleo_id' => $nucleo->id]);
        $b = $this->socio($this->vicenza, ['nucleo_id' => $nucleo->id]);

        $this->come($this->staff(['responsabile_sede'], $this->padova))
            ->from('/soci-nuclei')
            ->delete('/nuclei/'.$nucleo->id)
            ->assertSessionHasErrors('nucleo');

        $this->assertNotNull(Nucleo::query()->find($nucleo->id));
        $this->assertSame($nucleo->id, Socio::withoutGlobalScopes()->find($b->id)->nucleo_id);

        $this->come($this->admin())->delete('/nuclei/'.$nucleo->id)->assertSessionHasNoErrors();
        $this->assertNull(Socio::withoutGlobalScopes()->find($a->id)->nucleo_id);
        $this->assertNull(Socio::withoutGlobalScopes()->find($b->id)->nucleo_id);
    }

    public function test_un_nucleo_con_un_socio_nel_cestino_si_scioglie_comunque(): void
    {
        $nucleo = Nucleo::factory()->create(['associazione_id' => $this->associazione->id]);
        $this->socio($this->padova, ['nucleo_id' => $nucleo->id]);
        $cestinato = $this->socio($this->padova, ['nucleo_id' => $nucleo->id]);
        $cestinato->delete();

        $this->come($this->admin())->delete('/nuclei/'.$nucleo->id)->assertSessionHasNoErrors();
        $this->assertNull(Nucleo::query()->find($nucleo->id));
        $this->assertNull(Socio::withTrashed()->find($cestinato->id)->nucleo_id);
    }

    // ── Documenti dei soci ──────────────────────────────────────────

    private function documento(Socio $socio): DocumentoSocio
    {
        return DocumentoSocio::create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $socio->id,
            'nome_file' => 'certificato.pdf',
            'percorso_file' => 'documenti-soci/certificato.pdf',
        ]);
    }

    public function test_i_documenti_dei_soci_richiedono_anche_l_autorita_sui_soci(): void
    {
        $socio = $this->socio();
        $this->documento($socio);

        // Il volontario ha documenti.view ma non soci.view: non vede il fascicolo
        $volontario = $this->staff(['volontario']);
        $this->assertTrue($volontario->can('documenti.view'));
        $this->assertFalse($volontario->can('soci.view'));
        $this->come($volontario)->get(route('documenti-socio.index'))->assertForbidden();

        // Chi ha entrambi i permessi continua a vedere l'elenco
        $this->come($this->admin())->get(route('documenti-socio.index'))->assertOk()->assertSee('certificato.pdf');

        // Il tesoriere legge i soci ma non li modifica: non aggiunge documenti
        $this->come($this->staff(['tesoriere']))
            ->post(route('documenti-socio.store'), ['socio_id' => $socio->id])
            ->assertForbidden();
    }

    public function test_eliminare_un_documento_dalla_scheda_socio_richiede_documenti_delete(): void
    {
        $socio = $this->socio();
        $documento = $this->documento($socio);

        // L'operatore modifica i soci ma non ha documenti.delete
        $operatore = $this->staff(['operatore']);
        $this->assertTrue($operatore->can('soci.update'));
        $this->assertFalse($operatore->can('documenti.delete'));

        $this->come($operatore)->delete('/soci/'.$socio->id.'/documenti/'.$documento->id)->assertForbidden();
        $this->assertNull($documento->fresh()->deleted_at);

        $this->come($this->admin())->delete('/soci/'.$socio->id.'/documenti/'.$documento->id)->assertRedirect();
        $this->assertNotNull(DocumentoSocio::withTrashed()->find($documento->id)->deleted_at);
    }

    public function test_eliminazione_definitiva_del_socio_porta_via_documenti_e_file(): void
    {
        Storage::fake('local');
        $socio = $this->socio();
        $documento = $this->documento($socio);
        $documento->addMediaFromString('contenuto')->usingFileName('certificato.pdf')->toMediaCollection(DocumentoSocio::COLLEZIONE_FILE);
        $this->assertDatabaseHas('media', ['model_type' => DocumentoSocio::class, 'model_id' => $documento->id]);

        $socio->delete();
        $socio->forceDelete();

        $this->assertDatabaseMissing('documenti_socio', ['id' => $documento->id]);
        $this->assertDatabaseMissing('media', ['model_type' => DocumentoSocio::class, 'model_id' => $documento->id]);
    }

    public function test_cestino_svuota_elimina_anche_i_documenti_soci_scaduti(): void
    {
        $socio = $this->socio();
        $vecchio = $this->documento($socio);
        $recente = $this->documento($socio);
        $vecchio->delete();
        $recente->delete();
        DocumentoSocio::withTrashed()->whereKey($vecchio->id)->update(['deleted_at' => now()->subDays(120)]);

        $this->artisan('cestino:svuota')->assertSuccessful();

        $this->assertNull(DocumentoSocio::withTrashed()->find($vecchio->id));
        $this->assertNotNull(DocumentoSocio::withTrashed()->find($recente->id));
    }

    // ── Sessioni e token ────────────────────────────────────────────

    public function test_i_token_api_non_sopravvivono_al_recupero_password_ne_alla_revoca(): void
    {
        $utente = $this->admin();
        $utente->createToken('uno', ['soci:read']);
        $this->assertSame(1, $utente->tokens()->count());

        app(ResetUserPassword::class)->reset($utente, [
            'password' => 'Nuova-Password-Sicura-2026!',
            'password_confirmation' => 'Nuova-Password-Sicura-2026!',
        ]);
        $this->assertSame(0, $utente->fresh()->tokens()->count());

        $utente->createToken('due', ['soci:read']);
        $utente->fresh()->revocaSessioni();
        $this->assertSame(0, $utente->fresh()->tokens()->count());
    }

    public function test_la_sessione_di_un_account_non_sopravvive_al_cambio_di_password_o_alla_verifica(): void
    {
        $password = 'Password-Prova-2026!';
        $account = Account::factory()->nonVerificato()->create([
            'associazione_id' => $this->associazione->id, 'password' => $password,
        ]);
        $base = '/a/'.$this->associazione->slug;

        // Chi ha registrato l'indirizzo senza possederlo accede e non fa altro
        $this->post($base.'/login', ['email' => $account->email, 'password' => $password]);
        $this->assertAuthenticatedAs($account, 'account');

        // Il vero titolare verifica l'indirizzo: la sessione precedente decade
        $account->forceFill(['email_verified_at' => now()])->save();
        $this->nuovaRichiesta(); // come tra due richieste reali: l'utente si rilegge dal database
        $this->get($base.'/account')->assertRedirect($base.'/login');

        // Lo stesso vale per la reimpostazione della password
        $this->flushSession();
        $this->post($base.'/login', ['email' => $account->email, 'password' => $password]);
        $this->get($base.'/account')->assertOk();
        $account->forceFill(['password' => 'Nuova-Password-Sicura-2026!'])->save();
        $this->nuovaRichiesta();
        $this->get($base.'/account')->assertRedirect($base.'/login');
    }

    public function test_il_cookie_ricordami_dell_account_non_sopravvive_a_password_e_verifica(): void
    {
        $account = Account::factory()->nonVerificato()->create(['associazione_id' => $this->associazione->id]);
        $account->forceFill(['remember_token' => 'token-emesso-prima'])->save();
        $this->assertSame('token-emesso-prima', $account->fresh()->remember_token);

        $account->forceFill(['email_verified_at' => now()])->save();
        $dopoVerifica = $account->fresh()->remember_token;
        $this->assertNotSame('token-emesso-prima', $dopoVerifica);

        $account->forceFill(['password' => 'Nuova-Password-Sicura-2026!'])->save();
        $this->assertNotSame($dopoVerifica, $account->fresh()->remember_token);

        // Altri salvataggi non lo toccano
        $stabile = $account->fresh()->remember_token;
        $account->forceFill(['nome' => 'Altro'])->save();
        $this->assertSame($stabile, $account->fresh()->remember_token);
    }

    public function test_la_sessione_di_un_socio_non_sopravvive_alla_nuova_password(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id, 'stato' => Socio::STATO_ATTIVO,
        ]);

        $this->actingAs($socio, 'socio')->get('/area-soci')->assertOk();

        $socio->forceFill(['password_hash' => 'Nuova-Password-Sicura-2026!'])->save();
        $this->get('/area-soci')->assertRedirect(route('area-soci.login'));
    }

    // ── Mail e accessi ──────────────────────────────────────────────

    public function test_il_link_password_dell_area_soci_ha_una_pausa_per_destinatario(): void
    {
        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        $socio = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id, 'stato' => Socio::STATO_ATTIVO, 'email' => 'tetto@example.com',
        ]);

        foreach (range(1, 6) as $_) {
            $this->post(route('area-soci.password.email'), ['email' => 'tetto@example.com'])->assertSessionHas('status');
        }

        // Una sola mail nella pausa: gli altri tentativi non rinnovano il token
        Mail::assertSent(ImpostaPasswordSocioMail::class, 1);
        $token = $socio->fresh()->password_reset_token;
        $this->assertNotNull($token);

        // Passata la pausa il titolare può chiederne un altro
        $this->travel(11)->minutes();
        $this->post(route('area-soci.password.email'), ['email' => 'tetto@example.com'])->assertSessionHas('status');
        Mail::assertSent(ImpostaPasswordSocioMail::class, 2);
        $this->assertNotSame($token, $socio->fresh()->password_reset_token);
    }

    public function test_il_limite_di_accessi_staff_conta_insieme_le_varianti_accentate(): void
    {
        $chiave = function (string $email) {
            $richiesta = Request::create('/login', 'POST', ['email' => $email]);
            $richiesta->server->set('REMOTE_ADDR', '203.0.113.7');

            return (RateLimiter::limiter('login'))($richiesta)->key;
        };

        $this->assertSame($chiave('admin@example.org'), $chiave('admín@example.org'));
        $this->assertSame($chiave('admin@example.org'), $chiave('ADMIN@example.org'));
        $this->assertNotSame($chiave('admin@example.org'), $chiave('altro@example.org'));
    }

    public function test_l_alert_di_accessi_falliti_non_rende_cliccabile_l_email_digitata(): void
    {
        $notifica = new TentativiAccessoFallitiNotification('[verifica](https://attacker.example/x)', 5, 10, 'web', '203.0.113.7');
        $html = (string) $notifica->toMail($this->admin())->render();

        $this->assertStringNotContainsString('href="https://attacker.example', $html);
        $this->assertStringContainsString('attacker', $html);

        $this->assertSame('plain', TestoMarkdown::neutralizza('plain'));
        $this->assertStringNotContainsString('[', preg_replace('/\\\\\[/', '', TestoMarkdown::neutralizza('[a](b)')));
    }

    // ── Iscrizioni ──────────────────────────────────────────────────

    public function test_un_account_non_verificato_non_crea_soci_con_la_sua_email(): void
    {
        $this->associazione->forceFill(['iscrizioni_socio_aperte' => true])->save();
        $account = Account::factory()->nonVerificato()->create(['associazione_id' => $this->associazione->id]);
        $base = '/a/'.$this->associazione->slug;

        $this->actingAs($account, 'account')
            ->post($base.'/iscriviti', [
                'nome' => 'Prova', 'cognome' => 'Altrui', 'privacy' => '1',
                'telefono' => '3331234567', 'data_nascita' => '1990-05-10', 'citta' => 'Milano',
            ])
            ->assertRedirect($base.'/verifica-email')
            ->assertSessionHasErrors('iscrizione');

        $this->assertSame(0, RichiestaIscrizione::withoutGlobalScopes()->count());
        $this->assertDatabaseMissing('soci', ['email' => $account->email]);
    }

    // ── Tessere ─────────────────────────────────────────────────────

    public function test_la_tessera_di_un_socio_non_piu_attivo_non_risulta_valida(): void
    {
        $socio = $this->socio();
        $tessera = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
        ]);
        $qr = app(TesseraQrService::class);
        $json = route('tessere.verifica.json', ['tessera' => $tessera->id, 'signature' => $qr->signature($tessera->id)]);

        $this->getJson($json)->assertOk()->assertJsonPath('stato', 'valida');

        // Stato cambiato dalla scheda, senza cessazione: la tessera resta Attiva sul DB
        $socio->forceFill(['stato' => Socio::STATO_RADIATO])->save();
        $this->assertSame(Tessera::STATO_ATTIVA, $tessera->fresh()->stato);

        $this->getJson($json)->assertOk()
            ->assertJsonPath('stato', 'scaduta')
            ->assertJsonPath('tessera.stato_tessera', 'Socio non attivo');

        $this->get($qr->verificationUrl($tessera))->assertOk()
            ->assertSee('Tessera Scaduta / Non Attiva')
            ->assertSee('Socio non attivo')
            ->assertDontSee('Radiato');
    }

    // ── Voci da validare chiuse in codice ───────────────────────────

    public function test_il_mailer_del_tenant_in_un_worker_acceso_segue_l_smtp_aggiornato(): void
    {
        $settings = SmtpSetting::create([
            'associazione_id' => $this->associazione->id,
            'smtp_host' => 'smtp.primo.test',
            'smtp_port' => 587,
            'smtp_user' => 'posta',
            'smtp_pass_encrypted' => 'segretissima',
            'smtp_encryption' => 'tls',
            'from_email' => 'noreply@audit.test',
            'from_name' => 'Audit',
            'max_per_hour' => 100,
        ]);
        $nome = 'tenant-'.$this->associazione->id;

        TenantNotificationMailer::applica(new MailMessage, $this->associazione->fresh());
        $primo = Mail::mailer($nome);

        // Stessa configurazione: il mailer già costruito resta quello
        TenantNotificationMailer::applica(new MailMessage, $this->associazione->fresh());
        $this->assertSame($primo, Mail::mailer($nome));

        // SMTP cambiato nello stesso processo: il mailer va ricostruito
        $settings->forceFill(['smtp_host' => 'smtp.secondo.test'])->save();
        TenantNotificationMailer::applica(new MailMessage, $this->associazione->fresh());
        $secondo = Mail::mailer($nome);

        $this->assertNotSame($primo, $secondo);
        $this->assertStringContainsString('smtp.secondo.test', (string) $secondo->getSymfonyTransport());
    }

    public function test_un_webhook_paypal_senza_header_di_firma_non_chiama_paypal(): void
    {
        $config = PaymentGatewaySetting::forceCreate([
            'associazione_id' => $this->associazione->id,
            'paypal_enabled' => true,
            'paypal_client_id' => 'client',
            'paypal_client_secret_encrypted' => 'segreto',
            'paypal_webhook_id' => 'WH-123',
        ]);

        $gateway = new class extends PayPalGateway
        {
            public int $chiamate = 0;

            protected function provider(PaymentGatewaySetting $config): PayPalClient
            {
                $this->chiamate++;

                throw new \RuntimeException('nessuna chiamata a PayPal attesa');
            }
        };

        $headerCompleti = [
            'paypal-auth-algo' => 'SHA256withRSA',
            'paypal-cert-url' => 'https://api.paypal.com/cert',
            'paypal-transmission-id' => 'id',
            'paypal-transmission-sig' => 'firma',
            'paypal-transmission-time' => '2026-11-15T10:00:00Z',
        ];

        $this->assertFalse($gateway->verifyWebhookSignature($config, [], ['event_type' => 'CHECKOUT.ORDER.APPROVED']));
        $this->assertFalse($gateway->verifyWebhookSignature($config, array_merge($headerCompleti, ['paypal-transmission-sig' => '']), []));
        $this->assertSame(0, $gateway->chiamate);

        // Con tutti gli header si arriva alla verifica (qui il client fallisce: false, fail closed)
        $this->assertFalse($gateway->verifyWebhookSignature($config, $headerCompleti, []));
        $this->assertSame(1, $gateway->chiamate);
    }
}
