<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\Attrezzatura;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attrezzatura>
 */
class AttrezzaturaFactory extends Factory
{
    protected $model = Attrezzatura::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'nome' => fake()->words(2, true),
            'descrizione' => fake()->sentence(),
            'quantita' => fake()->numberBetween(1, 10),
            'valore_unitario' => fake()->randomFloat(2, 10, 500),
            'stato' => Attrezzatura::STATO_BUONO,
            'spazio_id' => null,
            'ubicazione' => 'Magazzino',
        ];
    }

    public function fuoriUso(): static
    {
        return $this->state(['stato' => Attrezzatura::STATO_FUORI_USO]);
    }
}
