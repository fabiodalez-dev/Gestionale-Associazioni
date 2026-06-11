<?php

namespace Tests\Feature\Prenotazioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Notifications\Accounts\VerificaEmailAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Profilo e preferenze dell'area riservata account: anagrafica,
 * cambio password (con password corrente), cambio email (azzera la
 * verifica e reinvia il link), preferenze json.
 */
class AreaRiservataProfiloTest extends TestCase
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

    // ── Anagrafica ───────────────────────────────────────────────────

    public function test_aggiornamento_anagrafica(): void
    {
        $this->actingAs($this->account, 'account')
            ->patch($this->urlTenant('account/profilo'), [
                'nome' => 'Marina',
                'cognome' => 'Bianchi',
                'telefono' => '3209876543',
            ])
            ->assertRedirect($this->urlTenant('account/profilo'));

        $account = $this->account->fresh();

        $this->assertSame('Marina', $account->nome);
        $this->assertSame('Bianchi', $account->cognome);
        $this->assertSame('3209876543', $account->telefono);
    }

    // ── Cambio password ──────────────────────────────────────────────

    public function test_cambio_password_richiede_la_password_corrente(): void
    {
        $this->actingAs($this->account, 'account')
            ->from($this->urlTenant('account/profilo'))
            ->patch($this->urlTenant('account/profilo/password'), [
                'password_corrente' => 'password-sbagliata',
                'password' => 'nuovaPassword99',
                'password_confirmation' => 'nuovaPassword99',
            ])
            ->assertSessionHasErrors('password_corrente');

        $this->assertTrue(Hash::check('passworD1234', $this->account->fresh()->password));
    }

    public function test_cambio_password_con_password_corrente_valida(): void
    {
        $this->actingAs($this->account, 'account')
            ->patch($this->urlTenant('account/profilo/password'), [
                'password_corrente' => 'passworD1234',
                'password' => 'nuovaPassword99',
                'password_confirmation' => 'nuovaPassword99',
            ])
            ->assertRedirect($this->urlTenant('account/profilo'));

        $this->assertTrue(Hash::check('nuovaPassword99', $this->account->fresh()->password));
    }

    // ── Cambio email ─────────────────────────────────────────────────

    public function test_cambio_email_azzera_la_verifica_e_reinvia_il_link(): void
    {
        Notification::fake();

        $this->actingAs($this->account, 'account')
            ->patch($this->urlTenant('account/profilo/email'), [
                'email' => 'nuova.email@example.com',
            ])
            ->assertRedirect($this->urlTenant('verifica-email'));

        $account = $this->account->fresh();

        $this->assertSame('nuova.email@example.com', $account->email);
        $this->assertNull($account->email_verified_at);

        Notification::assertSentTo($account, VerificaEmailAccount::class);
    }

    public function test_cambio_email_rifiuta_email_gia_usata_nel_tenant(): void
    {
        Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'occupata@example.com',
        ]);

        $this->actingAs($this->account, 'account')
            ->from($this->urlTenant('account/profilo'))
            ->patch($this->urlTenant('account/profilo/email'), [
                'email' => 'occupata@example.com',
            ])
            ->assertSessionHasErrors('email');

        $this->assertNotSame('occupata@example.com', $this->account->fresh()->email);
    }

    // ── Preferenze ───────────────────────────────────────────────────

    public function test_preferenze_di_default_promemoria_attivo(): void
    {
        $this->actingAs($this->account, 'account')
            ->get($this->urlTenant('account/preferenze'))
            ->assertOk()
            ->assertSee('Promemoria eventi');
    }

    public function test_salvataggio_preferenze_json(): void
    {
        $this->actingAs($this->account, 'account')
            ->patch($this->urlTenant('account/preferenze'), [
                'newsletter' => '1',
                // promemoria_eventi NON spuntato → false
                'marketing' => '1',
            ])
            ->assertRedirect($this->urlTenant('account/preferenze'));

        $preferenze = $this->account->fresh()->preferenze;

        $this->assertTrue($preferenze['newsletter']);
        $this->assertFalse($preferenze['promemoria_eventi']);
        $this->assertTrue($preferenze['marketing']);
    }

    public function test_salvataggio_preferenze_non_cancella_le_altre_chiavi(): void
    {
        $this->account->forceFill([
            'preferenze' => ['richiesta_socio_motivazione' => 'Voglio partecipare'],
        ])->save();

        $this->actingAs($this->account, 'account')
            ->patch($this->urlTenant('account/preferenze'), ['newsletter' => '1'])
            ->assertRedirect($this->urlTenant('account/preferenze'));

        $this->assertSame(
            'Voglio partecipare',
            $this->account->fresh()->preferenze['richiesta_socio_motivazione'],
        );
    }
}
