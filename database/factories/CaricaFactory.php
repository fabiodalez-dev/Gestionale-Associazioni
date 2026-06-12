<?php

namespace Database\Factories;

use App\Models\Carica;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Carica>
 */
class CaricaFactory extends Factory
{
    protected $model = Carica::class;

    public function definition(): array
    {
        return [
            'socio_id' => Socio::factory(),
            // Stessa associazione del socio (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Socio::withoutGlobalScopes()->find($attributes['socio_id'])->associazione_id;
            },
            'ruolo' => fake()->randomElement(Carica::RUOLI),
            'inizio' => now()->subYear()->toDateString(),
            'fine' => null,
            'note' => null,
        ];
    }

    public function conclusa(): static
    {
        return $this->state(fn (array $attributes) => [
            'inizio' => now()->subYears(4)->toDateString(),
            'fine' => now()->subYear()->toDateString(),
        ]);
    }
}
