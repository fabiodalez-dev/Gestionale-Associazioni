<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\CategoriaMovimento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoriaMovimento>
 */
class CategoriaMovimentoFactory extends Factory
{
    protected $model = CategoriaMovimento::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'nome' => ucfirst(fake()->unique()->word()),
            'tipo' => fake()->randomElement(CategoriaMovimento::TIPI),
        ];
    }

    public function entrata(): static
    {
        return $this->state(['tipo' => CategoriaMovimento::TIPO_ENTRATA]);
    }

    public function uscita(): static
    {
        return $this->state(['tipo' => CategoriaMovimento::TIPO_USCITA]);
    }
}
