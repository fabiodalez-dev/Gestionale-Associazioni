<?php

namespace Tests\Feature\Auth;

use App\Models\Associazione;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_seleziona_un_associazione(): void
    {
        $associazione = Associazione::factory()->create();
        $superAdmin = Utente::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post('/tenant/switch', [
            'associazione_id' => $associazione->id,
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertSame($associazione->id, session('associazione_id'));
        $this->assertSame($associazione->nome, session('associazione_nome'));
    }

    public function test_super_admin_torna_alla_vista_globale(): void
    {
        $associazione = Associazione::factory()->create();
        $superAdmin = Utente::factory()->superAdmin()->create();

        session(['associazione_id' => $associazione->id, 'associazione_nome' => $associazione->nome]);

        $response = $this->actingAs($superAdmin)->post('/tenant/switch', [
            'associazione_id' => '',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertNull(session('associazione_id'));
        $this->assertNull(session('associazione_nome'));
    }

    public function test_associazione_disattivata_non_selezionabile(): void
    {
        $associazione = Associazione::factory()->inattiva()->create();
        $superAdmin = Utente::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post('/tenant/switch', [
            'associazione_id' => $associazione->id,
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('error');
        $this->assertNull(session('associazione_id'));
    }

    public function test_admin_associazione_non_puo_cambiare_tenant(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create();
        $altraAssociazione = Associazione::factory()->create();

        $response = $this->actingAs($admin)->post('/tenant/switch', [
            'associazione_id' => $altraAssociazione->id,
        ]);

        $response->assertForbidden();
    }
}
