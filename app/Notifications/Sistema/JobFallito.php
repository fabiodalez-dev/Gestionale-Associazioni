<?php

namespace App\Notifications\Sistema;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;
use Spatie\FailedJobMonitor\Notification;

/**
 * Avviso di job fallito per il super_admin. Rispetto alla notifica del
 * pacchetto non include il payload del job (può contenere dati personali
 * serializzati) né lo stack trace completo: solo classe, coda, messaggio e
 * il link alla pagina Job falliti con il dettaglio.
 */
class JobFallito extends Notification
{
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $evento = $this->getEvent();
        $base = rtrim((string) config('app.url'), '/');

        return (new MailMessage)
            ->mailer((string) config('mail.default'))
            ->error()
            ->subject('Job fallito su '.config('app.name'))
            ->line('Un job in coda è fallito definitivamente.')
            ->line('Job: '.$evento->job->resolveName())
            ->line('Coda: '.$evento->job->getQueue().' ('.$evento->connectionName.')')
            ->line('Errore: '.Str::limit($evento->exception->getMessage(), 500))
            ->action('Apri i job falliti', $base.route('job-falliti.index', [], false))
            ->line('Per '.config('failed-job-monitor.throttle_minutes').' minuti non riceverai altri avvisi per lo stesso tipo di job.');
    }
}
