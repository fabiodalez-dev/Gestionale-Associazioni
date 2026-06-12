<?php

namespace Tests\Feature\Comunicazione;

use App\Models\Annuncio;
use App\Models\Associazione;
use App\Models\EmailQueue;
use App\Models\NewsletterInviata;
use App\Models\Socio;

/**
 * f.8 — Newsletter da annunci: anteprima brandizzata, invio in coda ai
 * soci attivi con email senza opt-out, storico invii, permessi e tenant.
 */
class NewsletterTest extends ComunicazioneTestCase
{
    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('newsletter.index'))->assertRedirect('/login');
    }

    public function test_pagina_newsletter_elenca_annunci_pubblicati_e_storico(): void
    {
        Annuncio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Annuncio Da Includere',
        ]);
        Annuncio::factory()->bozza()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Bozza Non Selezionabile',
        ]);
        NewsletterInviata::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Newsletter di maggio',
            'inviata_a' => 12,
        ]);

        $altra = Associazione::factory()->create();
        NewsletterInviata::factory()->create([
            'associazione_id' => $altra->id,
            'titolo' => 'Newsletter Altrui Segreta',
        ]);

        $this->actingAs($this->admin)
            ->get(route('newsletter.index'))
            ->assertOk()
            ->assertViewIs('comunicazione.newsletter.index')
            ->assertSee('Annuncio Da Includere')
            ->assertDontSee('Bozza Non Selezionabile')
            ->assertSee('Newsletter di maggio')
            ->assertDontSee('Newsletter Altrui Segreta');
    }

    public function test_anteprima_renderizza_gli_annunci_nel_layout_brandizzato(): void
    {
        $annuncio = Annuncio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Grande Festa Estiva',
            'corpo_html' => '<p>Vi aspettiamo numerosi!</p>',
        ]);

        $this->actingAs($this->admin)
            ->post(route('newsletter.anteprima'), [
                'titolo' => 'Le novità di giugno',
                'annunci' => [$annuncio->id],
            ])
            ->assertOk()
            ->assertSee('Le novità di giugno')
            ->assertSee('Grande Festa Estiva')
            ->assertSee('Vi aspettiamo numerosi!', false)
            ->assertSee($this->associazione->nome);
    }

    public function test_invio_accoda_solo_ai_soci_attivi_con_email_senza_opt_out(): void
    {
        $this->configuraSmtp();

        $annuncio = Annuncio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'titolo' => 'Annuncio In Newsletter',
        ]);

        $destinatario = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'destinatario@example.com',
        ]);
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'optout@example.com',
            'email_opt_out' => true,
        ]);
        Socio::factory()->sospeso()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'sospeso@example.com',
        ]);
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => '',
        ]);

        // Socio attivo di un ALTRO tenant: mai incluso
        $altra = Associazione::factory()->create();
        Socio::factory()->create([
            'associazione_id' => $altra->id,
            'email' => 'altrui@example.com',
        ]);

        $response = $this->actingAs($this->admin)->post(route('newsletter.store'), [
            'titolo' => 'Le novità di giugno',
            'annunci' => [$annuncio->id],
        ]);

        $response->assertRedirect(route('newsletter.index'));
        $response->assertSessionHas('success');

        $coda = EmailQueue::allTenants()->get();
        $this->assertCount(1, $coda);
        $this->assertSame('destinatario@example.com', $coda->first()->to_email);
        $this->assertSame('Le novità di giugno', $coda->first()->subject);
        $this->assertSame($destinatario->id, $coda->first()->socio_id);
        $this->assertStringContainsString('Annuncio In Newsletter', $coda->first()->body_html);

        $storico = NewsletterInviata::allTenants()->firstOrFail();
        $this->assertSame($this->associazione->id, $storico->associazione_id);
        $this->assertSame(1, $storico->inviata_a);
        $this->assertSame([$annuncio->id], $storico->annunci_ids);
        $this->assertSame($this->admin->id, $storico->inviata_da);
    }

    public function test_invio_rifiutato_se_smtp_non_configurato(): void
    {
        $annuncio = Annuncio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($this->admin)
            ->from(route('newsletter.index'))
            ->post(route('newsletter.store'), [
                'titolo' => 'Niente SMTP',
                'annunci' => [$annuncio->id],
            ])
            ->assertRedirect(route('newsletter.index'))
            ->assertSessionHas('danger');

        $this->assertSame(0, EmailQueue::allTenants()->count());
        $this->assertSame(0, NewsletterInviata::allTenants()->count());
    }

    public function test_invio_rifiutato_con_soli_annunci_non_pubblicati_o_di_altri_tenant(): void
    {
        $this->configuraSmtp();
        Socio::factory()->create(['associazione_id' => $this->associazione->id]);

        $bozza = Annuncio::factory()->bozza()->create(['associazione_id' => $this->associazione->id]);

        $altra = Associazione::factory()->create();
        $estraneo = Annuncio::factory()->create(['associazione_id' => $altra->id]);

        $this->actingAs($this->admin)
            ->from(route('newsletter.index'))
            ->post(route('newsletter.store'), [
                'titolo' => 'Newsletter vuota',
                'annunci' => [$bozza->id, $estraneo->id],
            ])
            ->assertRedirect(route('newsletter.index'))
            ->assertSessionHas('warning');

        $this->assertSame(0, EmailQueue::allTenants()->count());
        $this->assertSame(0, NewsletterInviata::allTenants()->count());
    }

    public function test_operatore_consulta_lo_storico_ma_non_invia(): void
    {
        $operatore = $this->creaOperatore();
        $annuncio = Annuncio::factory()->create(['associazione_id' => $this->associazione->id]);

        $this->actingAs($operatore)->get(route('newsletter.index'))->assertOk();

        $this->actingAs($operatore)->post(route('newsletter.store'), [
            'titolo' => 'Non permesso',
            'annunci' => [$annuncio->id],
        ])->assertForbidden();
    }
}
