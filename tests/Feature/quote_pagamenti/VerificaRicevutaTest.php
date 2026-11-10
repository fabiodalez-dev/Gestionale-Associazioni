<?php

namespace Tests\Feature\quote_pagamenti;

use App\Models\Associazione;
use App\Models\Ricevuta;
use App\Services\Pagamenti\RicevutaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifica pubblica delle ricevute dal QR del PDF (#77).
 */
class VerificaRicevutaTest extends TestCase
{
    use RefreshDatabase;

    private function ricevuta(): Ricevuta
    {
        $associazione = Associazione::factory()->create(['nome' => 'APS Faro']);

        return Ricevuta::factory()->create([
            'associazione_id' => $associazione->id,
            'numero' => 12, 'anno' => 2026, 'importo' => 25,
            'intestatario_nome' => 'Mario', 'intestatario_cognome' => 'Rossi',
            'intestatario_codice_fiscale' => 'RSSMRA85T10A562S',
        ]);
    }

    public function test_url_firmato_mostra_la_ricevuta_con_intestatario_abbreviato(): void
    {
        $ricevuta = $this->ricevuta();
        $url = URL::signedRoute('ricevute.verifica', ['ricevuta' => $ricevuta->id]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Ricevuta valida')
            ->assertSee('APS Faro')
            ->assertSee('12/2026')
            ->assertSee('Mario R.')
            ->assertDontSee('Rossi')
            ->assertDontSee('RSSMRA85T10A562S')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->getJson($url)
            ->assertOk()
            ->assertJson(['valida' => true, 'numero' => '12/2026', 'importo' => '25.00', 'intestatario' => 'Mario R.']);
    }

    public function test_senza_firma_o_ricevuta_inesistente(): void
    {
        $ricevuta = $this->ricevuta();

        $this->get('/verifica-ricevuta/'.$ricevuta->id)->assertForbidden();

        $this->getJson(URL::signedRoute('ricevute.verifica', ['ricevuta' => (string) Str::uuid()]))
            ->assertNotFound()
            ->assertJson(['valida' => false]);
    }

    public function test_il_pdf_contiene_il_qr_di_verifica(): void
    {
        $ricevuta = $this->ricevuta();

        $pdf = app(RicevutaService::class)->pdfContent($ricevuta);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('/Image', $pdf);
    }
}
