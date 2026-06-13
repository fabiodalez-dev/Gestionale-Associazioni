<?php

namespace Database\Factories;

use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RiunionePartecipante>
 */
class RiunionePartecipanteFactory extends Factory
{
    protected $model = RiunionePartecipante::class;

    public function definition(): array
    {
        return [
            'riunione_id' => Riunione::factory(),
            // Stessa associazione della riunione (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Riunione::withoutGlobalScopes()->find($attributes['riunione_id'])->associazione_id;
            },
            // Invitato di default: socio intestatario di carica dello stesso tenant
            'socio_id' => function (array $attributes) {
                return Socio::factory()->create([
                    'associazione_id' => $attributes['associazione_id'],
                ])->id;
            },
            'utente_id' => null,
            'carica_ruolo' => 'Consigliere',
            'stato' => RiunionePartecipante::STATO_INVITATO,
        ];
    }

    public function confermato(): static
    {
        return $this->state(['stato' => RiunionePartecipante::STATO_CONFERMATO]);
    }

    public function assente(): static
    {
        return $this->state(['stato' => RiunionePartecipante::STATO_ASSENTE]);
    }
}
