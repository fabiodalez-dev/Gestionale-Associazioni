<?php

namespace Tests\Feature\Operations;

use App\Models\Associazione;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Rotta /health (miglioramento 20): pagina riservata al super_admin.
 */
class HealthRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/health')->assertRedirect('/login');
    }

    public function test_admin_associazione_non_puo_accedere(): void
    {
        $associazione = Associazione::factory()->create();
        $admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $associazione->id,
        ]);
        $admin->assignRole('admin_associazione');

        $this->actingAs($admin)->get('/health')->assertForbidden();
    }

    public function test_super_admin_vede_la_pagina_health(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin)->get('/health')->assertOk();
    }

    public function test_health_json_non_registrata_senza_secret_token(): void
    {
        $this->assertFalse(Route::has('health.json'));

        $superAdmin = Utente::factory()->superAdmin()->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin)->get('/health/json')->assertNotFound();
    }
}
