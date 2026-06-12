<?php

namespace Database\Factories;

use App\Models\RichiestaModificaDati;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RichiestaModificaDati>
 */
class RichiestaModificaDatiFactory extends Factory
{
    protected $model = RichiestaModificaDati::class;

    public function definition(): array
    {
        return [
            'socio_id' => Socio::factory(),
            // Stessa associazione del socio (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Socio::withoutGlobalScopes()->find($attributes['socio_id'])->associazione_id;
            },
            'proposte' => ['telefono' => fake()->phoneNumber()],
            'stato' => RichiestaModificaDati::STATO_IN_ATTESA,
            // Origine di default: il socio stesso (area soci)
            'origine_type' => Socio::class,
            'origine_id' => fn (array $attributes) => $attributes['socio_id'],
            'gestita_da' => null,
            'gestita_at' => null,
        ];
    }

    public function approvata(): static
    {
        return $this->state([
            'stato' => RichiestaModificaDati::STATO_APPROVATA,
            'gestita_at' => now(),
        ]);
    }

    public function rifiutata(): static
    {
        return $this->state([
            'stato' => RichiestaModificaDati::STATO_RIFIUTATA,
            'gestita_at' => now(),
        ]);
    }
}
