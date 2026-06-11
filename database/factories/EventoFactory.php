<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\Evento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Evento>
 */
class EventoFactory extends Factory
{
    protected $model = Evento::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'titolo' => ucfirst(fake()->words(3, true)),
            'descrizione' => fake()->paragraph(),
            'data_evento' => fake()->dateTimeBetween('+1 week', '+3 months'),
            'luogo' => fake()->city(),
        ];
    }

    public function passato(): static
    {
        return $this->state(fn (array $attributes) => [
            'data_evento' => fake()->dateTimeBetween('-3 months', '-1 week'),
        ]);
    }

    public function oggi(): static
    {
        return $this->state(fn (array $attributes) => [
            'data_evento' => now()->setTime(18, 0),
        ]);
    }
}
