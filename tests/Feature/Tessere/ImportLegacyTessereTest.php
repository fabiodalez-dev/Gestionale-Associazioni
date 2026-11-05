<?php

namespace Tests\Feature\Tessere;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * app:import-legacy copia le tessere fuori da Eloquent: la colonna del
 * vincolo unique socio/anno (issue #21) va valorizzata dopo la copia,
 * lasciando fuori (e segnalando) i doppioni legacy senza scartarli.
 */
class ImportLegacyTessereTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_tessere_importate_entrano_nel_vincolo_socio_anno_tranne_i_doppioni(): void
    {
        $associazione = Associazione::factory()->create();
        $socio = Socio::factory()->create(['associazione_id' => $associazione->id]);
        $conDoppioni = Socio::factory()->create(['associazione_id' => $associazione->id]);

        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        Schema::connection('legacy')->create('tessere', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('socio_id', 36);
            $table->char('associazione_id', 36);
            $table->string('numero_tessera', 50);
            $table->integer('anno_validita');
            $table->date('data_emissione');
            $table->date('data_scadenza');
            $table->string('tipo_scadenza', 20);
            $table->string('stato', 20);
        });

        $riga = fn (Socio $s, string $numero, string $stato = Tessera::STATO_ATTIVA): array => [
            'id' => (string) Str::uuid(),
            'socio_id' => $s->id,
            'associazione_id' => $associazione->id,
            'numero_tessera' => $numero,
            'anno_validita' => 2025,
            'data_emissione' => '2025-01-10',
            'data_scadenza' => '2025-12-31',
            'tipo_scadenza' => 'anno_solare',
            'stato' => $stato,
        ];
        $unica = $riga($socio, '20250001');
        $annullata = $riga($socio, '20250002', Tessera::STATO_ANNULLATA);
        $doppia1 = $riga($conDoppioni, '20250003');
        $doppia2 = $riga($conDoppioni, '20250004');
        DB::connection('legacy')->table('tessere')->insert([$unica, $annullata, $doppia1, $doppia2]);

        $this->artisan('app:import-legacy', ['--tables' => 'tessere'])
            ->expectsOutputToContain('1 coppie socio/anno con più tessere non annullate')
            ->expectsOutputToContain("socio {$conDoppioni->id}, anno 2025: tessere 20250003, 20250004")
            ->assertSuccessful();

        // Tutte le righe importate, nessuna modificata nello stato
        $this->assertSame(4, Tessera::allTenants()->count());
        $this->assertDatabaseHas('tessere', ['id' => $unica['id'], 'anno_non_annullata' => 2025]);
        $this->assertDatabaseHas('tessere', ['id' => $annullata['id'], 'stato' => Tessera::STATO_ANNULLATA, 'anno_non_annullata' => null]);
        $this->assertDatabaseHas('tessere', ['id' => $doppia1['id'], 'stato' => Tessera::STATO_ATTIVA, 'anno_non_annullata' => null]);
        $this->assertDatabaseHas('tessere', ['id' => $doppia2['id'], 'stato' => Tessera::STATO_ATTIVA, 'anno_non_annullata' => null]);

        // Un salvataggio che non tocca socio, anno o stato (es. il QR URL
        // persistito alla stampa) non rimette i doppioni nel vincolo: prima
        // il secondo salvataggio violava l'unique (socio, anno)
        foreach ([$doppia1['id'], $doppia2['id']] as $id) {
            $tessera = Tessera::allTenants()->findOrFail($id);
            $tessera->qr_code_url = 'https://example.test/verifica/'.$id;
            $tessera->save();
        }
        $this->assertSame(2, Tessera::allTenants()->where('socio_id', $conDoppioni->id)->whereNull('anno_non_annullata')->count());

        // Cambiare lo stato ricalcola la colonna: annullato un doppione,
        // l'altro torna nel vincolo appena viene modificato nello stato
        Tessera::allTenants()->findOrFail($doppia1['id'])->update(['stato' => Tessera::STATO_ANNULLATA]);
        Tessera::allTenants()->findOrFail($doppia2['id'])->update(['stato' => Tessera::STATO_SOSPESA]);
        $this->assertDatabaseHas('tessere', ['id' => $doppia2['id'], 'anno_non_annullata' => 2025]);
    }
}
