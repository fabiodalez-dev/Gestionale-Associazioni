<?php

namespace Database\Factories;

use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tessera>
 */
class TesseraFactory extends Factory
{
    protected $model = Tessera::class;

    public function definition(): array
    {
        $anno = (int) now()->year;

        return [
            'socio_id' => Socio::factory(),
            // Stessa associazione del socio (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Socio::withoutGlobalScopes()->find($attributes['socio_id'])->associazione_id;
            },
            'numero_tessera' => $anno.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'anno_validita' => $anno,
            'data_emissione' => now()->toDateString(),
            'data_scadenza' => now()->endOfYear()->toDateString(),
            'tipo_scadenza' => Tessera::TIPO_SCADENZA_SOLARE,
            'stato' => Tessera::STATO_ATTIVA,
        ];
    }

    public function annuale(): static
    {
        return $this->state(fn (array $attributes) => [
            'tipo_scadenza' => Tessera::TIPO_SCADENZA_ANNUALE,
            'data_scadenza' => now()->addYear()->toDateString(),
        ]);
    }

    public function scaduta(): static
    {
        return $this->state(fn (array $attributes) => [
            'anno_validita' => (int) now()->subYear()->year,
            'data_emissione' => now()->subYear()->toDateString(),
            'data_scadenza' => now()->subYear()->endOfYear()->toDateString(),
        ]);
    }
}
