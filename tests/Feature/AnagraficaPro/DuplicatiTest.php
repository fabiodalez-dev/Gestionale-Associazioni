<?php

namespace Tests\Feature\AnagraficaPro;

use App\Models\Associazione;
use App\Models\DocumentoSocio;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\StoricoAttivitaSocio;
use App\Models\Tessera;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.24 PIANO-VITA-SOCIALE: pagina "Possibili duplicati" (match
 * email/CF/nome+cognome+nascita normalizzati) e merge assistito.
 */
class DuplicatiTest extends TestCase
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

    private function socio(array $override = []): Socio
    {
        return Socio::factory()->create(array_merge([
            'associazione_id' => $this->associazione->id,
        ], $override));
    }

    public function test_rileva_duplicati_per_email_cf_e_anagrafica_normalizzati(): void
    {
        // Email uguale a meno di maiuscole/spazi
        $this->socio(['email' => 'mario.rossi@example.com', 'nome' => 'Mario', 'cognome' => 'Rossi']);
        $this->socio(['email' => '  MARIO.ROSSI@example.com ', 'nome' => 'M.', 'cognome' => 'Rossi']);

        // Codice fiscale uguale (case-insensitive)
        $this->socio(['codice_fiscale' => 'RSSMRA80A01H501U', 'email' => 'a@example.com']);
        $this->socio(['codice_fiscale' => 'rssmra80a01h501u', 'email' => 'b@example.com']);

        // Nome+cognome+data nascita (spazi e maiuscole diversi)
        $this->socio(['nome' => 'Anna  Maria', 'cognome' => 'Verdi', 'data_nascita' => '1990-05-01', 'email' => 'c@example.com']);
        $this->socio(['nome' => 'anna maria', 'cognome' => 'VERDI', 'data_nascita' => '1990-05-01', 'email' => 'd@example.com']);

        // Socio senza duplicati
        $this->socio(['email' => 'unico@example.com']);

        $response = $this->actingAs($this->admin)->get('/soci-duplicati');

        $response->assertOk();
        $response->assertSee('Stessa email');
        $response->assertSee('Stesso codice fiscale');
        $response->assertSee('Stessi nome, cognome e data di nascita');
        $response->assertSee('mario.rossi@example.com');
        $response->assertSee('RSSMRA80A01H501U');
        $response->assertDontSee('unico@example.com');
    }

    public function test_i_duplicati_di_altri_tenant_non_compaiono(): void
    {
        $altra = Associazione::factory()->create();
        // Stessa email normalizzata (l'unique per tenant è case-sensitive sul raw)
        Socio::factory()->create(['associazione_id' => $altra->id, 'email' => 'estraneo@example.com']);
        Socio::factory()->create(['associazione_id' => $altra->id, 'email' => 'ESTRANEO@example.com']);

        $response = $this->actingAs($this->admin)->get('/soci-duplicati');

        $response->assertOk();
        $response->assertDontSee('estraneo@example.com');
        $response->assertSee('Nessun possibile duplicato rilevato');
    }

    public function test_merge_sposta_dati_e_soft_elimina_il_duplicato(): void
    {
        $principale = $this->socio(['email' => 'doppio@example.com', 'telefono' => null, 'codice_fiscale' => null]);
        $duplicato = $this->socio(['email' => 'doppio2@example.com', 'telefono' => '3331112223', 'codice_fiscale' => 'RSSMRA80A01H501U']);

        $tessera = Tessera::factory()->create(['socio_id' => $duplicato->id, 'associazione_id' => $this->associazione->id]);
        $quota = Quota::factory()->create(['socio_id' => $duplicato->id, 'associazione_id' => $this->associazione->id]);
        $documento = DocumentoSocio::create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $duplicato->id,
            'nome_file' => 'doc.pdf',
            'percorso_file' => 'documenti-soci/test/doc.pdf',
        ]);
        $storico = StoricoAttivitaSocio::create([
            'associazione_id' => $this->associazione->id,
            'socio_id' => $duplicato->id,
            'tipo_attivita' => 'Iscrizione',
            'descrizione' => 'Iscrizione iniziale',
            'data_attivita' => now(),
        ]);
        $evento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);
        $partecipazione = EventoPartecipante::create([
            'associazione_id' => $this->associazione->id,
            'evento_id' => $evento->id,
            'socio_id' => $duplicato->id,
            'stato_partecipazione' => 'Confermato',
        ]);

        $response = $this->actingAs($this->admin)->post('/soci-duplicati/unisci', [
            'principale_id' => $principale->id,
            'duplicato_id' => $duplicato->id,
        ]);

        $response->assertRedirect(route('soci-duplicati.index'));
        $response->assertSessionHas('success');

        $this->assertSame($principale->id, $tessera->refresh()->socio_id);
        $this->assertSame($principale->id, $quota->refresh()->socio_id);
        $this->assertSame($principale->id, $documento->refresh()->socio_id);
        $this->assertSame($principale->id, $storico->refresh()->socio_id);
        $this->assertSame($principale->id, $partecipazione->refresh()->socio_id);

        // Campi mancanti completati dal duplicato
        $principale->refresh();
        $this->assertSame('3331112223', $principale->telefono);
        $this->assertSame('RSSMRA80A01H501U', $principale->codice_fiscale);

        // Duplicato nel cestino (soft delete), principale vivo
        $this->assertSoftDeleted('soci', ['id' => $duplicato->id]);
        $this->assertNull($principale->deleted_at);

        // L'unione è tracciata nello storico del principale
        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $principale->id,
            'tipo_attivita' => 'Unione duplicati',
        ]);
    }

    public function test_merge_non_sposta_partecipazioni_in_conflitto(): void
    {
        $principale = $this->socio(['email' => 'p@example.com']);
        $duplicato = $this->socio(['email' => 'd@example.com']);
        $evento = Evento::factory()->create(['associazione_id' => $this->associazione->id]);

        foreach ([$principale, $duplicato] as $socio) {
            EventoPartecipante::create([
                'associazione_id' => $this->associazione->id,
                'evento_id' => $evento->id,
                'socio_id' => $socio->id,
                'stato_partecipazione' => 'Confermato',
            ]);
        }

        $this->actingAs($this->admin)->post('/soci-duplicati/unisci', [
            'principale_id' => $principale->id,
            'duplicato_id' => $duplicato->id,
        ])->assertRedirect(route('soci-duplicati.index'));

        // La partecipazione del duplicato resta sul duplicato (conflitto unique)
        $this->assertDatabaseHas('eventi_partecipanti', [
            'evento_id' => $evento->id,
            'socio_id' => $duplicato->id,
        ]);
        $this->assertSame(
            1,
            EventoPartecipante::allTenants()->where('socio_id', $principale->id)->count()
        );
    }

    public function test_merge_con_socio_di_altro_tenant_restituisce_404(): void
    {
        $principale = $this->socio();
        $altra = Associazione::factory()->create();
        $estraneo = Socio::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)->post('/soci-duplicati/unisci', [
            'principale_id' => $principale->id,
            'duplicato_id' => $estraneo->id,
        ])->assertNotFound();

        $this->assertNull($estraneo->refresh()->deleted_at);
    }

    public function test_merge_dello_stesso_socio_viene_rifiutato(): void
    {
        $socio = $this->socio();

        $this->actingAs($this->admin)->post('/soci-duplicati/unisci', [
            'principale_id' => $socio->id,
            'duplicato_id' => $socio->id,
        ])->assertSessionHasErrors('principale_id');

        $this->assertNull($socio->refresh()->deleted_at);
    }

    public function test_pagina_duplicati_richiede_login(): void
    {
        $this->get('/soci-duplicati')->assertRedirect();
        $this->post('/soci-duplicati/unisci', [])->assertRedirect();
    }
}
