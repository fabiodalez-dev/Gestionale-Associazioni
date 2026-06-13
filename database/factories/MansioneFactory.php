<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\Mansione;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mansione>
 */
class MansioneFactory extends Factory
{
    protected $model = Mansione::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'nome' => fake()->unique()->words(2, true),
            'descrizione' => fake()->sentence(),
        ];
    }
}
