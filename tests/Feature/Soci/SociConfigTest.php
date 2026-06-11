<?php

namespace Tests\Feature\Soci;

use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\CategoriaSocio;
use App\Models\GruppoDinamico;
use App\Models\Socio;
use App\Models\Tag;
use App\Models\TipoSocio;
use App\Models\Utente;
use App\Models\ValoreCampoPersonalizzato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SociConfigTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    private Utente $operatore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create([
            'associazione_id' => $this->associazione->id,
        ]);
        $this->operatore = Utente::factory()->operatore()->create([
            'associazione_id' => $this->associazione->id,
        ]);
    }

    public function test_le_pagine_di_configurazione_sono_vietate_all_operatore(): void
    {
        foreach (['/tipi-socio', '/categorie-socio', '/config-campi', '/config-tags', '/config-gruppi'] as $url) {
            $this->actingAs($this->operatore)->get($url)->assertForbidden();
        }
    }

    public function test_le_pagine_di_configurazione_sono_accessibili_all_admin(): void
    {
        foreach (['/tipi-socio', '/categorie-socio', '/config-campi', '/config-tags', '/config-gruppi'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }

    public function test_crud_tipo_socio_con_costo_tessera_nullable(): void
    {
        $this->actingAs($this->admin)->post('/tipi-socio', [
            'nome' => 'Sostenitore',
            'descrizione' => 'Tipo sostenitore',
            'costo_tessera' => '50.00',
        ])->assertRedirect(route('tipi-socio.index'));

        $tipo = TipoSocio::query()->where('nome', 'Sostenitore')->first();
        $this->assertNotNull($tipo);
        $this->assertSame('50.00', (string) $tipo->costo_tessera);
        $this->assertSame($this->associazione->id, $tipo->associazione_id);

        // costo vuoto → NULL (fallback su associazioni.costo_tessera, spec §3.3)
        $this->actingAs($this->admin)->put("/tipi-socio/{$tipo->id}", [
            'nome' => 'Sostenitore',
            'costo_tessera' => '',
        ]);
        $this->assertNull($tipo->refresh()->costo_tessera);

        $this->actingAs($this->admin)->delete("/tipi-socio/{$tipo->id}");
        $this->assertDatabaseMissing('tipi_socio', ['id' => $tipo->id]);
    }

    public function test_eliminare_un_tipo_lascia_i_soci_senza_tipo(): void
    {
        $tipo = TipoSocio::create(['associazione_id' => $this->associazione->id, 'nome' => 'Ordinario']);
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'tipo_socio_id' => $tipo->id,
        ]);

        $this->actingAs($this->admin)->delete("/tipi-socio/{$tipo->id}");

        $this->assertNull($socio->refresh()->tipo_socio_id);
    }

    public function test_crud_categoria_socio(): void
    {
        $this->actingAs($this->admin)->post('/categorie-socio', [
            'nome' => 'Junior',
            'testo_tessera' => 'Tessera junior valida per attività giovanili',
        ])->assertRedirect(route('categorie-socio.index'));

        $categoria = CategoriaSocio::query()->where('nome', 'Junior')->first();
        $this->assertNotNull($categoria);

        $this->actingAs($this->admin)->delete("/categorie-socio/{$categoria->id}");
        $this->assertDatabaseMissing('categorie_socio', ['id' => $categoria->id]);
    }

    public function test_preset_campi_personalizzati_inserisce_solo_i_mancanti(): void
    {
        CampoPersonalizzato::create([
            'associazione_id' => $this->associazione->id,
            'nome_campo' => 'Professione',
            'tipo_campo' => 'text',
        ]);

        $this->actingAs($this->admin)->post('/config-campi/preset', [
            'preset' => ['Professione', 'Titolo di Studio', 'Sito Web'],
        ])->assertSessionHas('success', '2 campi aggiunti.');

        $this->assertSame(3, CampoPersonalizzato::query()->count());
        $this->assertDatabaseHas('campi_personalizzati', [
            'nome_campo' => 'Titolo di Studio',
            'tipo_campo' => 'select',
        ]);
    }

    public function test_campo_select_richiede_le_opzioni(): void
    {
        $this->actingAs($this->admin)->post('/config-campi', [
            'nome_campo' => 'Taglia',
            'tipo_campo' => 'select',
            'opzioni' => '',
        ])->assertSessionHasErrors('opzioni');
    }

    public function test_il_valore_di_un_campo_personalizzato_viene_salvato_e_la_checkbox_azzerata(): void
    {
        $campoTesto = CampoPersonalizzato::create([
            'associazione_id' => $this->associazione->id,
            'nome_campo' => 'Professione',
            'tipo_campo' => 'text',
        ]);
        $campoCheck = CampoPersonalizzato::create([
            'associazione_id' => $this->associazione->id,
            'nome_campo' => 'Newsletter cartacea',
            'tipo_campo' => 'checkbox',
        ]);

        $socio = Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $payload = [
            'nome' => $socio->nome,
            'cognome' => $socio->cognome,
            'email' => $socio->email,
            'numero_socio' => $socio->numero_socio,
            'data_nascita' => '1990-01-01',
            'data_iscrizione' => now()->toDateString(),
            'stato' => Socio::STATO_ATTIVO,
        ];

        $this->actingAs($this->admin)->put("/soci/{$socio->id}", $payload + [
            'custom_field' => [$campoTesto->id => 'Ingegnere', $campoCheck->id => '1'],
        ]);

        $this->assertDatabaseHas('valori_campi_personalizzati', ['campo_id' => $campoTesto->id, 'valore' => 'Ingegnere']);
        $this->assertDatabaseHas('valori_campi_personalizzati', ['campo_id' => $campoCheck->id, 'valore' => '1']);

        // Checkbox deselezionata (assente dal POST) → valore azzerato (fix bug legacy, spec §14.12)
        $this->actingAs($this->admin)->put("/soci/{$socio->id}", $payload + [
            'custom_field' => [$campoTesto->id => 'Ingegnere'],
        ]);

        $this->assertSame('0', ValoreCampoPersonalizzato::query()
            ->where('socio_id', $socio->id)
            ->where('campo_id', $campoCheck->id)
            ->value('valore'));
    }

    public function test_crud_tag(): void
    {
        $this->actingAs($this->admin)->post('/config-tags', [
            'nome_tag' => 'Volontario',
            'colore' => '#FF7B11',
        ])->assertRedirect(route('config-tags.index'));

        $tag = Tag::query()->where('nome_tag', 'Volontario')->first();
        $this->assertNotNull($tag);
        $this->assertSame('#FF7B11', $tag->colore);

        $this->actingAs($this->admin)->delete("/config-tags/{$tag->id}");
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_crud_gruppo_dinamico_con_whitelist_filtri(): void
    {
        $this->actingAs($this->admin)->post('/config-gruppi', [
            'nome_gruppo' => 'Attivi di Milano',
            'filtri' => [
                'stato' => 'Attivo',
                'citta' => 'Milano',
                'colonna_maligna' => 'x', // fuori whitelist: scartata
            ],
        ])->assertRedirect(route('config-gruppi.index'));

        $gruppo = GruppoDinamico::query()->where('nome_gruppo', 'Attivi di Milano')->first();
        $this->assertNotNull($gruppo);
        $this->assertSame(['stato' => 'Attivo', 'citta' => 'Milano'], $gruppo->filtri_json);
    }

    public function test_isolamento_tenant_sulle_configurazioni(): void
    {
        $tipoAltrui = TipoSocio::create([
            'associazione_id' => Associazione::factory()->create()->id,
            'nome' => 'Estraneo',
        ]);

        $this->actingAs($this->admin)
            ->put("/tipi-socio/{$tipoAltrui->id}", ['nome' => 'Hackerato'])
            ->assertNotFound();

        $this->actingAs($this->admin)->get('/tipi-socio')->assertDontSee('Estraneo');
    }
}
