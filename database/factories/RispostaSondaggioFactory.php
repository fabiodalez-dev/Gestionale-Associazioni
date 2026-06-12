<?php

namespace Database\Factories;

use App\Models\RispostaSondaggio;
use App\Models\Socio;
use App\Models\Sondaggio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RispostaSondaggio>
 */
class RispostaSondaggioFactory extends Factory
{
    protected $model = RispostaSondaggio::class;

    public function definition(): array
    {
        return [
            'sondaggio_id' => Sondaggio::factory(),
            // Stessa associazione del sondaggio (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Sondaggio::withoutGlobalScopes()->find($attributes['sondaggio_id'])->associazione_id;
            },
            // Votante di default: un socio dello stesso tenant
            // (gli attributi precedenti sono già risolti a questo punto)
            'votante_type' => Socio::class,
            'votante_id' => function (array $attributes) {
                return Socio::factory()->create([
                    'associazione_id' => $attributes['associazione_id'],
                ])->id;
            },
            'opzione' => 0,
        ];
    }
}
