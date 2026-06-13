<?php

namespace Database\Factories;

use App\Models\Adempimento;
use App\Models\AdempimentoAnno;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdempimentoAnno>
 */
class AdempimentoAnnoFactory extends Factory
{
    protected $model = AdempimentoAnno::class;

    public function definition(): array
    {
        return [
            'adempimento_id' => Adempimento::factory(),
            // Stessa associazione dell'adempimento (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Adempimento::withoutGlobalScopes()->find($attributes['adempimento_id'])->associazione_id;
            },
            'anno' => (int) now()->format('Y'),
            'completato_at' => null,
            'completato_da' => null,
        ];
    }

    public function completato(): static
    {
        return $this->state(['completato_at' => now()]);
    }
}
