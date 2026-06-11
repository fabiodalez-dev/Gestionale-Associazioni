<?php

namespace Database\Factories;

use App\Models\Quota;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Quota>
 */
class QuotaFactory extends Factory
{
    protected $model = Quota::class;

    public function definition(): array
    {
        return [
            'socio_id' => Socio::factory(),
            // Stessa associazione del socio (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Socio::withoutGlobalScopes()->find($attributes['socio_id'])->associazione_id;
            },
            'anno' => (int) now()->year,
            'importo' => fake()->randomFloat(2, 10, 100),
            'data_scadenza' => now()->endOfYear()->toDateString(),
            'data_pagamento' => null,
            'stato' => Quota::STATO_DA_PAGARE,
            'tipo' => 'Tesseramento',
        ];
    }

    public function pagata(): static
    {
        return $this->state(fn (array $attributes) => [
            'stato' => Quota::STATO_PAGATA,
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => fake()->randomElement(['contanti', 'bonifico', 'carta']),
        ]);
    }

    public function scaduta(): static
    {
        return $this->state(fn (array $attributes) => [
            'anno' => (int) now()->subYear()->year,
            'data_scadenza' => now()->subMonths(2)->toDateString(),
        ]);
    }
}
