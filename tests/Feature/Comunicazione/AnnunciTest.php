<?php

namespace Tests\Feature\Comunicazione;

use App\Models\Annuncio;
use App\Models\Associazione;
use App\Models\Socio;

/**
 * f.7 — Bacheca annunci: CRUD admin (permessi email.*), sezione Novità
 * sulla home pubblica, pagina elenco /a/{slug}/novita, annunci solo_soci
 * nella bacheca dell'area riservata. Isolamento multi-tenant ovunque.
 */
class AnnunciTest extends ComunicazioneTestCase
{
    // ── CRUD admin ───────────────────────────────────────────────────

    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('annunci.index'))->assertRedirect('/login');
    }

    public function test_admin_vede_la_lista_annunci_del_proprio_tenant(): void
    {
        Annuncio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Festa sociale di giugno',
        ]);

        $altra = Associazione::factory()->create();
        Annuncio::factory()->create([
            'associazione_id' => $altra->id,
            'titolo' => 'Annuncio Altrui Segreto',
        ]);

        $this->actingAs($this->admin)
            ->get(route('annunci.index'))
            ->assertOk()
            ->assertViewIs('comunicazione.annunci.index')
            ->assertSee('Festa sociale di giugno')
            ->assertDontSee('Annuncio Altrui Segreto');
    }

    public function test_admin_apre_le_pagine_di_creazione_e_modifica(): void
    {
        $this->actingAs($this->admin)
            ->get(route('annunci.create'))
            ->assertOk()
            ->assertViewIs('comunicazione.annunci.form')
            ->assertSee('Nuovo annuncio');

        $annuncio = Annuncio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->get(route('annunci.edit', $annuncio))
            ->assertOk()
            ->assertSee('Modifica annuncio')
            ->assertSee($annuncio->titolo);
    }

    public function test_store_crea_annuncio_purificando_l_html(): void
    {
        $response = $this->actingAs($this->admin)->post(route('annunci.store'), [
            'titolo' => 'Nuovo annuncio',
            'corpo_html' => '<p>Testo <strong>ok</strong></p><script>alert("xss")</script>',
            'pubblicato_dal' => now()->subHour()->format('Y-m-d\TH:i'),
            'pubblicato_al' => '',
            'in_evidenza' => '1',
            'solo_soci' => '0',
        ]);

        $response->assertRedirect(route('annunci.index'));

        $annuncio = Annuncio::allTenants()->where('titolo', 'Nuovo annuncio')->firstOrFail();
        $this->assertSame($this->associazione->id, $annuncio->associazione_id);
        $this->assertSame($this->admin->id, $annuncio->creato_da);
        $this->assertTrue($annuncio->in_evidenza);
        $this->assertTrue($annuncio->isPubblicato());
        $this->assertStringContainsString('<strong>ok</strong>', $annuncio->corpo_html);
        $this->assertStringNotContainsString('<script', $annuncio->corpo_html);
    }

    public function test_validazione_titolo_e_corpo_obbligatori(): void
    {
        $this->actingAs($this->admin)
            ->from(route('annunci.create'))
            ->post(route('annunci.store'), ['titolo' => '', 'corpo_html' => ''])
            ->assertRedirect(route('annunci.create'))
            ->assertSessionHasErrors(['titolo', 'corpo_html']);
    }

    public function test_update_e_destroy_annuncio(): void
    {
        $annuncio = Annuncio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)->put(route('annunci.update', $annuncio), [
            'titolo' => 'Titolo aggiornato',
            'corpo_html' => '<p>Aggiornato</p>',
            'pubblicato_dal' => '',
            'pubblicato_al' => '',
            'in_evidenza' => '0',
            'solo_soci' => '1',
        ])->assertRedirect(route('annunci.index'));

        $annuncio->refresh();
        $this->assertSame('Titolo aggiornato', $annuncio->titolo);
        $this->assertTrue($annuncio->solo_soci);
        $this->assertNull($annuncio->pubblicato_dal);

        $this->actingAs($this->admin)
            ->delete(route('annunci.destroy', $annuncio))
            ->assertRedirect(route('annunci.index'));

        $this->assertSame(0, Annuncio::allTenants()->whereKey($annuncio->id)->count());
    }

    public function test_admin_non_gestisce_annunci_di_altri_tenant(): void
    {
        $altra = Associazione::factory()->create();
        $estraneo = Annuncio::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->get(route('annunci.edit', $estraneo))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->delete(route('annunci.destroy', $estraneo))
            ->assertNotFound();

        $this->assertSame(1, Annuncio::allTenants()->whereKey($estraneo->id)->count());
    }

    public function test_operatore_vede_ma_non_crea_annunci(): void
    {
        $operatore = $this->creaOperatore();

        $this->actingAs($operatore)->get(route('annunci.index'))->assertOk();

        $this->actingAs($operatore)->get(route('annunci.create'))->assertForbidden();

        $this->actingAs($operatore)->post(route('annunci.store'), [
            'titolo' => 'Non permesso',
            'corpo_html' => '<p>No</p>',
            'in_evidenza' => '0',
            'solo_soci' => '0',
        ])->assertForbidden();
    }

    // ── Home pubblica e pagina Novità ────────────────────────────────

    public function test_home_pubblica_mostra_le_novita_pubblicate(): void
    {
        Annuncio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Apertura nuova sede',
        ]);
        Annuncio::factory()->soloSoci()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Riservato Ai Soci Top Secret',
        ]);
        Annuncio::factory()->bozza()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Bozza Invisibile',
        ]);

        $this->get($this->urlTenant())
            ->assertOk()
            ->assertSee('Apertura nuova sede')
            ->assertDontSee('Riservato Ai Soci Top Secret')
            ->assertDontSee('Bozza Invisibile');
    }

    public function test_pagina_novita_elenca_solo_i_pubblicati_non_riservati(): void
    {
        Annuncio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Annuncio Visibile',
        ]);
        Annuncio::factory()->scaduto()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Annuncio Scaduto Invisibile',
        ]);
        Annuncio::factory()->soloSoci()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Riservato Ai Soci Top Secret',
        ]);

        $altra = Associazione::factory()->create(['frontend_pubblico_attivo' => true]);
        Annuncio::factory()->create([
            'associazione_id' => $altra->id,
            'titolo' => 'Annuncio Altrui Segreto',
        ]);

        $this->get($this->urlTenant('novita'))
            ->assertOk()
            ->assertViewIs('comunicazione.pubblico.novita')
            ->assertSee('Annuncio Visibile')
            ->assertDontSee('Annuncio Scaduto Invisibile')
            ->assertDontSee('Riservato Ai Soci Top Secret')
            ->assertDontSee('Annuncio Altrui Segreto');
    }

    // ── Bacheca area soci ────────────────────────────────────────────

    public function test_bacheca_area_soci_richiede_login_socio(): void
    {
        $this->get(route('area-soci.bacheca'))
            ->assertRedirect(route('area-soci.login'));
    }

    public function test_bacheca_area_soci_include_gli_annunci_solo_soci(): void
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create([
            'associazione_id' => $this->associazione->id,
        ]);

        Annuncio::factory()->soloSoci()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Assemblea riservata ai soci',
        ]);
        Annuncio::factory()->bozza()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Bozza Invisibile',
        ]);

        $altra = Associazione::factory()->create();
        Annuncio::factory()->create([
            'associazione_id' => $altra->id,
            'titolo' => 'Annuncio Altrui Segreto',
        ]);

        $this->actingAs($socio, 'socio')
            ->get(route('area-soci.bacheca'))
            ->assertOk()
            ->assertViewIs('comunicazione.area-soci.bacheca')
            ->assertSee('Assemblea riservata ai soci')
            ->assertDontSee('Bozza Invisibile')
            ->assertDontSee('Annuncio Altrui Segreto');
    }
}
