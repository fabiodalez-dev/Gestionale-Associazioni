<?php

namespace Tests\Feature\Soci;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Tag;
use App\Models\Tessera;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SociCrudTest extends TestCase
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

    private function payloadSocio(array $override = []): array
    {
        return array_merge([
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'email' => 'mario.rossi@example.com',
            'data_nascita' => '1985-12-10',
            'data_iscrizione' => now()->toDateString(),
            'stato' => Socio::STATO_ATTIVO,
            'telefono' => '3331234567',
            'privacy_consenso' => '1',
        ], $override);
    }

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get('/soci')->assertRedirect('/login');
    }

    public function test_operatore_accede_alla_lista_soci(): void
    {
        $operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        $this->actingAs($operatore)->get('/soci')->assertOk()->assertViewIs('soci.index');
    }

    public function test_creazione_socio_con_numero_progressivo_tessera_e_storico(): void
    {
        $response = $this->actingAs($this->admin)->post('/soci', $this->payloadSocio());

        $socio = Socio::query()->where('email', 'mario.rossi@example.com')->first();

        $this->assertNotNull($socio);
        $response->assertRedirect(route('soci.show', $socio));

        // Numero socio progressivo zero-padded a 3 cifre (spec §1.5)
        $this->assertSame('001', $socio->numero_socio);
        $this->assertSame($this->associazione->id, $socio->associazione_id);

        // Tessera automatica per l'anno corrente (spec §1.6), numero a 4 cifre
        $tessera = $socio->tessere()->first();
        $this->assertNotNull($tessera);
        $this->assertSame('0001', $tessera->numero_tessera);
        $this->assertSame((int) now()->year, $tessera->anno_validita);
        $this->assertSame(Tessera::STATO_ATTIVA, $tessera->stato);
        $this->assertNotEmpty($tessera->qr_code_url);

        // Storico attività: Iscrizione + Generazione Tessera (spec §9)
        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Iscrizione',
        ]);
        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Generazione Tessera',
        ]);
    }

    public function test_il_progressivo_numero_socio_incrementa_per_associazione(): void
    {
        Socio::factory()->create(['associazione_id' => $this->associazione->id, 'numero_socio' => '007']);

        // Un'altra associazione non influenza il progressivo
        Socio::factory()->create(['numero_socio' => '999']);

        $this->actingAs($this->admin)->post('/soci', $this->payloadSocio());

        $this->assertDatabaseHas('soci', [
            'email' => 'mario.rossi@example.com',
            'numero_socio' => '008',
        ]);
    }

    public function test_flag_genera_tessera_disattivato_non_crea_la_tessera(): void
    {
        $this->actingAs($this->admin)->post('/soci', $this->payloadSocio(['genera_tessera' => '0']));

        $socio = Socio::query()->where('email', 'mario.rossi@example.com')->first();

        $this->assertNotNull($socio);
        $this->assertSame(0, $socio->tessere()->count());
    }

    public function test_validazione_campi_obbligatori(): void
    {
        $response = $this->actingAs($this->admin)
            ->from('/soci/crea')
            ->post('/soci', ['nome' => '', 'cognome' => '', 'email' => 'non-valida']);

        $response->assertRedirect('/soci/crea');
        $response->assertSessionHasErrors(['nome', 'cognome', 'email', 'data_nascita']);
    }

    public function test_codice_fiscale_invalido_viene_rifiutato_e_quello_valido_normalizzato_uppercase(): void
    {
        $this->actingAs($this->admin)
            ->post('/soci', $this->payloadSocio(['codice_fiscale' => 'XXXXXXXXXXXXXXXX']))
            ->assertSessionHasErrors('codice_fiscale');

        $this->actingAs($this->admin)
            ->post('/soci', $this->payloadSocio(['codice_fiscale' => 'rssmra85t10a562s']))
            ->assertSessionDoesntHaveErrors('codice_fiscale');

        $this->assertDatabaseHas('soci', [
            'email' => 'mario.rossi@example.com',
            'codice_fiscale' => 'RSSMRA85T10A562S',
        ]);
    }

    public function test_codice_fiscale_duplicato_nella_stessa_associazione_viene_rifiutato(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'codice_fiscale' => 'RSSMRA85T10A562S',
        ]);

        $this->actingAs($this->admin)
            ->post('/soci', $this->payloadSocio(['codice_fiscale' => 'RSSMRA85T10A562S']))
            ->assertSessionHasErrors('codice_fiscale');
    }

    public function test_aggiornamento_socio_con_tag_e_storico(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $tag = Tag::create([
            'associazione_id' => $this->associazione->id,
            'nome_tag' => 'Volontario',
            'colore' => '#FF7B11',
        ]);

        $response = $this->actingAs($this->admin)->put("/soci/{$socio->id}", $this->payloadSocio([
            'numero_socio' => $socio->numero_socio,
            'email' => $socio->email,
            'nome' => 'Aggiornato',
            'tag_ids' => [$tag->id],
        ]));

        $response->assertRedirect(route('soci.show', $socio));

        $socio->refresh();
        $this->assertSame('Aggiornato', $socio->nome);
        $this->assertTrue($socio->tags->contains('id', $tag->id));

        $this->assertDatabaseHas('storico_attivita_socio', [
            'socio_id' => $socio->id,
            'tipo_attivita' => 'Modifica Anagrafica',
        ]);
    }

    public function test_i_tag_di_un_altra_associazione_vengono_rifiutati(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $tagAltrui = Tag::create([
            'associazione_id' => Associazione::factory()->create()->id,
            'nome_tag' => 'Estraneo',
            'colore' => '#000000',
        ]);

        $this->actingAs($this->admin)->put("/soci/{$socio->id}", $this->payloadSocio([
            'numero_socio' => $socio->numero_socio,
            'email' => $socio->email,
            'tag_ids' => [$tagAltrui->id],
        ]))->assertSessionHasErrors('tag_ids.0');
    }

    public function test_soft_delete_cestino_e_ripristino(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->delete("/soci/{$socio->id}")
            ->assertRedirect(route('soci.index'));

        $this->assertSoftDeleted('soci', ['id' => $socio->id]);

        // Compare nel cestino
        $this->actingAs($this->admin)->get('/soci/cestino')
            ->assertOk()
            ->assertSee($socio->cognome);

        // Ripristino
        $this->actingAs($this->admin)
            ->post("/soci/{$socio->id}/ripristina")
            ->assertRedirect(route('soci.cestino'));

        $this->assertDatabaseHas('soci', ['id' => $socio->id, 'deleted_at' => null]);
    }

    public function test_eliminazione_definitiva_dal_cestino(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);
        $socio->delete();

        $this->actingAs($this->admin)
            ->delete("/soci/{$socio->id}/definitivo")
            ->assertRedirect(route('soci.cestino'));

        $this->assertDatabaseMissing('soci', ['id' => $socio->id]);
    }

    public function test_isolamento_tenant_il_socio_di_un_altra_associazione_da_404(): void
    {
        $socioAltrui = Socio::factory()->create();

        $this->actingAs($this->admin)->get("/soci/{$socioAltrui->id}")->assertNotFound();
        $this->actingAs($this->admin)->get("/soci/{$socioAltrui->id}/modifica")->assertNotFound();
        $this->actingAs($this->admin)->delete("/soci/{$socioAltrui->id}")->assertNotFound();
    }

    public function test_la_lista_mostra_solo_i_soci_del_proprio_tenant(): void
    {
        $mio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'cognome' => 'Verdolini',
        ]);
        Tessera::factory()->create(['socio_id' => $mio->id]);

        $altrui = Socio::factory()->create(['cognome' => 'Estraneo']);
        Tessera::factory()->create(['socio_id' => $altrui->id]);

        $this->actingAs($this->admin)->get('/soci?tessera_stato=all')
            ->assertOk()
            ->assertSee('Verdolini')
            ->assertDontSee('Estraneo');
    }

    public function test_dettaglio_socio_mostra_le_4_tab(): void
    {
        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->get("/soci/{$socio->id}")
            ->assertOk()
            ->assertSee('Anagrafica')
            ->assertSee('Quote e Tessere')
            ->assertSee('Documenti')
            ->assertSee('Storico');
    }
}
