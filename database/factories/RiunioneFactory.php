<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\Riunione;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Riunione>
 */
class RiunioneFactory extends Factory
{
    protected $model = Riunione::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'titolo' => 'Riunione del direttivo',
            'data_ora' => now()->addWeek()->setTime(21, 0),
            'luogo' => 'Sede sociale',
            'spazio_id' => null,
            'odg' => [
                ['punto' => 'Approvazione bilancio', 'descrizione' => 'Esame del bilancio consuntivo'],
                ['punto' => 'Varie ed eventuali', 'descrizione' => null],
            ],
            'stato' => Riunione::STATO_PIANIFICATA,
            'verbale_id' => null,
            'convocazione_inviata_at' => null,
        ];
    }

    public function svolta(): static
    {
        return $this->state([
            'data_ora' => now()->subWeek()->setTime(21, 0),
            'stato' => Riunione::STATO_SVOLTA,
        ]);
    }

    /**
     * Assemblea dei soci (issue #6), ordinaria o straordinaria.
     */
    public function assemblea(string $organo = Riunione::ORGANO_ASSEMBLEA_ORDINARIA): static
    {
        return $this->state([
            'titolo' => 'Assemblea dei soci',
            'organo' => $organo,
        ]);
    }

    public function annullata(): static
    {
        return $this->state(['stato' => Riunione::STATO_ANNULLATA]);
    }
}
