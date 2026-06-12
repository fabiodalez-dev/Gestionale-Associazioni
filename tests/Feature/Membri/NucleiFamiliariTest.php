<?php

namespace Tests\Feature\Membri;

use App\Models\Associazione;
use App\Models\Nucleo;
use App\Models\Socio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.20 (piano "Gestione 2") — Nuclei familiari: creazione dalla scheda
 * socio, aggiunta/rimozione membri, capofamiglia, vista raggruppata,
 * filtro "per nucleo" nella lista soci, isolamento tenant.
 */
class NucleiFamiliariTest extends TestCase
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

    private function creaSocio(array $attributes = []): Socio
    {
        return Socio::factory()->create(array_merge(
            ['associazione_id' => $this->associazione->id],
            $attributes
        ));
    }

    // ------------------------------------------------------------------
    // Creazione e gestione
    // ------------------------------------------------------------------

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/soci-nuclei')->assertRedirect('/login');
    }

    public function test_crea_nucleo_con_capofamiglia_dalla_scheda_socio(): void
    {
        $socio = $this->creaSocio();

        $this->actingAs($this->admin)
            ->from(route('soci.show', $socio))
            ->post('/nuclei', [
                'nome' => 'Famiglia Rossi',
                'capofamiglia_id' => $socio->id,
            ])
            ->assertRedirect(route('soci.show', $socio))
            ->assertSessionHas('success');

        $nucleo = Nucleo::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('Famiglia Rossi', $nucleo->nome);
        $this->assertSame($socio->id, $nucleo->capofamiglia_id);
        $this->assertSame($this->associazione->id, $nucleo->associazione_id);

        // Il capofamiglia entra subito nel nucleo
        $this->assertSame($nucleo->id, $socio->fresh()->nucleo_id);
    }

    public function test_aggiungi_e_rimuovi_membro(): void
    {
        $capofamiglia = $this->creaSocio();
        $figlio = $this->creaSocio();
        $nucleo = Nucleo::factory()->create([
            'associazione_id' => $this->associazione->id,
            'capofamiglia_id' => $capofamiglia->id,
        ]);
        $capofamiglia->forceFill(['nucleo_id' => $nucleo->id])->save();

        $this->actingAs($this->admin)
            ->post("/nuclei/{$nucleo->id}/membri", ['socio_id' => $figlio->id])
            ->assertSessionHas('success');

        $this->assertSame($nucleo->id, $figlio->fresh()->nucleo_id);

        $this->actingAs($this->admin)
            ->delete("/nuclei/{$nucleo->id}/membri/{$figlio->id}")
            ->assertSessionHas('success');

        $this->assertNull($figlio->fresh()->nucleo_id);
        // Il capofamiglia resta invariato
        $this->assertSame($capofamiglia->id, $nucleo->fresh()->capofamiglia_id);
    }

    public function test_rimozione_del_capofamiglia_azzera_il_riferimento_sul_nucleo(): void
    {
        $capofamiglia = $this->creaSocio();
        $nucleo = Nucleo::factory()->create([
            'associazione_id' => $this->associazione->id,
            'capofamiglia_id' => $capofamiglia->id,
        ]);
        $capofamiglia->forceFill(['nucleo_id' => $nucleo->id])->save();

        $this->actingAs($this->admin)
            ->delete("/nuclei/{$nucleo->id}/membri/{$capofamiglia->id}")
            ->assertSessionHas('success');

        $this->assertNull($capofamiglia->fresh()->nucleo_id);
        $this->assertNull($nucleo->fresh()->capofamiglia_id);
    }

    public function test_update_rinomina_e_cambia_capofamiglia(): void
    {
        $socioA = $this->creaSocio();
        $socioB = $this->creaSocio();
        $nucleo = Nucleo::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Vecchio nome',
            'capofamiglia_id' => $socioA->id,
        ]);
        $socioA->forceFill(['nucleo_id' => $nucleo->id])->save();

        $this->actingAs($this->admin)
            ->put("/nuclei/{$nucleo->id}", [
                'nome' => 'Nuovo nome',
                'capofamiglia_id' => $socioB->id,
            ])
            ->assertSessionHas('success');

        $nucleo->refresh();
        $this->assertSame('Nuovo nome', $nucleo->nome);
        $this->assertSame($socioB->id, $nucleo->capofamiglia_id);
        // Il nuovo capofamiglia entra nel nucleo
        $this->assertSame($nucleo->id, $socioB->fresh()->nucleo_id);
    }

    public function test_destroy_scioglie_il_nucleo_e_sgancia_i_membri(): void
    {
        $socio = $this->creaSocio();
        $nucleo = Nucleo::factory()->create([
            'associazione_id' => $this->associazione->id,
            'capofamiglia_id' => $socio->id,
        ]);
        $socio->forceFill(['nucleo_id' => $nucleo->id])->save();

        $this->actingAs($this->admin)
            ->delete("/nuclei/{$nucleo->id}")
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('nuclei', ['id' => $nucleo->id]);
        $this->assertNull($socio->fresh()->nucleo_id);
    }

    // ------------------------------------------------------------------
    // Vista raggruppata e filtro lista
    // ------------------------------------------------------------------

    public function test_vista_per_nuclei_mostra_solo_il_tenant_corrente(): void
    {
        $socio = $this->creaSocio(['cognome' => 'Verdi']);
        $nucleo = Nucleo::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Famiglia Verdi',
            'capofamiglia_id' => $socio->id,
        ]);
        $socio->forceFill(['nucleo_id' => $nucleo->id])->save();

        Nucleo::factory()->create(['nome' => 'Famiglia AltroTenant']);

        $this->actingAs($this->admin)
            ->get('/soci-nuclei')
            ->assertOk()
            ->assertSee('Famiglia Verdi')
            ->assertSee('Capofamiglia')
            ->assertDontSee('Famiglia AltroTenant');
    }

    public function test_filtro_per_nucleo_nella_lista_soci(): void
    {
        $nucleo = Nucleo::factory()->create(['associazione_id' => $this->associazione->id]);
        $dentro = $this->creaSocio(['cognome' => 'DentroNucleo', 'nucleo_id' => $nucleo->id]);
        $fuori = $this->creaSocio(['cognome' => 'FuoriNucleo']);

        // Filtro per nucleo specifico
        $json = $this->actingAs($this->admin)
            ->get('/soci?tessera_stato=all&ajax=1&nucleo='.$nucleo->id)
            ->assertOk()
            ->json();

        $this->assertSame(1, $json['count']);
        $this->assertStringContainsString('DentroNucleo', $json['html']);
        $this->assertStringNotContainsString('FuoriNucleo', $json['html']);

        // Filtro "senza nucleo"
        $json = $this->actingAs($this->admin)
            ->get('/soci?tessera_stato=all&ajax=1&nucleo=senza')
            ->assertOk()
            ->json();

        $this->assertStringContainsString('FuoriNucleo', $json['html']);
        $this->assertStringNotContainsString('DentroNucleo', $json['html']);

        // Filtro "con nucleo"
        $json = $this->actingAs($this->admin)
            ->get('/soci?tessera_stato=all&ajax=1&nucleo=con')
            ->assertOk()
            ->json();

        $this->assertStringContainsString('DentroNucleo', $json['html']);
        $this->assertStringNotContainsString('FuoriNucleo', $json['html']);
    }

    public function test_la_scheda_socio_mostra_il_nucleo(): void
    {
        $socio = $this->creaSocio();
        $nucleo = Nucleo::factory()->create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Famiglia Bianchi',
            'capofamiglia_id' => $socio->id,
        ]);
        $socio->forceFill(['nucleo_id' => $nucleo->id])->save();

        $this->actingAs($this->admin)
            ->get(route('soci.show', $socio))
            ->assertOk()
            ->assertSee('Nucleo familiare')
            ->assertSee('Famiglia Bianchi');
    }

    // ------------------------------------------------------------------
    // Isolamento tenant
    // ------------------------------------------------------------------

    public function test_nucleo_di_un_altro_tenant_da_404(): void
    {
        $nucleoEstraneo = Nucleo::factory()->create();

        $this->actingAs($this->admin)
            ->put("/nuclei/{$nucleoEstraneo->id}", ['nome' => 'Hack'])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->delete("/nuclei/{$nucleoEstraneo->id}")
            ->assertNotFound();
    }

    public function test_un_socio_di_un_altro_tenant_non_e_aggiungibile(): void
    {
        $nucleo = Nucleo::factory()->create(['associazione_id' => $this->associazione->id]);
        $socioEstraneo = Socio::factory()->create();

        $this->actingAs($this->admin)
            ->from('/soci-nuclei')
            ->post("/nuclei/{$nucleo->id}/membri", ['socio_id' => $socioEstraneo->id])
            ->assertSessionHasErrors('socio_id');

        $this->assertNull($socioEstraneo->fresh()->nucleo_id);
    }
}
