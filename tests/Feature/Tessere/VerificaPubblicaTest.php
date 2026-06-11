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

        $response = $this->get('/verifica-tessera/'.$tessera->id);

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

        $this->get('/verifica-tessera/'.$tessera->id)
            ->assertOk()
            ->assertSee('Tessera Scaduta / Non Attiva');
    }

    public function test_tessera_sospesa_mostrata_come_non_attiva_con_stato_reale(): void
    {
        $tessera = $this->creaTessera(['stato' => 'Sospesa']);

        $this->get('/verifica-tessera/'.$tessera->id)
            ->assertOk()
            ->assertSee('Tessera Scaduta / Non Attiva')
            ->assertSee('Sospesa');
    }

    public function test_uuid_non_valido_o_inesistente(): void
    {
        $this->get('/verifica-tessera/non-un-uuid')
            ->assertOk()
            ->assertSee('Tessera Non Trovata');

        $this->get('/verifica-tessera/00000000-0000-0000-0000-000000000000')
            ->assertOk()
            ->assertSee('Tessera Non Trovata');
    }

    public function test_endpoint_json_per_lo_scanner(): void
    {
        $tessera = $this->creaTessera();

        $response = $this->getJson('/api/verifica-tessera/'.$tessera->id);

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
        $this->getJson('/api/verifica-tessera/00000000-0000-0000-0000-000000000000')
            ->assertOk()
            ->assertJson(['stato' => 'not_found'])
            ->assertJsonMissingPath('tessera');
    }

    public function test_url_firmato_del_qr_e_valido_mentre_firma_manomessa_e_rifiutata(): void
    {
        $tessera = $this->creaTessera();

        $signedUrl = app(TesseraQrService::class)->verificationUrl($tessera);

        // URL firmato (quello nel QR) → valida
        $this->get($signedUrl)->assertOk()->assertSee('Tessera Valida');

        // Firma manomessa → not_found
        $this->get(route('tessere.verifica', ['tessera' => $tessera->id, 'signature' => 'manomessa']))
            ->assertOk()
            ->assertSee('Tessera Non Trovata');

        // Senza firma (inserimento manuale / QR legacy) → valida comunque
        $this->get('/verifica-tessera/'.$tessera->id)->assertOk()->assertSee('Tessera Valida');
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
