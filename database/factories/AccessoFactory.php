<?php

namespace Database\Factories;

use App\Models\Accesso;
use App\Models\Utente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Accesso>
 */
class AccessoFactory extends Factory
{
    protected $model = Accesso::class;

    public function definition(): array
    {
        return [
            'associazione_id' => null,
            'guard' => 'web',
            'autenticabile_type' => null,
            'autenticabile_id' => null,
            'email_tentata' => fake()->safeEmail(),
            'ip' => fake()->ipv4(),
            'user_agent' => 'Mozilla/5.0 (Test)',
            'esito' => Accesso::ESITO_LOGIN,
        ];
    }

    public function fallito(): static
    {
        return $this->state(['esito' => Accesso::ESITO_FALLITO]);
    }

    public function logout(): static
    {
        return $this->state(['esito' => Accesso::ESITO_LOGOUT]);
    }

    /**
     * Accesso riuscito di un utente backend (guard web).
     */
    public function diUtente(Utente $utente): static
    {
        return $this->state([
            'associazione_id' => $utente->associazione_id,
            'guard' => 'web',
            'autenticabile_type' => $utente::class,
            'autenticabile_id' => $utente->id,
            'email_tentata' => $utente->email,
        ]);
    }
}
