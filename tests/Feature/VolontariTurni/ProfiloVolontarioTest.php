<?php

namespace Tests\Feature\VolontariTurni;

use App\Models\Associazione;
use App\Models\Mansione;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.1/f.2 — Profilo volontario: disponibilità + flag + mansioni dalla scheda
 * socio (admin) e dall'area riservata (socio), più lista volontari con
 * filtro per mansione.
 */
class ProfiloVolontarioTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_admin_aggiorna_profilo_volontario_dalla_scheda(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $mansione = Mansione::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->put("/volontari/{$socio->id}/profilo", [
                'volontario_attivo' => '1',
                'disponibilita' => ['lun' => ['mattina', 'sera'], 'sab' => ['pomeriggio']],
                'mansioni' => [$mansione->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $socio->refresh();
        $this->assertTrue($socio->volontario_attivo);
        $this->assertSame(['lun' => ['mattina', 'sera'], 'sab' => ['pomeriggio']], $socio->disponibilita);
        $this->assertTrue($socio->mansioni()->where('mansioni.id', $mansione->id)->exists());
    }

    public function test_fascia_non_valida_viene_rifiutata(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        // Una fascia inesistente fa scattare la regola disponibilita.*.*
        $this->actingAs($this->admin)
            ->put("/volontari/{$socio->id}/profilo", [
                'volontario_attivo' => '1',
                'disponibilita' => ['lun' => ['notte']],
            ])
            ->assertSessionHasErrors();
    }

    public function test_giorno_sconosciuto_viene_scartato_in_normalizzazione(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        // 'xyz' non è un giorno valido: la fascia è valida (passa la validazione)
        // ma il giorno viene scartato in normalizzazione.
        $this->actingAs($this->admin)
            ->put("/volontari/{$socio->id}/profilo", [
                'volontario_attivo' => '1',
                'disponibilita' => ['lun' => ['mattina'], 'xyz' => ['mattina']],
            ])
            ->assertSessionHasNoErrors();

        $socio->refresh();
        $this->assertSame(['lun' => ['mattina']], $socio->disponibilita);
    }

    public function test_mansione_di_altro_tenant_viene_ignorata(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $altra = Associazione::factory()->create();
        $mansioneAltrui = Mansione::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->put("/volontari/{$socio->id}/profilo", [
                'volontario_attivo' => '1',
                'mansioni' => [$mansioneAltrui->id],
            ])
            ->assertRedirect();

        $this->assertSame(0, $socio->mansioni()->count());
    }

    public function test_lista_volontari_filtra_per_mansione(): void
    {
        $mansione = Mansione::factory()->create(['associazione_id' => $this->associazione->id]);

        $conMansione = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Rossi', 'volontario_attivo' => true,
        ]);
        $conMansione->mansioni()->attach($mansione->id);

        $senzaMansione = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Bianchi', 'volontario_attivo' => true,
        ]);

        $response = $this->actingAs($this->admin)->get("/volontari?mansione={$mansione->id}");

        $response->assertOk();
        $ids = $response->viewData('soci')->pluck('id')->all();
        $this->assertContains($conMansione->id, $ids);
        $this->assertNotContains($senzaMansione->id, $ids);
    }

    public function test_socio_aggiorna_la_propria_disponibilita_in_area_riservata(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($socio, 'socio')
            ->patch(route('area-soci.volontariato.disponibilita'), [
                'volontario_attivo' => '1',
                'disponibilita' => ['mer' => ['sera']],
            ])
            ->assertRedirect(route('area-soci.volontariato'))
            ->assertSessionHas('success');

        $socio->refresh();
        $this->assertTrue($socio->volontario_attivo);
        $this->assertSame(['mer' => ['sera']], $socio->disponibilita);
    }

    public function test_scheda_socio_mostra_il_tab_volontariato(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->get(route('soci.show', [$socio, 'tab' => 'volontariato']))
            ->assertOk()
            ->assertSee('Salva profilo volontario')
            ->assertSee('Volontariato');
    }
}
