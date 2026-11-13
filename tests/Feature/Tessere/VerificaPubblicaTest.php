<?php

namespace Tests\Feature\Tessere;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\Tessere\TesseraQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerificaPubblicaTest extends TestCase
{
    use RefreshDatabase;

    /** URL firmato della pagina HTML (quello stampato nel QR). */
    private function urlFirmato(Tessera $tessera): string
    {
        return app(TesseraQrService::class)->verificationUrl($tessera);
    }

    /** URL firmato dell'endpoint JSON usato dallo scanner (stessa firma del QR). */
    private function urlJsonFirmato(Tessera|string $tessera): string
    {
        $id = $tessera instanceof Tessera ? $tessera->id : $tessera;

        return route('tessere.verifica.json', ['tessera' => $id, 'signature' => app(TesseraQrService::class)->signature($id)]);
    }

    private function creaTessera(array $attrs = []): Tessera
    {
        $assoc = Associazione::factory()->create();
        $socio = Socio::factory()->create(['associazione_id' => $assoc->id]);

        return Tessera::factory()->create(array_merge([
            'socio_id' => $socio->id,
            'associazione_id' => $assoc->id,
        ], $attrs));
    }

    public function test_landing_senza_parametro(): void
    {
        $this->get('/verifica-tessera')
            ->assertOk()
            ->assertSee('Verifica Tessera Associativa')
            ->assertSee('Scansiona il QR code');
    }

    public function test_tessera_valida_visibile_senza_autenticazione(): void
    {
        $tessera = $this->creaTessera();

        $response = $this->get($this->urlFirmato($tessera));

        $response->assertOk();
        $response->assertSee('Tessera Valida');
        $response->assertSee($tessera->numero_tessera);
        $response->assertSee($tessera->socio->nome);
    }

    public function test_tessera_scaduta(): void
    {
        $tessera = $this->creaTessera([
            'data_emissione' => now()->subYear()->toDateString(),
            'data_scadenza' => now()->subDay()->toDateString(),
        ]);

        $this->get($this->urlFirmato($tessera))
            ->assertOk()
            ->assertSee('Tessera Scaduta / Non Attiva');
    }

    public function test_tessera_sospesa_mostrata_come_non_attiva_con_stato_reale(): void
    {
        $tessera = $this->creaTessera(['stato' => 'Sospesa']);

        $this->get($this->urlFirmato($tessera))
            ->assertOk()
            ->assertSee('Tessera Scaduta / Non Attiva')
            ->assertSee('Sospesa');
    }

    public function test_uuid_non_valido_o_inesistente(): void
    {
        $this->get('/verifica-tessera/non-un-uuid')
            ->assertOk()
            ->assertSee('Tessera Non Trovata');

        // Firma corretta ma UUID inesistente → not_found
        $this->get(app(TesseraQrService::class)->verificationUrl('00000000-0000-0000-0000-000000000000'))
            ->assertOk()
            ->assertSee('Tessera Non Trovata');
    }

    public function test_endpoint_json_per_lo_scanner(): void
    {
        $tessera = $this->creaTessera();

        $response = $this->getJson($this->urlJsonFirmato($tessera));

        $response->assertOk();
        $response->assertJson([
            'stato' => 'valida',
            'tessera' => [
                'numero_tessera' => $tessera->numero_tessera,
                'anno_validita' => $tessera->anno_validita,
                'stato_tessera' => 'Attiva',
                'socio_nome' => $tessera->socio->nome,
                'socio_cognome' => $tessera->socio->cognome,
                'associazione_nome' => $tessera->associazione->nome,
            ],
        ]);
    }

    public function test_endpoint_json_not_found(): void
    {
        $this->getJson($this->urlJsonFirmato('00000000-0000-0000-0000-000000000000'))
            ->assertOk()
            ->assertJson(['stato' => 'not_found'])
            ->assertJsonMissingPath('tessera');
    }

    public function test_url_firmato_del_qr_e_valido_mentre_firma_manomessa_e_rifiutata(): void
    {
        $tessera = $this->creaTessera();

        $signedUrl = $this->urlFirmato($tessera);
        $this->assertStringContainsString('signature=', $signedUrl);

        // URL firmato (quello nel QR) → valida
        $this->get($signedUrl)->assertOk()->assertSee('Tessera Valida');

        // Firma manomessa → not_found
        $this->get(route('tessere.verifica', ['tessera' => $tessera->id, 'signature' => 'manomessa']))
            ->assertOk()
            ->assertSee('Tessera Non Trovata');
    }

    /**
     * Hardening (audit run-1): il solo UUID non è più una capability. Prima
     * l'URL senza firma mostrava la tessera (compat QR legacy / inserimento
     * manuale); ora senza `signature` valida la risposta è identica a quella
     * di una tessera inesistente, su tutte e tre le vie (HTML, ?json, JSON).
     */
    public function test_senza_firma_la_tessera_non_e_rivelata_su_nessuna_via(): void
    {
        $tessera = $this->creaTessera();

        $this->get('/verifica-tessera/'.$tessera->id)
            ->assertOk()
            ->assertSee('Tessera Non Trovata')
            ->assertDontSee($tessera->numero_tessera)
            ->assertDontSee($tessera->socio->cognome);

        // Compat legacy ?t= senza firma: stessa risposta neutra
        $this->get('/verifica-tessera?t='.$tessera->id)
            ->assertOk()
            ->assertSee('Tessera Non Trovata');

        $this->get('/verifica-tessera/'.$tessera->id.'?json=1')
            ->assertOk()
            ->assertExactJson(['stato' => 'not_found']);

        $this->getJson('/api/verifica-tessera/'.$tessera->id)
            ->assertOk()
            ->assertExactJson(['stato' => 'not_found']);

        // La firma di un'altra tessera non vale per questa
        $altra = $this->creaTessera();
        $this->getJson(route('tessere.verifica.json', ['tessera' => $tessera->id, 'signature' => app(TesseraQrService::class)->signature($altra)]))
            ->assertOk()
            ->assertExactJson(['stato' => 'not_found']);
    }

    public function test_tessera_importata_con_qr_legacy_senza_firma_resta_verificabile(): void
    {
        // Compatibilità con le installazioni esistenti: una tessera importata dal
        // vecchio gestionale ha stampato un QR con l'URL senza firma; quel QR
        // deve continuare a funzionare. Le tessere emesse da qui hanno sempre
        // l'URL firmato e per loro il solo UUID non basta.
        $legacy = $this->creaTessera();
        $legacy->forceFill(['qr_code_url' => url('/verifica-tessera/'.$legacy->id)])->save();

        $this->get('/verifica-tessera/'.$legacy->id)
            ->assertOk()
            ->assertSee('Tessera Valida')
            ->assertSee($legacy->numero_tessera);
        $this->getJson('/api/verifica-tessera/'.$legacy->id)
            ->assertOk()
            ->assertJsonPath('stato', 'valida');
        $this->getJson('/api/v1/tessere/verify?tessera_id='.$legacy->id)
            ->assertOk()
            ->assertJsonPath('valid', true);

        // Tessera con qr_code_url firmato (emessa da questa app): solo UUID → not_found
        $moderna = $this->creaTessera();
        $moderna->forceFill(['qr_code_url' => app(TesseraQrService::class)->verificationUrl($moderna)])->save();
        $this->get('/verifica-tessera/'.$moderna->id)->assertOk()->assertSee('Tessera Non Trovata');
        $this->getJson('/api/v1/tessere/verify?tessera_id='.$moderna->id)->assertForbidden();
    }

    public function test_il_comando_tessere_firma_qr_chiude_la_finestra_legacy(): void
    {
        $legacy = $this->creaTessera();
        $legacy->forceFill(['qr_code_url' => url('/verifica-tessera/'.$legacy->id)])->save();
        $this->get('/verifica-tessera/'.$legacy->id)->assertOk()->assertSee('Tessera Valida');

        $this->artisan('tessere:firma-qr', ['--dry-run' => true])->expectsOutputToContain('1 tessere')->assertSuccessful();
        $this->assertStringNotContainsString('signature=', (string) $legacy->fresh()->qr_code_url);

        $this->artisan('tessere:firma-qr')->expectsOutputToContain('1 tessere aggiornate')->assertSuccessful();
        $this->assertStringContainsString('signature=', (string) $legacy->fresh()->qr_code_url);
        $this->get('/verifica-tessera/'.$legacy->id)->assertOk()->assertSee('Tessera Non Trovata');
        $this->get($legacy->fresh()->qr_code_url)->assertOk()->assertSee('Tessera Valida');
    }

    public function test_la_firma_del_qr_vale_anche_per_l_endpoint_json_dello_scanner(): void
    {
        $tessera = $this->creaTessera();

        // Lo scanner ricava uuid e signature dall'URL stampato nel QR
        $qrUrl = $this->urlFirmato($tessera);
        parse_str((string) parse_url($qrUrl, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['signature'] ?? '');

        $this->getJson(route('tessere.verifica.json', ['tessera' => $tessera->id, 'signature' => $query['signature']]))
            ->assertOk()
            ->assertJsonPath('stato', 'valida')
            ->assertJsonPath('tessera.numero_tessera', $tessera->numero_tessera);

        // L'UUID in maiuscolo (QR letto da un lettore che normalizza) vale lo stesso
        $this->getJson(route('tessere.verifica.json', ['tessera' => strtoupper($tessera->id), 'signature' => $query['signature']]))
            ->assertOk()
            ->assertJsonPath('stato', 'valida');
    }

    public function test_pagina_scanner_pubblica_con_headers_camera(): void
    {
        $response = $this->get('/scanner-tessera');

        $response->assertOk();
        $response->assertSee('Scanner Tessera');
        $response->assertSee('html5-qrcode.min.js');
        $this->assertStringContainsString('camera=(self)', (string) $response->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('blob:', (string) $response->headers->get('Content-Security-Policy'));
    }
}
