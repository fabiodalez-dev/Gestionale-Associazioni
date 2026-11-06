<?php

namespace Tests\Feature\Struttura;

use App\Models\Adempimento;
use App\Models\Associazione;
use App\Models\ManualeEsecuzione;
use App\Models\ManualePagina;
use App\Models\Socio;
use App\Models\Utente;
use App\Services\Struttura\ManualeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manuale operativo interno (issue #12): pagine per sezione con testo
 * sanificato, ricerca, checklist, adempimenti collegati, visibilità per i
 * soci, storico delle revisioni e permessi.
 */
class ManualeOperativoTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
    }

    private function actAsAdmin(): self
    {
        $this->actingAs($this->admin)->withSession(['associazione_id' => $this->associazione->id]);

        return $this;
    }

    private function pagina(array $attributi = []): ManualePagina
    {
        return ManualePagina::create(array_merge([
            'associazione_id' => $this->associazione->id,
            'sezione' => 'Eventi',
            'titolo' => 'Organizzare la cena sociale',
            'contenuto' => "## Prima\n- Prenotare la sala",
            'passi' => ['Prenotare la sala', 'Inviare gli inviti'],
        ], $attributi));
    }

    public function test_admin_crea_una_pagina_con_passi_uno_per_riga(): void
    {
        $this->actAsAdmin()->post(route('manuale.store'), [
            'sezione' => 'Tesseramento',
            'titolo' => 'Rinnovi',
            'contenuto' => '**Importante**: entro marzo.',
            'passi' => "Aprire l'anno\n\n  Inviare le email  \n",
            'visibilita' => 'staff',
        ])->assertSessionHasNoErrors();

        $pagina = ManualePagina::query()->firstOrFail();
        $this->assertSame(["Aprire l'anno", 'Inviare le email'], $pagina->passi);
        $this->assertNotNull($pagina->revisionata_at);

        $this->actAsAdmin()->get(route('manuale.show', $pagina))
            ->assertOk()
            ->assertSee('<strong>Importante</strong>', false)
            ->assertSee('Avvia checklist');
    }

    public function test_il_contenuto_e_ripulito_da_html_e_link_pericolosi(): void
    {
        $pagina = $this->pagina([
            'contenuto' => "<script>alert(1)</script>\n\n[clicca](javascript:alert(2))\n\n<img src=x onerror=alert(3)>",
        ]);

        $html = $pagina->contenutoHtml();

        // L'HTML scritto nel testo resta testo: niente tag eseguibili
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_indice_per_sezione_con_ricerca_nel_testo(): void
    {
        $this->pagina();
        $this->pagina(['sezione' => 'Tesseramento', 'titolo' => 'Apertura anno', 'contenuto' => 'Usare la pagina Rinnovi.']);

        $this->actAsAdmin()->get(route('manuale.index'))->assertOk()->assertSee('Eventi')->assertSee('Tesseramento');
        $this->actAsAdmin()->get(route('manuale.index', ['search' => 'Rinnovi']))
            ->assertOk()
            ->assertSee('Apertura anno')
            ->assertDontSee('Organizzare la cena sociale');
    }

    public function test_modelli_iniziali_non_duplicati(): void
    {
        $this->actAsAdmin()->post(route('manuale.modelli'))->assertSessionHas('success');
        $this->assertSame(count(ManualeService::MODELLI), ManualePagina::query()->count());

        $this->actAsAdmin()->post(route('manuale.modelli'));
        $this->assertSame(count(ManualeService::MODELLI), ManualePagina::query()->count());
    }

    public function test_checklist_avviata_e_completata_passo_per_passo(): void
    {
        $pagina = $this->pagina();

        $this->actAsAdmin()->post(route('manuale.avvia', $pagina), ['titolo' => 'Cena 2026'])->assertSessionHas('success');
        $esecuzione = ManualeEsecuzione::query()->with('passi')->firstOrFail();
        $this->assertSame('Cena 2026', $esecuzione->titolo);
        $this->assertCount(2, $esecuzione->passi);

        // I passi sono una copia: cambiare la pagina non tocca la checklist
        $pagina->update(['passi' => ['Altro']]);

        foreach ($esecuzione->passi as $passo) {
            $this->actAsAdmin()->post(route('manuale.esecuzioni.passo', [$esecuzione, $passo]), ['completato' => '1', 'note' => 'ok']);
        }

        $esecuzione->refresh();
        $this->assertNotNull($esecuzione->completata_at);
        $this->assertSame($this->admin->id, $esecuzione->passi->first()->completato_da);
        $this->assertSame('Prenotare la sala', $esecuzione->passi->first()->testo);

        // Togliere una spunta riapre la checklist
        $this->actAsAdmin()->post(route('manuale.esecuzioni.passo', [$esecuzione, $esecuzione->passi->first()]), ['completato' => '0']);
        $this->assertNull($esecuzione->fresh()->completata_at);

        $this->actAsAdmin()->get(route('manuale.esecuzioni.show', $esecuzione))->assertOk()->assertSee('Cena 2026');
    }

    public function test_adempimento_collegato_alla_procedura(): void
    {
        $pagina = $this->pagina();

        $this->actAsAdmin()->post(route('adempimenti.store'), [
            'titolo' => 'Cena sociale annuale',
            'mese_scadenza' => 11,
            'attivo' => '1',
            'manuale_pagina_id' => $pagina->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($pagina->id, Adempimento::query()->value('manuale_pagina_id'));
        $this->actAsAdmin()->get(route('adempimenti.index'))->assertOk()->assertSee(route('manuale.show', $pagina), false);
        $this->actAsAdmin()->get(route('manuale.show', $pagina))->assertSee('Cena sociale annuale');
    }

    public function test_storico_revisioni_con_testo_precedente(): void
    {
        $pagina = $this->pagina(['contenuto' => 'Versione uno']);

        $this->actAsAdmin()->put(route('manuale.update', $pagina), [
            'sezione' => 'Eventi',
            'titolo' => $pagina->titolo,
            'contenuto' => 'Versione due',
            'visibilita' => 'staff',
        ]);

        $this->actAsAdmin()->get(route('manuale.show', $pagina))
            ->assertOk()
            ->assertSee('Versione due')
            ->assertSee('Testo precedente')
            ->assertSee('Versione uno');
    }

    public function test_pagine_per_i_soci_nell_area_riservata(): void
    {
        $perSoci = $this->pagina(['titolo' => 'Come iscriversi a un evento', 'visibilita' => 'soci']);
        $interna = $this->pagina(['titolo' => 'Procedura interna di cassa']);
        $socio = Socio::factory()->conAccessoAreaSoci()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($socio, 'socio')->get(route('area-soci.manuale'))
            ->assertOk()
            ->assertSee('Come iscriversi a un evento')
            ->assertDontSee('Procedura interna di cassa');

        $this->actingAs($socio, 'socio')->get(route('area-soci.manuale.show', $perSoci))->assertOk();
        $this->actingAs($socio, 'socio')->get(route('area-soci.manuale.show', $interna))->assertNotFound();
    }

    public function test_operatore_legge_ma_non_modifica_e_tenant_isolati(): void
    {
        $pagina = $this->pagina();
        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($operatore)->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('manuale.show', $pagina))->assertOk();
        $this->actingAs($operatore)->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('manuale.edit', $pagina))->assertForbidden();

        $altra = ManualePagina::withoutEvents(fn () => ManualePagina::create([
            'associazione_id' => Associazione::factory()->create()->id,
            'sezione' => 'X',
            'titolo' => 'Altrove',
        ]));
        $this->actAsAdmin()->get(route('manuale.show', $altra))->assertNotFound();
    }

    public function test_ricerca_globale_trova_le_pagine(): void
    {
        $this->pagina(['titolo' => 'Chiusura del bilancio']);

        $this->actAsAdmin()->getJson(route('ricerca-globale', ['q' => 'bilancio']))
            ->assertOk()
            ->assertJsonFragment(['titolo' => 'Chiusura del bilancio']);
    }
}
