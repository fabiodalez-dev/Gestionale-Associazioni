<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\Spazio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Spazio>
 */
class SpazioFactory extends Factory
{
    protected $model = Spazio::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'nome' => 'Sala '.fake()->word(),
            'descrizione' => fake()->sentence(),
            'capienza' => fake()->numberBetween(10, 100),
            'sede_id' => null,
            'attivo' => true,
        ];
    }

    public function nonAttivo(): static
    {
        return $this->state(['attivo' => false]);
    }
}
