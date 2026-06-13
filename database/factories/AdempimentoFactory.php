<?php

namespace Database\Factories;

use App\Models\Adempimento;
use App\Models\Associazione;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Adempimento>
 */
class AdempimentoFactory extends Factory
{
    protected $model = Adempimento::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'titolo' => fake()->sentence(3),
            'descrizione' => fake()->sentence(),
            'mese_scadenza' => fake()->numberBetween(1, 12),
            'attivo' => true,
        ];
    }

    public function nonAttivo(): static
    {
        return $this->state(['attivo' => false]);
    }
}
