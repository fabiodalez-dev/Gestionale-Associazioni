<?php

namespace Database\Factories;

use App\Models\Associazione;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Associazione>
 */
class AssociazioneFactory extends Factory
{
    protected $model = Associazione::class;

    public function definition(): array
    {
        return [
            'nome' => 'Associazione '.fake()->unique()->company(),
            'partita_iva' => fake()->unique()->numerify('###########'),
            'codice_fiscale' => null,
            'indirizzo' => fake()->streetAddress(),
            'citta' => fake()->city(),
            'provincia' => strtoupper(fake()->lexify('??')),
            'cap' => fake()->numerify('#####'),
            'email' => fake()->unique()->safeEmail(),
            'telefono' => fake()->numerify('3#########'),
            'costo_tessera' => fake()->randomFloat(2, 10, 50),
            'attiva' => true,
            'tipo_scadenza_default' => 'solare',
            'giorni_notifica_scadenza' => 30,
        ];
    }

    public function inattiva(): static
    {
        return $this->state(fn (array $attributes) => ['attiva' => false]);
    }

    public function scadenzaAnnuale(): static
    {
        return $this->state(fn (array $attributes) => ['tipo_scadenza_default' => 'annuale']);
    }
}
