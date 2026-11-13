<?php

namespace Tests\Feature\api_v1;

use App\Models\ApiKey;
use App\Models\Associazione;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Migration del flag esplicito api_keys.globale (audit run-2): le chiavi
 * esistenti senza associazione restano globali (compatibilità), la FK passa
 * a cascadeOnDelete, l'import legacy valorizza il flag con la stessa regola.
 */
class ApiKeyGlobaleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_13_140500_add_globale_to_api_keys_table.php';

    private function eseguiArtisan(string $comando): void
    {
        $this->assertSame(0, Artisan::call($comando, [
            '--path' => base_path(self::MIGRATION),
            '--realpath' => true,
            '--force' => true,
        ]), Artisan::output());
    }

    /**
     * @return array{columns: list<string>, on_delete: string}|null
     */
    private function foreignKeyAssociazione(): ?array
    {
        $fk = collect(Schema::getForeignKeys('api_keys'))
            ->first(fn (array $fk) => $fk['columns'] === ['associazione_id']);

        return $fk === null ? null : ['columns' => $fk['columns'], 'on_delete' => strtolower((string) $fk['on_delete'])];
    }

    private function inserisciChiave(?string $associazioneId, string $nome): string
    {
        $id = (string) Str::uuid();
        DB::table('api_keys')->insert([
            'id' => $id,
            'associazione_id' => $associazioneId,
            'api_key_hash' => hash('sha256', $nome),
            'api_key_prefix' => substr(hash('sha256', $nome), 0, 8),
            'nome' => $nome,
            'permessi' => json_encode(['*']),
            'attiva' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_le_chiavi_esistenti_senza_associazione_restano_globali_e_la_fk_diventa_cascade(): void
    {
        $this->eseguiArtisan('migrate:rollback');
        $this->assertFalse(Schema::hasColumn('api_keys', 'globale'));
        $this->assertSame('set null', $this->foreignKeyAssociazione()['on_delete'] ?? null);

        $associazione = Associazione::factory()->create();
        $globale = $this->inserisciChiave(null, 'Globale esistente');
        $scopata = $this->inserisciChiave($associazione->id, 'Scopata esistente');

        $this->eseguiArtisan('migrate');

        $this->assertTrue(ApiKey::findOrFail($globale)->isGlobale());
        $this->assertFalse((bool) ApiKey::findOrFail($scopata)->globale);
        $this->assertSame('cascade', $this->foreignKeyAssociazione()['on_delete'] ?? null);

        // Con la cascade l'eliminazione dell'associazione porta via le sue chiavi
        DB::table('associazioni')->where('id', $associazione->id)->delete();
        $this->assertDatabaseMissing('api_keys', ['id' => $scopata]);
        $this->assertDatabaseHas('api_keys', ['id' => $globale, 'globale' => true]);
    }

    public function test_la_migration_si_puo_annullare_e_riapplicare(): void
    {
        $this->eseguiArtisan('migrate:rollback');
        $this->eseguiArtisan('migrate');

        $this->assertTrue(Schema::hasColumn('api_keys', 'globale'));
        $this->assertSame('cascade', $this->foreignKeyAssociazione()['on_delete'] ?? null);
    }

    public function test_l_import_legacy_marca_globali_solo_le_chiavi_senza_associazione(): void
    {
        $associazione = Associazione::factory()->create(['attiva' => true]);

        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        Schema::connection('legacy')->create('api_keys', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('associazione_id', 36)->nullable();
            $table->string('api_key', 64);
            $table->string('nome');
            $table->text('permessi');
            $table->boolean('attiva')->default(true);
        });
        $chiaveGlobale = str_repeat('a1', 32);
        $chiaveScopata = str_repeat('b2', 32);
        DB::connection('legacy')->table('api_keys')->insert([
            ['id' => (string) Str::uuid(), 'associazione_id' => null, 'api_key' => $chiaveGlobale, 'nome' => 'Legacy globale', 'permessi' => json_encode(['*']), 'attiva' => true],
            ['id' => (string) Str::uuid(), 'associazione_id' => $associazione->id, 'api_key' => $chiaveScopata, 'nome' => 'Legacy scopata', 'permessi' => json_encode(['*']), 'attiva' => true],
        ]);

        $this->artisan('app:import-legacy', ['--tables' => 'api_keys'])->assertSuccessful();

        $this->assertTrue(ApiKey::query()->where('nome', 'Legacy globale')->sole()->isGlobale());
        $this->assertFalse((bool) ApiKey::query()->where('nome', 'Legacy scopata')->sole()->globale);
        $this->getJson('/api/v1/associazioni', ['Authorization' => 'Bearer '.$chiaveGlobale])->assertOk();
    }
}
