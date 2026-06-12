<?php

namespace Tests\Feature\Rinnovi;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\TipoSocio;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Tests\TestCase;

/**
 * Setup comune per i test del modulo rinnovi (f.1–f.6 piano Vita sociale).
 */
abstract class RinnoviTestCase extends TestCase
{
    protected Associazione $associazione;

    protected Utente $admin;

    protected function configuraTenant(): void
    {
        $this->seed(RolesSeeder::class);

        $this->associazione = Associazione::factory()->create([
            'costo_tessera' => 20.00,
        ]);

        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->admin->assignRole('admin_associazione');
    }

    /**
     * Utente del tenant SENZA alcun permesso spatie (per i test 403).
     */
    protected function creaUtenteSenzaPermessi(): Utente
    {
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

    protected function creaTipoSocio(float $costoTessera = 35.00): TipoSocio
    {
        // TipoSocio non ha factory: creazione diretta
        return TipoSocio::create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Ordinario '.fake()->unique()->numberBetween(1, 9999),
            'costo_tessera' => $costoTessera,
        ]);
    }
}
