<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\Progetto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Progetto>
 */
class ProgettoFactory extends Factory
{
    protected $model = Progetto::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'nome' => 'Progetto '.fake()->words(2, true),
            'descrizione' => fake()->paragraph(),
            'dal' => now()->subMonth()->toDateString(),
            'al' => now()->addMonths(3)->toDateString(),
            'responsabile_id' => null,
            'stato' => Progetto::STATO_ATTIVO,
            'budget' => fake()->randomFloat(2, 500, 5000),
        ];
    }

    public function bozza(): static
    {
        return $this->state(['stato' => Progetto::STATO_BOZZA]);
    }

    public function concluso(): static
    {
        return $this->state(['stato' => Progetto::STATO_CONCLUSO]);
    }
}
