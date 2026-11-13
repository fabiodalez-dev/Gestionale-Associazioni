<?php

namespace Tests\Feature\Prenotazioni;

use App\Http\Controllers\FrontendPubblico\RegistrazioneController;
use App\Models\Account;
use App\Models\Associazione;
use App\Notifications\Accounts\ResetPasswordAccount;
use App\Notifications\Accounts\TentativoRegistrazioneAccount;
use App\Notifications\Accounts\VerificaEmailAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Auth pubblica del frontend prenotazioni (fase 2a):
 * registrazione (honeypot + throttle), verifica email firmata,
 * login/logout tenant-scoped, password reset col broker 'accounts'.
 */
class AuthPubblicaTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    protected function setUp(): void
    {
        parent::setUp();

        // Honeypot disattivato per i percorsi "umani"; riattivato nel test dedicato
        config(['honeypot.enabled' => false]);

        $this->associazione = Associazione::factory()->create([
            'frontend_pubblico_attivo' => true,
        ]);
    }

    protected function tearDown(): void
    {
        // Evita che il binding del tenant pubblico sporchi gli altri test
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    private function datiRegistrazione(array $override = []): array
    {
        return array_merge([
            'nome' => 'Giulia',
            'cognome' => 'Verdi',
            'email' => 'giulia.verdi@example.com',
            'telefono' => '3331234567',
            'password' => 'Lampione-Viola-Treno-42',
            'password_confirmation' => 'Lampione-Viola-Treno-42',
            'privacy' => '1',
        ], $override);
    }

    private function urlTenant(string $path = '', ?Associazione $associazione = null): string
    {
        $slug = ($associazione ?? $this->associazione)->slug;

        return '/a/'.$slug.($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    // ── Registrazione ────────────────────────────────────────────────

    public function test_registrazione_felice_crea_account_invia_verifica_e_rimanda_all_accesso(): void
    {
        Notification::fake();

        $response = $this->post($this->urlTenant('registrati'), $this->datiRegistrazione());

        // Niente login automatico: la risposta deve essere identica a quella per
        // un'email già registrata (vedi test_email_gia_registrata_risposta_neutra...)
        $response->assertRedirect($this->urlTenant('login'))->assertSessionHas('success');

        /** @var Account $account */
        $account = Account::allTenants()->where('email', 'giulia.verdi@example.com')->first();

        $this->assertNotNull($account);
        $this->assertSame($this->associazione->id, $account->associazione_id);
        $this->assertSame('Giulia', $account->nome);
        $this->assertNull($account->email_verified_at);
        $this->assertNotNull($account->privacy_accettata_at);
        $this->assertTrue($account->attivo);
        $this->assertTrue(Hash::check('Lampione-Viola-Treno-42', $account->password));

        $this->assertGuest('account');

        Notification::assertSentTo($account, VerificaEmailAccount::class);
    }

    public function test_registrazione_rifiuta_password_debole_e_privacy_mancante(): void
    {
        $this->from($this->urlTenant('registrati'))
            ->post($this->urlTenant('registrati'), $this->datiRegistrazione([
                'password' => 'corta1',
                'password_confirmation' => 'corta1',
            ]))
            ->assertSessionHasErrors('password');

        $this->from($this->urlTenant('registrati'))
            ->post($this->urlTenant('registrati'), $this->datiRegistrazione([
                'privacy' => '',
            ]))
            ->assertSessionHasErrors('privacy');

        $this->assertSame(0, Account::allTenants()->count());
    }

    public function test_email_unica_per_tenant_ma_riusabile_su_tenant_diversi(): void
    {
        Notification::fake();

        Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'giulia.verdi@example.com',
        ]);

        // Stesso tenant: nessun nuovo account, ma risposta identica al successo
        $this->from($this->urlTenant('registrati'))
            ->post($this->urlTenant('registrati'), $this->datiRegistrazione())
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->urlTenant('login'));
        $this->assertSame(1, Account::allTenants()->where('email', 'giulia.verdi@example.com')->count());

        // Altro tenant: accettata
        $altra = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);

        $this->post($this->urlTenant('registrati', $altra), $this->datiRegistrazione())
            ->assertRedirect($this->urlTenant('login', $altra));

        $this->assertSame(
            1,
            Account::allTenants()->where('associazione_id', $altra->id)
                ->where('email', 'giulia.verdi@example.com')->count()
        );
    }

    public function test_email_gia_registrata_risposta_neutra_e_avviso_al_titolare(): void
    {
        Notification::fake();
        // Il throttle 5/m della rotta ha il suo test: qui servono più invii di fila
        $this->withoutMiddleware(ThrottleRequests::class);

        // Risposta di riferimento: email nuova
        $nuova = $this->post($this->urlTenant('registrati'), $this->datiRegistrazione(['email' => 'nuova@example.com']));
        $this->flushSession();

        $esistente = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'giulia.verdi@example.com',
            'nome' => 'Giulia',
        ]);

        $doppia = $this->from($this->urlTenant('registrati'))
            ->post($this->urlTenant('registrati'), $this->datiRegistrazione(['nome' => 'Impostore']));

        // Stessa destinazione e stesso messaggio della registrazione riuscita
        $doppia->assertSessionHasNoErrors()->assertRedirect($nuova->headers->get('Location'));
        $doppia->assertSessionHas('success', RegistrazioneController::MESSAGGIO_INVIATA);
        $this->assertGuest('account');

        // Nessun duplicato, dati del titolare intatti, avviso con il link al recupero password
        $this->assertSame(1, Account::allTenants()->where('email', 'giulia.verdi@example.com')->count());
        $this->assertSame('Giulia', $esistente->fresh()->nome);
        Notification::assertNotSentTo($esistente, VerificaEmailAccount::class);
        Notification::assertSentTo($esistente, TentativoRegistrazioneAccount::class, function (TentativoRegistrazioneAccount $n) use ($esistente): bool {
            $mail = $n->toMail($esistente);

            return $mail->actionUrl === route('pubblico.password.richiesta', $this->associazione->slug)
                && ! str_contains(implode(' ', $mail->introLines), 'Impostore');
        });

        // Avvisi limitati: la casella del titolare non si inonda
        for ($i = 0; $i < 4; $i++) {
            $this->post($this->urlTenant('registrati'), $this->datiRegistrazione());
        }
        Notification::assertSentToTimes($esistente, TentativoRegistrazioneAccount::class, RegistrazioneController::AVVISI_PER_ACCOUNT);

        // Account nel cestino con la stessa email: niente 500 né avvisi, stessa risposta
        $cestinato = Account::factory()->create(['associazione_id' => $this->associazione->id, 'email' => 'cestino@example.com']);
        $cestinato->delete();
        $this->post($this->urlTenant('registrati'), $this->datiRegistrazione(['email' => 'cestino@example.com']))
            ->assertRedirect($this->urlTenant('login'))->assertSessionHasNoErrors();
        Notification::assertNotSentTo($cestinato, TentativoRegistrazioneAccount::class);
    }

    public function test_registrazione_throttle_5_al_minuto(): void
    {
        for ($i = 0; $i < 5; $i++) {
            // Richieste non valide: contano comunque per il rate limiter
            $this->post($this->urlTenant('registrati'), []);
        }

        $this->post($this->urlTenant('registrati'), $this->datiRegistrazione())
            ->assertStatus(429);
    }

    public function test_registrazione_honeypot_finto_successo_per_i_bot(): void
    {
        config([
            'honeypot.enabled' => true,
            'honeypot.randomize_name_field_name' => false,
            'honeypot.valid_from_timestamp' => false,
        ]);

        $response = $this->from($this->urlTenant('registrati'))
            ->post($this->urlTenant('registrati'), $this->datiRegistrazione([
                'website_url' => 'http://spam.example.com', // campo esca compilato
            ]));

        $response->assertRedirect($this->urlTenant('registrati'));
        $response->assertSessionHas('success'); // finto successo

        // ...ma nessun account è stato creato e nessuno è loggato
        $this->assertSame(0, Account::allTenants()->count());
        $this->assertGuest('account');
    }

    public function test_registrazione_404_su_frontend_disattivato(): void
    {
        $spenta = Associazione::factory()->create(['frontend_pubblico_attivo' => false]);

        $this->get($this->urlTenant('registrati', $spenta))->assertNotFound();
        $this->post($this->urlTenant('registrati', $spenta), $this->datiRegistrazione())->assertNotFound();
    }

    // ── Verifica email ───────────────────────────────────────────────

    public function test_verifica_email_con_link_firmato(): void
    {
        /** @var Account $account */
        $account = Account::factory()->nonVerificato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $url = URL::temporarySignedRoute(
            'pubblico.verifica-email.verifica',
            now()->addMinutes(60),
            [
                'associazione' => $this->associazione->slug,
                'id' => $account->id,
                'hash' => sha1($account->email),
            ]
        );

        $this->get($url)->assertRedirect($this->urlTenant('login'));

        $this->assertNotNull($account->fresh()->email_verified_at);
    }

    public function test_verifica_email_rifiuta_url_non_firmato(): void
    {
        /** @var Account $account */
        $account = Account::factory()->nonVerificato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->get($this->urlTenant('verifica-email/'.$account->id.'/'.sha1($account->email)))
            ->assertForbidden();

        $this->assertNull($account->fresh()->email_verified_at);
    }

    public function test_verifica_email_404_su_account_di_altro_tenant(): void
    {
        $altra = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);

        /** @var Account $account */
        $account = Account::factory()->nonVerificato()->create([
            'associazione_id' => $altra->id,
        ]);

        // Link firmato per il tenant SBAGLIATO: 404 uniforme
        $url = URL::temporarySignedRoute(
            'pubblico.verifica-email.verifica',
            now()->addMinutes(60),
            [
                'associazione' => $this->associazione->slug,
                'id' => $account->id,
                'hash' => sha1($account->email),
            ]
        );

        $this->get($url)->assertNotFound();

        $this->assertNull($account->fresh()->email_verified_at);
    }

    public function test_reinvio_email_di_verifica(): void
    {
        Notification::fake();

        /** @var Account $account */
        $account = Account::factory()->nonVerificato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($account, 'account')
            ->from($this->urlTenant('verifica-email'))
            ->post($this->urlTenant('verifica-email/reinvia'))
            ->assertRedirect($this->urlTenant('verifica-email'));

        Notification::assertSentTo($account, VerificaEmailAccount::class);
    }

    // ── Login / logout ───────────────────────────────────────────────

    public function test_login_felice(): void
    {
        /** @var Account $account */
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'password' => 'Lampione-Viola-Treno-42',
        ]);

        $response = $this->post($this->urlTenant('login'), [
            'email' => $account->email,
            'password' => 'Lampione-Viola-Treno-42',
        ]);

        $response->assertRedirect($this->urlTenant());
        $this->assertAuthenticatedAs($account, 'account');
        $this->assertNotNull($account->fresh()->ultimo_accesso_at);
    }

    public function test_login_rifiuta_credenziali_errate_e_account_disattivato(): void
    {
        /** @var Account $account */
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'password' => 'Lampione-Viola-Treno-42',
        ]);

        $this->from($this->urlTenant('login'))
            ->post($this->urlTenant('login'), [
                'email' => $account->email,
                'password' => 'password-sbagliata',
            ])
            ->assertSessionHasErrors('email');

        $this->assertGuest('account');

        $account->forceFill(['attivo' => false])->save();

        $this->from($this->urlTenant('login'))
            ->post($this->urlTenant('login'), [
                'email' => $account->email,
                'password' => 'Lampione-Viola-Treno-42',
            ])
            ->assertSessionHasErrors('email');

        $this->assertGuest('account');
    }

    public function test_login_throttle_per_email_e_ip(): void
    {
        /** @var Account $account */
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'password' => 'Lampione-Viola-Treno-42',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->post($this->urlTenant('login'), [
                'email' => $account->email,
                'password' => 'password-sbagliata',
            ]);
        }

        // Sesto tentativo: bloccato anche con la password GIUSTA
        $response = $this->from($this->urlTenant('login'))
            ->post($this->urlTenant('login'), [
                'email' => $account->email,
                'password' => 'Lampione-Viola-Treno-42',
            ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Troppi tentativi',
            session('errors')->first('email')
        );
        $this->assertGuest('account');
    }

    public function test_login_cross_tenant_rifiutato(): void
    {
        $altra = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);

        /** @var Account $account */
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'password' => 'Lampione-Viola-Treno-42',
        ]);

        // Credenziali VALIDE del tenant A sul frontend del tenant B: rifiutate
        $this->from($this->urlTenant('login', $altra))
            ->post($this->urlTenant('login', $altra), [
                'email' => $account->email,
                'password' => 'Lampione-Viola-Treno-42',
            ])
            ->assertSessionHasErrors('email');

        $this->assertGuest('account');
    }

    public function test_account_loggato_su_a_viene_sloggato_navigando_su_b(): void
    {
        $altra = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);

        /** @var Account $account */
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $guardName = Auth::guard('account')->getName();

        $this->withSession([$guardName => $account->id])
            ->get($this->urlTenant('', $altra))
            ->assertOk()
            ->assertSessionMissing($guardName);
    }

    public function test_logout(): void
    {
        /** @var Account $account */
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($account, 'account')
            ->post($this->urlTenant('logout'))
            ->assertRedirect($this->urlTenant());

        $this->assertGuest('account');
    }

    // ── Password reset (broker accounts) ─────────────────────────────

    public function test_richiesta_reset_risposta_neutra_e_notifica_inviata(): void
    {
        Notification::fake();

        /** @var Account $account */
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        // Email esistente
        $this->from($this->urlTenant('password/reset'))
            ->post($this->urlTenant('password/email'), ['email' => $account->email])
            ->assertRedirect($this->urlTenant('password/reset'))
            ->assertSessionHas('status');

        Notification::assertSentTo($account, ResetPasswordAccount::class);

        // Email inesistente: STESSA risposta (niente enumeration)
        $this->from($this->urlTenant('password/reset'))
            ->post($this->urlTenant('password/email'), ['email' => 'inesistente@example.com'])
            ->assertRedirect($this->urlTenant('password/reset'))
            ->assertSessionHas('status');
    }

    public function test_reset_password_con_token_valido(): void
    {
        /** @var Account $account */
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'password' => 'vecchiaPassword1',
        ]);

        $token = Password::broker('accounts')->createToken($account);

        $this->post($this->urlTenant('password/reset'), [
            'token' => $token,
            'email' => $account->email,
            'password' => 'Girasole-Marmo-Vento-19',
            'password_confirmation' => 'Girasole-Marmo-Vento-19',
        ])->assertRedirect($this->urlTenant('login'));

        $this->assertTrue(Hash::check('Girasole-Marmo-Vento-19', $account->fresh()->password));

        // Login con la nuova password
        $this->post($this->urlTenant('login'), [
            'email' => $account->email,
            'password' => 'Girasole-Marmo-Vento-19',
        ])->assertRedirect($this->urlTenant());

        $this->assertAuthenticatedAs($account->fresh(), 'account');
    }

    public function test_reset_password_con_token_non_valido(): void
    {
        /** @var Account $account */
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'password' => 'vecchiaPassword1',
        ]);

        $this->from($this->urlTenant('password/reset/token-finto'))
            ->post($this->urlTenant('password/reset'), [
                'token' => 'token-finto',
                'email' => $account->email,
                'password' => 'Girasole-Marmo-Vento-19',
                'password_confirmation' => 'Girasole-Marmo-Vento-19',
            ])
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('vecchiaPassword1', $account->fresh()->password));
    }

    public function test_pagine_auth_404_su_slug_inesistente(): void
    {
        $this->get('/a/slug-che-non-esiste/login')->assertNotFound();
        $this->get('/a/slug-che-non-esiste/registrati')->assertNotFound();
        $this->get('/a/slug-che-non-esiste/password/reset')->assertNotFound();
    }
}
