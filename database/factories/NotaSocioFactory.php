<?php

namespace Database\Factories;

use App\Models\NotaSocio;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotaSocio>
 */
class NotaSocioFactory extends Factory
{
    protected $model = NotaSocio::class;

    public function definition(): array
    {
        return [
            'socio_id' => Socio::factory(),
            // Stessa associazione del socio (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Socio::withoutGlobalScopes()->find($attributes['socio_id'])->associazione_id;
            },
            'autore_id' => null,
            'testo' => fake()->sentence(),
            'fissata' => false,
        ];
    }

    public function fissata(): static
    {
        return $this->state(['fissata' => true]);
    }
}
