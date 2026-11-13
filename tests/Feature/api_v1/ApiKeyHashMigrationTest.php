<?php

namespace Tests\Feature\api_v1;

use App\Models\Associazione;
use App\Services\ApiV1\BackfillHashChiaviApi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Backfill della migration che hasha le chiavi API esistenti
 * (hardening audit run-1): le chiavi in chiaro già in tabella diventano
 * hash + prefisso, e la colonna in chiaro sparisce.
 */
class ApiKeyHashMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_13_110000_hash_api_keys_table.php';

    /**
     * Riporta la tabella allo schema precedente (colonna api_key in chiaro).
     */
    private function tornaAlloSchemaLegacy(): void
    {
        $this->assertSame(0, Artisan::call('migrate:rollback', [
            '--path' => base_path(self::MIGRATION),
            '--realpath' => true,
            '--force' => true,
        ]), Artisan::output());

        $this->assertTrue(Schema::hasColumn('api_keys', 'api_key'));
        $this->assertFalse(Schema::hasColumn('api_keys', 'api_key_hash'));
    }

    private function eseguiMigration(): void
    {
        $this->assertSame(0, Artisan::call('migrate', [
            '--path' => base_path(self::MIGRATION),
            '--realpath' => true,
            '--force' => true,
        ]), Artisan::output());
    }

    /**
     * Inserisce una chiave come la salvava il codice vecchio (in chiaro).
     *
     * @param  array<string, mixed>  $extra
     */
    private function inserisciChiaveLegacy(string $chiaveInChiaro, array $extra = []): string
    {
        $id = (string) Str::uuid();

        DB::table('api_keys')->insert(array_merge([
            'id' => $id,
            'associazione_id' => Associazione::factory()->create()->id,
            'api_key' => $chiaveInChiaro,
            'nome' => 'Chiave legacy in chiaro',
            'permessi' => json_encode(['soci:read']),
            'attiva' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));

        return $id;
    }

    public function test_il_backfill_converte_le_chiavi_in_chiaro_in_hash_e_prefisso(): void
    {
        $this->tornaAlloSchemaLegacy();
        $chiave = str_repeat('c0ffee11', 8); // 64 hex, come generava la pagina Chiavi API
        $id = $this->inserisciChiaveLegacy($chiave);

        $this->eseguiMigration();

        $this->assertFalse(Schema::hasColumn('api_keys', 'api_key'));
        $this->assertDatabaseHas('api_keys', [
            'id' => $id,
            'api_key_hash' => hash('sha256', $chiave),
            'api_key_prefix' => 'c0ffee11',
        ]);

        // La chiave convertita autentica ancora i client esistenti
        $this->getJson('/api/v1/soci', ['Authorization' => 'Bearer '.$chiave])->assertOk();
    }

    public function test_il_backfill_hasha_anche_le_chiavi_legacy_non_esadecimali(): void
    {
        $this->tornaAlloSchemaLegacy();
        $chiave = 'sk_legacy_formato_libero_0001';
        $id = $this->inserisciChiaveLegacy($chiave);

        $this->eseguiMigration();

        $this->assertDatabaseHas('api_keys', [
            'id' => $id,
            'api_key_hash' => hash('sha256', $chiave),
            'api_key_prefix' => 'sk_legac',
        ]);
    }

    public function test_il_backfill_e_idempotente_e_non_rihasha_le_righe_gia_convertite(): void
    {
        $this->tornaAlloSchemaLegacy();

        // Scenario di esecuzione parziale: colonne nuove già presenti e una
        // riga già convertita in un giro precedente.
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('api_key_hash', 64)->nullable();
            $table->string('api_key_prefix', 8)->nullable();
        });

        $daConvertire = str_repeat('ab12', 16);
        $idDaConvertire = $this->inserisciChiaveLegacy($daConvertire);

        $giaConvertita = str_repeat('cd34', 16);
        $idGiaConvertita = $this->inserisciChiaveLegacy($giaConvertita, [
            'api_key_hash' => hash('sha256', $giaConvertita),
            'api_key_prefix' => substr($giaConvertita, 0, 8),
        ]);

        $backfill = new BackfillHashChiaviApi;
        $this->assertSame(1, $backfill->esegui());
        $this->assertSame(0, $backfill->esegui(), 'La seconda esecuzione non tocca nulla');

        $this->assertDatabaseHas('api_keys', [
            'id' => $idDaConvertire,
            'api_key_hash' => hash('sha256', $daConvertire),
            'api_key_prefix' => 'ab12ab12',
        ]);
        // La riga già convertita non è stata ri-hashata (hash dell'hash)
        $this->assertDatabaseHas('api_keys', [
            'id' => $idGiaConvertita,
            'api_key_hash' => hash('sha256', $giaConvertita),
        ]);
        $this->assertSame(0, DB::table('api_keys')->whereNull('api_key_hash')->count());
    }
}
