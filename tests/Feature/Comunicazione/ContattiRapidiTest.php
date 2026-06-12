<?php

namespace Tests\Feature\Comunicazione;

use App\Models\Socio;
use App\Services\Comunicazione\ContattiRapidi;

/**
 * f.10 — Contatti rapidi: link wa.me (con prefisso internazionale),
 * tel: e mailto: sulla scheda socio e nelle liste — pura UI, zero API.
 */
class ContattiRapidiTest extends ComunicazioneTestCase
{
    // ── Normalizzazione numeri ───────────────────────────────────────

    public function test_numero_italiano_senza_prefisso_riceve_il_39(): void
    {
        $this->assertSame('https://wa.me/393331234567', ContattiRapidi::whatsappUrl('333 1234567'));
    }

    public function test_numero_con_piu_mantiene_il_prefisso(): void
    {
        $this->assertSame('https://wa.me/443331234567', ContattiRapidi::whatsappUrl('+44 333 1234567'));
    }

    public function test_numero_con_doppio_zero_perde_gli_zeri_iniziali(): void
    {
        $this->assertSame('https://wa.me/393331234567', ContattiRapidi::whatsappUrl('0039 333 1234567'));
    }

    public function test_telefono_vuoto_o_invalido_non_genera_link(): void
    {
        $this->assertNull(ContattiRapidi::whatsappUrl(null));
        $this->assertNull(ContattiRapidi::whatsappUrl(''));
        $this->assertNull(ContattiRapidi::whatsappUrl('abc'));
        $this->assertNull(ContattiRapidi::telUrl(null));
        $this->assertNull(ContattiRapidi::telUrl('   '));
    }

    public function test_tel_url_conserva_il_piu_e_rimuove_gli_spazi(): void
    {
        $this->assertSame('tel:+393331234567', ContattiRapidi::telUrl('+39 333 1234567'));
    }

    // ── UI scheda e lista soci ───────────────────────────────────────

    public function test_scheda_socio_mostra_i_bottoni_contatto_rapido(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'mario@example.com',
            'telefono' => '333 1234567',
        ]);

        $this->actingAs($this->admin)
            ->get(route('soci.show', $socio))
            ->assertOk()
            ->assertSee('https://wa.me/393331234567')
            ->assertSee('tel:3331234567', false)
            ->assertSee('mailto:mario@example.com', false);
    }

    public function test_scheda_socio_senza_telefono_non_mostra_whatsapp(): void
    {
        $socio = Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'email' => 'senza.telefono@example.com',
            'telefono' => null,
        ]);

        $this->actingAs($this->admin)
            ->get(route('soci.show', $socio))
            ->assertOk()
            ->assertDontSee('https://wa.me/')
            ->assertSee('mailto:senza.telefono@example.com', false);
    }

    public function test_lista_soci_mostra_i_contatti_rapidi(): void
    {
        Socio::factory()->create([
            'associazione_id' => $this->associazione->id,
            'telefono' => '333 7654321',
        ]);

        // tessera_stato=all: il default della lista mostra solo i soci con tessera attiva
        $this->actingAs($this->admin)
            ->get(route('soci.index', ['tessera_stato' => 'all']))
            ->assertOk()
            ->assertSee('https://wa.me/393337654321');
    }
}
