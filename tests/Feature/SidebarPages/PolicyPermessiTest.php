<?php

namespace Tests\Feature\SidebarPages;

use App\Models\Associazione;
use App\Models\Utente;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressione sull'irrigidimento delle policy: con i permessi spatie seedati,
 * l'accesso ai moduli richiede il permesso granulare (niente accesso implicito
 * per il solo fatto di essere autenticati).
 */
class PolicyPermessiTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->associazione = Associazione::factory()->create();
    }

    public function test_utente_senza_ruolo_spatie_non_accede_a_eventi_e_documenti(): void
    {
        $utente = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        // Nessun assignRole: i permessi esistono ma l'utente non li ha

        $this->actingAs($utente)->get('/eventi')->assertForbidden();
        $this->actingAs($utente)->get('/documenti')->assertForbidden();
    }

    public function test_operatore_con_ruolo_assegnato_accede_a_eventi_e_documenti(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $operatore->assignRole('operatore');

        $this->actingAs($operatore)->get('/eventi')->assertOk();
        $this->actingAs($operatore)->get('/documenti')->assertOk();
    }

    public function test_operatore_con_ruolo_assegnato_resta_escluso_dalle_api(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $operatore->assignRole('operatore');

        $this->actingAs($operatore)->get('/api-keys')->assertForbidden();
        $this->actingAs($operatore)->get('/api-docs')->assertForbidden();
    }

    public function test_admin_associazione_con_ruolo_assegnato_accede_alle_api(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $admin->assignRole('admin_associazione');

        $this->actingAs($admin)->get('/api-keys')->assertOk();
        $this->actingAs($admin)->get('/api-docs')->assertOk();
    }
}
