<?php

namespace Database\Factories;

use App\Models\Riunione;
use App\Models\VotazioneRiunione;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VotazioneRiunione>
 */
class VotazioneRiunioneFactory extends Factory
{
    protected $model = VotazioneRiunione::class;

    public function definition(): array
    {
        return [
            'riunione_id' => Riunione::factory()->svolta(),
            // Stessa associazione della riunione (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Riunione::withoutGlobalScopes()->find($attributes['riunione_id'])->associazione_id;
            },
            'punto_indice' => 0,
            'esito' => VotazioneRiunione::ESITO_APPROVATO,
            'favorevoli' => 5,
            'contrari' => 1,
            'astenuti' => 0,
            'note' => null,
        ];
    }

    public function respinta(): static
    {
        return $this->state([
            'esito' => VotazioneRiunione::ESITO_RESPINTO,
            'favorevoli' => 1,
            'contrari' => 5,
        ]);
    }

    public function rinviata(): static
    {
        return $this->state(['esito' => VotazioneRiunione::ESITO_RINVIATO]);
    }
}
