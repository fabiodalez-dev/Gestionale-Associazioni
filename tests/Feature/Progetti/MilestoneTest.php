<?php

namespace Tests\Feature\Progetti;

use App\Models\Associazione;
use App\Models\MilestoneProgetto;
use App\Models\Progetto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.16 — Milestone: checklist con barra avanzamento, toggle completamento,
 * evidenza scadute, permessi e isolamento progetto/tenant.
 */
class MilestoneTest extends TestCase
{
    use ConfiguraTenantProgetti;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuraTenant();
    }

    private function progetto(): Progetto
    {
        return Progetto::factory()->create(['associazione_id' => $this->associazione->id]);
    }

    public function test_store_milestone(): void
    {
        $progetto = $this->progetto();

        $this->actingAs($this->admin)->post(route('progetti.milestone.store', $progetto), [
            'titolo' => 'Definire budget',
            'scadenza' => '2026-09-01',
        ])->assertRedirect(route('progetti.show', $progetto));

        $this->assertDatabaseHas('milestone_progetto', [
            'progetto_id' => $progetto->id,
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Definire budget',
        ]);
    }

    public function test_store_valida_titolo_obbligatorio(): void
    {
        $progetto = $this->progetto();

        $this->actingAs($this->admin)->post(route('progetti.milestone.store', $progetto), [
            'titolo' => '',
        ])->assertSessionHasErrors('titolo');
    }

    public function test_toggle_completa_e_riapre(): void
    {
        $progetto = $this->progetto();
        $milestone = MilestoneProgetto::factory()->create([
            'progetto_id' => $progetto->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->admin)->post(route('progetti.milestone.toggle', [$progetto, $milestone]))
            ->assertRedirect(route('progetti.show', $progetto));
        $this->assertNotNull($milestone->fresh()->completata_at);

        $this->actingAs($this->admin)->post(route('progetti.milestone.toggle', [$progetto, $milestone]))
            ->assertRedirect(route('progetti.show', $progetto));
        $this->assertNull($milestone->fresh()->completata_at);
    }

    public function test_percentuale_avanzamento(): void
    {
        $progetto = $this->progetto();
        MilestoneProgetto::factory()->count(3)->create([
            'progetto_id' => $progetto->id,
            'associazione_id' => $this->associazione->id,
        ]);
        MilestoneProgetto::factory()->completata()->create([
            'progetto_id' => $progetto->id,
            'associazione_id' => $this->associazione->id,
        ]);

        // 1 completata su 4 = 25%
        $this->assertSame(25, $progetto->fresh()->percentualeAvanzamento());
    }

    public function test_milestone_scaduta_e_riconosciuta(): void
    {
        $progetto = $this->progetto();
        $scaduta = MilestoneProgetto::factory()->scaduta()->create([
            'progetto_id' => $progetto->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->assertTrue($scaduta->isScaduta());

        $this->actingAs($this->admin)->get(route('progetti.show', $progetto))
            ->assertOk()
            ->assertSee('scaduta');
    }

    public function test_destroy_milestone(): void
    {
        $progetto = $this->progetto();
        $milestone = MilestoneProgetto::factory()->create([
            'progetto_id' => $progetto->id,
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($this->admin)->delete(route('progetti.milestone.destroy', [$progetto, $milestone]))
            ->assertRedirect(route('progetti.show', $progetto));

        $this->assertDatabaseMissing('milestone_progetto', ['id' => $milestone->id]);
    }

    public function test_milestone_di_altro_progetto_non_modificabile(): void
    {
        $progetto = $this->progetto();
        $altroProgetto = $this->progetto();
        $milestone = MilestoneProgetto::factory()->create([
            'progetto_id' => $altroProgetto->id,
            'associazione_id' => $this->associazione->id,
        ]);

        // La milestone appartiene ad un altro progetto dello stesso tenant → 404
        $this->actingAs($this->admin)
            ->post(route('progetti.milestone.toggle', [$progetto, $milestone]))
            ->assertNotFound();
    }

    public function test_operatore_non_puo_gestire_milestone(): void
    {
        $progetto = $this->progetto();

        $this->actingAs($this->creaOperatore())
            ->post(route('progetti.milestone.store', $progetto), ['titolo' => 'X'])
            ->assertForbidden();
    }

    public function test_progetto_di_altro_tenant_non_raggiungibile_per_milestone(): void
    {
        $altra = Associazione::factory()->create();
        $progettoAltrui = Progetto::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->post(route('progetti.milestone.store', $progettoAltrui->id), ['titolo' => 'X'])
            ->assertNotFound();
    }
}
