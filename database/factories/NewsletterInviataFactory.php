<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\NewsletterInviata;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsletterInviata>
 */
class NewsletterInviataFactory extends Factory
{
    protected $model = NewsletterInviata::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'titolo' => 'Newsletter '.fake()->monthName(),
            'annunci_ids' => [],
            'inviata_a' => 0,
            'inviata_at' => now(),
            'inviata_da' => null,
        ];
    }
}
