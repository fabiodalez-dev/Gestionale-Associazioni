<?php

namespace Tests\Feature\Tessere;

use App\Models\Associazione;
use App\Models\TesseraTemplate;
use App\Models\TipoSocio;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TemplateTessereTest extends TessereTestCase
{
    use RefreshDatabase;

    public function test_index_template(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);

        TesseraTemplate::create([
            'associazione_id' => $assoc->id,
            'titolo' => 'Template Generale',
            'contenuto' => 'Benvenuto {NOME_COMPLETO}',
            'attivo' => true,
        ]);

        $this->actingAs($admin)
            ->get('/tessere/templates')
            ->assertOk()
            ->assertSee('Template Generale');
    }

    public function test_creazione_template_generico(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);

        $this->actingAs($admin)->post('/tessere/templates', [
            'tipo_socio_id' => '',
            'titolo' => 'Il mio template',
            'contenuto' => 'Tessera {NUMERO_TESSERA} di {NOME_COMPLETO}',
            'attivo' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('tessera_templates', [
            'associazione_id' => $assoc->id,
            'tipo_socio_id' => null,
            'titolo' => 'Il mio template',
            'attivo' => true,
        ]);
    }

    public function test_unicita_per_tipo_socio_anche_per_il_generico(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);

        TesseraTemplate::create([
            'associazione_id' => $assoc->id,
            'tipo_socio_id' => null,
            'titolo' => 'Generico',
            'contenuto' => 'Testo',
            'attivo' => true,
        ]);

        $this->actingAs($admin)->post('/tessere/templates', [
            'tipo_socio_id' => '',
            'titolo' => 'Generico bis',
            'contenuto' => 'Altro testo',
        ])->assertSessionHasErrors('tipo_socio_id');
    }

    public function test_tipo_socio_di_altra_associazione_rifiutato(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();
        $adminA = $this->creaAdmin($assocA);

        $tipoB = TipoSocio::create(['associazione_id' => $assocB->id, 'nome' => 'Tipo B']);

        $this->actingAs($adminA)->post('/tessere/templates', [
            'tipo_socio_id' => $tipoB->id,
            'titolo' => 'X',
            'contenuto' => 'Y',
        ])->assertSessionHasErrors('tipo_socio_id');
    }

    public function test_modifica_ed_eliminazione_template(): void
    {
        $assoc = Associazione::factory()->create();
        $admin = $this->creaAdmin($assoc);

        $template = TesseraTemplate::create([
            'associazione_id' => $assoc->id,
            'titolo' => 'Da modificare',
            'contenuto' => 'Vecchio testo',
            'attivo' => true,
        ]);

        $this->actingAs($admin)->put('/tessere/templates/'.$template->id, [
            'tipo_socio_id' => '',
            'titolo' => 'Modificato',
            'contenuto' => 'Nuovo testo',
            'attivo' => '0',
        ])->assertRedirect();

        $this->assertDatabaseHas('tessera_templates', [
            'id' => $template->id,
            'titolo' => 'Modificato',
            'attivo' => false,
        ]);

        $this->actingAs($admin)->delete('/tessere/templates/'.$template->id)->assertRedirect();
        $this->assertDatabaseMissing('tessera_templates', ['id' => $template->id]);
    }

    public function test_isolamento_tenant_template_di_altra_associazione_404(): void
    {
        $assocA = Associazione::factory()->create();
        $assocB = Associazione::factory()->create();
        $adminA = $this->creaAdmin($assocA);

        $templateB = TesseraTemplate::allTenants()->create([
            'associazione_id' => $assocB->id,
            'titolo' => 'Di B',
            'contenuto' => 'Testo B',
            'attivo' => true,
        ]);

        $this->actingAs($adminA)
            ->put('/tessere/templates/'.$templateB->id, [
                'tipo_socio_id' => '',
                'titolo' => 'Hack',
                'contenuto' => 'Hack',
            ])->assertNotFound();
    }
}
