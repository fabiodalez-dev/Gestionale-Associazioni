<?php

namespace Tests\Feature\Comunicazione;

use App\Models\Associazione;
use App\Models\Discussione;
use App\Models\DiscussioneIscrizione;
use App\Models\DiscussioneSegnalazione;
use App\Models\DiscussioneSpazio;
use App\Models\Socio;
use App\Models\Tag;
use App\Models\Utente;
use App\Notifications\Comunicazione\RiepilogoDiscussioniNotification;
use App\Services\Comunicazione\DiscussioniService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Spazio di discussione tra soci (issue #13): accesso per spazio, regolamento,
 * discussioni e risposte, testo sanificato, segnalazioni e moderazione,
 * sospensione, riepilogo email e passaggio a proposta.
 */
class DiscussioniSociTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    private Utente $admin;

    private DiscussioneSpazio $generale;

    private DiscussioniService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->associazione = Associazione::factory()->create();
        $this->admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $this->associazione->id]);
        $this->generale = DiscussioneSpazio::create(['associazione_id' => $this->associazione->id, 'nome' => 'Generale']);
        $this->service = app(DiscussioniService::class);
    }

    private function actAsAdmin(): self
    {
        $this->actingAs($this->admin)->withSession(['associazione_id' => $this->associazione->id]);

        return $this;
    }

    private function socio(bool $regolamento = true, array $attributi = []): Socio
    {
        $socio = Socio::factory()->conAccessoAreaSoci()->create(array_merge(['associazione_id' => $this->associazione->id], $attributi));

        if ($regolamento) {
            $this->service->accettaRegolamento($socio);
        }

        return $socio;
    }

    public function test_per_scrivere_serve_accettare_il_regolamento(): void
    {
        $socio = $this->socio(regolamento: false);

        $this->actingAs($socio, 'socio')->get(route('area-soci.discussioni'))->assertOk()->assertSee('Ho letto e accetto');
        $this->actingAs($socio, 'socio')->post(route('area-soci.discussioni.store', $this->generale), [
            'titolo' => 'Ciao', 'testo' => 'Primo messaggio',
        ])->assertSessionHas('error');

        $this->actingAs($socio, 'socio')->post(route('area-soci.discussioni.regolamento'))->assertSessionHas('success');
        $this->actingAs($socio, 'socio')->post(route('area-soci.discussioni.store', $this->generale), [
            'titolo' => 'Ciao', 'testo' => 'Primo messaggio',
        ])->assertSessionHas('success');

        $discussione = Discussione::query()->firstOrFail();
        $this->assertSame(1, $discussione->messaggi()->count());
        // L'autore segue la propria discussione
        $this->assertTrue(DiscussioneIscrizione::query()->where('socio_id', $socio->id)->exists());
    }

    public function test_risposte_e_testo_sanificato_con_link(): void
    {
        $autore = $this->socio();
        $discussione = $this->service->apri($this->generale, $autore, 'Gita', 'Chi viene?');
        $altro = $this->socio();

        $this->actingAs($altro, 'socio')->post(route('area-soci.discussioni.rispondi', $discussione), [
            'testo' => "Io! <script>alert(1)</script>\nInfo: https://esempio.it/gita?a=1&b=2",
        ])->assertSessionHas('success');

        $pagina = $this->actingAs($autore, 'socio')->get(route('area-soci.discussioni.show', $discussione))->assertOk();
        $pagina->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false)
            ->assertSee('<a href="https://esempio.it/gita?a=1&amp;b=2" target="_blank" rel="noopener nofollow ugc">', false)
            ->assertSee($altro->nome_completo)
            ->assertDontSee($altro->email);
    }

    public function test_spazio_per_tag_visibile_solo_a_chi_ha_il_tag(): void
    {
        $tag = Tag::create(['associazione_id' => $this->associazione->id, 'nome_tag' => 'Gruppo eventi']);
        $spazio = DiscussioneSpazio::create(['associazione_id' => $this->associazione->id, 'nome' => 'Eventi', 'accesso' => 'tag', 'tag_id' => $tag->id]);
        $conTag = $this->socio();
        $conTag->tags()->attach($tag->id, ['associazione_id' => $this->associazione->id]);
        $senzaTag = $this->socio();

        $this->actingAs($conTag, 'socio')->get(route('area-soci.discussioni'))->assertSee('Eventi');
        $this->actingAs($senzaTag, 'socio')->get(route('area-soci.discussioni'))->assertDontSee('Eventi');
        $this->actingAs($senzaTag, 'socio')->get(route('area-soci.discussioni.spazio', $spazio))->assertNotFound();
        $this->actingAs($senzaTag, 'socio')->post(route('area-soci.discussioni.store', $spazio), ['titolo' => 'x', 'testo' => 'y'])->assertSessionHas('error');
    }

    public function test_soci_non_attivi_e_altre_associazioni_senza_accesso(): void
    {
        $sospeso = Socio::factory()->conAccessoAreaSoci()->sospeso()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($sospeso, 'socio')->get(route('area-soci.discussioni.spazio', $this->generale))->assertNotFound();

        $altraAssoc = Socio::factory()->conAccessoAreaSoci()->create();
        $this->actingAs($altraAssoc, 'socio')->get(route('area-soci.discussioni.spazio', $this->generale))->assertNotFound();
    }

    public function test_segnalazione_e_moderazione(): void
    {
        $autore = $this->socio();
        $discussione = $this->service->apri($this->generale, $autore, 'Litigio', 'Messaggio offensivo');
        $messaggio = $discussione->messaggi()->firstOrFail();
        $segnalante = $this->socio();

        $this->actingAs($segnalante, 'socio')->post(route('area-soci.discussioni.segnala', $messaggio), ['motivo' => 'Offensivo'])->assertSessionHas('success');
        $this->actingAs($segnalante, 'socio')->post(route('area-soci.discussioni.segnala', $messaggio))->assertSessionHas('error');
        $this->actingAs($autore, 'socio')->post(route('area-soci.discussioni.segnala', $messaggio))->assertSessionHas('error');

        $this->actAsAdmin()->get(route('discussioni.index'))->assertOk()->assertSee('Messaggio offensivo')->assertSee('Offensivo');

        $this->actAsAdmin()->post(route('discussioni.messaggi.nascondi', $messaggio))->assertSessionHas('success');
        $this->assertNotNull($messaggio->fresh()->nascosto_at);
        $this->assertNotNull(DiscussioneSegnalazione::query()->value('gestita_at'));

        // I soci non vedono più il messaggio, la moderazione sì
        $this->actingAs($segnalante, 'socio')->get(route('area-soci.discussioni.show', $discussione))->assertDontSee('Messaggio offensivo');
        $this->actAsAdmin()->get(route('discussioni.show', $discussione))->assertSee('Messaggio offensivo')->assertSee('Ripristina');

        $this->actAsAdmin()->post(route('discussioni.messaggi.ripristina', $messaggio));
        $this->assertNull($messaggio->fresh()->nascosto_at);
    }

    public function test_discussione_chiusa_e_socio_sospeso_non_scrivono(): void
    {
        $autore = $this->socio();
        $discussione = $this->service->apri($this->generale, $autore, 'Tema', 'Testo');

        $this->actAsAdmin()->patch(route('discussioni.update', $discussione), ['chiusa' => '1'])->assertSessionHas('success');
        $this->actingAs($autore, 'socio')->post(route('area-soci.discussioni.rispondi', $discussione), ['testo' => 'Ancora'])->assertSessionHas('error');

        $this->actAsAdmin()->patch(route('discussioni.update', $discussione), ['chiusa' => '0']);
        $this->actAsAdmin()->post(route('discussioni.soci.sospendi', $autore), ['motivo' => 'Toni accesi'])->assertSessionHas('success');
        $this->actingAs($autore, 'socio')->post(route('area-soci.discussioni.rispondi', $discussione), ['testo' => 'Ancora'])->assertSessionHas('error');
        $this->actingAs($autore, 'socio')->get(route('area-soci.discussioni'))->assertSee('Toni accesi');

        $this->actAsAdmin()->post(route('discussioni.soci.riattiva', $autore));
        $this->actingAs($autore, 'socio')->post(route('area-soci.discussioni.rispondi', $discussione), ['testo' => 'Ancora'])->assertSessionHas('success');
    }

    public function test_riepilogo_dei_nuovi_messaggi_con_opt_out(): void
    {
        $autore = $this->socio();
        $discussione = $this->service->apri($this->generale, $autore, 'Assemblea', 'Ne parliamo?');
        $optOut = $this->socio(attributi: ['email_opt_out' => true]);
        $this->service->segui($discussione, $optOut);

        $this->travel(1)->hours();
        $this->service->rispondi($discussione, $this->socio(), 'Sì, giovedì.');
        $this->travel(1)->minutes();

        $this->artisan('discussioni:riepilogo')->assertSuccessful();
        Notification::assertSentTo($autore, RiepilogoDiscussioniNotification::class, fn ($n) => $n->discussioni[0]['nuovi'] === 1 && $n->discussioni[0]['titolo'] === 'Assemblea');
        Notification::assertNotSentTo($optOut, RiepilogoDiscussioniNotification::class);

        // Nessuna novità: nessun nuovo riepilogo
        $this->artisan('discussioni:riepilogo')->assertSuccessful();
        Notification::assertSentToTimes($autore, RiepilogoDiscussioniNotification::class, 1);
    }

    public function test_i_propri_messaggi_non_vanno_nel_riepilogo(): void
    {
        $autore = $this->socio();
        $discussione = $this->service->apri($this->generale, $autore, 'Solo io', 'Primo');
        $this->travel(1)->hours();
        $this->service->rispondi($discussione, $autore, 'Secondo');
        $this->travel(1)->minutes();

        $this->artisan('discussioni:riepilogo')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_spazi_gestiti_dalla_segreteria_e_permessi(): void
    {
        $this->actAsAdmin()->post(route('discussioni.spazi.store'), ['nome' => 'Idee', 'accesso' => 'tutti'])->assertSessionHas('success');
        $this->assertTrue(DiscussioneSpazio::query()->where('nome', 'Idee')->exists());

        $this->actAsAdmin()->post(route('discussioni.spazi.store'), ['nome' => 'Senza tag', 'accesso' => 'tag'])->assertSessionHasErrors('tag_id');

        $this->actAsAdmin()->put(route('discussioni.spazi.update', $this->generale), ['nome' => 'Generale', 'accesso' => 'tutti', 'archiviato' => '1']);
        $this->actingAs($this->socio(), 'socio')->get(route('area-soci.discussioni'))->assertDontSee('Generale');

        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $this->associazione->id]);
        $this->actingAs($operatore)->withSession(['associazione_id' => $this->associazione->id])
            ->get(route('discussioni.index'))->assertForbidden();
    }

    public function test_la_discussione_diventa_una_proposta(): void
    {
        $autore = $this->socio();
        $discussione = $this->service->apri($this->generale, $autore, 'Un orto sociale', 'Usiamo il giardino della sede.');

        $this->actingAs($autore, 'socio')->get(route('area-soci.discussioni.show', $discussione))->assertSee('Fanne una proposta');
        $this->actingAs($autore, 'socio')->get(route('area-soci.proposte', ['da_discussione' => $discussione->id]))
            ->assertOk()
            ->assertSee('value="Un orto sociale"', false)
            ->assertSee('Usiamo il giardino della sede.');

        // Non con la discussione di un altro
        $this->actingAs($this->socio(), 'socio')->get(route('area-soci.proposte', ['da_discussione' => $discussione->id]))
            ->assertDontSee('value="Un orto sociale"', false);
    }
}
