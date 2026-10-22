<?php

namespace Tests\Feature\Sistema;

use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Avvisi "Da gestire" nella barra in alto e app installabile nel pannello.
 */
class AvvisiTopbarTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_scadute_tra_gli_avvisi_e_manifest_della_pwa(): void
    {
        $this->seed(RolesSeeder::class);
        $associazione = Associazione::factory()->create();
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $associazione->id]);
        $admin->assignRole(Utente::RUOLO_ADMIN_ASSOCIAZIONE);
        $socio = Socio::factory()->create(['associazione_id' => $associazione->id]);
        Quota::create(['associazione_id' => $associazione->id, 'socio_id' => $socio->id, 'anno' => now()->year, 'importo' => 25, 'data_scadenza' => now()->subDays(5)->toDateString(), 'stato' => 'Da Pagare', 'tipo' => 'Quota Associativa']);

        $this->actingAs($admin)->withSession(['associazione_id' => $associazione->id])
            ->get(route('dashboard'))->assertOk()
            ->assertSee('quota scaduta non pagata')
            ->assertSee('rel="manifest"', false)
            ->assertSee('service-worker.js', false);
    }

    public function test_il_service_worker_non_salva_le_pagine(): void
    {
        $sw = (string) file_get_contents(public_path('service-worker.js'));
        $this->assertStringNotContainsString('DYNAMIC_CACHE', $sw);
        $this->assertStringContainsString("pathname.indexOf('/assets/') === 0", $sw);
    }
}
