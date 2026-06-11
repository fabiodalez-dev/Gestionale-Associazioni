<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RichiestaIscrizione>
 */
class RichiestaIscrizioneFactory extends Factory
{
    protected $model = RichiestaIscrizione::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            // Stessa associazione della richiesta (coerenza multi-tenant)
            'account_id' => function (array $attributes) {
                return Account::factory()->create([
                    'associazione_id' => $attributes['associazione_id'],
                ])->id;
            },
            'socio_id' => null,
            'stato' => RichiestaIscrizione::STATO_IN_ATTESA,
            'dati_form' => [
                'telefono' => fake()->numerify('+39 3#########'),
                'citta' => fake()->city(),
            ],
        ];
    }

    public function inAttesa(): static
    {
        return $this->state(fn (array $attributes) => [
            'stato' => RichiestaIscrizione::STATO_IN_ATTESA,
        ]);
    }

    /**
     * Richiesta con il socio preiscritto già creato (stato 'In Attesa').
     */
    public function conSocio(?Socio $socio = null): static
    {
        return $this->state(fn (array $attributes) => [
            'socio_id' => $socio?->id ?? Socio::factory()->create([
                'associazione_id' => $attributes['associazione_id'],
                'stato' => Socio::STATO_IN_ATTESA,
            ])->id,
        ]);
    }

    public function approvata(?Utente $gestore = null): static
    {
        return $this->state(fn (array $attributes) => [
            'stato' => RichiestaIscrizione::STATO_APPROVATA,
            'gestita_da' => $gestore?->id,
            'gestita_at' => now(),
        ]);
    }

    public function rifiutata(string $motivo = 'Dati incompleti', ?Utente $gestore = null): static
    {
        return $this->state(fn (array $attributes) => [
            'stato' => RichiestaIscrizione::STATO_RIFIUTATA,
            'motivo_rifiuto' => $motivo,
            'gestita_da' => $gestore?->id,
            'gestita_at' => now(),
        ]);
    }
}
