<?php

namespace Database\Factories;

use App\Models\Associazione;
use App\Models\Utente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Utente>
 */
class UtenteFactory extends Factory
{
    protected $model = Utente::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'nome' => fake()->firstName(),
            'cognome' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => 'password', // hashed dal cast 'hashed'
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE,
            'attivo' => true,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'ruolo' => Utente::RUOLO_SUPER_ADMIN,
            'associazione_id' => null,
        ]);
    }

    public function adminAssociazione(): static
    {
        return $this->state(fn (array $attributes) => [
            'ruolo' => Utente::RUOLO_ADMIN_ASSOCIAZIONE,
        ]);
    }

    public function operatore(): static
    {
        return $this->state(fn (array $attributes) => [
            'ruolo' => Utente::RUOLO_OPERATORE,
        ]);
    }

    public function disattivo(): static
    {
        return $this->state(fn (array $attributes) => ['attivo' => false]);
    }
}
