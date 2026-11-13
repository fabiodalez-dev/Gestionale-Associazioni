<?php

namespace Tests\Feature\FrontendPubblico;

use App\Models\Account;
use App\Models\Associazione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Navigazione cross-tenant nel mini-sito: la sessione di un account del
 * tenant A non vale sul tenant B (RisolviTenantPubblico). Sulle rotte
 * riservate all'account la richiesta viene rediretta alla login di B,
 * sulle pagine pubbliche si prosegue come ospite.
 */
class AccountCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    private Account $accountA;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantA = Associazione::factory()->create(['slug' => 'tenant-a', 'frontend_pubblico_attivo' => true]);
        Associazione::factory()->create(['slug' => 'tenant-b', 'frontend_pubblico_attivo' => true]);
        $this->accountA = Account::factory()->create(['associazione_id' => $tenantA->id]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant.pubblico');

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rotteRiservate(): array
    {
        return [
            'dashboard' => ['/account'],
            'prenotazioni' => ['/account/prenotazioni'],
            'sondaggi' => ['/account/sondaggi'],
        ];
    }

    #[DataProvider('rotteRiservate')]
    public function test_account_di_a_sulle_rotte_riservate_di_b_viene_rediretto_alla_login_di_b(string $percorso): void
    {
        $guardName = Auth::guard('account')->getName();

        // AutenticaAccount (priorità di Authenticate) gira PRIMA di
        // RisolviTenantPubblico e trova l'account: senza il redirect il
        // controller girerebbe con l'account appena sloggato
        $this->withSession([$guardName => $this->accountA->id])
            ->get('/a/tenant-b'.$percorso)
            ->assertRedirect(route('pubblico.login', ['associazione' => 'tenant-b']))
            ->assertSessionMissing($guardName);

        $this->assertGuest('account');
    }

    public function test_account_di_a_resta_loggato_sulle_rotte_riservate_di_a(): void
    {
        $guardName = Auth::guard('account')->getName();

        $this->withSession([$guardName => $this->accountA->id])
            ->get('/a/tenant-a/account')
            ->assertOk();

        $this->assertAuthenticatedAs($this->accountA, 'account');
    }

    public function test_account_di_a_sulle_pagine_pubbliche_di_b_prosegue_come_ospite(): void
    {
        $guardName = Auth::guard('account')->getName();

        $this->withSession([$guardName => $this->accountA->id])
            ->get('/a/tenant-b')
            ->assertOk()
            ->assertSessionMissing($guardName);

        $this->assertGuest('account');
    }
}
