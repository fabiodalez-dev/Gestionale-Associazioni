<?php

namespace Tests\Feature\Soci;

use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SociStatoTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_approvazione_pre_iscrizione(): void
    {
        $socio = Socio::factory()->inAttesa()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->post("/soci/{$socio->id}/approva")
            ->assertSessionHas('success');

        $this->assertSame(Socio::STATO_IN_ATTESA_DI_PAGAMENTO, $socio->refresh()->stato);
        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Approvazione pre-iscrizione',
        ]);
    }

    public function test_approvazione_fallisce_se_lo_stato_non_e_in_attesa(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->post("/soci/{$socio->id}/approva")
            ->assertSessionHas('error');

        $this->assertSame(Socio::STATO_ATTIVO, $socio->refresh()->stato);
    }

    public function test_conferma_pagamento_manuale_attiva_il_socio_con_quota_e_tessera(): void
    {
        $socio = Socio::factory()->inAttesaDiPagamento()->create([
            'associazione_id' => $this->associazione->id,
            'data_iscrizione' => '2020-01-01',
        ]);

        $this->actingAs($this->admin)
            ->post("/soci/{$socio->id}/conferma-pagamento", [
                'importo' => '25.00',
                'metodo' => 'contanti',
                'data_pagamento' => now()->toDateString(),
                'tipo' => 'Quota Associativa',
            ])
            ->assertSessionHas('success');

        $socio->refresh();

        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);
        // La data di iscrizione viene sovrascritta con oggi (spec §2.4)
        $this->assertSame(now()->toDateString(), $socio->data_iscrizione->toDateString());

        $quota = $socio->quote()->first();
        $this->assertNotNull($quota);
        $this->assertSame(Quota::STATO_PAGATA, $quota->stato);
        $this->assertSame('contanti', $quota->metodo_pagamento);
        $this->assertSame(25.0, (float) $quota->importo);

        $this->assertSame(1, $socio->tessere()->count());
        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Pagamento confermato',
        ]);
    }

    public function test_conferma_pagamento_con_importo_zero_attiva_senza_creare_quota(): void
    {
        $socio = Socio::factory()->inAttesaDiPagamento()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->post("/soci/{$socio->id}/conferma-pagamento", [
            'importo' => '0',
            'metodo' => 'contanti',
        ]);

        $socio->refresh();
        $this->assertSame(Socio::STATO_ATTIVO, $socio->stato);
        $this->assertSame(0, $socio->quote()->count());
        $this->assertSame(1, $socio->tessere()->count());
    }

    public function test_metodo_online_non_disponibile_senza_gateway_configurato(): void
    {
        $socio = Socio::factory()->inAttesaDiPagamento()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->post("/soci/{$socio->id}/conferma-pagamento", [
                'importo' => '25.00',
                'metodo' => 'stripe',
            ])
            ->assertSessionHasErrors('metodo');
    }

    public function test_metodo_online_crea_quota_da_pagare_senza_attivare_il_socio(): void
    {
        $this->associazione->paymentGatewaySetting()->create([
            'stripe_enabled' => true,
            'stripe_secret_key_encrypted' => 'test',
        ]);

        $socio = Socio::factory()->inAttesaDiPagamento()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->post("/soci/{$socio->id}/conferma-pagamento", [
                'importo' => '25.00',
                'metodo' => 'stripe',
            ])
            ->assertSessionHas('payment_link');

        $socio->refresh();
        $this->assertSame(Socio::STATO_IN_ATTESA_DI_PAGAMENTO, $socio->stato);

        $quota = $socio->quote()->first();
        $this->assertNotNull($quota);
        $this->assertSame(Quota::STATO_DA_PAGARE, $quota->stato);
        $this->assertSame('stripe', $quota->gateway);
        $this->assertNotEmpty($quota->payment_token);
    }

    public function test_rifiuto_elimina_definitivamente_la_pre_iscrizione(): void
    {
        $socio = Socio::factory()->inAttesa()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->post("/soci/{$socio->id}/rifiuta")
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('soci', ['id' => $socio->id]);
    }

    public function test_rifiuto_non_ammesso_per_socio_attivo(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->post("/soci/{$socio->id}/rifiuta")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('soci', ['id' => $socio->id]);
    }
}
