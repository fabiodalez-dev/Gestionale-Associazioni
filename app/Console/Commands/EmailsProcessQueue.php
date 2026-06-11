<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEmailQueueJob;
use App\Models\EmailQueue;
use Illuminate\Console\Command;

/**
 * Sostituisce cron/process_email_queue.php del legacy: per ogni tenant con
 * email pending dispatcha un job di elaborazione (batch 50). Schedulato ogni
 * 5 minuti. Con --sync elabora subito senza passare dalla queue Laravel.
 */
class EmailsProcessQueue extends Command
{
    protected $signature = 'emails:process-queue
        {--sync : Elabora subito senza accodare il job}
        {--batch=50 : Numero massimo di email per tenant}';

    protected $description = 'Elabora la coda email di tutte le associazioni con invii pending';

    public function handle(): int
    {
        $batchSize = max(1, (int) $this->option('batch'));

        $tenantIds = EmailQueue::allTenants()
            ->where('status', EmailQueue::STATUS_PENDING)
            ->distinct()
            ->pluck('associazione_id');

        if ($tenantIds->isEmpty()) {
            $this->info('Nessuna email in coda.');

            return self::SUCCESS;
        }

        foreach ($tenantIds as $associazioneId) {
            if ($this->option('sync')) {
                ProcessEmailQueueJob::dispatchSync($associazioneId, $batchSize);
            } else {
                ProcessEmailQueueJob::dispatch($associazioneId, $batchSize);
            }

            $this->line("[{$associazioneId}] elaborazione ".($this->option('sync') ? 'eseguita' : 'accodata').'.');
        }

        $this->info($tenantIds->count().' associazioni elaborate.');

        return self::SUCCESS;
    }
}
