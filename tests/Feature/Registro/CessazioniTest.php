<?php

namespace Tests\Feature\Registro;

use App\Models\Associazione;
use App\Models\Cessazione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Services\Registro\CessazioneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cessazioni formali (f.15 del piano vita sociale): registrazione con
 * aggiornamento stato socio, annullamento tessere attive, storico.
 */
class CessazioniTest extends TestCase
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

    private function creaSocioAttivo(array $attributes = []): Socio
    {
        return Socio::factory()->create(array_merge([
            'associazione_id' => $this->associazione->id,
        ], $attributes));
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('cessazioni.index'))->assertRedirect('/login');
    }

    public function test_pagina_elenca_le_cessazioni_registrate(): void
    {
        $socio = $this->creaSocioAttivo(['cognome' => 'Dimissionari', 'stato' => Socio::STATO_RADIATO]);
        Cessazione::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'tipo' => Cessazione::TIPO_DIMISSIONI,
            'data' => '2026-05-01',
        ]);

        $this->actingAs($this->admin)
            ->get(route('cessazioni.index'))
            ->assertOk()
            ->assertSee('Cessazioni')
            ->assertSee('Dimissionari')
            ->assertSee('01/05/2026');
    }

    public function test_registrazione_dimissioni_aggiorna_stato_annulla_tessere_e_scrive_storico(): void
    {
        $socio = $this->creaSocioAttivo();
        $tesseraAttiva = Tessera::factory()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'stato' => Tessera::STATO_ATTIVA,
        ]);
        $tesseraScaduta = Tessera::factory()->scaduta()->create([
            'socio_id' => $socio->id,
            'associazione_id' => $this->associazione->id,
            'stato' => Tessera::STATO_SCADUTA,
        ]);

        $response = $this->actingAs($this->admin)->post(route('cessazioni.store'), [
            'socio_id' => $socio->id,
            'tipo' => Cessazione::TIPO_DIMISSIONI,
            'data' => '2026-06-01',
            'motivo' => 'Dimissioni volontarie',
            'delibera_numero' => '9/2026',
            'delibera_data' => '2026-05-28',
        ]);

        $response->assertRedirect(route('cessazioni.index'));
        $response->assertSessionHas('success');

        // Stato socio coerente
        $this->assertSame(Socio::STATO_RADIATO, $socio->refresh()->stato);

        // Tessere: l'attiva viene annullata, la scaduta resta scaduta
        $this->assertSame(Tessera::STATO_ANNULLATA, $tesseraAttiva->refresh()->stato);
        $this->assertSame(Tessera::STATO_SCADUTA, $tesseraScaduta->refresh()->stato);

        // Registro cessazioni
        $cessazione = Cessazione::query()->where('socio_id', $socio->id)->first();
        $this->assertNotNull($cessazione);
        $this->assertSame(Cessazione::TIPO_DIMISSIONI, $cessazione->tipo);
        $this->assertSame('2026-06-01', $cessazione->data->toDateString());
        $this->assertSame('Dimissioni volontarie', $cessazione->motivo);
        $this->assertSame('9/2026', $cessazione->delibera_numero);
        $this->assertSame('2026-05-28', $cessazione->delibera_data->toDateString());
        $this->assertSame($this->admin->id, $cessazione->registrata_da);

        // Storico attività del socio
        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => CessazioneService::TIPO_ATTIVITA_STORICO,
        ]);
    }

    public function test_registrazione_decesso_porta_il_socio_a_deceduto(): void
    {
        $socio = $this->creaSocioAttivo();

        $this->actingAs($this->admin)->post(route('cessazioni.store'), [
            'socio_id' => $socio->id,
            'tipo' => Cessazione::TIPO_DECESSO,
            'data' => '2026-06-01',
        ])->assertRedirect(route('cessazioni.index'));

        $this->assertSame(Socio::STATO_DECEDUTO, $socio->refresh()->stato);
    }

    public function test_tipo_non_valido_viene_rifiutato(): void
    {
        $socio = $this->creaSocioAttivo();

        $this->actingAs($this->admin)
            ->from(route('cessazioni.index'))
            ->post(route('cessazioni.store'), [
                'socio_id' => $socio->id,
                'tipo' => 'sparizione',
                'data' => '2026-06-01',
            ])
            ->assertSessionHasErrors('tipo');

        $this->assertSame(0, Cessazione::query()->count());
        $this->assertSame(Socio::STATO_ATTIVO, $socio->refresh()->stato);
    }

    public function test_socio_gia_cessato_non_e_ricessabile(): void
    {
        $socio = $this->creaSocioAttivo(['stato' => Socio::STATO_RADIATO]);

        $response = $this->actingAs($this->admin)->post(route('cessazioni.store'), [
            'socio_id' => $socio->id,
            'tipo' => Cessazione::TIPO_DIMISSIONI,
            'data' => '2026-06-01',
        ]);

        $response->assertRedirect(route('cessazioni.index'));
        $response->assertSessionHas('error');
        $this->assertSame(0, Cessazione::query()->count());
    }

    public function test_isolamento_tenant_socio_di_altra_associazione_viene_rifiutato(): void
    {
        $altra = Associazione::factory()->create();
        $socioAltrui = Socio::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->from(route('cessazioni.index'))
            ->post(route('cessazioni.store'), [
                'socio_id' => $socioAltrui->id,
                'tipo' => Cessazione::TIPO_DIMISSIONI,
                'data' => '2026-06-01',
            ])
            ->assertSessionHasErrors('socio_id');

        $this->assertSame(0, Cessazione::allTenants()->count());
        $this->assertSame(Socio::STATO_ATTIVO, $socioAltrui->refresh()->stato);
    }

    public function test_isolamento_tenant_l_elenco_non_mostra_cessazioni_altrui(): void
    {
        $altra = Associazione::factory()->create();
        $socioAltrui = Socio::factory()->create([
            'associazione_id' => $altra->id,
            'cognome' => 'Fuoritenant',
        ]);
        Cessazione::factory()->create([
            'socio_id' => $socioAltrui->id,
            'associazione_id' => $altra->id,
            'tipo' => Cessazione::TIPO_DECADENZA,
        ]);

        $this->actingAs($this->admin)
            ->get(route('cessazioni.index'))
            ->assertOk()
            ->assertDontSee('Fuoritenant');
    }

    public function test_operatore_puo_registrare_una_cessazione(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $socio = $this->creaSocioAttivo();

        $this->actingAs($operatore)->post(route('cessazioni.store'), [
            'socio_id' => $socio->id,
            'tipo' => Cessazione::TIPO_DECADENZA,
            'data' => '2026-06-01',
        ])->assertRedirect(route('cessazioni.index'));

        $this->assertSame(Socio::STATO_RADIATO, $socio->refresh()->stato);
        $this->assertSame(1, Cessazione::query()->count());
    }
}
