<?php

namespace Tests\Feature\Admin;

use App\Health\RiscrittureIndirizziCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Spatie\Health\Enums\Status;
use Tests\TestCase;

/**
 * Controllo di salute delle riscritture .htaccess (#32), con il caso reale
 * di IONOS: home ok, /login 500, index.php diretto ok.
 */
class RiscrittureIndirizziCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://sito.test']);
    }

    private function esito(): Status
    {
        return RiscrittureIndirizziCheck::new()->run()->status;
    }

    public function test_riscritture_funzionanti(): void
    {
        Http::fake([
            'sito.test/up' => Http::response('ok', 200),
            'sito.test/up/' => Http::response('', 301, ['Location' => 'https://sito.test/up']),
        ]);

        $this->assertSame(Status::ok()->value, $this->esito()->value);
    }

    public function test_riscritture_rotte_come_su_ionos(): void
    {
        Http::fake([
            'sito.test/up' => Http::response('Internal Server Error', 500),
            'sito.test/public/index.php/up' => Http::response('ok', 200),
        ]);

        $risultato = RiscrittureIndirizziCheck::new()->run();

        $this->assertSame(Status::failed()->value, $risultato->status->value);
        $this->assertStringContainsString('public/.htaccess', $risultato->getNotificationMessage());
    }

    public function test_redirect_della_barra_finale_con_public(): void
    {
        Http::fake([
            'sito.test/up' => Http::response('ok', 200),
            'sito.test/up/' => Http::response('', 301, ['Location' => 'https://sito.test/public/up']),
        ]);

        $this->assertSame(Status::warning()->value, $this->esito()->value);
    }

    public function test_server_che_non_raggiunge_se_stesso_nessun_falso_allarme(): void
    {
        Http::fake(fn () => throw new ConnectionException('irraggiungibile'));

        $this->assertSame(Status::ok()->value, $this->esito()->value);
    }

    public function test_installazione_in_public_prova_index_php_senza_doppio_public(): void
    {
        config(['app.url' => 'https://sito.test/gestionale/public']);
        Http::fake([
            'sito.test/gestionale/public/up' => Http::response('', 404),
            'sito.test/gestionale/public/index.php/up' => Http::response('ok', 200),
        ]);

        $this->assertSame(Status::failed()->value, $this->esito()->value);
    }
}
