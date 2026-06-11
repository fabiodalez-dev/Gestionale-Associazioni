<?php

namespace Tests\Feature\Tessere;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GenerazioneMassivaTest extends TessereTestCase
{
    use RefreshDatabase;

    public function test_genera_tessere_per_tutti_i_soci_attivi_senza_tessera_nell_anno(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);
        $anno = (int) now()->year;

        $attivi = Socio::factory()->count(3)->create(['associazione_id' => $assoc->id]);
        Socio::factory()->sospeso()->create(['associazione_id' => $assoc->id]);

        // Un socio attivo ha già una tessera (Annullata): viene saltato comunque
        $giaTesserato = $attivi->first();
        Tessera::factory()->create([
            'socio_id' => $giaTesserato->id,
            'associazione_id' => $assoc->id,
            'anno_validita' => $anno,
            'stato' => 'Annullata',
            'numero_tessera' => 'PRE-1',
        ]);

        $response = $this->actingAs($admin)->post('/tessere/genera-massivo', [
            'anno_validita' => $anno,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', "Generate 2 nuove tessere per l'anno {$anno}.");

        // 1 preesistente + 2 generate; il sospeso e il già tesserato sono esclusi
        $this->assertSame(3, Tessera::allTenants()->where('associazione_id', $assoc->id)->count());

        // Numerazione unificata {anno}{NNNN}
        $numeri = Tessera::allTenants()
            ->where('associazione_id', $assoc->id)
            ->where('numero_tessera', 'like', $anno.'%')
            ->pluck('numero_tessera')
            ->sort()
            ->values();
        $this->assertSame([$anno.'0001', $anno.'0002'], $numeri->all());
    }

    public function test_generazione_massiva_con_tipo_annuale_scadenza_un_anno_dall_emissione(): void
    {
        $assoc = Associazione::factory()->scadenzaAnnuale()->create();
        $admin = $this->creaAdmin($assoc);
        Socio::factory()->create(['associazione_id' => $assoc->id]);

        $this->actingAs($admin)->post('/tessere/genera-massivo', [
            'anno_validita' => (int) now()->year,
        ])->assertRedirect();

        $tessera = Tessera::allTenants()->where('associazione_id', $assoc->id)->firstOrFail();
        $this->assertSame('annuale', $tessera->tipo_scadenza);
        $this->assertSame(now()->addYear()->toDateString(), $tessera->data_scadenza->toDateString());
    }

    public function test_generazione_massiva_non_tocca_i_soci_di_altre_associazioni(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();
        $admin = $this->creaAdmin($assocA);

        Socio::factory()->count(2)->create(['associazione_id' => $assocA->id]);
        Socio::factory()->count(5)->create(['associazione_id' => $assocB->id]);

        $this->actingAs($admin)->post('/tessere/genera-massivo', [
            'anno_validita' => (int) now()->year,
        ])->assertRedirect();

        $this->assertSame(2, Tessera::allTenants()->where('associazione_id', $assocA->id)->count());
        $this->assertSame(0, Tessera::allTenants()->where('associazione_id', $assocB->id)->count());
    }

    public function test_evento_di_altra_associazione_rifiutato(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();
        $admin = $this->creaAdmin($assocA);
        $eventoB = \App\Models\Evento::factory()->create(['associazione_id' => $assocB->id]);

        $response = $this->actingAs($admin)->post('/tessere/genera-massivo', [
            'anno_validita' => (int) now()->year,
            'evento_creazione_id' => $eventoB->id,
        ]);

        $response->assertSessionHasErrors('evento_creazione_id');
    }
}
