<?php

namespace Tests\Feature\Progetti;

use App\Models\Associazione;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;

/**
 * Setup comune per i test del modulo progetti (f.12–f.16 "Organizzazione 3").
 */
trait ConfiguraTenantProgetti
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

    /**
     * Operatore senza permessi struttura.* (matrice ruoli legacy):
     * non vede né gestisce i progetti.
     */
    protected function creaOperatore(): Utente
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $operatore->assignRole('operatore');

        return $operatore;
    }
}
