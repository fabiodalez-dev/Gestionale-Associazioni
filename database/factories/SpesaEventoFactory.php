<?php

namespace Database\Factories;

use App\Models\Evento;
use App\Models\SpesaEvento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpesaEvento>
 */
class SpesaEventoFactory extends Factory
{
    protected $model = SpesaEvento::class;

    public function definition(): array
    {
        return [
            'evento_id' => Evento::factory(),
            // Stessa associazione dell'evento (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Evento::withoutGlobalScopes()->find($attributes['evento_id'])->associazione_id;
            },
            'descrizione' => fake()->sentence(3),
            'importo' => fake()->randomFloat(2, 10, 300),
            'data' => now()->toDateString(),
        ];
    }
}
