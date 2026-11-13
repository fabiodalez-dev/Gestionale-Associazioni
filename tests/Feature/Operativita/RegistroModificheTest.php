<?php

namespace Tests\Feature\Operativita;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Tessera;
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

    /**
     * Il solo causer non basta: un admin del tenant che modifica un dato di
     * un'altra associazione (es. utente spostato di tenant) non porta quella
     * riga nel registro del tenant corrente.
     */
    public function test_il_causer_del_tenant_non_basta_se_il_dato_e_di_un_altro_tenant(): void
    {
        $altra = Associazione::factory()->create();
        $socioAltrui = Socio::factory()->create([
            'associazione_id' => $altra->id,
            'nome' => 'Estraneo',
        ]);
        $this->modificaSocio($this->admin, $socioAltrui, 'EstraneoModificato');

        $response = $this->actingAs($this->admin)->get('/registro-modifiche');

        $response->assertOk();
        $response->assertDontSee('EstraneoModificato');
        $this->assertFalse(
            collect($response->viewData('attivita')->items())->pluck('subject_id')->contains($socioAltrui->id)
        );
    }

    /**
     * Le eliminazioni fisiche restano consultabili: la riga non esiste più
     * nella tabella, ma l'associazione_id loggato in attribute_changes la
     * assegna al tenant.
     */
    public function test_resta_visibile_l_eliminazione_fisica_di_un_dato_del_tenant(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $tessera = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'numero_tessera' => 'TES-ELIMINATA',
        ]);

        $this->actingAs($this->admin);
        $tessera->delete();
        auth()->logout();
        $this->assertDatabaseMissing('tessere', ['id' => $tessera->id]);

        $eliminazioni = $this->actingAs($this->admin)->get('/registro-modifiche?azione=deleted&modello=Tessera');
        $eliminazioni->assertOk();
        $this->assertTrue(
            collect($eliminazioni->viewData('attivita')->items())->pluck('subject_id')->contains($tessera->id)
        );

        // ...ma non nel registro di un altro tenant
        $altra = Associazione::factory()->create();
        $adminAltrui = Utente::factory()->adminAssociazione()->create(['associazione_id' => $altra->id]);
        $altrui = $this->actingAs($adminAltrui)->get('/registro-modifiche?azione=deleted&modello=Tessera');
        $altrui->assertOk();
        $this->assertEmpty($altrui->viewData('attivita')->items());
    }
}
