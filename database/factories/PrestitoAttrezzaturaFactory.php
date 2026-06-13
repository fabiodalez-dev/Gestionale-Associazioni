<?php

namespace Database\Factories;

use App\Models\Attrezzatura;
use App\Models\PrestitoAttrezzatura;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrestitoAttrezzatura>
 */
class PrestitoAttrezzaturaFactory extends Factory
{
    protected $model = PrestitoAttrezzatura::class;

    public function definition(): array
    {
        return [
            'attrezzatura_id' => Attrezzatura::factory(),
            // Stessa associazione dell'attrezzatura (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Attrezzatura::withoutGlobalScopes()->find($attributes['attrezzatura_id'])->associazione_id;
            },
            'socio_id' => function (array $attributes) {
                return Socio::factory()->create([
                    'associazione_id' => $attributes['associazione_id'],
                ])->id;
            },
            'quantita' => 1,
            'dal' => now()->toDateString(),
            'restituzione_prevista' => now()->addDays(7)->toDateString(),
            'restituito_at' => null,
            'note' => null,
        ];
    }

    public function restituito(): static
    {
        return $this->state(['restituito_at' => now()]);
    }

    public function inRitardo(): static
    {
        return $this->state([
            'dal' => now()->subDays(14)->toDateString(),
            'restituzione_prevista' => now()->subDays(3)->toDateString(),
            'restituito_at' => null,
        ]);
    }
}
