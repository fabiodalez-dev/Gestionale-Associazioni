<?php

namespace Tests\Feature\AreaSoci;

use App\Mail\AreaSoci\ImpostaPasswordSocioMail;
use App\Models\Associazione;
use App\Models\Socio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SetPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_richiesta_reset_genera_token_e_invia_email(): void
    {
        Mail::fake();

        $socio = Socio::factory()->create(['email' => 'socio@example.com']);

        $response = $this->from(route('area-soci.login'))->post(route('area-soci.password.email'), [
            'email' => 'socio@example.com',
        ]);

        $response->assertRedirect(route('area-soci.login'));
        $response->assertSessionHas('status');

        $socio->refresh();
        $this->assertNotNull($socio->password_reset_token);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $socio->password_reset_token);
        $this->assertTrue($socio->password_reset_expires->isFuture());
        // TTL 1 ora come il legacy
        $this->assertTrue($socio->password_reset_expires->lte(now()->addHour()->addMinute()));

        // Nel DB c'è solo l'impronta sha256: il link porta il token in chiaro (64 hex)
        // e la sua impronta coincide con la colonna, che da sola non apre il form
        Mail::assertSent(ImpostaPasswordSocioMail::class, function (ImpostaPasswordSocioMail $mail) use ($socio) {
            $tokenNelLink = basename((string) parse_url($mail->url, PHP_URL_PATH));

            return $mail->hasTo('socio@example.com')
                && preg_match('/^[a-f0-9]{64}$/', $tokenNelLink) === 1
                && ! str_contains($mail->url, (string) $socio->password_reset_token)
                && hash('sha256', $tokenNelLink) === $socio->password_reset_token;
        });
    }

    public function test_il_valore_salvato_nel_db_non_apre_il_form(): void
    {
        // Chi legge la tabella soci trova solo l'impronta: usarla come token non funziona
        $token = bin2hex(random_bytes(32));
        Socio::factory()->create([
            'password_reset_token' => Socio::hashTokenReset($token),
            'password_reset_expires' => now()->addHour(),
        ]);

        $this->get(route('area-soci.set-password', ['token' => Socio::hashTokenReset($token)]))
            ->assertOk()
            ->assertSee('Token non valido o scaduto')
            ->assertDontSee('Conferma password');

        $this->post(route('area-soci.set-password.store', ['token' => Socio::hashTokenReset($token)]), [
            'password' => 'nuova-password-123',
            'password_confirmation' => 'nuova-password-123',
        ])->assertSessionHasErrors('token');
    }

    public function test_richiesta_reset_per_email_inesistente_ha_risposta_neutra(): void
    {
        Mail::fake();

        $response = $this->from(route('area-soci.login'))->post(route('area-soci.password.email'), [
            'email' => 'inesistente@example.com',
        ]);

        // Stessa risposta dell'email esistente: niente user-enumeration (fix del legacy)
        $response->assertRedirect(route('area-soci.login'));
        $response->assertSessionHas('status');
        $response->assertSessionDoesntHaveErrors();

        Mail::assertNothingSent();
    }

    public function test_richiesta_reset_per_socio_in_attesa_o_cessato_non_genera_token(): void
    {
        Mail::fake();

        foreach ([Socio::STATO_IN_ATTESA, Socio::STATO_RADIATO] as $stato) {
            $socio = Socio::factory()->create(['stato' => $stato]);

            $this->from(route('area-soci.login'))->post(route('area-soci.password.email'), [
                'email' => $socio->email,
            ])->assertRedirect(route('area-soci.login'))->assertSessionHas('status'); // risposta neutra

            $this->assertNull($socio->fresh()->password_reset_token);
        }

        Mail::assertNothingSent();
    }

    public function test_nuova_richiesta_sovrascrive_il_token_precedente(): void
    {
        Mail::fake();

        $socio = Socio::factory()->create([
            'email' => 'socio@example.com',
            'password_reset_token' => str_repeat('a', 64),
            'password_reset_expires' => now()->addHour(),
        ]);

        $this->post(route('area-soci.password.email'), ['email' => 'socio@example.com']);

        $this->assertNotSame(str_repeat('a', 64), $socio->fresh()->password_reset_token);
    }

    public function test_form_set_password_mostrato_con_token_valido(): void
    {
        $token = bin2hex(random_bytes(32));
        Socio::factory()->create([
            'password_reset_token' => Socio::hashTokenReset($token), // nel DB solo l'impronta
            'password_reset_expires' => now()->addHour(),
        ]);

        $response = $this->get(route('area-soci.set-password', ['token' => $token]));

        $response->assertOk();
        $response->assertSee('Conferma password');
    }

    public function test_token_scaduto_non_mostra_il_form(): void
    {
        $token = bin2hex(random_bytes(32));
        Socio::factory()->create([
            'password_reset_token' => Socio::hashTokenReset($token), // nel DB solo l'impronta
            'password_reset_expires' => now()->subMinute(),
        ]);

        $response = $this->get(route('area-soci.set-password', ['token' => $token]));

        $response->assertOk();
        $response->assertSee('Token non valido o scaduto');
        $response->assertDontSee('Conferma password');
    }

    public function test_token_inesistente_non_mostra_il_form(): void
    {
        $response = $this->get(route('area-soci.set-password', ['token' => str_repeat('f', 64)]));

        $response->assertOk();
        $response->assertSee('Token non valido o scaduto');
    }

    public function test_set_password_con_token_valido(): void
    {
        $token = bin2hex(random_bytes(32));
        $socio = Socio::factory()->create([
            'password_hash' => null,
            'password_reset_token' => Socio::hashTokenReset($token), // nel DB solo l'impronta
            'password_reset_expires' => now()->addHour(),
        ]);

        $response = $this->post(route('area-soci.set-password.store', ['token' => $token]), [
            'password' => 'nuova-password-123',
            'password_confirmation' => 'nuova-password-123',
        ]);

        $response->assertRedirect(route('area-soci.login'));
        $response->assertSessionHas('status');

        $socio->refresh();
        $this->assertTrue(Hash::check('nuova-password-123', (string) $socio->password_hash));
        // Token monouso: azzerato dopo l'uso
        $this->assertNull($socio->password_reset_token);
        $this->assertNull($socio->password_reset_expires);
    }

    public function test_password_troppo_corta_viene_rifiutata(): void
    {
        $token = bin2hex(random_bytes(32));
        $socio = Socio::factory()->create([
            'password_hash' => null,
            'password_reset_token' => Socio::hashTokenReset($token), // nel DB solo l'impronta
            'password_reset_expires' => now()->addHour(),
        ]);

        $response = $this->post(route('area-soci.set-password.store', ['token' => $token]), [
            'password' => 'corta12',
            'password_confirmation' => 'corta12',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertNull($socio->fresh()->password_hash);
    }

    public function test_password_non_corrispondenti_vengono_rifiutate(): void
    {
        $token = bin2hex(random_bytes(32));
        Socio::factory()->create([
            'password_reset_token' => Socio::hashTokenReset($token), // nel DB solo l'impronta
            'password_reset_expires' => now()->addHour(),
        ]);

        $response = $this->post(route('area-soci.set-password.store', ['token' => $token]), [
            'password' => 'password-valida-1',
            'password_confirmation' => 'password-diversa-2',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_set_password_con_token_scaduto_viene_rifiutato(): void
    {
        $token = bin2hex(random_bytes(32));
        $socio = Socio::factory()->create([
            'password_hash' => null,
            'password_reset_token' => Socio::hashTokenReset($token), // nel DB solo l'impronta
            'password_reset_expires' => now()->subMinute(),
        ]);

        $response = $this->post(route('area-soci.set-password.store', ['token' => $token]), [
            'password' => 'nuova-password-123',
            'password_confirmation' => 'nuova-password-123',
        ]);

        $response->assertSessionHasErrors('token');
        $this->assertNull($socio->fresh()->password_hash);
    }

    public function test_stessa_email_in_due_associazioni_riceve_un_link_per_ciascuna(): void
    {
        Mail::fake();

        $alfa = Associazione::factory()->create(['nome' => 'Circolo Alfa']);
        $beta = Associazione::factory()->create(['nome' => 'Circolo Beta']);
        $socioA = Socio::factory()->create(['associazione_id' => $alfa->id, 'email' => 'doppio@example.com']);
        $socioB = Socio::factory()->create(['associazione_id' => $beta->id, 'email' => 'doppio@example.com']);

        $this->from(route('area-soci.login'))
            ->post(route('area-soci.password.email'), ['email' => 'doppio@example.com'])
            ->assertSessionHas('status');

        // Un token per socio, ciascuno sul proprio record
        foreach ([$socioA, $socioB] as $socio) {
            $socio->refresh();
            $this->assertNotNull($socio->password_reset_token);
            Mail::assertSent(ImpostaPasswordSocioMail::class, function (ImpostaPasswordSocioMail $mail) use ($socio) {
                $token = basename((string) parse_url($mail->url, PHP_URL_PATH));

                return $mail->socio->is($socio) && hash('sha256', $token) === $socio->password_reset_token;
            });
        }
        Mail::assertSent(ImpostaPasswordSocioMail::class, 2);
        $this->assertNotSame($socioA->password_reset_token, $socioB->password_reset_token);

        // Il link di Beta imposta la password di Beta, non quella di Alfa
        $urlBeta = '';
        Mail::assertSent(ImpostaPasswordSocioMail::class, function (ImpostaPasswordSocioMail $mail) use ($socioB, &$urlBeta) {
            if ($mail->socio->is($socioB)) {
                $urlBeta = $mail->url;
            }

            return true;
        });
        $token = basename((string) parse_url($urlBeta, PHP_URL_PATH));

        $this->get(route('area-soci.set-password', ['token' => $token]))->assertOk()->assertSee('Circolo Beta');
        $this->post(route('area-soci.set-password.store', ['token' => $token]), [
            'password' => 'Una-Password-Molto-Robusta-2026!',
            'password_confirmation' => 'Una-Password-Molto-Robusta-2026!',
        ])->assertRedirect(route('area-soci.login'));

        $this->assertTrue(Hash::check('Una-Password-Molto-Robusta-2026!', (string) $socioB->fresh()->password_hash));
        $this->assertNull($socioA->fresh()->password_hash);
        $this->assertNotNull($socioA->fresh()->password_reset_token);
    }
}
