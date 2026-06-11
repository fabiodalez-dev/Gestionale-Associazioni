<?php

namespace Tests\Feature\Tessere;

use App\Models\Associazione;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Tests\TestCase;

abstract class TessereTestCase extends TestCase
{
    /**
     * Crea un admin_associazione con ruolo/permessi spatie per il tenant dato.
     */
    protected function creaAdmin(Associazione $associazione): Utente
    {
        $this->seed(RolesSeeder::class);

        /** @var Utente $admin */
        $admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $associazione->id,
        ]);
        $admin->assignRole('admin_associazione');

        return $admin;
    }
}
