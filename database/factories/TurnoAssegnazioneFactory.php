<?php

namespace Database\Factories;

use App\Models\Socio;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TurnoAssegnazione>
 */
class TurnoAssegnazioneFactory extends Factory
{
    protected $model = TurnoAssegnazione::class;

    public function definition(): array
    {
        return [
            'turno_id' => Turno::factory(),
            // Stessa associazione del turno (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Turno::withoutGlobalScopes()->find($attributes['turno_id'])->associazione_id;
            },
            'socio_id' => function (array $attributes) {
                return Socio::factory()->create([
                    'associazione_id' => $attributes['associazione_id'],
                ])->id;
            },
            'stato' => TurnoAssegnazione::STATO_PROPOSTO,
            'ore_effettive' => null,
            'motivo_rinuncia' => null,
        ];
    }

    public function confermata(): static
    {
        return $this->state(['stato' => TurnoAssegnazione::STATO_CONFERMATO]);
    }

    public function rinunciata(): static
    {
        return $this->state([
            'stato' => TurnoAssegnazione::STATO_RINUNCIATO,
            'motivo_rinuncia' => fake()->sentence(4),
        ]);
    }
}
