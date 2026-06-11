<?php

namespace Tests\Feature\Email;

use App\Models\Associazione;
use App\Models\EmailQueue;
use App\Models\Socio;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

class ComunicazioniTest extends EmailTestCase
{
    public function test_ospite_viene_rediretto_al_login(): void
    {
        $this->get(route('comunicazioni.index'))->assertRedirect('/login');
    }

    public function test_admin_accede_alla_pagina_comunicazioni(): void
    {
        $this->actingAs($this->admin)
            ->get(route('comunicazioni.index'))
            ->assertOk()
            ->assertViewIs('email.comunicazioni')
            ->assertSee('Invia Comunicazione');
    }

    public function test_invio_rifiutato_se_smtp_non_configurato(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato' => 'Attivo',
            'email' => 'socio@example.com',
        ]);

        $response = $this->actingAs($this->admin)
            ->from(route('comunicazioni.index'))
            ->post(route('comunicazioni.store'), [
                'subject' => 'Avviso',
                'corpo_html' => '<p>Testo</p>',
                'recipient_type' => 'all',
            ]);

        $response->assertRedirect(route('comunicazioni.index'));
        $response->assertSessionHas('danger');
        $this->assertSame(0, EmailQueue::allTenants()->count());
    }

    public function test_invio_accoda_email_personalizzate_per_socio_escludendo_opt_out(): void
    {
        $this->configuraSmtp();

        $mario = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato' => 'Attivo',
            'email' => 'mario@example.com',
            'nome' => 'Mario',
            'cognome' => 'Rossi',
        ]);
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato' => 'Attivo',
            'email' => 'optout@example.com',
            'email_opt_out' => true,
        ]);
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato' => 'Sospeso',
            'email' => 'sospeso@example.com',
        ]);
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato' => 'Attivo',
            'email' => '',
        ]);

        $response = $this->actingAs($this->admin)->post(route('comunicazioni.store'), [
            'subject' => 'Ciao {NOME}',
            'corpo_html' => '<p>Gentile {NOME} {COGNOME}, comunicazione di {NOME_ASSOCIAZIONE}.</p>',
            'recipient_type' => 'all',
        ]);

        $response->assertRedirect(route('comunicazioni.index'));
        $response->assertSessionHas('success', fn ($msg) => str_contains($msg, '1 email accodate'));

        $items = EmailQueue::allTenants()->where('associazione_id', $this->associazione->id)->get();
        $this->assertCount(1, $items);

        $item = $items->first();
        $this->assertSame('mario@example.com', $item->to_email);
        $this->assertSame($mario->id, $item->socio_id);
        $this->assertSame('comunicazione', $item->template_codice);
        $this->assertSame(EmailQueue::STATUS_PENDING, $item->status);
        // Rendering per-socio: placeholder risolti all'accodamento
        $this->assertSame('Ciao Mario', $item->subject);
        $this->assertStringContainsString('Gentile Mario Rossi', $item->body_html);
        // Layout base applicato con link di disiscrizione personale
        $this->assertStringContainsString('/email/unsubscribe/'.$mario->id.'/', $item->body_html);
    }

    public function test_invio_con_filtro_tag_accoda_solo_i_soci_taggati(): void
    {
        $this->configuraSmtp();

        $conTag = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato' => 'Attivo',
            'email' => 'contag@example.com',
        ]);
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato' => 'Attivo',
            'email' => 'senzatag@example.com',
        ]);

        $tag = Tag::create(['associazione_id' => $this->associazione->id, 'nome_tag' => 'Volontari']);
        DB::table('socio_tags')->insert([
            'socio_id' => $conTag->id,
            'tag_id' => $tag->id,
            'created_at' => now(),
        ]);

        $this->actingAs($this->admin)->post(route('comunicazioni.store'), [
            'subject' => 'Solo taggati',
            'corpo_html' => '<p>Ciao</p>',
            'recipient_type' => 'tag',
            'recipient_filter_id' => $tag->id,
        ]);

        $emails = EmailQueue::allTenants()->pluck('to_email');
        $this->assertCount(1, $emails);
        $this->assertSame('contag@example.com', $emails->first());
    }

    public function test_zero_destinatari_mostra_warning_senza_accodare(): void
    {
        $this->configuraSmtp();

        $response = $this->actingAs($this->admin)
            ->from(route('comunicazioni.index'))
            ->post(route('comunicazioni.store'), [
                'subject' => 'Avviso',
                'corpo_html' => '<p>Testo</p>',
                'recipient_type' => 'all',
            ]);

        $response->assertSessionHas('warning');
        $this->assertSame(0, EmailQueue::allTenants()->count());
    }

    public function test_validazione_oggetto_e_corpo_obbligatori(): void
    {
        $this->configuraSmtp();

        $this->actingAs($this->admin)->post(route('comunicazioni.store'), [
            'subject' => '',
            'corpo_html' => '',
            'recipient_type' => 'all',
        ])->assertSessionHasErrors(['subject', 'corpo_html']);
    }

    public function test_operatore_senza_email_create_non_puo_inviare(): void
    {
        $this->configuraSmtp();

        $this->actingAs($this->creaOperatore())->post(route('comunicazioni.store'), [
            'subject' => 'Avviso',
            'corpo_html' => '<p>Testo</p>',
            'recipient_type' => 'all',
        ])->assertForbidden();
    }

    public function test_isolamento_tenant_i_soci_di_b_non_ricevono_le_comunicazioni_di_a(): void
    {
        $this->configuraSmtp();

        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'stato' => 'Attivo',
            'email' => 'tenant-a@example.com',
        ]);

        $assocB = Associazione::factory()->create();
        Socio::factory()->create([
            'associazione_id' => $assocB->id,
            'stato' => 'Attivo',
            'email' => 'tenant-b@example.com',
        ]);

        $this->actingAs($this->admin)->post(route('comunicazioni.store'), [
            'subject' => 'Solo tenant A',
            'corpo_html' => '<p>Ciao</p>',
            'recipient_type' => 'all',
        ]);

        $emails = EmailQueue::allTenants()->pluck('to_email');
        $this->assertCount(1, $emails);
        $this->assertSame('tenant-a@example.com', $emails->first());
    }

    public function test_conteggio_destinatari_ajax(): void
    {
        Socio::factory()->count(3)->create([
            'associazione_id' => $this->associazione->id,
            'stato' => 'Attivo',
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('comunicazioni.count', ['recipient_type' => 'all']))
            ->assertOk()
            ->assertJson(['success' => true, 'count' => 3]);
    }
}
