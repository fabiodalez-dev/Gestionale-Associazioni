<?php

namespace Tests\Feature\Dashboard;

use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La dashboard mostra incassi, soci e richieste solo a chi ha i permessi
 * dei rispettivi moduli.
 */
class DashboardPermessiTest extends TestCase
{
    use RefreshDatabase;

    public function test_chi_vede_solo_gli_eventi_non_vede_incassi_ne_soci(): void
    {
        $this->seed(RolesSeeder::class);
        $associazione = Associazione::factory()->create();
        $socio = Socio::factory()->create(['associazione_id' => $associazione->id, 'nome' => 'Riservato', 'cognome' => 'Nominativo']);
        Quota::create(['associazione_id' => $associazione->id, 'socio_id' => $socio->id, 'anno' => now()->year, 'importo' => 777, 'stato' => 'Pagata', 'data_pagamento' => now()->toDateString(), 'tipo' => 'Quota Associativa', 'data_scadenza' => now()->endOfYear()->toDateString()]);

        $soloEventi = Role::findOrCreate('solo_eventi_test', 'web');
        $soloEventi->syncPermissions(['eventi.view']);
        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $associazione->id]);
        $operatore->syncRoles([$soloEventi]);

        $this->actingAs($operatore)->withSession(['associazione_id' => $associazione->id])
            ->get(route('dashboard'))->assertOk()
            ->assertDontSee('Quote incassate')
            ->assertDontSee('777')
            ->assertDontSee('Nominativo')
            ->assertDontSee('Composizione soci')
            ->assertDontSee('Rinnovi in scadenza');

        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $associazione->id]);
        $admin->assignRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE);
        $this->actingAs($admin)->withSession(['associazione_id' => $associazione->id])
            ->get(route('dashboard'))->assertOk()
            ->assertSee('Quote incassate')
            ->assertSee('€ 777,00')
            ->assertSee('Nominativo')
            ->assertSee('Composizione soci');
    }
}
