<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\Movimento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Movimento>
 */
class MovimentoFactory extends Factory
{
    protected $model = Movimento::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'tipo' => fake()->randomElement(Movimento::TIPI),
            'importo' => fake()->randomFloat(2, 5, 500),
            'data' => now()->toDateString(),
            'categoria_id' => null,
            'descrizione' => fake()->sentence(4),
            'metodo' => null,
            'origine_type' => null,
            'origine_id' => null,
            'registrato_da' => null,
        ];
    }

    public function entrata(): static
    {
        return $this->state(['tipo' => Movimento::TIPO_ENTRATA]);
    }

    public function uscita(): static
    {
        return $this->state(['tipo' => Movimento::TIPO_USCITA]);
    }
}
