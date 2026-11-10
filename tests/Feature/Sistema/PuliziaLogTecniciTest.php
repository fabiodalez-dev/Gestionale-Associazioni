<?php

namespace Tests\Feature\Sistema;

use App\Models\Accesso;
use App\Models\Associazione;
use App\Models\EmailLog;
use App\Models\EmailQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pulizia a durata fissa dei log tecnici (#70) con model:prune, come la
 * lancia lo scheduler ogni notte.
 */
class PuliziaLogTecniciTest extends TestCase
{
    use RefreshDatabase;

    private Associazione $associazione;

    protected function setUp(): void
    {
        parent::setUp();
        $this->associazione = Associazione::factory()->create();
    }

    private function accesso(string $esito, int $giorniFa): string
    {
        $id = (string) Str::uuid();
        DB::table('accessi')->insert([
            'id' => $id, 'associazione_id' => $this->associazione->id, 'guard' => 'web',
            'email_tentata' => 'x@example.test', 'esito' => $esito,
            'created_at' => now()->subDays($giorniFa), 'updated_at' => now()->subDays($giorniFa),
        ]);

        return $id;
    }

    private function coda(string $stato, int $giorniFa): string
    {
        $id = (string) Str::uuid();
        DB::table('email_queue')->insert([
            'id' => $id, 'associazione_id' => $this->associazione->id, 'batch_id' => (string) Str::uuid(),
            'to_email' => 'socio@example.test', 'subject' => 'Ciao', 'body_html' => '<p>x</p>',
            'status' => $stato, 'created_at' => now()->subDays($giorniFa),
        ]);

        return $id;
    }

    private function storico(int $giorniFa): string
    {
        $id = (string) Str::uuid();
        DB::table('email_log')->insert([
            'id' => $id, 'associazione_id' => $this->associazione->id,
            'to_email' => 'socio@example.test', 'subject' => 'Ciao', 'status' => 'sent',
            'sent_at' => now()->subDays($giorniFa),
        ]);

        return $id;
    }

    public function test_cancella_solo_cio_che_ha_superato_la_durata_fissa(): void
    {
        $fallitoVecchio = $this->accesso(Accesso::ESITO_FALLITO, 91);
        $fallitoRecente = $this->accesso(Accesso::ESITO_FALLITO, 30);
        $loginVecchio = $this->accesso(Accesso::ESITO_LOGIN, 400);
        $loginDiMesi = $this->accesso(Accesso::ESITO_LOGIN, 200);

        $inviataVecchia = $this->coda(EmailQueue::STATUS_SENT, 91);
        $fallitaVecchia = $this->coda(EmailQueue::STATUS_FAILED, 120);
        $daInviareVecchia = $this->coda(EmailQueue::STATUS_PENDING, 200);
        $inviataRecente = $this->coda(EmailQueue::STATUS_SENT, 10);

        $storicoVecchio = $this->storico(400);
        $storicoRecente = $this->storico(100);

        Artisan::call('model:prune', ['--model' => [Accesso::class, EmailQueue::class, EmailLog::class]]);

        $restano = fn (string $tabella) => DB::table($tabella)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$fallitoRecente, $loginDiMesi], $restano('accessi'));
        $this->assertEqualsCanonicalizing([$daInviareVecchia, $inviataRecente], $restano('email_queue'));
        $this->assertEqualsCanonicalizing([$storicoRecente], $restano('email_log'));

        $this->assertNotContains($fallitoVecchio, $restano('accessi'));
        $this->assertNotContains($loginVecchio, $restano('accessi'));
        $this->assertNotContains($inviataVecchia, $restano('email_queue'));
        $this->assertNotContains($fallitaVecchia, $restano('email_queue'));
        $this->assertNotContains($storicoVecchio, $restano('email_log'));
    }
}
