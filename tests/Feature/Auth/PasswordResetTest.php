<?php

namespace Tests\Feature\Auth;

use App\Models\Utente;
use App\Notifications\Auth\ResetPasswordUtente;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const NUOVA_PASSWORD = 'Girasole-Marmo-Vento-19';

    public function test_il_login_mostra_ricordami_e_password_dimenticata(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Ricordami per 30 giorni')
            ->assertSee(route('password.request'), false);
    }

    public function test_la_pagina_di_recupero_viene_mostrata(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('Invia link di recupero');
    }

    public function test_richiesta_link_invia_la_notifica_in_italiano(): void
    {
        Notification::fake();
        $utente = Utente::factory()->superAdmin()->create(['email' => 'admin@example.com']);

        $this->post('/forgot-password', ['email' => 'admin@example.com'])
            ->assertSessionHas('status', trans('passwords.sent'));

        Notification::assertSentTo($utente, ResetPasswordUtente::class, function (ResetPasswordUtente $notification) use ($utente) {
            $mail = $notification->toMail($utente);

            return str_contains($mail->subject, 'Reimposta la tua password')
                && str_contains((string) $mail->actionUrl, '/reset-password/'.$notification->token);
        });
    }

    public function test_il_link_di_reset_usa_app_url_anche_con_host_contraffatto(): void
    {
        Notification::fake();
        config(['app.url' => 'https://gestionale.example.com']);
        $utente = Utente::factory()->create(['email' => 'admin@example.com']);

        $this->withServerVariables(['HTTP_HOST' => 'attaccante.example'])
            ->post('/forgot-password', ['email' => 'admin@example.com']);

        Notification::assertSentTo($utente, ResetPasswordUtente::class, function (ResetPasswordUtente $notification) use ($utente) {
            $url = (string) $notification->toMail($utente)->actionUrl;

            return str_starts_with($url, 'https://gestionale.example.com/reset-password/')
                && ! str_contains($url, 'attaccante');
        });
    }

    public function test_email_inesistente_riceve_la_stessa_risposta(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nessuno@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', trans('passwords.sent'));

        Notification::assertNothingSent();
    }

    public function test_reset_con_token_valido_cambia_password_e_revoca_accessi(): void
    {
        $utente = Utente::factory()->create([
            'email' => 'admin@example.com',
            'remember_token' => 'token-ricordami-vecchio',
        ]);
        DB::table('sessions')->insert([
            'id' => 'sessione-vecchia', 'user_id' => $utente->id, 'payload' => '', 'last_activity' => time(),
        ]);
        $token = Password::broker('utenti')->createToken($utente);

        $this->get('/reset-password/'.$token.'?email=admin@example.com')
            ->assertOk()
            ->assertSee('Salva nuova password');

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'admin@example.com',
            'password' => self::NUOVA_PASSWORD,
            'password_confirmation' => self::NUOVA_PASSWORD,
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $utente->refresh();
        $this->assertTrue(Hash::check(self::NUOVA_PASSWORD, $utente->password_hash));
        $this->assertNotSame('token-ricordami-vecchio', $utente->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'sessione-vecchia']);

        // Il token è monouso
        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'admin@example.com',
            'password' => 'Altra-Frase-Sicura-77',
            'password_confirmation' => 'Altra-Frase-Sicura-77',
        ])->assertSessionHasErrors('email');
    }

    public function test_reset_rifiuta_password_debole_o_con_dati_personali(): void
    {
        $utente = Utente::factory()->create(['email' => 'mario.rossi@example.com', 'nome' => 'Mario', 'cognome' => 'Rossi']);
        $token = Password::broker('utenti')->createToken($utente);

        foreach (['password1234', 'MarioRossi2026', 'mario.rossi@example.com'] as $debole) {
            $this->post('/reset-password', [
                'token' => $token,
                'email' => 'mario.rossi@example.com',
                'password' => $debole,
                'password_confirmation' => $debole,
            ])->assertSessionHasErrors('password');
        }

        $this->assertFalse(Hash::check('password1234', $utente->fresh()->password_hash));
    }

    public function test_ricordami_dura_quanto_configurato(): void
    {
        $utente = Utente::factory()->create(['email' => 'admin@example.com', 'password_hash' => self::NUOVA_PASSWORD]);

        $response = $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => self::NUOVA_PASSWORD,
            'remember' => 'on',
        ]);

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNotNull($cookie);
        $giorni = ($cookie->getExpiresTime() - time()) / 86400;
        $this->assertEqualsWithDelta(30, $giorni, 0.1);
        $this->assertAuthenticatedAs($utente);
    }

    public function test_policy_valuta_la_robustezza_con_zxcvbn(): void
    {
        $valida = fn (string $password, array $dati = []) => Validator::make(
            ['password' => $password],
            ['password' => PasswordPolicy::rules($dati)],
        )->passes();

        $this->assertFalse($valida('corta1'));
        $this->assertFalse($valida('password123456'));
        $this->assertFalse($valida('qwertyuiop12'));
        $this->assertFalse($valida('Associazione2026', ['associazione2026']));
        $this->assertTrue($valida(self::NUOVA_PASSWORD));
        $this->assertTrue($valida('una frase lunga da ricordare'));
    }
}
