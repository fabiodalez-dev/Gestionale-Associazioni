<?php

namespace Tests\Feature\Progetti;

use App\Models\MilestoneProgetto;
use App\Models\Progetto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * f.16 — Avanzamento progetti: regressione N+1 (review round 3, finding #4).
 * percentualeAvanzamento() deve usare i contatori eager-loaded via withCount()
 * senza emettere COUNT lazy per ogni riga della lista progetti.
 */
class ProgettoAvanzamentoTest extends TestCase
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

    public function test_percentuale_usa_contatori_eager_loaded_senza_query_extra(): void
    {
        $progetto = $this->progetto();
        MilestoneProgetto::factory()->count(3)->create([
            'progetto_id' => $progetto->id,
            'associazione_id' => $this->associazione->id,
        ]);
        MilestoneProgetto::factory()->completata()->count(1)->create([
            'progetto_id' => $progetto->id,
            'associazione_id' => $this->associazione->id,
        ]);

        // Carico il progetto con gli stessi withCount del controller index().
        $caricato = Progetto::query()
            ->withCount([
                'milestones',
                'milestones as milestones_completate_count' => fn ($q) => $q->whereNotNull('completata_at'),
            ])
            ->findOrFail($progetto->id);

        DB::enableQueryLog();
        $percentuale = $caricato->percentualeAvanzamento();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(25, $percentuale); // 1 su 4 completate
        $this->assertCount(0, $queries, 'percentualeAvanzamento non deve emettere query quando i count sono eager-loaded');
    }

    public function test_percentuale_zero_milestone_senza_query_extra(): void
    {
        $progetto = $this->progetto();

        $caricato = Progetto::query()
            ->withCount([
                'milestones',
                'milestones as milestones_completate_count' => fn ($q) => $q->whereNotNull('completata_at'),
            ])
            ->findOrFail($progetto->id);

        DB::enableQueryLog();
        $percentuale = $caricato->percentualeAvanzamento();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(0, $percentuale);
        $this->assertCount(0, $queries);
    }

    public function test_percentuale_fallback_query_quando_non_eager_loaded(): void
    {
        $progetto = $this->progetto();
        MilestoneProgetto::factory()->count(2)->create([
            'progetto_id' => $progetto->id,
            'associazione_id' => $this->associazione->id,
        ]);
        MilestoneProgetto::factory()->completata()->count(2)->create([
            'progetto_id' => $progetto->id,
            'associazione_id' => $this->associazione->id,
        ]);

        // Modello fresco senza withCount: deve comunque calcolare correttamente.
        $fresco = Progetto::query()->findOrFail($progetto->id);

        $this->assertSame(50, $fresco->percentualeAvanzamento());
    }

    public function test_lista_progetti_non_genera_n_plus_1_per_avanzamento(): void
    {
        // 5 progetti, ciascuno con milestone miste: senza il fix sarebbero
        // ~2 COUNT lazy per riga (10 query extra).
        foreach (range(1, 5) as $i) {
            $progetto = $this->progetto();
            MilestoneProgetto::factory()->count(2)->create([
                'progetto_id' => $progetto->id,
                'associazione_id' => $this->associazione->id,
            ]);
            MilestoneProgetto::factory()->completata()->count(1)->create([
                'progetto_id' => $progetto->id,
                'associazione_id' => $this->associazione->id,
            ]);
        }

        DB::enableQueryLog();
        $this->actingAs($this->admin)
            ->get(route('progetti.index'))
            ->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Nessuna query deve riferirsi a milestone_progetto con un filtro
        // completata_at: significherebbe un COUNT lazy emesso dalla vista.
        $countLazy = collect($queries)->filter(function ($q) {
            $sql = strtolower($q['query']);

            return str_contains($sql, 'milestone_progetto')
                && str_contains($sql, 'completata_at')
                && ! str_contains($sql, 'milestones_completate_count');
        });

        $this->assertTrue(
            $countLazy->isEmpty(),
            'La lista progetti non deve emettere COUNT lazy sulle milestone: '.$countLazy->count().' query trovate'
        );
    }
}
