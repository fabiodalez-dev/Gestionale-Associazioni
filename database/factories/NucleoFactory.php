<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\Nucleo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Nucleo>
 */
class NucleoFactory extends Factory
{
    protected $model = Nucleo::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'nome' => 'Famiglia '.fake()->lastName(),
            'capofamiglia_id' => null,
        ];
    }
}
