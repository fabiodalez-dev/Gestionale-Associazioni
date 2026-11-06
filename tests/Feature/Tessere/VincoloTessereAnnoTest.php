<?php

namespace Tests\Feature\Tessere;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Services\Tessere\VincoloTessereAnnoService;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * Installazione aggiornata con tessere doppie già presenti: la migration
 * del vincolo socio/anno (issue #21) l'ha rinviato. Senza terminale, le
 * pagine Tessere e Aggiornamenti elencano i doppioni e il vincolo si attiva
 * da solo appena sono sistemati dal backoffice (o di notte, dal comando).
 */
class VincoloTessereAnnoTest extends TessereTestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Socio $socio;

    private Tessera $prima;

    private Tessera $doppione;

    protected function setUp(): void
    {
        parent::setUp();

        // Come dopo la migration con doppioni: colonna presente, indice no
        Schema::table('tessere', fn (Blueprint $table) => $table->dropUnique(VincoloTessereAnnoService::INDICE));
        VincoloTessereAnnoService::dimenticaStato();

        $this->associazione = Associazione::factory()->create();
        $this->socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Bianchi',
            'nome' => 'Anna',
        ]);
        $base = ['associazione_id' => $this->associazione->id, 'socio_id' => $this->socio->id, 'anno_validita' => 2026, 'stato' => Tessera::STATO_ATTIVA];
        $this->prima = Tessera::factory()->create($base + ['numero_tessera' => '20260001']);
        $this->doppione = Tessera::factory()->create($base + ['numero_tessera' => '20260001-B']);
    }

    protected function tearDown(): void
    {
        VincoloTessereAnnoService::dimenticaStato();

        parent::tearDown();
    }

    private function vincolo(): VincoloTessereAnnoService
    {
        return app(VincoloTessereAnnoService::class);
    }

    private function indicePresente(): bool
    {
        return Schema::hasIndex('tessere', VincoloTessereAnnoService::INDICE, 'unique');
    }

    public function test_con_doppioni_il_vincolo_resta_spento_e_i_doppioni_sono_elencati(): void
    {
        $this->assertFalse($this->vincolo()->applicaSePossibile());
        $this->assertFalse($this->indicePresente());

        $doppioni = $this->vincolo()->doppioni();
        $this->assertCount(1, $doppioni);
        $this->assertSame('Bianchi Anna', $doppioni[0]->nominativo);
        $this->assertSame(2026, $doppioni[0]->anno);
        $this->assertSame(2, $doppioni[0]->totale);
        $this->assertStringContainsString('20260001', $doppioni[0]->numeri);
        $this->assertStringContainsString('20260001-B', $doppioni[0]->numeri);

        // Nessuna tessera toccata
        $this->assertSame(Tessera::STATO_ATTIVA, $this->doppione->fresh()->stato);
    }

    public function test_annullare_il_doppione_dal_backoffice_attiva_subito_il_vincolo(): void
    {
        $this->doppione->update(['stato' => Tessera::STATO_ANNULLATA]);

        $this->assertTrue($this->indicePresente());
        $this->assertSame([], $this->vincolo()->doppioni()->all());
    }

    public function test_eliminare_il_doppione_attiva_subito_il_vincolo(): void
    {
        $this->doppione->delete();

        $this->assertTrue($this->indicePresente());
    }

    public function test_un_salvataggio_che_non_tocca_socio_anno_o_stato_non_tenta_il_vincolo(): void
    {
        // Il secondo doppione ha ancora la colonna del vincolo valorizzata:
        // un salvataggio qualsiasi (es. il QR alla stampa) non deve fallire
        $this->doppione->update(['qr_code_url' => 'https://example.test/qr']);

        $this->assertFalse($this->indicePresente());
    }

    public function test_la_pagina_tessere_elenca_i_doppioni_e_dopo_la_correzione_il_vincolo_e_attivo(): void
    {
        $admin = $this->creaAdmin($this->associazione);

        $this->actingAs($admin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('tessere.index'))
            ->assertOk()
            ->assertSee('Tessere doppie da sistemare')
            ->assertSee('Bianchi Anna')
            ->assertSee('20260001-B');

        // Correzione fuori dal modello (es. riga cancellata da un'altra
        // installazione o da SQL): la pagina stessa attiva il vincolo
        Tessera::allTenants()->whereKey($this->doppione->id)->toBase()->delete();

        $this->actingAs($admin)
            ->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('tessere.index'))
            ->assertOk()
            ->assertDontSee('Tessere doppie da sistemare');
        $this->assertTrue($this->indicePresente());
    }

    public function test_la_pagina_tessere_non_mostra_i_nomi_dei_soci_di_altre_associazioni(): void
    {
        $altra = Associazione::factory()->create();
        $admin = $this->creaAdmin($altra);

        $this->actingAs($admin)
            ->withSession(['associazione_id' => $altra->id])
            ->get(route('tessere.index'))
            ->assertOk()
            ->assertSee('Tessere doppie da sistemare')
            ->assertDontSee('Bianchi Anna')
            ->assertSee('Altri 1 casi riguardano soci che non vedi da qui');
    }

    public function test_la_pagina_aggiornamenti_avvisa_il_super_admin(): void
    {
        $this->seed(RolesSeeder::class);
        $super = Utente::factory()->superAdmin()->create();
        $super->assignRole('super_admin');

        $this->actingAs($super)
            ->get(route('aggiornamenti.index'))
            ->assertOk()
            ->assertSee('1 socio ha più tessere valide nello stesso anno', false);
    }

    public function test_il_comando_notturno_elenca_i_doppioni_poi_applica_il_vincolo(): void
    {
        $this->artisan('tessere:applica-vincolo-anno')
            ->expectsOutputToContain('Vincolo non ancora applicato')
            ->expectsOutputToContain('Bianchi Anna (2026)')
            ->assertSuccessful();
        $this->assertFalse($this->indicePresente());

        Tessera::allTenants()->whereKey($this->doppione->id)->toBase()->update(['stato' => Tessera::STATO_ANNULLATA]);

        $this->artisan('tessere:applica-vincolo-anno')
            ->expectsOutputToContain('Vincolo applicato')
            ->assertSuccessful();
        $this->assertTrue($this->indicePresente());

        // La colonna del vincolo è riallineata anche per l'update fuori dal modello
        $this->assertNull(Tessera::allTenants()->find($this->doppione->id)->getAttribute('anno_non_annullata'));
    }
}
