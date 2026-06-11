<?php

namespace Tests\Feature\Foundation;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_scope_filtra_i_soci_per_associazione_dell_utente(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();

        Socio::factory()->count(2)->create(['associazione_id' => $assocA->id]);
        Socio::factory()->create(['associazione_id' => $assocB->id]);

        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $assocA->id]);

        $this->actingAs($admin);

        $this->assertSame(2, Socio::count());
        $this->assertSame(3, Socio::allTenants()->count());
    }

    public function test_super_admin_senza_tenant_selezionato_vede_tutto(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();

        Socio::factory()->create(['associazione_id' => $assocA->id]);
        Socio::factory()->create(['associazione_id' => $assocB->id]);

        $superAdmin = Utente::factory()->superAdmin()->create();
        $this->actingAs($superAdmin);

        $this->assertSame(2, Socio::count());

        session(['associazione_id' => $assocA->id]);
        $this->assertSame(1, Socio::count());
    }

    public function test_associazione_id_viene_autocompilata_alla_creazione(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $assoc->id]);

        $this->actingAs($admin);

        $socio = Socio::factory()->create(['associazione_id' => null]);

        $this->assertSame($assoc->id, $socio->associazione_id);
    }

    public function test_guard_socio_autentica_il_socio(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci('segreta123')->create();

        $this->assertTrue(
            auth()->guard('socio')->attempt([
                'email' => $socio->email,
                'password' => 'segreta123',
            ])
        );

        $this->assertSame($socio->id, auth()->guard('socio')->id());
    }

    public function test_utente_usa_password_hash_come_colonna_password(): void
    {
        $utente = Utente::factory()->create(['password_hash' => 'password123']);

        $this->assertTrue(
            auth()->guard('web')->attempt([
                'email' => $utente->email,
                'password' => 'password123',
            ])
        );
    }
}
