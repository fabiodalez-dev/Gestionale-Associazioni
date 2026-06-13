<?php

namespace Database\Factories;

use App\Models\MilestoneProgetto;
use App\Models\Progetto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MilestoneProgetto>
 */
class MilestoneProgettoFactory extends Factory
{
    protected $model = MilestoneProgetto::class;

    public function definition(): array
    {
        return [
            'progetto_id' => Progetto::factory(),
            // Stessa associazione del progetto (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Progetto::withoutGlobalScopes()->find($attributes['progetto_id'])->associazione_id;
            },
            'titolo' => fake()->sentence(3),
            'scadenza' => now()->addMonth()->toDateString(),
            'completata_at' => null,
            'ordine' => 0,
        ];
    }

    public function completata(): static
    {
        return $this->state(['completata_at' => now()]);
    }

    public function scaduta(): static
    {
        return $this->state([
            'scadenza' => now()->subDays(5)->toDateString(),
            'completata_at' => null,
        ]);
    }
}
