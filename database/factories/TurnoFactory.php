<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\Turno;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Turno>
 */
class TurnoFactory extends Factory
{
    protected $model = Turno::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'titolo' => 'Turno '.fake()->words(2, true),
            'data' => now()->addDays(7)->toDateString(),
            'ora_inizio' => '09:00:00',
            'ora_fine' => '12:00:00',
            'posti' => 2,
            'mansione_id' => null,
            'evento_id' => null,
            'spazio_id' => null,
            'progetto_id' => null,
            'note' => null,
            'stato' => Turno::STATO_APERTO,
            'auto_conferma' => false,
        ];
    }

    public function chiuso(): static
    {
        return $this->state(['stato' => Turno::STATO_CHIUSO]);
    }

    public function autoConferma(): static
    {
        return $this->state(['auto_conferma' => true]);
    }
}
