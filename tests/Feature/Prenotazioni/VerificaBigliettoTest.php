<?php

namespace Tests\Feature\Prenotazioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Services\Prenotazioni\BigliettoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Verifica pubblica del biglietto: GET /verifica-biglietto/{token}
 * (firma obbligatoria, esiti valida / già usato / non valida con motivo,
 * nessun dato personale completo in pagina).
 */
class VerificaBigliettoTest extends TestCase
{
    use RefreshDatabase;

    private function creaPrenotazione(array $attributi = []): Prenotazione
    {
        $assoc = Associazione::factory()->create();
        $evento = Evento::factory()->pubblicato()->create([
            'associazione_id' => $assoc->id,
            'titolo' => 'Concerto di Prova',
        ]);
        $account = Account::factory()->create([
            'associazione_id' => $assoc->id,
            'nome' => 'Mario',
            'cognome' => 'Verdirossi',
        ]);

        return Prenotazione::factory()->create(array_merge([
            'evento_id' => $evento->id,
            'associazione_id' => $assoc->id,
            'account_id' => $account->id,
            'posti' => 2,
        ], $attributi));
    }

    public function test_biglietto_confermato_con_url_firmato_e_valido(): void
    {
        $prenotazione = $this->creaPrenotazione();

        $url = app(BigliettoService::class)->signedUrl($prenotazione);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee('Biglietto valido');
        $response->assertSee('Concerto di Prova');
        $response->assertSee($prenotazione->codice);
    }

    public function test_firma_manomessa_biglietto_non_valido(): void
    {
        $p1 = $this->creaPrenotazione();
        $p2 = $this->creaPrenotazione();

        // URL firmato di p1 con il token di p2: firma non più valida
        $url = str_replace(
            $p1->qr_token,
            $p2->qr_token,
            app(BigliettoService::class)->signedUrl($p1)
        );

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee('Biglietto non valido');
        $response->assertSee('manomesso');
        $response->assertDontSee('Biglietto valido');
    }

    public function test_url_senza_firma_biglietto_non_valido(): void
    {
        $prenotazione = $this->creaPrenotazione();

        $response = $this->get('/verifica-biglietto/'.$prenotazione->qr_token);

        $response->assertOk();
        $response->assertSee('Biglietto non valido');
    }

    public function test_token_inesistente_biglietto_non_trovato(): void
    {
        $url = URL::signedRoute('biglietti.verifica', ['token' => bin2hex(random_bytes(32))]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee('Biglietto non valido');
        $response->assertSee('Biglietto non trovato.');
    }

    public function test_biglietto_gia_usato_mostra_orario_checkin(): void
    {
        $prenotazione = $this->creaPrenotazione([
            'checkin_at' => now()->setDate(2026, 6, 10)->setTime(14, 30),
        ]);

        $response = $this->get(app(BigliettoService::class)->signedUrl($prenotazione));

        $response->assertOk();
        $response->assertSee('Biglietto già utilizzato');
        $response->assertSee('14:30');
        $response->assertSee('10/06/2026');
    }

    public function test_prenotazione_annullata_non_valida_con_motivo(): void
    {
        $prenotazione = $this->creaPrenotazione([
            'stato' => Prenotazione::STATO_ANNULLATA,
            'annullata_at' => now(),
        ]);

        $response = $this->get(app(BigliettoService::class)->signedUrl($prenotazione));

        $response->assertOk();
        $response->assertSee('Biglietto non valido');
        $response->assertSee('Prenotazione annullata.');
    }

    public function test_prenotazione_in_lista_attesa_non_valida(): void
    {
        $prenotazione = $this->creaPrenotazione([
            'stato' => Prenotazione::STATO_LISTA_ATTESA,
        ]);

        $response = $this->get(app(BigliettoService::class)->signedUrl($prenotazione));

        $response->assertOk();
        $response->assertSee('Biglietto non valido');
        $response->assertSee('lista d&#039;attesa', false);
    }

    public function test_token_con_formato_errato_404(): void
    {
        $this->get('/verifica-biglietto/token-non-esadecimale')->assertNotFound();
        $this->get('/verifica-biglietto/'.str_repeat('a', 32))->assertNotFound();
    }

    public function test_qr_e_pdf_del_biglietto_generati(): void
    {
        $prenotazione = $this->creaPrenotazione();
        $service = app(BigliettoService::class);

        $svg = $service->qrSvg($prenotazione);
        $this->assertStringContainsString('<svg', $svg);

        $dataUri = $service->qrDataUri($prenotazione);
        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);

        $pdf = $service->pdfBinary($prenotazione);
        $this->assertStringStartsWith('%PDF', $pdf);

        $this->assertSame(
            'biglietto_'.str_replace('-', '_', $prenotazione->codice).'.pdf',
            $service->pdfFilename($prenotazione)
        );
    }

    public function test_pagina_pubblica_non_espone_dati_personali_completi(): void
    {
        $prenotazione = $this->creaPrenotazione();
        $account = $prenotazione->account()->withoutGlobalScope('associazione')->firstOrFail();

        $response = $this->get(app(BigliettoService::class)->signedUrl($prenotazione));

        $response->assertOk();
        $response->assertSee('Mario V.');
        $response->assertDontSee('Verdirossi');
        $response->assertDontSee($account->email);
    }
}
