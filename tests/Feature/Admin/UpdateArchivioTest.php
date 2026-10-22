<?php

namespace Tests\Feature\Admin;

use App\Services\Update\UpdateService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * L'aggiornatore scarica il pacchetto completo pubblicato dalla release
 * (bin/build-release.sh) e ripiega sullo zipball dei sorgenti solo se manca.
 */
class UpdateArchivioTest extends TestCase
{
    public function test_preferisce_il_pacchetto_della_release(): void
    {
        config(['update.github.owner' => 'fabiodalez-dev', 'update.github.repo' => 'gestionale-associazioni']);
        Http::fake([
            'api.github.com/repos/fabiodalez-dev/gestionale-associazioni/releases/tags/v1.2.3' => Http::response([
                'tag_name' => 'v1.2.3',
                'assets' => [
                    ['name' => 'gestionale-associazioni-v1.2.3.zip.sha256', 'browser_download_url' => 'https://github.com/x/sha'],
                    ['name' => 'gestionale-associazioni-v1.2.3.zip', 'browser_download_url' => 'https://github.com/x/pacchetto.zip'],
                ],
            ]),
        ]);

        $archivio = app(UpdateService::class)->archivioRelease('v1.2.3');

        $this->assertTrue($archivio['asset']);
        $this->assertSame('https://github.com/x/pacchetto.zip', $archivio['url']);
    }

    public function test_senza_pacchetto_usa_lo_zipball_dei_sorgenti(): void
    {
        config(['update.github.owner' => 'fabiodalez-dev', 'update.github.repo' => 'gestionale-associazioni']);
        Http::fake([
            'api.github.com/repos/fabiodalez-dev/gestionale-associazioni/releases/tags/v1.2.3' => Http::response(['tag_name' => 'v1.2.3', 'assets' => []]),
        ]);

        $archivio = app(UpdateService::class)->archivioRelease('v1.2.3');

        $this->assertFalse($archivio['asset']);
        $this->assertSame('https://api.github.com/repos/fabiodalez-dev/gestionale-associazioni/zipball/v1.2.3', $archivio['url']);
    }

    public function test_errore_di_rete_sull_elenco_asset_non_blocca(): void
    {
        config(['update.github.owner' => 'o', 'update.github.repo' => 'r']);
        Http::fake(['api.github.com/*' => Http::response('', 500)]);

        $archivio = app(UpdateService::class)->archivioRelease('v2.0.0');

        $this->assertFalse($archivio['asset']);
        $this->assertStringContainsString('/zipball/v2.0.0', $archivio['url']);
    }
}
