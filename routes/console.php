<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Spatie\Health\Commands\DispatchQueueCheckJobsCommand;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTaskLogItem;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Modulo email — schedulazione (ex cron legacy)
|--------------------------------------------------------------------------
*/
Schedule::command('emails:process-queue')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('emails:send-reminders')
    ->dailyAt('08:00')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Modulo documenti-verbali-scadenze — promemoria scadenze (estensione §6.3)
|--------------------------------------------------------------------------
*/
Schedule::command('scadenze:invia-promemoria')
    ->dailyAt('07:30')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Backup pianificati (spatie/laravel-backup) — miglioramento n. 17
|--------------------------------------------------------------------------
*/
Schedule::command('backup:clean')
    ->dailyAt('01:30')
    ->withoutOverlapping();

Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->withoutOverlapping();

Schedule::command('backup:monitor')
    ->dailyAt('03:00')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Coda Laravel (jobs asincroni) — miglioramento n. 18
|--------------------------------------------------------------------------
|
| Il worker gira in modo continuativo via supervisor (vedi docs/PIANO-
| MIGRAZIONE.md): qui solo retry periodico dei job falliti e potatura.
|
*/
Schedule::command('queue:retry all')
    ->dailyAt('03:30')
    ->withoutOverlapping();

// I failed_jobs più vecchi di 7 giorni vengono eliminati (sono già stati
// ritentati dal retry quotidiano: se falliscono ancora dopo una settimana
// restano tracciati nei log applicativi).
Schedule::command('queue:prune-failed', ['--hours' => 168])
    ->daily()
    ->withoutOverlapping();

Schedule::command('queue:prune-batches', ['--hours' => 168])
    ->daily()
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Health check (spatie/laravel-health) — miglioramento n. 20
|--------------------------------------------------------------------------
|
| health:check esegue i check registrati in AppServiceProvider e salva i
| risultati (consultabili su /health). I due heartbeat alimentano
| rispettivamente il QueueCheck e lo ScheduleCheck.
|
*/
Schedule::command(RunHealthChecksCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(DispatchQueueCheckJobsCommand::class)
    ->everyMinute();

Schedule::command(ScheduleCheckHeartbeatCommand::class)
    ->everyMinute();

/*
|--------------------------------------------------------------------------
| Monitoraggio scheduler (spatie/laravel-schedule-monitor) — migl. n. 19
|--------------------------------------------------------------------------
|
| sync registra in tabella i task pianificati (da rilanciare anche a ogni
| deploy); il prune elimina i log item più vecchi di 30 giorni
| (config/schedule-monitor.php → delete_log_items_older_than_days).
|
*/
Schedule::command('schedule-monitor:sync')
    ->dailyAt('04:00')
    ->withoutOverlapping();

Schedule::command('model:prune', [
    '--model' => [MonitoredScheduledTaskLogItem::class],
])
    ->dailyAt('04:10')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Pulizia activity log (spatie/laravel-activitylog) — audit trail
|--------------------------------------------------------------------------
|
| Elimina le attività più vecchie di config('activitylog.clean_after_days')
| (365 giorni): l'audit resta consultabile per un anno senza far crescere
| la tabella all'infinito.
|
*/
Schedule::command('activitylog:clean --force')
    ->dailyAt('04:30')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Modulo prenotazioni — scadenze pagamento e promemoria eventi
|--------------------------------------------------------------------------
|
| gestisci-scadenze: ogni minuto marca 'scaduta' le in_attesa_pagamento
| oltre finestra e promuove la lista d'attesa (mutex per-evento nel
| PrenotazioneService). promemoria-eventi: ogni giorno alle 08:30 invia
| il promemoria alle confermate con evento tra 24h e 48h (finestra di
| 24h + run giornaliero = un solo invio per prenotazione).
|
*/
Schedule::command('prenotazioni:gestisci-scadenze')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('prenotazioni:promemoria-eventi')
    ->dailyAt('08:30')
    ->withoutOverlapping();
