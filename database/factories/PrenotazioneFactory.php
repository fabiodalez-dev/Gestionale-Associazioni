<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Evento;
use App\Models\Prenotazione;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prenotazione>
 */
class PrenotazioneFactory extends Factory
{
    protected $model = Prenotazione::class;

    public function definition(): array
    {
        return [
            'evento_id' => Evento::factory(),
            // Stessa associazione dell'evento (coerenza multi-tenant)
            'associazione_id' => function (array $attributes) {
                return Evento::withoutGlobalScopes()->find($attributes['evento_id'])->associazione_id;
            },
            'account_id' => function (array $attributes) {
                return Account::factory()->create([
                    'associazione_id' => Evento::withoutGlobalScopes()
                        ->find($attributes['evento_id'])->associazione_id,
                ])->id;
            },
            'codice' => Prenotazione::generaCodice(),
            'qr_token' => Prenotazione::generaQrToken(),
            'stato' => Prenotazione::STATO_CONFERMATA,
            'posti' => 1,
            'prezzo_unitario' => 0,
            'importo_totale' => 0,
        ];
    }

    public function confermata(): static
    {
        return $this->state(fn (array $attributes) => [
            'stato' => Prenotazione::STATO_CONFERMATA,
        ]);
    }

    public function inAttesaPagamento(float $prezzoUnitario = 10.0): static
    {
        return $this->state(fn (array $attributes) => [
            'stato' => Prenotazione::STATO_IN_ATTESA_PAGAMENTO,
            'prezzo_unitario' => $prezzoUnitario,
            'importo_totale' => $prezzoUnitario * (int) ($attributes['posti'] ?? 1),
            'scadenza_pagamento_at' => now()->addMinutes(30),
        ]);
    }

    public function inApprovazione(): static
    {
        return $this->state(fn (array $attributes) => [
            'stato' => Prenotazione::STATO_IN_APPROVAZIONE,
        ]);
    }

    public function listaAttesa(): static
    {
        return $this->state(fn (array $attributes) => [
            'stato' => Prenotazione::STATO_LISTA_ATTESA,
        ]);
    }

    public function annullata(): static
    {
        return $this->state(fn (array $attributes) => [
            'stato' => Prenotazione::STATO_ANNULLATA,
            'annullata_at' => now(),
            'motivo_annullamento' => 'Annullata dall\'utente',
        ]);
    }

    public function scaduta(): static
    {
        return $this->state(fn (array $attributes) => [
            'stato' => Prenotazione::STATO_SCADUTA,
            'prezzo_unitario' => 10,
            'importo_totale' => 10 * (int) ($attributes['posti'] ?? 1),
            'scadenza_pagamento_at' => now()->subMinutes(5),
        ]);
    }

    public function pagata(): static
    {
        return $this->state(fn (array $attributes) => [
            'stato' => Prenotazione::STATO_CONFERMATA,
            'gateway' => fake()->randomElement(['stripe', 'paypal']),
            'gateway_transaction_id' => fake()->uuid(),
            'pagata_at' => now(),
        ]);
    }

    public function conCheckin(): static
    {
        return $this->state(fn (array $attributes) => [
            'stato' => Prenotazione::STATO_CONFERMATA,
            'checkin_at' => now(),
        ]);
    }
}
