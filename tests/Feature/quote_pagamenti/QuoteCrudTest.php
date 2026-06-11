<?php

namespace Tests\Feature\quote_pagamenti;

use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteCrudTest extends TestCase
{
    use ConfiguraTenantQuote;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
    }

    public function test_index_mostra_solo_le_quote_del_proprio_tenant(): void
    {
        $socio = $this->creaSocio();
        $this->creaQuota($socio);
        $this->creaQuota($socio, ['anno' => 2024]);

        $altraAssociazione = Associazione::factory()->create();
        $altroSocio = Socio::factory()->create(['associazione_id' => $altraAssociazione->id]);
        Quota::factory()->create([
            'socio_id' => $altroSocio->id,
            'associazione_id' => $altraAssociazione->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('quote.index'));

        $response->assertOk();
        $response->assertViewHas('quote', fn ($quote) => $quote->total() === 2);
    }

    public function test_index_richiede_login(): void
    {
        $this->get(route('quote.index'))->assertRedirect();
    }

    public function test_utente_senza_permessi_riceve_403(): void
    {
        $senzaRuolo = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        // Nessun ruolo assegnato → nessun permesso quote.view

        $this->actingAs($senzaRuolo)->get(route('quote.index'))->assertForbidden();
    }

    public function test_filtro_stato_pagata_applicato_in_sql(): void
    {
        $socio = $this->creaSocio();
        $this->creaQuota($socio, ['data_pagamento' => now()->toDateString()]);
        $this->creaQuota($socio, ['data_pagamento' => null]);

        $response = $this->actingAs($this->admin)
            ->get(route('quote.index', ['status' => Quota::STATO_PAGATA]));

        $response->assertOk();
        $response->assertViewHas('quote', fn ($quote) => $quote->total() === 1);
    }

    public function test_store_crea_quota_nel_tenant_corrente(): void
    {
        $socio = $this->creaSocio();

        $response = $this->actingAs($this->admin)->post(route('quote.store'), [
            'socio_id' => $socio->id,
            'anno' => 2026,
            'importo' => '75.50',
            'data_scadenza' => '2026-12-31',
            'tipo' => 'Quota Associativa',
        ]);

        $response->assertRedirect(route('quote.index'));
        $response->assertSessionHas('success', 'Quota creata.');

        $this->assertDatabaseHas('quote', [
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'anno' => 2026,
            'tipo' => 'Quota Associativa',
        ]);
    }

    public function test_store_valida_i_campi_obbligatori(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('quote.index'))
            ->post(route('quote.store'), [
                'socio_id' => '',
                'anno' => 1900,
                'importo' => '-5',
                'data_scadenza' => 'non-una-data',
                'tipo' => '',
            ]);

        $response->assertSessionHasErrors(['socio_id', 'anno', 'importo', 'data_scadenza', 'tipo']);
        $this->assertSame(0, Quota::allTenants()->count());
    }

    public function test_store_rifiuta_socio_di_altro_tenant(): void
    {
        $altraAssociazione = Associazione::factory()->create();
        $altroSocio = Socio::factory()->create(['associazione_id' => $altraAssociazione->id]);

        $response = $this->actingAs($this->admin)->post(route('quote.store'), [
            'socio_id' => $altroSocio->id,
            'anno' => 2026,
            'importo' => '50.00',
            'data_scadenza' => '2026-12-31',
            'tipo' => 'Quota Associativa',
        ]);

        $response->assertSessionHasErrors('socio_id');
        $this->assertSame(0, Quota::allTenants()->count());
    }

    public function test_update_modifica_la_quota(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, ['importo' => 50]);

        $response = $this->actingAs($this->admin)->put(route('quote.update', $quota), [
            'socio_id' => $socio->id,
            'anno' => $quota->anno,
            'importo' => '99.00',
            'data_scadenza' => '2026-11-30',
            'tipo' => 'Quota Sostenitore',
        ]);

        $response->assertRedirect(route('quote.index'));
        $quota->refresh();
        $this->assertSame('99.00', (string) $quota->importo);
        $this->assertSame('Quota Sostenitore', $quota->tipo);
    }

    public function test_update_su_quota_di_altro_tenant_restituisce_404(): void
    {
        $altraAssociazione = Associazione::factory()->create();
        $altroSocio = Socio::factory()->create(['associazione_id' => $altraAssociazione->id]);
        $quotaAltrui = Quota::factory()->create([
            'socio_id' => $altroSocio->id,
            'associazione_id' => $altraAssociazione->id,
        ]);

        $response = $this->actingAs($this->admin)->put(route('quote.update', $quotaAltrui), [
            'socio_id' => $altroSocio->id,
            'anno' => 2026,
            'importo' => '1.00',
            'data_scadenza' => '2026-12-31',
            'tipo' => 'Hack',
        ]);

        $response->assertNotFound();
        $this->assertNotSame('Hack', $quotaAltrui->fresh()->tipo);
    }

    public function test_destroy_elimina_la_quota(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio);

        $response = $this->actingAs($this->admin)->delete(route('quote.destroy', $quota));

        $response->assertRedirect(route('quote.index'));
        $response->assertSessionHas('success', 'Quota eliminata con successo.');
        $this->assertDatabaseMissing('quote', ['id' => $quota->id]);
    }

    public function test_destroy_bloccato_per_quote_pagate_online(): void
    {
        $socio = $this->creaSocio();
        $quota = $this->creaQuota($socio, [
            'data_pagamento' => now()->toDateString(),
            'gateway' => 'stripe',
            'gateway_transaction_id' => 'pi_123',
        ]);

        $response = $this->actingAs($this->admin)->delete(route('quote.destroy', $quota));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('quote', ['id' => $quota->id]);
    }

    public function test_autocomplete_soci_scoped_sul_tenant(): void
    {
        $this->creaSocio(['nome' => 'Giovanni', 'cognome' => 'Verdolini']);

        $altraAssociazione = Associazione::factory()->create();
        Socio::factory()->create([
            'associazione_id' => $altraAssociazione->id,
            'nome' => 'Giovanna',
            'cognome' => 'Verdolesi',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson(route('quote.soci-search', ['q' => 'Verdol']));

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['nome_completo' => 'Verdolini Giovanni']);
    }
}
