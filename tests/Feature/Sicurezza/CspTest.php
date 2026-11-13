<?php

namespace Tests\Feature\Sicurezza;

use App\Models\Associazione;
use App\Models\Utente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Content-Security-Policy: script-src con nonce e senza 'unsafe-inline',
 * nessun CDN esterno, nessuno script o handler inline senza nonce nelle view.
 */
class CspTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'csp.enabled' => true,
            'csp.nonce_enabled' => true,
            'csp.nonce_policy' => true,
        ]);
    }

    public function test_nessuna_view_ha_script_inline_senza_nonce(): void
    {
        $senzaNonce = [];
        foreach ($this->sorgentiView() as $percorso => $contenuto) {
            preg_match_all('/<script\b[^>]*>/i', $contenuto, $tag);
            foreach ($tag[0] as $apertura) {
                if (! str_contains($apertura, 'src=') && ! str_contains($apertura, '@cspNonce')) {
                    $senzaNonce[] = $percorso.': '.$apertura;
                }
            }
        }

        $this->assertSame([], $senzaNonce, 'Script inline senza @cspNonce');
    }

    public function test_nessuna_view_ha_handler_on_o_url_javascript(): void
    {
        $trovati = [];
        foreach ($this->sorgentiView() as $percorso => $contenuto) {
            if (preg_match_all('/\son[a-z]+\s*=\s*["\']/i', $contenuto, $m)) {
                $trovati[] = $percorso.': '.implode(', ', array_map('trim', $m[0]));
            }
            if (preg_match('/href\s*=\s*["\']\s*javascript:/i', $contenuto)) {
                $trovati[] = $percorso.': href javascript:';
            }
        }

        $this->assertSame([], $trovati, 'Handler inline bloccati dalla CSP con nonce');
    }

    public function test_la_policy_usa_il_nonce_e_non_ammette_unsafe_inline_ne_cdn(): void
    {
        $response = $this->get('/login')->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $scriptSrc = $this->direttiva($csp, 'script-src');
        $this->assertMatchesRegularExpression("/^'self' 'nonce-[A-Za-z0-9]+'$/", $scriptSrc);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $csp);
        // Nessun host esterno per gli script; l'unica eccezione è il percorso
        // delle icone Font Awesome che GrapesJS carica da sé, solo come stile e font
        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $scriptSrc);
        $icone = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/';
        $this->assertStringContainsString($icone, $this->direttiva($csp, 'style-src'));
        $this->assertStringContainsString($icone, $this->direttiva($csp, 'font-src'));
        foreach (preg_split('/[\s;]+/', $csp) as $token) {
            if (str_contains($token, 'cdnjs.cloudflare.com')) {
                $this->assertSame($icone, $token, 'unico host cdnjs ammesso: il percorso delle icone');
            }
        }
        // Gli attributi style="" restano ammessi (dichiarato nel preset)
        $this->assertStringContainsString("'unsafe-inline'", $this->direttiva($csp, 'style-src'));

        $this->assertScriptInlineConNonce($response, $scriptSrc);
    }

    public function test_il_nonce_cambia_a_ogni_richiesta(): void
    {
        $primo = $this->direttiva((string) $this->get('/login')->headers->get('Content-Security-Policy'), 'script-src');
        $this->app->forgetScopedInstances();
        $secondo = $this->direttiva((string) $this->get('/login')->headers->get('Content-Security-Policy'), 'script-src');

        $this->assertNotSame($primo, $secondo);
    }

    public function test_con_nonce_disattivato_si_torna_a_unsafe_inline(): void
    {
        // Compatibilità: CSP_NONCE_ENABLED=false ripristina la policy precedente
        config(['csp.nonce_policy' => false]);

        $response = $this->get('/login')->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertSame("'self' 'unsafe-inline'", $this->direttiva($csp, 'script-src'));
        $this->assertStringNotContainsString("'nonce-", $csp);
    }

    public function test_pagine_autenticate_hanno_tutti_gli_script_inline_con_nonce(): void
    {
        $associazione = Associazione::factory()->create();
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => $associazione->id]);

        foreach (['/dashboard', '/soci', '/eventi', '/profilo'] as $url) {
            $this->app->forgetScopedInstances();
            $response = $this->actingAs($admin)->get($url);
            $this->assertSame(200, $response->status(), $url);

            $scriptSrc = $this->direttiva((string) $response->headers->get('Content-Security-Policy'), 'script-src');
            $this->assertStringContainsString("'nonce-", $scriptSrc, $url);
            $this->assertScriptInlineConNonce($response, $scriptSrc, $url);
            $this->assertStringContainsString('assets/js/azioni-dichiarative.js', (string) $response->getContent(), $url);
        }
    }

    public function test_pagine_scanner_e_checkin_usano_il_nonce(): void
    {
        foreach (['/checkin', '/scanner-tessera'] as $url) {
            $this->app->forgetScopedInstances();
            $response = $this->get($url)->assertOk();

            $scriptSrc = $this->direttiva((string) $response->headers->get('Content-Security-Policy'), 'script-src');
            $this->assertMatchesRegularExpression("/^'self' 'nonce-[A-Za-z0-9]+'$/", $scriptSrc, $url);
            $this->assertScriptInlineConNonce($response, $scriptSrc, $url);
        }

        config(['csp.nonce_policy' => false]);
        $this->app->forgetScopedInstances();
        $csp = (string) $this->get('/checkin')->headers->get('Content-Security-Policy');
        $this->assertSame("'self' 'unsafe-inline'", $this->direttiva($csp, 'script-src'));
    }

    /**
     * @return array<string, string>
     */
    private function sorgentiView(): array
    {
        $view = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $view[$file->getRelativePathname()] = $file->getContents();
            }
        }

        $this->assertNotEmpty($view);

        return $view;
    }

    private function direttiva(string $csp, string $nome): string
    {
        foreach (explode(';', $csp) as $parte) {
            $parte = trim($parte);
            if (str_starts_with($parte, $nome.' ')) {
                return trim(substr($parte, strlen($nome)));
            }
        }

        $this->fail("Direttiva {$nome} assente in: {$csp}");
    }

    private function assertScriptInlineConNonce(TestResponse $response, string $scriptSrc, string $contesto = ''): void
    {
        $this->assertSame(1, preg_match("/'nonce-([A-Za-z0-9]+)'/", $scriptSrc, $m), $contesto);
        $nonce = $m[1];

        preg_match_all('/<script\b[^>]*>/i', (string) $response->getContent(), $tag);
        foreach ($tag[0] as $apertura) {
            if (str_contains($apertura, 'src=')) {
                continue;
            }
            $this->assertStringContainsString('nonce="'.$nonce.'"', $apertura, $contesto.' '.$apertura);
        }
    }
}
