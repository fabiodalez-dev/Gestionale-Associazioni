<?php

namespace Database\Factories;

use App\Models\PromemoriaInviato;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromemoriaInviato>
 */
class PromemoriaInviatoFactory extends Factory
{
    protected $model = PromemoriaInviato::class;

    public function definition(): array
    {
        return [
            'socio_id' => Socio::factory(),
            // Stessa associazione del socio (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Socio::withoutGlobalScopes()->find($attributes['socio_id'])->associazione_id;
            },
            'tessera_id' => null,
            'tipo' => PromemoriaInviato::TIPO_RINNOVO_TESSERA,
            'step' => fake()->randomElement([30, 15, 3]),
            'inviato_at' => now(),
        ];
    }
}
