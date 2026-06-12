<?php

namespace Tests\Feature\Economia;

use App\Models\Associazione;
use App\Models\PaymentGatewaySetting;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;

/**
 * Setup comune per i test del modulo economia (f.13–f.18 "Gestione 2").
 */
trait ConfiguraTenantEconomia
{
    protected Associazione $associazione;

    protected Utente $admin;

    protected function configuraTenant(): void
    {
        $this->seed(RolesSeeder::class);

        $this->associazione = Associazione::factory()->create();

        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->admin->assignRole('admin_associazione');
    }

    protected function creaUtenteSenzaPermessi(): Utente
    {
        // Operatore senza ruoli assegnati → nessun permesso spatie
        return Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    protected function creaSocio(array $attributes = []): Socio
    {
        return Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            ...$attributes,
        ]);
    }

    protected function creaQuota(Socio $socio, array $attributes = []): Quota
    {
        return Quota::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'data_pagamento' => null,
            ...$attributes,
        ]);
    }

    protected function configuraStripe(array $attributes = []): PaymentGatewaySetting
    {
        return PaymentGatewaySetting::factory()->conStripe()->create([
            'associazione_id' => $this->associazione->id,
            ...$attributes,
        ]);
    }

    protected function configuraPayPal(array $attributes = []): PaymentGatewaySetting
    {
        return PaymentGatewaySetting::factory()->conPayPal()->create([
            'associazione_id' => $this->associazione->id,
            ...$attributes,
        ]);
    }
}
