<?php

namespace Tests\Feature\Registro;

use App\Models\Associazione;
use App\Models\Carica;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Organi sociali (f.16 del piano vita sociale): cariche attive/storiche,
 * gestione admin-only, alert mandati in scadenza (pagina + dashboard).
 */
class OrganiSocialiTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    private Socio $socio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Pina',
            'cognome' => 'Presidenti',
        ]);
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('organi-sociali.index'))->assertRedirect('/login');
    }

    public function test_pagina_mostra_cariche_attive_e_storiche(): void
    {
        Carica::factory()->create([
            'socio_id' => $this->socio->id,
            'associazione_id' => $this->associazione->id,
            'ruolo' => 'Presidente',
        ]);

        $exPresidente = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Passatini',
        ]);
        Carica::factory()->conclusa()->create([
            'socio_id' => $exPresidente->id,
            'associazione_id' => $this->associazione->id,
            'ruolo' => 'Segretario',
        ]);

        $this->actingAs($this->admin)
            ->get(route('organi-sociali.index'))
            ->assertOk()
            ->assertSee('Organi sociali')
            ->assertSee('Presidente')
            ->assertSee('Presidenti')
            ->assertSee('Segretario')
            ->assertSee('Passatini');
    }

    public function test_admin_crea_una_carica(): void
    {
        $response = $this->actingAs($this->admin)->post(route('organi-sociali.store'), [
            'socio_id' => $this->socio->id,
            'ruolo' => 'Tesoriere',
            'inizio' => '2026-01-01',
            'fine' => '2029-12-31',
            'note' => 'Mandato triennale',
        ]);

        $response->assertRedirect(route('organi-sociali.index'));
        $response->assertSessionHas('success');

        $carica = Carica::query()->first();
        $this->assertNotNull($carica);
        $this->assertSame('Tesoriere', $carica->ruolo);
        $this->assertSame($this->socio->id, $carica->socio_id);
        $this->assertSame($this->associazione->id, $carica->associazione_id);
        $this->assertSame('2026-01-01', $carica->inizio->toDateString());
        $this->assertSame('2029-12-31', $carica->fine->toDateString());
    }

    public function test_fine_mandato_precedente_all_inizio_viene_rifiutata(): void
    {
        $this->actingAs($this->admin)
            ->from(route('organi-sociali.index'))
            ->post(route('organi-sociali.store'), [
                'socio_id' => $this->socio->id,
                'ruolo' => 'Presidente',
                'inizio' => '2026-01-01',
                'fine' => '2025-12-31',
            ])
            ->assertSessionHasErrors('fine');

        $this->assertSame(0, Carica::query()->count());
    }

    public function test_operatore_puo_vedere_ma_non_creare(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)->get(route('organi-sociali.index'))->assertOk();

        $this->actingAs($operatore)->post(route('organi-sociali.store'), [
            'socio_id' => $this->socio->id,
            'ruolo' => 'Presidente',
            'inizio' => '2026-01-01',
        ])->assertForbidden();

        $this->assertSame(0, Carica::query()->count());
    }

    public function test_admin_aggiorna_e_chiude_un_mandato(): void
    {
        $carica = Carica::factory()->create([
            'socio_id' => $this->socio->id,
            'associazione_id' => $this->associazione->id,
            'ruolo' => 'Consigliere',
            'inizio' => '2024-01-01',
            'fine' => null,
        ]);

        $this->actingAs($this->admin)->put(route('organi-sociali.update', $carica), [
            'socio_id' => $this->socio->id,
            'ruolo' => 'Consigliere',
            'inizio' => '2024-01-01',
            'fine' => '2026-06-30',
            'note' => 'Mandato concluso anticipatamente',
        ])->assertRedirect(route('organi-sociali.index'));

        $this->assertSame('2026-06-30', $carica->refresh()->fine->toDateString());
    }

    public function test_admin_elimina_una_carica(): void
    {
        $carica = Carica::factory()->create([
            'socio_id' => $this->socio->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('organi-sociali.destroy', $carica))
            ->assertRedirect(route('organi-sociali.index'));

        $this->assertDatabaseMissing('cariche', ['id' => $carica->id]);
    }

    public function test_isolamento_tenant_carica_altrui_produce_404(): void
    {
        $altra = Associazione::factory()->create();
        $socioAltrui = Socio::factory()->create(['associazione_id' => $altra->id]);
        $caricaAltrui = Carica::factory()->create([
            'socio_id' => $socioAltrui->id,
            'associazione_id' => $altra->id,
        ]);

        $this->actingAs($this->admin)->put(route('organi-sociali.update', $caricaAltrui->id), [
            'socio_id' => $socioAltrui->id,
            'ruolo' => 'Presidente',
            'inizio' => '2026-01-01',
        ])->assertNotFound();

        $this->actingAs($this->admin)
            ->delete(route('organi-sociali.destroy', $caricaAltrui->id))
            ->assertNotFound();
    }

    public function test_isolamento_tenant_socio_altrui_viene_rifiutato_alla_nomina(): void
    {
        $altra = Associazione::factory()->create();
        $socioAltrui = Socio::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->from(route('organi-sociali.index'))
            ->post(route('organi-sociali.store'), [
                'socio_id' => $socioAltrui->id,
                'ruolo' => 'Presidente',
                'inizio' => '2026-01-01',
            ])
            ->assertSessionHasErrors('socio_id');

        $this->assertSame(0, Carica::allTenants()->count());
    }

    public function test_alert_mandato_in_scadenza_sulla_pagina_organi_sociali(): void
    {
        Carica::factory()->create([
            'socio_id' => $this->socio->id,
            'associazione_id' => $this->associazione->id,
            'ruolo' => 'Presidente',
            'inizio' => now()->subYears(3)->toDateString(),
            'fine' => now()->addDays(30)->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('organi-sociali.index'))
            ->assertOk()
            ->assertSee('Mandati in scadenza')
            ->assertSee('In scadenza');
    }

    public function test_alert_mandati_in_scadenza_in_dashboard(): void
    {
        Carica::factory()->create([
            'socio_id' => $this->socio->id,
            'associazione_id' => $this->associazione->id,
            'ruolo' => 'Presidente',
            'inizio' => now()->subYears(3)->toDateString(),
            'fine' => now()->addDays(45)->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('mandato in scadenza')
            ->assertSee('Presidente')
            ->assertSee('Pina Presidenti');
    }

    public function test_dashboard_senza_mandati_in_scadenza_non_mostra_l_alert(): void
    {
        // Mandato lontano dalla scadenza (oltre i 90 giorni)
        Carica::factory()->create([
            'socio_id' => $this->socio->id,
            'associazione_id' => $this->associazione->id,
            'fine' => now()->addYears(2)->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('mandato in scadenza');
    }
}
