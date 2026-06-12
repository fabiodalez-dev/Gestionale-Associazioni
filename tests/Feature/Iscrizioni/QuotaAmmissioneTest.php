<?php

namespace Tests\Feature\Iscrizioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Quota;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use App\Models\TipoSocio;
use App\Models\Utente;
use App\Notifications\Iscrizioni\IscrizioneApprovataNotification;
use App\Services\Iscrizioni\ApprovazioneIscrizioneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Coerenza del tesseramento: all'ammissione (approvazione o iscrizione
 * diretta) nasce la quota associativa dell'anno, così il nuovo socio
 * compare in /quote, nella campagna rinnovi e riceve il link di pagamento.
 */
class QuotaAmmissioneTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create([
            'slug' => 'quote-coerenti',
            'frontend_pubblico_attivo' => true,
            'iscrizioni_socio_aperte' => true,
            'costo_tessera' => 30.00,
        ]);

        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    private function richiestaPendente(): RichiestaIscrizione
    {
        $account = Account::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email_verified_at' => now(),
        ]);

        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato' => Socio::STATO_IN_ATTESA,
        ]);

        return RichiestaIscrizione::create([
            'associazione_id' => $this->associazione->id,
            'account_id' => $account->id,
            'socio_id' => $socio->id,
            'stato' => RichiestaIscrizione::STATO_IN_ATTESA,
            'dati_form' => [],
        ]);
    }

    public function test_approvazione_crea_la_quota_dell_anno_con_importo_dell_associazione(): void
    {
        $richiesta = $this->richiestaPendente();

        app(ApprovazioneIscrizioneService::class)->approva($richiesta, $this->admin);

        $quota = Quota::allTenants()->where('socio_id', $richiesta->fresh()->socio_id)->first();
        $this->assertNotNull($quota, 'La quota di ammissione deve essere creata');
        $this->assertSame(30.0, (float) $quota->importo);
        $this->assertSame((int) now()->format('Y'), (int) $quota->anno);
        $this->assertNotSame('Pagata', $quota->stato);
    }

    public function test_il_costo_del_tipo_socio_ha_precedenza_sull_associazione(): void
    {
        $tipo = TipoSocio::create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Sostenitore',
            'costo_tessera' => 50.00,
        ]);

        $richiesta = $this->richiestaPendente();
        Socio::allTenants()->whereKey($richiesta->socio_id)->update(['tipo_socio_id' => $tipo->id]);

        app(ApprovazioneIscrizioneService::class)->approva($richiesta, $this->admin);

        $quota = Quota::allTenants()->where('socio_id', $richiesta->socio_id)->firstOrFail();
        $this->assertSame(50.0, (float) $quota->importo);
    }

    public function test_genera_quota_false_non_crea_la_quota(): void
    {
        $richiesta = $this->richiestaPendente();

        app(ApprovazioneIscrizioneService::class)
            ->approva($richiesta, $this->admin, generaQuota: false);

        $this->assertSame(0, Quota::allTenants()->where('socio_id', $richiesta->socio_id)->count());
    }

    public function test_importo_non_configurato_salta_la_quota_senza_errori(): void
    {
        $this->associazione->update(['costo_tessera' => null]);
        $richiesta = $this->richiestaPendente();

        app(ApprovazioneIscrizioneService::class)->approva($richiesta, $this->admin);

        $this->assertSame(RichiestaIscrizione::STATO_APPROVATA, $richiesta->fresh()->stato);
        $this->assertSame(0, Quota::allTenants()->where('socio_id', $richiesta->socio_id)->count());
    }

    public function test_quota_esistente_per_l_anno_non_viene_duplicata(): void
    {
        $richiesta = $this->richiestaPendente();
        Quota::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $richiesta->socio_id,
            'anno' => (int) now()->format('Y'),
        ]);

        app(ApprovazioneIscrizioneService::class)->approva($richiesta, $this->admin);

        $this->assertSame(1, Quota::allTenants()->where('socio_id', $richiesta->socio_id)->count());
    }

    public function test_la_notifica_di_approvazione_contiene_il_link_di_pagamento(): void
    {
        Notification::fake();
        $richiesta = $this->richiestaPendente();

        app(ApprovazioneIscrizioneService::class)->approva($richiesta, $this->admin);

        Notification::assertSentTo(
            $richiesta->account()->withoutGlobalScope('associazione')->first(),
            IscrizioneApprovataNotification::class,
            fn (IscrizioneApprovataNotification $n) => filled($n->linkPagamentoQuota)
                && str_contains($n->linkPagamentoQuota, '/pagamento/')
        );
    }

    public function test_iscrizione_diretta_da_ospite_crea_subito_la_quota(): void
    {
        $this->associazione->update(['modalita_iscrizione' => 'diretta']);
        $this->withoutMiddleware(\Spatie\Honeypot\ProtectAgainstSpam::class);

        $this->post('/a/quote-coerenti/iscriviti', [
            'nome' => 'Paolo', 'cognome' => 'Riva', 'email' => 'paolo.riva@example.com',
            'telefono' => '+39 333 0000003', 'data_nascita' => '1985-06-01', 'privacy' => '1',
        ])->assertSessionHas('success');

        $socio = Socio::withoutGlobalScopes()->where('email', 'paolo.riva@example.com')->firstOrFail();
        $quota = Quota::allTenants()->where('socio_id', $socio->id)->first();
        $this->assertNotNull($quota);
        $this->assertSame(30.0, (float) $quota->importo);
    }
}
