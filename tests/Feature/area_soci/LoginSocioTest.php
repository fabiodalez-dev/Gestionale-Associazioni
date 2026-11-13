<?php

namespace Tests\Feature\AreaSoci;

use App\Http\Requests\AreaSoci\LoginSocioRequest;
use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Utente;
use App\Support\LimiteAccessi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginSocioTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pagina_di_login_soci_viene_mostrata(): void
    {
        $response = $this->get(route('area-soci.login'));

        $response->assertOk();
        $response->assertSee('Accedi alla tua area riservata');
        $response->assertSee('Password dimenticata');
    }

    public function test_login_socio_con_credenziali_valide(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci('password123')->create([
            'email' => 'socio@example.com',
        ]);

        $response = $this->post(route('area-soci.login.attempt'), [
            'email' => 'socio@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('area-soci.dashboard'));
        $this->assertAuthenticatedAs($socio, 'socio');
    }

    public function test_login_con_password_errata_da_errore_generico(): void
    {
        Socio::factory()->conAccessoAreaSoci('password123')->create([
            'email' => 'socio@example.com',
        ]);

        $response = $this->from(route('area-soci.login'))->post(route('area-soci.login.attempt'), [
            'email' => 'socio@example.com',
            'password' => 'sbagliata',
        ]);

        $response->assertSessionHasErrors(['email' => 'Credenziali non valide.']);
        $this->assertGuest('socio');
    }

    public function test_socio_in_attesa_o_cessato_non_puo_accedere_ne_restare_loggato(): void
    {
        foreach ([Socio::STATO_IN_ATTESA, Socio::STATO_RADIATO, Socio::STATO_DECEDUTO, Socio::STATO_TRASFERITO] as $stato) {
            $socio = Socio::factory()->conAccessoAreaSoci('password123')->create(['stato' => $stato]);

            $this->from(route('area-soci.login'))->post(route('area-soci.login.attempt'), [
                'email' => $socio->email,
                'password' => 'password123',
            ])->assertSessionHasErrors(['email' => 'Credenziali non valide.']);
            $this->assertGuest('socio');

            // Sessione già aperta (es. stato cambiato dopo il login): logout forzato
            $this->actingAs($socio, 'socio')
                ->get(route('area-soci.dashboard'))
                ->assertRedirect(route('area-soci.login'));
            $this->assertGuest('socio');
        }
    }

    public function test_socio_sospeso_o_in_attesa_di_pagamento_accede(): void
    {
        foreach ([Socio::STATO_SOSPESO, Socio::STATO_IN_ATTESA_DI_PAGAMENTO] as $stato) {
            $socio = Socio::factory()->conAccessoAreaSoci('password123')->create(['stato' => $stato]);

            $this->post(route('area-soci.login.attempt'), [
                'email' => $socio->email,
                'password' => 'password123',
            ])->assertRedirect(route('area-soci.dashboard'));
            $this->assertAuthenticatedAs($socio, 'socio');
            auth()->guard('socio')->logout();
        }
    }

    public function test_socio_senza_password_impostata_non_puo_accedere(): void
    {
        Socio::factory()->create([
            'email' => 'socio@example.com',
            'password_hash' => null,
        ]);

        $response = $this->post(route('area-soci.login.attempt'), [
            'email' => 'socio@example.com',
            'password' => 'qualsiasi',
        ]);

        // Stesso errore generico: niente user-enumeration
        $response->assertSessionHasErrors(['email' => 'Credenziali non valide.']);
        $this->assertGuest('socio');
    }

    public function test_socio_cancellato_non_puo_accedere(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci('password123')->create([
            'email' => 'socio@example.com',
        ]);
        $socio->delete(); // soft delete

        $response = $this->post(route('area-soci.login.attempt'), [
            'email' => 'socio@example.com',
            'password' => 'password123',
        ]);

        $response->assertSessionHasErrors(['email' => 'Credenziali non valide.']);
        $this->assertGuest('socio');
    }

    public function test_rate_limiting_blocca_il_sesto_tentativo(): void
    {
        Socio::factory()->conAccessoAreaSoci('password123')->create([
            'email' => 'throttle@example.com',
        ]);

        foreach (range(1, 5) as $tentativo) {
            $this->post(route('area-soci.login.attempt'), [
                'email' => 'throttle@example.com',
                'password' => 'sbagliata',
            ])->assertRedirect();
        }

        $response = $this->post(route('area-soci.login.attempt'), [
            'email' => 'throttle@example.com',
            'password' => 'sbagliata',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Troppi tentativi',
            session('errors')->first('email')
        );
        $this->assertGuest('socio');
    }

    public function test_il_rate_limit_e_per_combinazione_email_e_ip(): void
    {
        foreach (range(1, 5) as $tentativo) {
            $this->post(route('area-soci.login.attempt'), [
                'email' => 'primo@example.com',
                'password' => 'sbagliata',
            ]);
        }

        // Email diversa, stesso IP: non bloccata
        $response = $this->post(route('area-soci.login.attempt'), [
            'email' => 'secondo@example.com',
            'password' => 'sbagliata',
        ]);

        $response->assertSessionHasErrors(['email' => 'Credenziali non valide.']);
    }

    public function test_login_riuscito_azzera_il_contatore_di_throttle(): void
    {
        Socio::factory()->conAccessoAreaSoci('password123')->create([
            'email' => 'socio@example.com',
        ]);

        $this->post(route('area-soci.login.attempt'), [
            'email' => 'socio@example.com',
            'password' => 'sbagliata',
        ]);

        $this->post(route('area-soci.login.attempt'), [
            'email' => 'socio@example.com',
            'password' => 'password123',
        ])->assertRedirect(route('area-soci.dashboard'));

        $this->assertSame(0, RateLimiter::attempts(LimiteAccessi::chiave('area-soci-login|socio@example.com|127.0.0.1')));
    }

    public function test_logout_socio(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();

        $response = $this->actingAs($socio, 'socio')->post(route('area-soci.logout'));

        $response->assertRedirect(route('area-soci.login'));
        $this->assertGuest('socio');
    }

    public function test_socio_autenticato_viene_rediretto_dalla_login(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create();

        $this->actingAs($socio, 'socio')
            ->get(route('area-soci.login'))
            ->assertRedirect(route('area-soci.dashboard'));
    }

    public function test_il_logout_socio_non_tocca_la_sessione_admin(): void
    {
        // Guard separate: il problema del legacy (sessione condivisa) non esiste
        $associazione = Associazione::factory()->create();
        $admin = Utente::factory()->create(['associazione_id' => $associazione->id]);
        $socio = Socio::factory()->conAccessoAreaSoci()->create(['associazione_id' => $associazione->id]);

        $this->actingAs($admin, 'web')
            ->actingAs($socio, 'socio')
            ->post(route('area-soci.logout'));

        $this->assertGuest('socio');
        $this->assertAuthenticatedAs($admin, 'web');
    }

    /** Stessa email in due associazioni: soci distinti, ciascuno con la propria password */
    private function socioInDueAssociazioni(string $passwordA, string $passwordB): array
    {
        $a = Associazione::factory()->create(['nome' => 'Circolo Alfa']);
        $b = Associazione::factory()->create(['nome' => 'Circolo Beta']);
        $socioA = Socio::factory()->conAccessoAreaSoci($passwordA)->create(['associazione_id' => $a->id, 'email' => 'doppio@example.com']);
        $socioB = Socio::factory()->conAccessoAreaSoci($passwordB)->create(['associazione_id' => $b->id, 'email' => 'doppio@example.com']);

        return [$socioA, $socioB];
    }

    public function test_stessa_email_in_due_associazioni_entra_dove_combacia_la_password(): void
    {
        // Prima si prendeva il primo socio con quell'email (first() senza
        // ordine): l'altra iscrizione era irraggiungibile
        [$socioA, $socioB] = $this->socioInDueAssociazioni('password-alfa-1', 'password-beta-2');

        $this->post(route('area-soci.login.attempt'), ['email' => 'doppio@example.com', 'password' => 'password-beta-2'])
            ->assertRedirect(route('area-soci.dashboard'));
        $this->assertAuthenticatedAs($socioB, 'socio');

        $this->post(route('area-soci.logout'));

        $this->post(route('area-soci.login.attempt'), ['email' => 'doppio@example.com', 'password' => 'password-alfa-1'])
            ->assertRedirect(route('area-soci.dashboard'));
        $this->assertAuthenticatedAs($socioA, 'socio');
    }

    public function test_stessa_email_e_stessa_password_propone_la_scelta_dell_associazione(): void
    {
        [$socioA, $socioB] = $this->socioInDueAssociazioni('stessa-password-1', 'stessa-password-1');

        $this->post(route('area-soci.login.attempt'), ['email' => 'doppio@example.com', 'password' => 'stessa-password-1'])
            ->assertRedirect(route('area-soci.login'));
        $this->assertGuest('socio');

        // La pagina di login mostra le associazioni (solo dopo la password giusta)
        $this->get(route('area-soci.login'))
            ->assertOk()
            ->assertSee('Circolo Alfa')
            ->assertSee('Circolo Beta')
            ->assertDontSee('Password dimenticata');

        $this->post(route('area-soci.login.attempt'), ['socio_scelto' => $socioB->id])
            ->assertRedirect(route('area-soci.dashboard'));
        $this->assertAuthenticatedAs($socioB, 'socio');
        $this->assertNull(session(LoginSocioRequest::SESSIONE_SCELTA));
    }

    public function test_la_scelta_accetta_solo_i_soci_verificati_in_sessione(): void
    {
        [$socioA] = $this->socioInDueAssociazioni('stessa-password-1', 'stessa-password-1');
        $estraneo = Socio::factory()->conAccessoAreaSoci('altra-password-9')->create();

        // Senza scelta in sospeso l'id non basta per entrare
        $this->from(route('area-soci.login'))
            ->post(route('area-soci.login.attempt'), ['socio_scelto' => $socioA->id])
            ->assertSessionHasErrors('email');
        $this->assertGuest('socio');

        // Con una scelta in sospeso, un id fuori dall'elenco viene rifiutato
        $this->post(route('area-soci.login.attempt'), ['email' => 'doppio@example.com', 'password' => 'stessa-password-1']);
        $this->from(route('area-soci.login'))
            ->post(route('area-soci.login.attempt'), ['socio_scelto' => $estraneo->id])
            ->assertSessionHasErrors('email');
        $this->assertGuest('socio');
        $this->assertNull(session(LoginSocioRequest::SESSIONE_SCELTA));
    }

    public function test_la_scelta_scade(): void
    {
        [$socioA] = $this->socioInDueAssociazioni('stessa-password-1', 'stessa-password-1');

        $this->post(route('area-soci.login.attempt'), ['email' => 'doppio@example.com', 'password' => 'stessa-password-1']);
        $this->travel(LoginSocioRequest::SCELTA_TTL + 1)->seconds();

        $this->get(route('area-soci.login'))->assertOk()->assertSee('Password dimenticata');
        $this->from(route('area-soci.login'))
            ->post(route('area-soci.login.attempt'), ['socio_scelto' => $socioA->id])
            ->assertSessionHasErrors('email');
        $this->assertGuest('socio');
    }

    public function test_annulla_la_scelta_torna_al_login(): void
    {
        $this->socioInDueAssociazioni('stessa-password-1', 'stessa-password-1');

        $this->post(route('area-soci.login.attempt'), ['email' => 'doppio@example.com', 'password' => 'stessa-password-1']);
        $this->post(route('area-soci.login.attempt'), ['annulla_scelta' => '1'])
            ->assertRedirect(route('area-soci.login'));

        $this->assertNull(session(LoginSocioRequest::SESSIONE_SCELTA));
        $this->get(route('area-soci.login'))->assertSee('Password dimenticata');
    }

    public function test_stessa_email_con_password_errata_resta_errore_generico_senza_associazioni(): void
    {
        $this->socioInDueAssociazioni('password-alfa-1', 'password-beta-2');

        $this->from(route('area-soci.login'))
            ->post(route('area-soci.login.attempt'), ['email' => 'doppio@example.com', 'password' => 'sbagliata'])
            ->assertSessionHasErrors(['email' => 'Credenziali non valide.']);

        $this->assertNull(session(LoginSocioRequest::SESSIONE_SCELTA));
        $this->get(route('area-soci.login'))->assertDontSee('Circolo Alfa')->assertDontSee('Circolo Beta');
        $this->assertGuest('socio');
    }
}
