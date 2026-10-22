<?php

namespace Tests\Feature\Sedi;

use App\Models\Associazione;
use App\Models\CategoriaMovimento;
use App\Models\Evento;
use App\Models\Movimento;
use App\Models\Prenotazione;
use App\Models\Quota;
use App\Models\Sede;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Services\Economia\PrimaNotaService;
use App\Support\PerimetroSedi;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerimetroSediTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Sede $padova;

    private Sede $vicenza;

    private Utente $responsabile;

    private Socio $socioPadova;

    private Socio $socioVicenza;

    private Socio $socioSenzaSede;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->padova = Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Padova']);
        $this->vicenza = Sede::create(['associazione_id' => $this->associazione->id, 'nome' => 'Vicenza']);

        $this->responsabile = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $this->responsabile->assegnaSedi([$this->padova->id]);

        $crea = fn (?Sede $sede, string $cognome) => Socio::factory()->create([
            'associazione_id' => $this->associazione->id, 'sede_id' => $sede?->id, 'cognome' => $cognome,
            'stato' => Socio::STATO_ATTIVO,
        ]);
        $this->socioPadova = $crea($this->padova, 'Padovani');
        $this->socioVicenza = $crea($this->vicenza, 'Vicentini');
        $this->socioSenzaSede = $crea(null, 'Senzasede');
    }

    private function comeResponsabile(): self
    {
        return $this->actingAs($this->responsabile)->withSession(['associazione_id' => $this->associazione->id]);
    }

    public function test_il_perimetro_dipende_dal_ruolo_e_dalle_sedi(): void
    {
        $this->assertSame([$this->padova->id], PerimetroSedi::sediDi($this->responsabile));

        $operatoreSenzaSedi = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $this->assertNull(PerimetroSedi::sediDi($operatoreSenzaSedi));

        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $admin->assegnaSedi([$this->padova->id]);
        $this->assertNull(PerimetroSedi::sediDi($admin), 'Gli admin non sono mai limitati');

        // Sedi di un'altra associazione ignorate
        $estranea = Sede::create(['associazione_id' => Associazione::factory()->create()->id, 'nome' => 'Altrove']);
        $operatoreSenzaSedi->assegnaSedi([$estranea->id]);
        $this->assertNull(PerimetroSedi::sediDi($operatoreSenzaSedi));
    }

    public function test_soci_e_dati_collegati_solo_della_propria_sede(): void
    {
        foreach ([$this->socioPadova, $this->socioVicenza] as $socio) {
            Quota::factory()->create(['associazione_id' => $this->associazione->id, 'socio_id' => $socio->id]);
            Tessera::factory()->create(['associazione_id' => $this->associazione->id, 'socio_id' => $socio->id]);
        }

        $this->comeResponsabile()->get(route('soci.index', ['tessera_stato' => 'all']))
            ->assertOk()->assertSee('Padovani')->assertDontSee('Vicentini')->assertDontSee('Senzasede');

        $this->comeResponsabile()->get(route('soci.show', $this->socioPadova))->assertOk();
        $this->comeResponsabile()->get(route('soci.show', $this->socioVicenza))->assertNotFound();
        $this->comeResponsabile()->get(route('soci.show', $this->socioSenzaSede))->assertNotFound();

        $this->actingAs($this->responsabile);
        $this->assertSame([$this->socioPadova->id], Quota::query()->pluck('socio_id')->all());
        $this->assertSame([$this->socioPadova->id], Tessera::query()->pluck('socio_id')->all());
        $this->assertSame(['Padova'], Sede::query()->pluck('nome')->all());
    }

    public function test_nuovo_socio_solo_in_una_propria_sede(): void
    {
        $dati = fn (?string $sede) => [
            'nome' => 'Nuovo', 'cognome' => 'Socio', 'email' => 'nuovo'.uniqid().'@example.com',
            'data_iscrizione' => now()->toDateString(), 'data_nascita' => '1990-01-01', 'stato' => Socio::STATO_ATTIVO, 'sede_id' => $sede,
        ];

        $this->comeResponsabile()->post(route('soci.store'), $dati(null))->assertSessionHasErrors('sede_id');
        $this->comeResponsabile()->post(route('soci.store'), $dati($this->vicenza->id))->assertSessionHasErrors('sede_id');
        $this->comeResponsabile()->post(route('soci.store'), $dati($this->padova->id))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('soci', ['nome' => 'Nuovo', 'sede_id' => $this->padova->id]);
    }

    public function test_eventi_della_sede_e_di_tutta_l_associazione(): void
    {
        $crea = fn (?Sede $sede, string $titolo) => Evento::factory()->pubblicato()->create([
            'associazione_id' => $this->associazione->id, 'sede_id' => $sede?->id, 'titolo' => $titolo,
        ]);
        $dellaSede = $crea($this->padova, 'Festa di Padova');
        $generale = $crea(null, 'Assemblea generale');
        $altra = $crea($this->vicenza, 'Gita di Vicenza');

        $this->actingAs($this->responsabile);
        $this->assertEqualsCanonicalizing([$dellaSede->id, $generale->id], Evento::query()->pluck('id')->all());

        // Modifica solo degli eventi della propria sede, non di quelli di tutta l'associazione
        $this->assertTrue($this->responsabile->can('update', $dellaSede));
        $this->assertFalse($this->responsabile->can('update', $generale));
        $this->assertFalse($this->responsabile->can('delete', $generale));

        $this->comeResponsabile()->get(route('eventi.index'))
            ->assertOk()->assertSee('Festa di Padova')->assertSee('Assemblea generale')->assertDontSee('Gita di Vicenza');

        $this->assertFalse(Evento::query()->whereKey($altra->id)->exists());
    }

    public function test_movimenti_automatici_ereditano_la_sede_del_socio(): void
    {
        $quota = Quota::factory()->create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $this->socioVicenza->id,
            'stato' => 'pagata',
            'importo' => 25,
            'data_pagamento' => now()->toDateString(),
        ]);

        app(PrimaNotaService::class)->registraIncassoQuotaBestEffort($quota->id);

        $movimento = Movimento::allTenants()->withoutGlobalScope('sede')->where('origine_id', $quota->id)->first();
        $this->assertNotNull($movimento);
        $this->assertSame($this->vicenza->id, $movimento->sede_id);

        // Il responsabile di Padova non vede il movimento di Vicenza
        $this->actingAs($this->responsabile);
        $this->assertFalse(Movimento::query()->whereKey($movimento->id)->exists());
    }

    public function test_l_admin_assegna_le_sedi_dalla_pagina_ruoli(): void
    {
        $this->seed(RolesSeeder::class);
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $estranea = Sede::create(['associazione_id' => Associazione::factory()->create()->id, 'nome' => 'Altrove']);

        $this->actingAs($admin)->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('ruoli.index'))->assertOk()->assertSee('Responsabile di sede')->assertSee('Vicenza');

        $this->actingAs($admin)->withSession(['associazione_id' => $this->associazione->id])
            ->put(route('ruoli.update', $operatore), ['ruoli' => ['responsabile_sede'], 'sedi' => [$estranea->id]])
            ->assertSessionHasErrors('sedi.0');

        $this->actingAs($admin)->withSession(['associazione_id' => $this->associazione->id])
            ->put(route('ruoli.update', $operatore), ['ruoli' => ['responsabile_sede'], 'sedi' => [$this->vicenza->id]])
            ->assertRedirect(route('ruoli.index'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'sedi Vicenza'));

        $this->assertSame([$this->vicenza->id], $operatore->fresh()->sediAssegnateIds());
        $this->assertTrue($operatore->fresh()->hasRole('responsabile_sede'));

        // Nessuna sede = tutta l'associazione
        $this->actingAs($admin)->withSession(['associazione_id' => $this->associazione->id])
            ->put(route('ruoli.update', $operatore), ['ruoli' => ['operatore']]);
        $this->assertSame([], $operatore->fresh()->sediAssegnateIds());
    }

    public function test_senza_sedi_assegnate_nessun_limite(): void
    {
        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($operatore);

        $this->actingAs($operatore)->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('soci.index', ['tessera_stato' => 'all']))->assertOk()->assertSee('Padovani')->assertSee('Vicentini')->assertSee('Senzasede');
    }

    public function test_prenotazioni_filtrate_tramite_l_evento(): void
    {
        $evPadova = Evento::factory()->pubblicato()->create(['associazione_id' => $this->associazione->id, 'sede_id' => $this->padova->id]);
        $evVicenza = Evento::factory()->pubblicato()->create(['associazione_id' => $this->associazione->id, 'sede_id' => $this->vicenza->id]);
        $p1 = Prenotazione::factory()->create(['evento_id' => $evPadova->id]);
        Prenotazione::factory()->create(['evento_id' => $evVicenza->id]);

        $this->actingAs($this->responsabile);
        $this->assertSame([$p1->id], Prenotazione::query()->pluck('id')->all());
    }

    public function test_movimento_manuale_e_form_con_le_sole_sedi_proprie(): void
    {
        $this->seed(RolesSeeder::class);
        $this->responsabile->syncRoles(['responsabile_sede']);
        $categoria = CategoriaMovimento::factory()->create(['associazione_id' => $this->associazione->id, 'tipo' => 'entrata']);
        $dati = ['tipo' => 'entrata', 'importo' => 10, 'data' => now()->toDateString(), 'categoria_id' => $categoria->id];

        $this->comeResponsabile()->post(route('prima-nota.store'), $dati)->assertSessionHasErrors('sede_id');
        $this->comeResponsabile()->post(route('prima-nota.store'), $dati + ['sede_id' => $this->vicenza->id])->assertSessionHasErrors('sede_id');
        $this->comeResponsabile()->post(route('prima-nota.store'), $dati + ['sede_id' => $this->padova->id])->assertRedirect(route('prima-nota.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('movimenti', ['categoria_id' => $categoria->id, 'sede_id' => $this->padova->id]);

        $this->comeResponsabile()->get(route('eventi.index'))
            ->assertOk()
            ->assertSee('<option value="'.$this->padova->id.'"', false)
            ->assertDontSee('<option value="'.$this->vicenza->id.'"', false)
            ->assertDontSee('Tutta l&#039;associazione', false);
    }

    public function test_la_ricerca_globale_rispetta_il_perimetro(): void
    {
        $this->comeResponsabile()->getJson(route('ricerca-globale', ['q' => 'Vicentini']))
            ->assertOk()->assertJsonPath('gruppi', []);
        $this->comeResponsabile()->getJson(route('ricerca-globale', ['q' => 'Padovani']))
            ->assertOk()->assertJsonPath('gruppi.0.risultati.0.titolo', fn (string $t) => str_starts_with($t, 'Padovani'));
    }
}
