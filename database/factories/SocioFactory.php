<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Socio>
 */
class SocioFactory extends Factory
{
    protected $model = Socio::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'numero_socio' => str_pad((string) fake()->unique()->numberBetween(1, 99999), 3, '0', STR_PAD_LEFT),
            'nome' => fake()->firstName(),
            'cognome' => fake()->lastName(),
            'data_nascita' => fake()->date('Y-m-d', '-18 years'),
            'codice_fiscale' => null,
            'email' => fake()->unique()->safeEmail(),
            'telefono' => fake()->numerify('3#########'),
            'indirizzo' => fake()->streetAddress(),
            'citta' => fake()->city(),
            'provincia' => strtoupper(fake()->lexify('??')),
            'cap' => fake()->numerify('#####'),
            'data_iscrizione' => now()->toDateString(),
            'stato' => Socio::STATO_ATTIVO,
            'privacy_consenso' => true,
            'email_opt_out' => false,
        ];
    }

    public function inAttesa(): static
    {
        return $this->state(fn (array $attributes) => ['stato' => Socio::STATO_IN_ATTESA]);
    }

    public function inAttesaDiPagamento(): static
    {
        return $this->state(fn (array $attributes) => ['stato' => Socio::STATO_IN_ATTESA_DI_PAGAMENTO]);
    }

    public function sospeso(): static
    {
        return $this->state(fn (array $attributes) => ['stato' => Socio::STATO_SOSPESO]);
    }

    public function conAccessoAreaSoci(string $password = 'password'): static
    {
        return $this->state(fn (array $attributes) => ['password_hash' => $password]);
    }
}
