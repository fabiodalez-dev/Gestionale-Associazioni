<?php

namespace Database\Factories;

use App\Models\PrenotazioneSpazio;
use App\Models\Spazio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrenotazioneSpazio>
 */
class PrenotazioneSpazioFactory extends Factory
{
    protected $model = PrenotazioneSpazio::class;

    public function definition(): array
    {
        $dal = now()->addDays(3)->setTime(10, 0);

        return [
            'spazio_id' => Spazio::factory(),
            // Stessa associazione dello spazio (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Spazio::withoutGlobalScopes()->find($attributes['spazio_id'])->associazione_id;
            },
            'titolo' => fake()->sentence(3),
            'dal' => $dal,
            'al' => $dal->copy()->addHours(2),
            'richiedente_id' => null,
            'note' => null,
        ];
    }
}
