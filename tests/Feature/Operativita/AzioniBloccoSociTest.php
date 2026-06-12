<?php

namespace Tests\Feature\Operativita;

use App\Exports\Operativita\SociSelezioneExport;
use App\Http\Controllers\Operativita\AzioniBloccoSociController;
use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Tag;
use App\Models\TipoSocio;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * f.2 — Azioni in blocco sulla lista soci: cambio stato/tipo/categoria/sede,
 * assegnazione/rimozione tag, export della selezione, audit nello storico
 * di ogni socio, isolamento multi-tenant.
 */
class AzioniBloccoSociTest extends TestCase
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

    public function test_cambia_lo_stato_in_blocco_e_scrive_l_audit(): void
    {
        $soci = Socio::factory()->count(3)->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->from('/soci')
            ->post('/soci/azioni-blocco', [
                'azione' => 'stato',
                'valore' => Socio::STATO_SOSPESO,
                'soci_ids' => $soci->pluck('id')->all(),
            ])
            ->assertRedirect('/soci')
            ->assertSessionHas('success');

        foreach ($soci as $socio) {
            $this->assertSame(Socio::STATO_SOSPESO, $socio->fresh()->stato);
            $this->assertDatabaseHas('storico_attivita_socio', [
                'socio_id' => $socio->id,
                'tipo_attivita' => AzioniBloccoSociController::TIPO_ATTIVITA_AUDIT,
                'utente_id' => $this->admin->id,
            ]);
        }
    }

    public function test_cambia_il_tipo_socio_in_blocco(): void
    {
        $tipo = TipoSocio::create([
            'associazione_id' => $this->associazione->id,
            'nome' => 'Ordinario',
        ]);
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->from('/soci')
            ->post('/soci/azioni-blocco', [
                'azione' => 'tipo_socio',
                'valore' => $tipo->id,
                'soci_ids' => [$socio->id],
            ])
            ->assertSessionHas('success');

        $this->assertSame($tipo->id, $socio->fresh()->tipo_socio_id);
    }

    public function test_assegna_e_rimuove_tag_in_blocco(): void
    {
        $tag = Tag::create([
            'associazione_id' => $this->associazione->id,
            'nome_tag' => 'Volontari',
            'colore' => '#FF7B11',
        ]);
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->from('/soci')->post('/soci/azioni-blocco', [
            'azione' => 'tag_assegna',
            'valore' => $tag->id,
            'soci_ids' => [$socio->id],
        ])->assertSessionHas('success');

        $this->assertTrue($socio->fresh()->tags->contains($tag));

        $this->actingAs($this->admin)->from('/soci')->post('/soci/azioni-blocco', [
            'azione' => 'tag_rimuovi',
            'valore' => $tag->id,
            'soci_ids' => [$socio->id],
        ])->assertSessionHas('success');

        $this->assertFalse($socio->fresh()->tags->contains($tag));
    }

    public function test_i_soci_di_un_altro_tenant_vengono_ignorati(): void
    {
        $altra = Associazione::factory()->create();
        $estraneo = Socio::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->from('/soci')
            ->post('/soci/azioni-blocco', [
                'azione' => 'stato',
                'valore' => Socio::STATO_SOSPESO,
                'soci_ids' => [$estraneo->id],
            ])
            ->assertSessionHas('error');

        $this->assertSame(Socio::STATO_ATTIVO, $estraneo->fresh()->stato);
    }

    public function test_valore_non_valido_per_lo_stato_viene_rifiutato(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->from('/soci')
            ->post('/soci/azioni-blocco', [
                'azione' => 'stato',
                'valore' => 'StatoInventato',
                'soci_ids' => [$socio->id],
            ])
            ->assertSessionHasErrors('valore');
    }

    public function test_tipo_socio_di_un_altro_tenant_viene_rifiutato(): void
    {
        $altra = Associazione::factory()->create();
        $tipoAltrui = TipoSocio::create([
            'associazione_id' => $altra->id,
            'nome' => 'Estraneo',
        ]);
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->from('/soci')
            ->post('/soci/azioni-blocco', [
                'azione' => 'tipo_socio',
                'valore' => $tipoAltrui->id,
                'soci_ids' => [$socio->id],
            ])
            ->assertSessionHasErrors('valore');
    }

    public function test_export_della_selezione(): void
    {
        Excel::fake();

        $soci = Socio::factory()->count(2)->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->post('/soci/azioni-blocco/export', [
            'soci_ids' => $soci->pluck('id')->all(),
        ]);

        Excel::assertDownloaded(
            'soci-selezione-'.now()->format('Ymd-His').'.xlsx',
            function (SociSelezioneExport $export) use ($soci) {
                return $export->query()->count() === $soci->count();
            }
        );
    }

    public function test_richiede_autenticazione(): void
    {
        $this->post('/soci/azioni-blocco', [])->assertRedirect('/login');
    }
}
