<?php

namespace Tests\Feature\Admin;

use App\Services\Update\UpdateService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Scelta e verifica del pacchetto della release: asset <repo>-<tag>.zip,
 * digest sha256 dell'API GitHub (fail-closed), download diretto su file.
 */
class UpdatePacchettoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['update.github.owner' => 'fabiodalez-dev', 'update.github.repo' => 'gestionale-associazioni', 'update.github.token' => null]);
        $this->dir = sys_get_temp_dir().'/updater_dl_'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_sceglie_l_asset_del_pacchetto_e_ignora_gli_altri(): void
    {
        $release = ['tag_name' => 'v1.2.3', 'assets' => [
            ['name' => 'gestionale-associazioni-v1.2.3.zip.sha256', 'browser_download_url' => 'https://github.com/x/sha'],
            ['name' => 'Gestionale-Associazioni-v1.2.3.zip', 'browser_download_url' => 'https://github.com/x/pacchetto.zip', 'digest' => 'sha256:'.str_repeat('a', 64), 'size' => 123],
        ]];

        $asset = app(UpdateService::class)->assetPacchetto($release);

        $this->assertNotNull($asset);
        $this->assertSame('https://github.com/x/pacchetto.zip', $asset['url']);
        $this->assertSame('sha256:'.str_repeat('a', 64), $asset['digest']);
        $this->assertNull(app(UpdateService::class)->assetPacchetto(['tag_name' => 'v1.2.3', 'assets' => []]));
    }

    public function test_scarica_e_verifica_il_pacchetto_con_il_digest_dell_api(): void
    {
        $contenuto = str_repeat('pacchetto finto ', 100);
        $this->fakeRelease($contenuto, 'sha256:'.hash('sha256', $contenuto));

        $dest = $this->dir.'/p.zip';
        $hash = app(UpdateService::class)->scaricaPacchetto('0.2.0', $dest);

        $this->assertSame(hash('sha256', $contenuto), $hash);
        $this->assertFileExists($dest);
        $this->assertSame($contenuto, file_get_contents($dest));
        $this->assertFileDoesNotExist($dest.'.part');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/releases/tags/v0.2.0'));
    }

    public function test_digest_non_corrispondente_rifiuta_e_cancella_il_file(): void
    {
        $contenuto = str_repeat('pacchetto manomesso ', 100);
        $this->fakeRelease($contenuto, 'sha256:'.str_repeat('0', 64));

        $dest = $this->dir.'/p.zip';
        try {
            app(UpdateService::class)->scaricaPacchetto('0.2.0', $dest);
            $this->fail('Attesa eccezione');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('integrità fallita', $e->getMessage());
        }
        $this->assertFileDoesNotExist($dest);
        $this->assertFileDoesNotExist($dest.'.part');
    }

    public function test_senza_digest_valido_il_download_viene_rifiutato(): void
    {
        $this->fakeRelease('x', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('digest sha256');

        app(UpdateService::class)->scaricaPacchetto('0.2.0', $this->dir.'/p.zip');
    }

    public function test_release_senza_pacchetto_viene_rifiutata_niente_zipball(): void
    {
        Http::fake([
            'api.github.com/repos/fabiodalez-dev/gestionale-associazioni/releases/tags/v0.2.0' => Http::response(['tag_name' => 'v0.2.0', 'assets' => [], 'zipball_url' => 'https://api.github.com/x/zipball']),
        ]);

        try {
            app(UpdateService::class)->scaricaPacchetto('0.2.0', $this->dir.'/p.zip');
            $this->fail('Attesa eccezione');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('non contiene il pacchetto installabile', $e->getMessage());
        }
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'zipball'));
    }

    public function test_controlla_aggiornamenti_segnala_se_il_pacchetto_manca(): void
    {
        Http::fake([
            'api.github.com/repos/fabiodalez-dev/gestionale-associazioni/releases/latest' => Http::response([
                'tag_name' => 'v99.0.0', 'body' => 'note', 'html_url' => 'https://github.com/x',
                'assets' => [['name' => 'gestionale-associazioni-v99.0.0.zip', 'browser_download_url' => 'https://github.com/x/p.zip', 'digest' => 'sha256:'.str_repeat('b', 64)]],
            ]),
        ]);

        $stato = app(UpdateService::class)->controllaAggiornamenti();

        $this->assertTrue($stato['disponibile']);
        $this->assertSame('99.0.0', $stato['ultima']);
        $this->assertSame('v99.0.0', $stato['tag']);
        $this->assertTrue($stato['pacchetto']);
    }

    private function fakeRelease(string $contenuto, ?string $digest): void
    {
        $asset = ['name' => 'gestionale-associazioni-v0.2.0.zip', 'browser_download_url' => 'https://github.com/fabiodalez-dev/gestionale-associazioni/releases/download/v0.2.0/gestionale-associazioni-v0.2.0.zip', 'size' => strlen($contenuto)];
        if ($digest !== null) {
            $asset['digest'] = $digest;
        }
        Http::fake([
            'api.github.com/repos/fabiodalez-dev/gestionale-associazioni/releases/tags/v0.2.0' => Http::response(['tag_name' => 'v0.2.0', 'assets' => [$asset]]),
            'github.com/*' => Http::response($contenuto, 200, ['Content-Type' => 'application/octet-stream']),
        ]);
    }
}
