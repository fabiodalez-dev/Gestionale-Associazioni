<?php

namespace Tests\Feature\Prenotazioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Notifications\Accounts\RichiestaSocioRicevuta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Privacy GDPR (export JSON + cancellazione/anonimizzazione),
 * richiesta "diventa socio" e pagina tessera+quote (solo socio_id).
 */
class AreaRiservataPrivacySocioTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create([
            'frontend_pubblico_attivo' => true,
        ]);

        $this->account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'password' => 'passworD1234',
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

    // ── Export GDPR ──────────────────────────────────────────────────

    public function test_export_json_contiene_dati_account_e_prenotazioni(): void
    {
        $evento = Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Torneo di scacchi',
        ]);

        $prenotazione = Prenotazione::factory()->confermata()->create([
            'evento_id' => $evento->id,
            'account_id' => $this->account->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $response = $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/privacy/export'));

        $response->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="dati-account-'.$this->associazione->slug.'.json"')
            ->assertJsonPath('account.email', $this->account->email)
            ->assertJsonPath('account.nome', $this->account->nome)
            ->assertJsonPath('prenotazioni.0.codice', $prenotazione->codice)
            ->assertJsonPath('prenotazioni.0.evento.titolo', 'Torneo di scacchi');
    }

    public function test_export_non_include_prenotazioni_altrui(): void
    {
        $evento = Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $altrui = Prenotazione::factory()->confermata()->create([
            'evento_id' => $evento->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/privacy/export'))
            ->assertOk()
            ->assertJsonCount(0, 'prenotazioni');
    }

    // ── Cancellazione GDPR (anonimizzazione) ─────────────────────────

    public function test_cancellazione_richiede_la_password_corretta(): void
    {
        $this->actingAs($this->account, 'account')
            ->from($this->urlTenant('account/privacy'))
            ->post($this->urlTenant('account/privacy/cancella'), [
                'password' => 'password-sbagliata',
            ])
            ->assertSessionHasErrors('password');

        $this->assertFalse($this->account->fresh()->trashed());
        $this->assertAuthenticatedAs($this->account, 'account');
    }

    public function test_cancellazione_anonimizza_e_slogga(): void
    {
        $evento = Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $prenotazione = Prenotazione::factory()->confermata()->create([
            'evento_id' => $evento->id,
            'account_id' => $this->account->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->post($this->urlTenant('account/privacy/cancella'), [
                'password' => 'passworD1234',
            ])
            ->assertRedirect($this->urlTenant());

        $this->assertGuest('account');

        /** @var Account $account */
        $account = Account::allTenants()->withTrashed()->find($this->account->id);

        $this->assertTrue($account->trashed());
        $this->assertSame('Account', $account->nome);
        $this->assertSame('Anonimizzato', $account->cognome);
        $this->assertStringContainsString('@anonimo.invalid', $account->email);
        $this->assertFalse($account->attivo);

        // Le prenotazioni restano intatte (FK restrict, contabilità)
        $this->assertSame(
            $account->id,
            $prenotazione->fresh()->account_id,
        );
    }

    // ── Diventa socio ────────────────────────────────────────────────

    public function test_richiesta_diventa_socio_setta_timestamp_e_notifica_gli_admin(): void
    {
        Notification::fake();

        $admin = Utente::factory()->create([
            'associazione_id' => $this->associazione->id,
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE,
        ]);

        $this->actingAs($this->account, 'account')
            ->post($this->urlTenant('account/diventa-socio'), [
                'motivazione' => 'Partecipo da anni alle vostre attività.',
            ])
            ->assertRedirect($this->urlTenant('account/diventa-socio'));

        $account = $this->account->fresh();

        $this->assertNotNull($account->richiesta_socio_at);
        $this->assertSame(
            'Partecipo da anni alle vostre attività.',
            $account->preferenze['richiesta_socio_motivazione'],
        );

        Notification::assertSentTo($admin, RichiestaSocioRicevuta::class);
    }

    public function test_richiesta_diventa_socio_non_si_duplica(): void
    {
        Notification::fake();

        $primaRichiesta = now()->subDays(3);

        $this->account->forceFill(['richiesta_socio_at' => $primaRichiesta])->save();

        $this->actingAs($this->account, 'account')
            ->post($this->urlTenant('account/diventa-socio'), [
                'motivazione' => 'Seconda richiesta.',
            ])
            ->assertRedirect($this->urlTenant('account/diventa-socio'));

        $this->assertSame(
            $primaRichiesta->toDateTimeString(),
            $this->account->fresh()->richiesta_socio_at->toDateTimeString(),
        );

        Notification::assertNothingSent();
    }

    public function test_la_pagina_mostra_lo_stato_della_richiesta_inviata(): void
    {
        $this->account->forceFill(['richiesta_socio_at' => now()->subDay()])->save();

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/diventa-socio'))
            ->assertOk()
            ->assertSee('Richiesta inviata il');
    }

    // ── Tessera + quote (solo se socio_id) ───────────────────────────

    public function test_tessera_e_404_per_account_non_collegato_a_socio(): void
    {
        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/tessera'))
            ->assertNotFound();
    }

    public function test_tessera_mostra_qr_e_quote_con_link_pagamento(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->account->forceFill(['socio_id' => $socio->id])->save();

        $tessera = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'stato' => Tessera::STATO_ATTIVA,
        ]);

        $token = str_repeat('ab', 32);

        $quota = Quota::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'payment_token' => $token,
            'payment_token_expires' => now()->addDay(),
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/tessera'))
            ->assertOk()
            ->assertSee($tessera->numero_tessera)
            ->assertSee('Paga ora')
            ->assertSee(route('pagamento.show', ['token' => $token]));
    }

    public function test_tessera_non_mostra_link_pagamento_per_quote_pagate(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->account->forceFill(['socio_id' => $socio->id])->save();

        Quota::factory()->pagata()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/tessera'))
            ->assertOk()
            ->assertSee('Pagata')
            ->assertDontSee('Paga ora');
    }
}
