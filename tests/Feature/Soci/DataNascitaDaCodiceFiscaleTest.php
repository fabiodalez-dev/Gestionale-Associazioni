<?php

namespace Tests\Feature\Soci;

use App\Models\Associazione;
use App\Models\Socio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Data di nascita ricavata dal codice fiscale quando manca (#59): vale per
 * ogni salvataggio (scheda, iscrizione dal sito, import, API).
 */
class DataNascitaDaCodiceFiscaleTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    protected function setUp(): void
    {
        parent::setUp();
        $this->associazione = Associazione::factory()->create();
    }

    public function test_data_mancante_ricavata_dal_codice_fiscale(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'codice_fiscale' => 'rssmra85t10a562s',
            'data_nascita' => null,
        ]);

        $this->assertSame('1985-12-10', $socio->fresh()->data_nascita->toDateString());
    }

    public function test_anche_con_omocodia(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'codice_fiscale' => 'RSSMRA85T10A56NH',
            'data_nascita' => null,
        ]);

        $this->assertSame('1985-12-10', $socio->fresh()->data_nascita->toDateString());
    }

    public function test_data_inserita_mai_sovrascritta(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'codice_fiscale' => 'RSSMRA85T10A562S',
            'data_nascita' => '1990-01-01',
        ]);

        $this->assertSame('1990-01-01', $socio->fresh()->data_nascita->toDateString());
    }

    public function test_codice_non_valido_o_assente_non_tocca_nulla(): void
    {
        $nonValido = Socio::factory()->create(['associazione_id' => $this->associazione->id, 'codice_fiscale' => 'RSSMRA85T10A562X', 'data_nascita' => null]);
        $assente = Socio::factory()->create(['associazione_id' => $this->associazione->id, 'codice_fiscale' => null, 'data_nascita' => null]);

        $this->assertNull($nonValido->fresh()->data_nascita);
        $this->assertNull($assente->fresh()->data_nascita);
    }

    public function test_codice_aggiunto_dopo_compila_la_data(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id, 'codice_fiscale' => null, 'data_nascita' => null]);

        $socio->update(['codice_fiscale' => 'RSSMRA85T10A562S']);

        $this->assertSame('1985-12-10', $socio->fresh()->data_nascita->toDateString());
    }
}
