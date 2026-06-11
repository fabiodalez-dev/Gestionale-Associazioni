<?php

namespace App\Jobs;

use App\Models\Associazione;
use App\Services\Email\EmailService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Motore di elaborazione della coda email di dominio (email_queue) per un tenant.
 *
 * Miglioramento 18: gira sulla queue database di Laravel con retry e backoff
 * esponenziale; i fallimenti definitivi finiscono in failed_jobs. La tabella
 * email_queue resta la coda di dominio visibile all'utente (stati, priorità,
 * annulla/riprova per riga).
 */
class ProcessEmailQueueJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Tentativi del job (oltre ai retry per-email gestiti in email_queue) */
    public int $tries = 3;

    /** Lock di unicità: una sola elaborazione per tenant alla volta */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $associazioneId,
        public readonly int $batchSize = 50,
    ) {}

    public function uniqueId(): string
    {
        return $this->associazioneId;
    }

    /**
     * Backoff esponenziale tra i retry del job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(EmailService $emailService): void
    {
        $associazione = Associazione::query()->find($this->associazioneId);

        if ($associazione === null) {
            return;
        }

        if (! $emailService->isConfigured($associazione)) {
            // SMTP non configurato: le email restano pending (come il cron legacy)
            return;
        }

        $result = $emailService->processQueue($associazione, $this->batchSize);

        Log::info('Coda email elaborata', [
            'associazione_id' => $this->associazioneId,
            'sent' => $result['sent'],
            'failed' => $result['failed'],
            'remaining' => $result['remaining'],
        ]);
    }
}
