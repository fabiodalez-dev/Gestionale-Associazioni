<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\Sponsor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sponsor>
 */
class SponsorFactory extends Factory
{
    protected $model = Sponsor::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'contatto_esterno_id' => null,
            'nome' => fake()->company(),
            'logo_url' => null,
            'livello' => fake()->randomElement(Sponsor::LIVELLI),
            'dal' => now()->subMonth()->toDateString(),
            'al' => now()->addYear()->toDateString(),
            'visibile_sul_sito' => false,
            'sito_url' => fake()->url(),
        ];
    }

    public function visibile(): static
    {
        return $this->state(['visibile_sul_sito' => true]);
    }

    public function scaduto(): static
    {
        return $this->state([
            'dal' => now()->subYear()->toDateString(),
            'al' => now()->subMonth()->toDateString(),
        ]);
    }
}
