<?php

namespace Database\Factories;

use App\Models\FeedbackEvento;
use App\Models\Prenotazione;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeedbackEvento>
 */
class FeedbackEventoFactory extends Factory
{
    protected $model = FeedbackEvento::class;

    public function definition(): array
    {
        return [
            'prenotazione_id' => Prenotazione::factory(),
            // Stessi evento/associazione della prenotazione (coerenza multi-tenant)
            'evento_id' => function (array $attributes) {
                return Prenotazione::withoutGlobalScopes()->find($attributes['prenotazione_id'])->evento_id;
            },
            'associazione_id' => function (array $attributes) {
                return Prenotazione::withoutGlobalScopes()->find($attributes['prenotazione_id'])->associazione_id;
            },
            'voto' => null,
            'commento' => null,
            'token' => FeedbackEvento::generaToken(),
            'inviato_at' => now(),
            'compilato_at' => null,
        ];
    }

    public function compilato(): static
    {
        return $this->state(fn (array $attributes) => [
            'voto' => fake()->numberBetween(FeedbackEvento::VOTO_MIN, FeedbackEvento::VOTO_MAX),
            'commento' => fake()->optional()->sentence(),
            'compilato_at' => now(),
        ]);
    }
}
