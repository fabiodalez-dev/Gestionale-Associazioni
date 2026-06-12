<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\Sondaggio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sondaggio>
 */
class SondaggioFactory extends Factory
{
    protected $model = Sondaggio::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'domanda' => fake()->sentence(6).'?',
            'opzioni' => ['Sì', 'No', 'Non so'],
            'aperto_dal' => now()->subDay(),
            'aperto_al' => now()->addWeek(),
            'solo_soci' => false,
        ];
    }

    public function chiuso(): static
    {
        return $this->state([
            'aperto_dal' => now()->subDays(10),
            'aperto_al' => now()->subDay(),
        ]);
    }

    public function soloSoci(): static
    {
        return $this->state(['solo_soci' => true]);
    }
}
