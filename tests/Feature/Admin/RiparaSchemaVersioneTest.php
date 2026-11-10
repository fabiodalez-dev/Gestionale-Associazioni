<?php

namespace Tests\Feature\Admin;

use App\Services\Update\RiparaSchema;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Il controllo dello schema al primo caricamento dopo un cambio di versione
 * (#29): una volta per versione, dopo la risposta. Su SQLite non c'è nulla
 * da riparare, ma il marcatore va scritto lo stesso per non riprovare a ogni
 * richiesta.
 */
class RiparaSchemaVersioneTest extends TestCase
{
    private string $marcatore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marcatore = storage_path(RiparaSchema::MARCATORE);
        File::delete($this->marcatore);
    }

    protected function tearDown(): void
    {
        File::delete($this->marcatore);
        parent::tearDown();
    }

    public function test_al_cambio_di_versione_controlla_una_volta_dopo_la_risposta(): void
    {
        app(RiparaSchema::class)->seNuovaVersione('9.9.9');
        $this->assertFileDoesNotExist($this->marcatore, 'Il controllo parte dopo la risposta, non durante');

        $this->app->terminate();

        $this->assertSame('9.9.9', trim((string) file_get_contents($this->marcatore)));
    }

    public function test_stessa_versione_gia_verificata_non_fa_nulla(): void
    {
        file_put_contents($this->marcatore, "9.9.9\n");
        $prima = filemtime($this->marcatore);
        touch($this->marcatore, $prima - 100);

        app(RiparaSchema::class)->seNuovaVersione('9.9.9');
        $this->app->terminate();

        $this->assertSame($prima - 100, filemtime($this->marcatore));
    }
}
