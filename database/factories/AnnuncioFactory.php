<?php

namespace Database\Factories;

use App\Models\Annuncio;
use App\Models\Associazione;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Annuncio>
 */
class AnnuncioFactory extends Factory
{
    protected $model = Annuncio::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'titolo' => fake()->sentence(5),
            'corpo_html' => '<p>'.fake()->paragraph().'</p>',
            'pubblicato_dal' => now()->subDay(),
            'pubblicato_al' => null,
            'in_evidenza' => false,
            'solo_soci' => false,
            'creato_da' => null,
        ];
    }

    public function bozza(): static
    {
        return $this->state(['pubblicato_dal' => null, 'pubblicato_al' => null]);
    }

    public function scaduto(): static
    {
        return $this->state([
            'pubblicato_dal' => now()->subDays(10),
            'pubblicato_al' => now()->subDay(),
        ]);
    }

    public function soloSoci(): static
    {
        return $this->state(['solo_soci' => true]);
    }
}
