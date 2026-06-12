<?php

namespace Database\Factories;

use App\Models\Cessazione;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cessazione>
 */
class CessazioneFactory extends Factory
{
    protected $model = Cessazione::class;

    public function definition(): array
    {
        return [
            'socio_id' => Socio::factory(),
            // Stessa associazione del socio (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Socio::withoutGlobalScopes()->find($attributes['socio_id'])->associazione_id;
            },
            'tipo' => fake()->randomElement(Cessazione::TIPI),
            'data' => now()->toDateString(),
            'motivo' => null,
            'delibera_numero' => null,
            'delibera_data' => null,
            'registrata_da' => null,
        ];
    }
}
