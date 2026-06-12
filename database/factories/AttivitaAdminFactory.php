<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\AttivitaAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttivitaAdmin>
 */
class AttivitaAdminFactory extends Factory
{
    protected $model = AttivitaAdmin::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'titolo' => fake()->sentence(4),
            'descrizione' => null,
            'scadenza' => now()->addDays(7)->toDateString(),
            'assegnatario_id' => null,
            'notabile_type' => null,
            'notabile_id' => null,
            'stato' => AttivitaAdmin::STATO_APERTA,
            'completata_at' => null,
        ];
    }

    public function completata(): static
    {
        return $this->state([
            'stato' => AttivitaAdmin::STATO_COMPLETATA,
            'completata_at' => now(),
        ]);
    }
}
