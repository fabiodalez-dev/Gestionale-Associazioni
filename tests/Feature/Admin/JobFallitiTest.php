<?php

namespace Tests\Feature\Admin;

use App\Health\JobFallitiCheck;
use App\Models\Associazione;
use App\Models\Utente;
use App\Notifications\Sistema\DestinatariJobFalliti;
use App\Notifications\Sistema\JobFallito;
use App\Support\Sistema\FiltroJobFalliti;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Health\Enums\Status;
use Tests\TestCase;

class JobFallitiTest extends TestCase
{
    use RefreshDatabase;

    private function jobFallito(string $nome = 'App\\Jobs\\ProcessEmailQueueJob', string $coda = 'default', ?string $quando = null): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => $coda,
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => $nome, 'attempts' => 3, 'maxTries' => 3, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => JobDiProva::class, 'command' => serialize(new JobDiProva)]]),
            'exception' => "RuntimeException: SMTP non raggiungibile in /app/X.php:10\nStack trace:\n#0 {main}",
            'failed_at' => $quando ?? now(),
        ]);

        return $uuid;
    }

    private function evento(string $nome = 'App\\Jobs\\ProcessEmailQueueJob'): JobFailed
    {
        $job = $this->createMock(Job::class);
        $job->method('resolveName')->willReturn($nome);
        $job->method('getQueue')->willReturn('default');

        return new JobFailed('database', $job, new RuntimeException('SMTP non raggiungibile'));
    }

    public function test_la_pagina_e_riservata_al_super_admin(): void
    {
        $admin = Utente::factory()->adminAssociazione()->create(['associazione_id' => Associazione::factory()->create()->id]);
        $uuid = $this->jobFallito();

        $this->actingAs($admin)->get(route('job-falliti.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('job-falliti.retry', $uuid))->assertForbidden();
        $this->actingAs($admin)->delete(route('job-falliti.destroy-all'))->assertForbidden();
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_elenco_dettaglio_riprova_ed_eliminazione(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $uuid = $this->jobFallito();
        $altro = $this->jobFallito('App\\Jobs\\AltroJob', 'promemoria');

        $this->actingAs($superAdmin)->get(route('job-falliti.index'))
            ->assertOk()
            ->assertSee('ProcessEmailQueueJob')
            ->assertSee('SMTP non raggiungibile')
            ->assertSee('2 job falliti');

        $this->actingAs($superAdmin)->get(route('job-falliti.index', ['coda' => 'promemoria']))
            ->assertOk()->assertSee('AltroJob')->assertDontSee('ProcessEmailQueueJob');

        $this->actingAs($superAdmin)->get(route('job-falliti.show', $uuid))
            ->assertOk()->assertSee('Stack trace');

        $this->actingAs($superAdmin)->post(route('job-falliti.retry', $uuid))->assertRedirect(route('job-falliti.index'));
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertDatabaseHas('jobs', ['queue' => 'default']);

        $this->actingAs($superAdmin)->delete(route('job-falliti.destroy', $altro))->assertRedirect();
        $this->assertDatabaseCount('failed_jobs', 0);

        $this->actingAs($superAdmin)->get(route('job-falliti.show', $altro))->assertNotFound();
    }

    public function test_riprova_ed_elimina_tutti(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();
        $this->jobFallito();
        $this->jobFallito();

        $this->actingAs($superAdmin)->post(route('job-falliti.retry-all'))->assertSessionHas('success', '2 job rimessi in coda.');
        $this->assertDatabaseCount('failed_jobs', 0);

        $this->jobFallito();
        $this->actingAs($superAdmin)->delete(route('job-falliti.destroy-all'))->assertSessionHas('success');
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_avviso_email_senza_payload_e_con_limite_per_tipo_di_job(): void
    {
        config(['failed-job-monitor.mail.to' => ['admin@example.com'], 'failed-job-monitor.throttle_minutes' => 15]);

        $notifica = (new JobFallito)->setEvent($this->evento());
        $this->assertTrue(FiltroJobFalliti::consenti($notifica));
        $this->assertFalse(FiltroJobFalliti::consenti((new JobFallito)->setEvent($this->evento())));
        $this->assertTrue(FiltroJobFalliti::consenti((new JobFallito)->setEvent($this->evento('App\\Jobs\\AltroJob'))));

        $mail = $notifica->toMail(new DestinatariJobFalliti);
        $testo = implode("\n", $mail->introLines);
        $this->assertStringContainsString('ProcessEmailQueueJob', $testo);
        $this->assertStringContainsString('SMTP non raggiungibile', $testo);
        $this->assertStringNotContainsString('commandName', $testo);
        $this->assertStringEndsWith('/job-falliti', $mail->actionUrl);

        $this->travel(16)->minutes();
        $this->assertTrue(FiltroJobFalliti::consenti((new JobFallito)->setEvent($this->evento())));
    }

    public function test_nessun_avviso_senza_destinatari(): void
    {
        config(['failed-job-monitor.mail.to' => []]);

        $this->assertFalse(FiltroJobFalliti::consenti((new JobFallito)->setEvent($this->evento())));
    }

    public function test_un_errore_di_invio_non_interrompe_il_worker(): void
    {
        config(['mail.default' => 'inesistente', 'failed-job-monitor.mail.to' => ['admin@example.com']]);

        Log::shouldReceive('warning')->once()->withArgs(fn (string $m) => str_starts_with($m, 'Avviso job fallito non inviato'));

        (new DestinatariJobFalliti)->notify((new JobFallito)->setEvent($this->evento()));
    }

    public function test_controllo_di_salute_sui_job_falliti_delle_ultime_24_ore(): void
    {
        $this->assertSame(Status::ok()->value, JobFallitiCheck::new()->run()->status->value);

        $this->jobFallito(quando: now()->subDays(2)->toDateTimeString());
        $this->assertSame(Status::ok()->value, JobFallitiCheck::new()->run()->status->value);

        $this->jobFallito();
        $this->assertSame(Status::warning()->value, JobFallitiCheck::new()->run()->status->value);

        $this->jobFallito();
        $this->assertSame(Status::failed()->value, JobFallitiCheck::new()->failWhenMoreThan(1)->run()->status->value);
    }

    public function test_destinatari_configurabili_dalle_impostazioni_di_sistema(): void
    {
        $superAdmin = Utente::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->get(route('impostazioni-sistema.index', ['sezione' => 'backup']))
            ->assertOk()->assertSee('FAILED_JOB_EMAILS');

        $this->actingAs($superAdmin)
            ->put(route('impostazioni-sistema.update', 'backup'), ['FAILED_JOB_EMAILS' => 'ok@example.com, non-una-email'])
            ->assertSessionHasErrors('FAILED_JOB_EMAILS');
    }
}

class JobDiProva implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}
