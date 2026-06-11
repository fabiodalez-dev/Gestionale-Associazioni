<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'associazione_id' => Associazione::factory(),
            'nome' => fake()->firstName(),
            'cognome' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'email_verified_at' => now(),
            'telefono' => fake()->numerify('3#########'),
            'socio_id' => null,
            'attivo' => true,
            'preferenze' => null,
            'privacy_accettata_at' => now(),
        ];
    }

    public function verificato(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => now(),
        ]);
    }

    public function nonVerificato(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function disattivato(): static
    {
        return $this->state(fn (array $attributes) => [
            'attivo' => false,
        ]);
    }

    /**
     * Account collegato a un socio (stessa associazione, socio_id unique).
     */
    public function collegatoASocio(?Socio $socio = null): static
    {
        return $this->afterCreating(function (Account $account) use ($socio) {
            $socio ??= Socio::factory()->create([
                'associazione_id' => $account->associazione_id,
            ]);

            $account->forceFill(['socio_id' => $socio->id])->save();
        });
    }
}
