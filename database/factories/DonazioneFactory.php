<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\Donazione;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Donazione>
 */
class DonazioneFactory extends Factory
{
    protected $model = Donazione::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'donatore_nome' => fake()->name(),
            'donatore_email' => fake()->unique()->safeEmail(),
            'importo' => fake()->randomFloat(2, 5, 200),
            'anonimo' => false,
            'stato' => Donazione::STATO_IN_ATTESA,
            'gateway' => null,
            'transaction_id' => null,
            'pagata_at' => null,
        ];
    }

    public function pagata(): static
    {
        return $this->state([
            'stato' => Donazione::STATO_PAGATA,
            'gateway' => 'stripe',
            'transaction_id' => 'pi_'.fake()->bothify('??##??##??##'),
            'pagata_at' => now(),
        ]);
    }

    public function anonima(): static
    {
        return $this->state(['anonimo' => true]);
    }
}
