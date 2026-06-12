<?php

namespace Tests\Feature\Rinnovi;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * f.1 — Rinnovo self-service dall'area account del mini-sito pubblico:
 * disponibile solo per gli account collegati a un socio attivo.
 */
class RinnovoAccountTest extends RinnoviTestCase
{
    use RefreshDatabase;

    private function configuraFrontend(): void
    {
        $this->configuraTenant();
        $this->associazione->forceFill(['frontend_pubblico_attivo' => true])->save();
    }

    private function creaAccountCollegato(Socio $socio): Account
    {
        return Account::factory()->collegatoASocio($socio)->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_guest_redirige_al_login_del_mini_sito(): void
    {
        $this->configuraFrontend();

        $this->post(route('pubblico.account.rinnova', $this->associazione))
            ->assertRedirect(route('pubblico.login', $this->associazione));
    }

    public function test_account_collegato_a_socio_attivo_crea_quota_e_va_al_pagamento(): void
    {
        $this->configuraFrontend();
        $socio = $this->creaSocio();
        $account = $this->creaAccountCollegato($socio);

        $response = $this->actingAs($account, 'account')
            ->post(route('pubblico.account.rinnova', $this->associazione));

        /** @var Quota $quota */
        $quota = Quota::allTenants()
            ->where('socio_id', $socio->id)
            ->where('anno', now()->year)
            ->firstOrFail();

        $this->assertTrue($quota->hasValidPaymentToken());
        $response->assertRedirect(route('pagamento.show', ['token' => $quota->payment_token]));
    }

    public function test_account_non_collegato_a_socio_riceve_404(): void
    {
        $this->configuraFrontend();
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($account, 'account')
            ->post(route('pubblico.account.rinnova', $this->associazione))
            ->assertNotFound();
    }

    public function test_account_con_tessera_anno_gia_emessa_riceve_avviso(): void
    {
        $this->configuraFrontend();
        $socio = $this->creaSocio();
        Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno_validita' => (int) now()->year,
        ]);
        $account = $this->creaAccountCollegato($socio);

        $response = $this->actingAs($account, 'account')
            ->post(route('pubblico.account.rinnova', $this->associazione));

        $response->assertRedirect(route('pubblico.account.tessera', $this->associazione));
        $response->assertSessionHas('warning');
        $this->assertSame(0, Quota::allTenants()->where('socio_id', $socio->id)->count());
    }

    public function test_isolamento_tenant_account_non_rinnova_su_altro_mini_sito(): void
    {
        $this->configuraFrontend();
        $socio = $this->creaSocio();
        $account = $this->creaAccountCollegato($socio);

        $altra = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);

        // Account del tenant A sul mini-sito del tenant B → 404 uniforme
        // (difesa in profondità nel controller, oltre al logout cross-tenant
        // del middleware sulle sessioni reali)
        $response = $this->actingAs($account, 'account')
            ->post(route('pubblico.account.rinnova', $altra));

        $response->assertNotFound();
        $this->assertSame(0, Quota::allTenants()->where('socio_id', $socio->id)->count());
    }

    public function test_pulsante_rinnova_visibile_nella_pagina_tessera_account(): void
    {
        $this->configuraFrontend();
        $socio = $this->creaSocio();
        $account = $this->creaAccountCollegato($socio);

        $this->actingAs($account, 'account')
            ->get(route('pubblico.account.tessera', $this->associazione))
            ->assertOk()
            ->assertSee('Rinnova per il '.now()->year);
    }
}
