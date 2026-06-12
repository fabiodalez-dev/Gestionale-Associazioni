<?php

namespace Tests\Feature\Operativita;

use App\Models\Associazione;
use App\Models\Evento;
use App\Models\SavedFilter;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.5 — Viste salvate per tessere/quote/prenotazioni evento: estensione del
 * pattern SavedFilter (scope 'soci') con salva/applica/elimina e condivisione
 * per tenant.
 */
class VisteSalvateTest extends TestCase
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

    public function test_salva_una_vista_tessere_con_i_parametri_whitelisted(): void
    {
        $this->actingAs($this->admin)
            ->from('/tessere')
            ->post('/viste-salvate/tessere', [
                'preset_name' => 'Scadute 2025',
                'anno' => '2025',
                'stato' => 'Scaduta',
                'search' => 'rossi',
                'parametro_estraneo' => 'ignorato',
            ])
            ->assertRedirect('/tessere')
            ->assertSessionHas('success');

        $vista = SavedFilter::query()->where('scope', 'tessere')->first();

        $this->assertNotNull($vista);
        $this->assertSame('Scadute 2025', $vista->name);
        $this->assertSame($this->admin->id, $vista->user_id);
        $this->assertSame($this->associazione->id, $vista->associazione_id);
        // L'ordine segue la whitelist dello scope ('anno', 'search', 'stato')
        $this->assertSame(
            ['anno' => '2025', 'search' => 'rossi', 'stato' => 'Scaduta'],
            $vista->params_json
        );
    }

    public function test_vista_condivisa_ha_user_id_null(): void
    {
        $this->actingAs($this->admin)
            ->from('/quote')
            ->post('/viste-salvate/quote', [
                'preset_name' => 'Non pagate',
                'status' => 'Da Pagare',
                'condiviso' => '1',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('saved_filters', [
            'scope' => 'quote',
            'name' => 'Non pagate',
            'user_id' => null,
        ]);
    }

    public function test_stesso_nome_fa_upsert(): void
    {
        $this->actingAs($this->admin)->from('/quote')->post('/viste-salvate/quote', [
            'preset_name' => 'Anno corrente',
            'year' => '2025',
        ]);
        $this->actingAs($this->admin)->from('/quote')->post('/viste-salvate/quote', [
            'preset_name' => 'Anno corrente',
            'year' => '2026',
        ]);

        $viste = SavedFilter::query()->where('scope', 'quote')->get();

        $this->assertCount(1, $viste);
        $this->assertSame(['year' => '2026'], $viste->first()->params_json);
    }

    public function test_scope_sconosciuto_404(): void
    {
        $this->actingAs($this->admin)
            ->post('/viste-salvate/inesistente', ['preset_name' => 'X'])
            ->assertNotFound();
    }

    public function test_elimina_una_vista_propria(): void
    {
        $vista = SavedFilter::create([
            'associazione_id' => $this->associazione->id,
            'user_id' => $this->admin->id,
            'scope' => 'prenotazioni',
            'name' => 'Solo confermate',
            'params_json' => ['stato' => 'confermata'],
        ]);

        $this->actingAs($this->admin)
            ->from('/eventi')
            ->delete("/viste-salvate/{$vista->id}")
            ->assertSessionHas('success', 'Vista eliminata.');

        $this->assertDatabaseMissing('saved_filters', ['id' => $vista->id]);
    }

    public function test_non_elimina_la_vista_personale_di_un_altro_utente(): void
    {
        $collega = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $vista = SavedFilter::create([
            'associazione_id' => $this->associazione->id,
            'user_id' => $collega->id,
            'scope' => 'tessere',
            'name' => 'Del collega',
            'params_json' => [],
        ]);

        $this->actingAs($this->admin)
            ->delete("/viste-salvate/{$vista->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('saved_filters', ['id' => $vista->id]);
    }

    public function test_non_gestisce_i_preset_soci_di_competenza_del_modulo_soci(): void
    {
        $preset = SavedFilter::create([
            'associazione_id' => $this->associazione->id,
            'user_id' => $this->admin->id,
            'scope' => 'soci',
            'name' => 'Preset soci',
            'params_json' => [],
        ]);

        $this->actingAs($this->admin)
            ->delete("/viste-salvate/{$preset->id}")
            ->assertNotFound();
    }

    public function test_vista_di_un_altro_tenant_non_raggiungibile(): void
    {
        $altra = Associazione::factory()->create();
        $adminAltrui = Utente::factory()->adminAssociazione()->create(['associazione_id' => $altra->id]);
        $vista = SavedFilter::create([
            'associazione_id' => $altra->id,
            'user_id' => $adminAltrui->id,
            'scope' => 'tessere',
            'name' => 'Estranea',
            'params_json' => [],
        ]);

        $this->actingAs($this->admin)
            ->delete("/viste-salvate/{$vista->id}")
            ->assertNotFound();
    }

    public function test_la_lista_prenotazioni_mostra_le_viste_salvate(): void
    {
        $evento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);
        SavedFilter::create([
            'associazione_id' => $this->associazione->id,
            'user_id' => null,
            'scope' => 'prenotazioni',
            'name' => 'Solo arrivati',
            'params_json' => ['stato' => 'confermata'],
        ]);

        $response = $this->actingAs($this->admin)->get("/eventi/{$evento->id}/prenotazioni");

        $response->assertOk();
        $response->assertSee('Viste salvate');
        $response->assertSee('Solo arrivati (condivisa)');
    }

    public function test_richiede_autenticazione(): void
    {
        $this->post('/viste-salvate/tessere', ['preset_name' => 'X'])->assertRedirect('/login');
    }
}
