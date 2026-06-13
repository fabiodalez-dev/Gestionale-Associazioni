<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\ContattoEsterno;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContattoEsterno>
 */
class ContattoEsternoFactory extends Factory
{
    protected $model = ContattoEsterno::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'tipo' => fake()->randomElement(ContattoEsterno::TIPI),
            'nome' => fake()->company(),
            'referente' => fake()->name(),
            'email' => fake()->safeEmail(),
            'telefono' => fake()->phoneNumber(),
            'note' => null,
            'tag' => [],
        ];
    }

    public function sponsor(): static
    {
        return $this->state(['tipo' => ContattoEsterno::TIPO_SPONSOR]);
    }
}
