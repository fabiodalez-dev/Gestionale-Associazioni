<?php

namespace Tests\Feature\Operativita;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.6 — Registro modifiche: UI di consultazione del log spatie/activitylog
 * con filtri per modello/utente/periodo/azione, diff leggibile dei campi,
 * permesso struttura.view, isolamento tenant.
 */
class RegistroModificheTest extends TestCase
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

    /**
     * Esegue una modifica reale come admin per popolare l'activity log.
     */
    private function modificaSocio(Utente $autore, Socio $socio, string $nuovoNome): void
    {
        $this->actingAs($autore);
        $socio->update(['nome' => $nuovoNome]);
        auth()->logout();
    }

    public function test_mostra_le_modifiche_con_diff_leggibile(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Mario',
        ]);
        $this->modificaSocio($this->admin, $socio, 'Marione');

        $response = $this->actingAs($this->admin)->get('/registro-modifiche');

        $response->assertOk();
        $response->assertViewIs('operativita.registro-modifiche.index');
        $response->assertSee('Registro modifiche');
        // Diff leggibile: vecchio valore → nuovo valore
        $response->assertSee('Mario');
        $response->assertSee('Marione');
        $response->assertSee('Modifica');
    }

    public function test_filtra_per_azione_e_modello(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Filtrato',
        ]);
        $this->modificaSocio($this->admin, $socio, 'FiltratoDopo');

        // Solo le 'created' non devono includere la modifica
        $soloCreazioni = $this->actingAs($this->admin)->get('/registro-modifiche?azione=created');
        $soloCreazioni->assertOk();
        $this->assertTrue(
            collect($soloCreazioni->viewData('attivita')->items())->every(fn ($a) => $a->event === 'created')
        );

        // Filtro per modello Socio
        $soloSoci = $this->actingAs($this->admin)->get('/registro-modifiche?modello=Socio');
        $soloSoci->assertOk();
        $this->assertTrue(
            collect($soloSoci->viewData('attivita')->items())->every(fn ($a) => $a->subject_type === Socio::class)
        );
        $this->assertNotEmpty($soloSoci->viewData('attivita')->items());
    }

    public function test_filtra_per_utente_e_periodo(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $this->modificaSocio($this->admin, $socio, 'PerUtente');

        $perUtente = $this->actingAs($this->admin)
            ->get('/registro-modifiche?utente_id='.$this->admin->id);
        $perUtente->assertOk();
        $this->assertNotEmpty($perUtente->viewData('attivita')->items());

        $futuro = $this->actingAs($this->admin)
            ->get('/registro-modifiche?dal='.now()->addYear()->toDateString());
        $futuro->assertOk();
        $this->assertEmpty($futuro->viewData('attivita')->items());
    }

    public function test_non_mostra_le_modifiche_di_un_altro_tenant(): void
    {
        $altra = Associazione::factory()->create();
        $adminAltrui = Utente::factory()->adminAssociazione()->create(['associazione_id' => $altra->id]);
        $socioAltrui = Socio::factory()->create([
            'associazione_id' => $altra->id,
            'nome' => 'Riservato',
        ]);
        $this->modificaSocio($adminAltrui, $socioAltrui, 'RiservatoModificato');

        $response = $this->actingAs($this->admin)->get('/registro-modifiche');

        $response->assertOk();
        $response->assertDontSee('RiservatoModificato');

        $ids = collect($response->viewData('attivita')->items())->pluck('subject_id');
        $this->assertFalse($ids->contains($socioAltrui->id));
    }

    public function test_vietata_all_operatore_senza_struttura_view(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)->get('/registro-modifiche')->assertForbidden();
    }

    public function test_accessibile_al_super_admin(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get('/registro-modifiche')
            ->assertOk();
    }

    public function test_richiede_autenticazione(): void
    {
        $this->get('/registro-modifiche')->assertRedirect('/login');
    }
}
