<?php

namespace Tests\Feature\AreaSoci;

use App\Mail\AreaSoci\ImpostaPasswordSocioMail;
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

        Mail::assertSent(ImpostaPasswordSocioMail::class, function (ImpostaPasswordSocioMail $mail) use ($socio) {
            return $mail->hasTo('socio@example.com')
                && str_contains($mail->url, $socio->fresh()->password_reset_token);
        });
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
            'password_reset_token' => $token,
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
            'password_reset_token' => $token,
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
            'password_reset_token' => $token,
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
            'password_reset_token' => $token,
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
            'password_reset_token' => $token,
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
            'password_reset_token' => $token,
            'password_reset_expires' => now()->subMinute(),
        ]);

        $response = $this->post(route('area-soci.set-password.store', ['token' => $token]), [
            'password' => 'nuova-password-123',
            'password_confirmation' => 'nuova-password-123',
        ]);

        $response->assertSessionHasErrors('token');
        $this->assertNull($socio->fresh()->password_hash);
    }
}
