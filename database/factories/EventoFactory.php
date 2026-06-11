<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\Evento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Evento>
 */
class EventoFactory extends Factory
{
    protected $model = Evento::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'titolo' => ucfirst(fake()->words(3, true)),
            'descrizione' => fake()->paragraph(),
            'data_evento' => fake()->dateTimeBetween('+1 week', '+3 months'),
            'luogo' => fake()->city(),
        ];
    }

    public function passato(): static
    {
        return $this->state(fn (array $attributes) => [
            'data_evento' => fake()->dateTimeBetween('-3 months', '-1 week'),
        ]);
    }

    public function oggi(): static
    {
        return $this->state(fn (array $attributes) => [
            'data_evento' => now()->setTime(18, 0),
        ]);
    }

    public function pubblicato(): static
    {
        return $this->state(fn (array $attributes) => [
            'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
        ]);
    }

    public function aPagamento(float $prezzo = 10.0): static
    {
        return $this->state(fn (array $attributes) => [
            'prezzo' => $prezzo,
        ]);
    }

    public function conCapienza(int $posti): static
    {
        return $this->state(fn (array $attributes) => [
            'capienza' => $posti,
        ]);
    }

    /**
     * Evento che richiede l'approvazione admin delle prenotazioni.
     */
    public function conApprovazione(): static
    {
        return $this->state(fn (array $attributes) => [
            'richiede_approvazione' => true,
        ]);
    }

    /**
     * Iscrizioni chiuse manualmente (oltre alla finestra iscrizioni_dal/al).
     */
    public function iscrizioniChiuse(): static
    {
        return $this->state(fn (array $attributes) => [
            'iscrizioni_aperte' => false,
        ]);
    }
}
