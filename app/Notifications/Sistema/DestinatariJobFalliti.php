<?php

namespace App\Notifications\Sistema;

use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Log;
use Spatie\FailedJobMonitor\Notifiable;
use Throwable;

/**
 * Destinatari degli avvisi sui job falliti. L'invio avviene dentro il worker
 * mentre registra il fallimento: se fallisce anche la posta (spesso è proprio
 * la causa) l'errore va nel log invece di interrompere il worker.
 */
class DestinatariJobFalliti extends Notifiable
{
    public function notify($instance): void
    {
        try {
            app(Dispatcher::class)->sendNow($this, $instance);
        } catch (Throwable $e) {
            Log::warning('Avviso job fallito non inviato: '.$e->getMessage());
        }
    }
}
