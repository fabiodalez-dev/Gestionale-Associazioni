<?php

namespace Tests\Feature\Sistema;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Disinstallazione: prova, annullamento, esecuzione su un database separato.
 */
class AppDisinstallaTest extends TestCase
{
    private string $db;

    private string $lock;

    private string $cartella;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = sys_get_temp_dir().'/disinstalla-'.uniqid().'.sqlite';
        touch($this->db);
        config(['database.connections.da_disinstallare' => ['driver' => 'sqlite', 'database' => $this->db, 'prefix' => '', 'foreign_key_constraints' => true]]);
        Schema::connection('da_disinstallare')->create('soci', fn ($t) => $t->id());
        DB::connection('da_disinstallare')->table('soci')->insert(['id' => 1]);
        $this->lock = sys_get_temp_dir().'/installed-'.uniqid().'.lock';
        file_put_contents($this->lock, 'ok');
        // Mai le cartelle vere del progetto: solo cartelle temporanee
        $this->cartella = sys_get_temp_dir().'/caricati-'.uniqid();
        File::makeDirectory($this->cartella);
        file_put_contents($this->cartella.'/logo.png', 'x');
        config(['installer.lock_file' => $this->lock, 'installer.cartelle_file' => [$this->cartella]]);
    }

    protected function tearDown(): void
    {
        File::delete([$this->db, $this->lock]);
        File::deleteDirectory($this->cartella);
        parent::tearDown();
    }

    public function test_prova_non_tocca_nulla(): void
    {
        $this->artisan('app:disinstalla', ['--prova' => true, '--database' => 'da_disinstallare'])
            ->expectsOutputToContain('Prova: nessuna modifica')
            ->assertSuccessful();
        $this->assertTrue(Schema::connection('da_disinstallare')->hasTable('soci'));
        $this->assertFileExists($this->lock);
        $this->assertFileExists($this->cartella.'/logo.png');
    }

    public function test_senza_conferma_si_annulla(): void
    {
        $this->artisan('app:disinstalla', ['--database' => 'da_disinstallare', '--senza-backup' => true])
            ->expectsQuestion('Scrivi DISINSTALLA per confermare', 'no')
            ->assertFailed();
        $this->assertTrue(Schema::connection('da_disinstallare')->hasTable('soci'));
    }

    public function test_cancella_tabelle_e_riporta_all_installer(): void
    {
        $this->artisan('app:disinstalla', ['--database' => 'da_disinstallare', '--senza-backup' => true])
            ->expectsQuestion('Scrivi DISINSTALLA per confermare', 'DISINSTALLA')
            ->assertSuccessful();
        $this->assertFalse(Schema::connection('da_disinstallare')->hasTable('soci'));
        $this->assertFileDoesNotExist($this->lock);
        $this->assertFileDoesNotExist($this->cartella.'/logo.png');
    }
}
