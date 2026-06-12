<?php

namespace Database\Factories;

use App\Models\Quota;
use App\Models\Ricevuta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ricevuta>
 */
class RicevutaFactory extends Factory
{
    protected $model = Ricevuta::class;

    /** Numerazione progressiva nel processo di test (unique assoc+anno+numero). */
    private static int $progressivo = 0;

    public function definition(): array
    {
        return [
            'quota_id' => Quota::factory()->pagata(),
            // Stessi associazione/anno/importo della quota (coerenza)
            'associazione_id' => function (array $attributes) {
                return Quota::withoutGlobalScopes()->find($attributes['quota_id'])->associazione_id;
            },
            'numero' => ++self::$progressivo,
            'anno' => function (array $attributes) {
                return Quota::withoutGlobalScopes()->find($attributes['quota_id'])->anno;
            },
            'importo' => function (array $attributes) {
                return Quota::withoutGlobalScopes()->find($attributes['quota_id'])->importo;
            },
            'emessa_at' => now(),
        ];
    }
}
