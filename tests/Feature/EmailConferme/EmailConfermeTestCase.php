<?php

namespace Tests\Feature\EmailConferme;

use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base comune dei test del modulo email-conferme (f.18–21):
 * ruoli/permessi spatie + tenant + helper su soci e quote.
 */
abstract class EmailConfermeTestCase extends TestCase
{
    use RefreshDatabase;

    protected Associazione $associazione;

    protected Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->admin->assignRole('admin_associazione');
    }

    protected function creaOperatore(?Associazione $associazione = null): Utente
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => ($associazione ?? $this->associazione)->id,
        ]);
        $operatore->assignRole('operatore');

        return $operatore;
    }

    protected function creaAdminPer(Associazione $associazione): Utente
    {
        $admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $associazione->id,
        ]);
        $admin->assignRole('admin_associazione');

        return $admin;
    }

    protected function creaSocio(array $attributes = [], ?Associazione $associazione = null): Socio
    {
        return Socio::factory()->create(array_merge([
            'associazione_id' => ($associazione ?? $this->associazione)->id,
        ], $attributes));
    }

    protected function creaQuotaPagata(Socio $socio, array $attributes = []): Quota
    {
        return Quota::factory()->create(array_merge([
            'socio_id' => $socio->id,
            'associazione_id' => $socio->associazione_id,
            'anno' => (int) now()->year,
            'data_pagamento' => now()->toDateString(),
            'metodo_pagamento' => 'bonifico',
        ], $attributes));
    }
}
