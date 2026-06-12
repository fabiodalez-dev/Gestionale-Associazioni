<?php

namespace Tests\Feature\AnagraficaPro;

use App\Models\Associazione;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * f.22 PIANO-VITA-SOCIALE: slug editabile nel form associazione
 * (unique, kebab-case) + box invito con link e QR PNG.
 */
class SlugInvitoTest extends TestCase
{
    use RefreshDatabase;

    private function payloadAssociazione(Associazione $associazione, array $override = []): array
    {
        return array_merge([
            'nome' => $associazione->nome,
            'email' => $associazione->email,
        ], $override);
    }

    public function test_super_admin_aggiorna_lo_slug_in_kebab_case(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $associazione = Associazione::factory()->create();

        $response = $this->actingAs($superAdmin)->put(
            "/associazioni/{$associazione->id}",
            $this->payloadAssociazione($associazione, ['slug' => 'nuovo-slug-2026'])
        );

        $response->assertRedirect(route('associazioni.index'));
        $this->assertSame('nuovo-slug-2026', $associazione->refresh()->slug);
    }

    public function test_slug_non_kebab_case_viene_rifiutato(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $associazione = Associazione::factory()->create();
        $slugOriginale = $associazione->slug;

        foreach (['Con Maiuscole', 'spazi non validi', 'àccénti', 'doppio--trattino', '-inizia-col-trattino'] as $slugInvalido) {
            $response = $this->actingAs($superAdmin)->put(
                "/associazioni/{$associazione->id}",
                $this->payloadAssociazione($associazione, ['slug' => $slugInvalido])
            );

            $response->assertSessionHasErrors('slug');
        }

        $this->assertSame($slugOriginale, $associazione->refresh()->slug);
    }

    public function test_slug_duplicato_viene_rifiutato(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        Associazione::factory()->create(['slug' => 'slug-occupato']);
        $associazione = Associazione::factory()->create();

        $response = $this->actingAs($superAdmin)->put(
            "/associazioni/{$associazione->id}",
            $this->payloadAssociazione($associazione, ['slug' => 'slug-occupato'])
        );

        $response->assertSessionHasErrors('slug');
        $this->assertNotSame('slug-occupato', $associazione->refresh()->slug);
    }

    public function test_slug_vuoto_in_update_lascia_lo_slug_invariato(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $associazione = Associazione::factory()->create(['slug' => 'slug-esistente']);

        $this->actingAs($superAdmin)->put(
            "/associazioni/{$associazione->id}",
            $this->payloadAssociazione($associazione, ['slug' => ''])
        )->assertRedirect(route('associazioni.index'));

        $this->assertSame('slug-esistente', $associazione->refresh()->slug);
    }

    public function test_creazione_con_slug_esplicito_e_autogenerazione_senza(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->post('/associazioni', [
            'nome' => 'Con Slug Scelto',
            'email' => 'conslug@example.com',
            'slug' => 'il-mio-slug',
        ])->assertRedirect(route('associazioni.index'));

        $this->assertSame('il-mio-slug', Associazione::where('email', 'conslug@example.com')->first()->slug);

        $this->actingAs($superAdmin)->post('/associazioni', [
            'nome' => 'Senza Slug Esplicito',
            'email' => 'senzaslug@example.com',
        ])->assertRedirect(route('associazioni.index'));

        $this->assertSame(
            'senza-slug-esplicito',
            Associazione::where('email', 'senzaslug@example.com')->first()->slug
        );
    }

    public function test_il_form_associazioni_mostra_il_campo_slug_con_anteprima(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $associazione = Associazione::factory()->create(['slug' => 'slug-visibile']);

        $response = $this->actingAs($superAdmin)->get("/associazioni?edit={$associazione->id}");

        $response->assertOk();
        $response->assertSee('name="slug"', false);
        $response->assertSee('slug-visibile');
        $response->assertSee('slugAnteprima');
    }

    public function test_box_invito_nelle_configurazioni_mostra_link_iscriviti(): void
    {
        $associazione = Associazione::factory()->create(['slug' => 'assoc-invito']);
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $associazione->id]);

        $response = $this->actingAs($admin)->get('/configurazioni?section=invito');

        $response->assertOk();
        $response->assertSee('Invita al tuo mini-sito');
        $response->assertSee('/a/assoc-invito/iscriviti');
        $response->assertSee('Scarica QR (PNG)');
    }

    public function test_qr_invito_png_inline_e_download(): void
    {
        $associazione = Associazione::factory()->create(['slug' => 'assoc-qr']);
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $associazione->id]);

        $inline = $this->actingAs($admin)->get('/configurazioni/invito-qr');
        $inline->assertOk();
        $inline->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", $inline->getContent());

        $download = $this->actingAs($admin)->get('/configurazioni/invito-qr?download=1');
        $download->assertOk();
        $download->assertHeader('Content-Disposition', 'attachment; filename="invito-assoc-qr.png"');
    }

    public function test_qr_invito_vietato_all_operatore_e_ai_guest(): void
    {
        $associazione = Associazione::factory()->create();
        $operatore = Utente::factory()->operatore()->create(['associazione_id' => $associazione->id]);

        // Guest prima di autenticarsi: actingAs persiste per tutto il test
        $this->get('/configurazioni/invito-qr')->assertRedirect();

        $this->actingAs($operatore)->get('/configurazioni/invito-qr')->assertForbidden();
    }
}
