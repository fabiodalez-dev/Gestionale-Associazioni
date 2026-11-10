<?php

namespace App\Services\Sistema;

use Cron\CronExpression;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Il gestionale funziona anche senza cron (#68), come Pinakes: se lo
 * scheduler non batte da più di MINUTI_SILENZIO minuti, una richiesta web
 * al minuto esegue, dopo aver inviato la risposta, le attività programmate
 * che nel frattempo sarebbero dovute partire (una volta ciascuna, non una per
 * ogni occorrenza persa) e svuota la coda dei job. Con il cron attivo non fa
 * nulla.
 */
class CronDiRiserva
{
    /** Silenzio dello scheduler oltre il quale si supplisce dal web. */
    public const MINUTI_SILENZIO = 10;

    /** Attività che dal web non hanno senso o falserebbero lo stato del cron. */
    private const ESCLUSE = [
        // Scriverebbe il battito: il cron sembrerebbe attivo e l'avviso sparirebbe
        'health:schedule-check-heartbeat',
        // Il worker della coda si lancia a parte, con un limite di tempo
        'queue:work',
    ];

    public function __construct(private readonly StatoCron $statoCron) {}

    public function necessario(): bool
    {
        if (! is_file((string) config('installer.lock_file'))) {
            return false;
        }

        $battito = $this->statoCron->ultimoBattito();

        return $battito === null || $battito->lt(now()->subMinutes(self::MINUTI_SILENZIO));
    }

    /** Dalla richiesta web: al più una volta al minuto, dopo la risposta. */
    public function pianifica(): void
    {
        if (! $this->necessario() || ! Cache::add('cron-riserva:turno', 1, 60)) {
            return;
        }

        app()->terminating(function (): void {
            try {
                $this->esegui();
            } catch (\Throwable $e) {
                Log::warning('CronDiRiserva: esecuzione fallita', ['error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Esegue le attività scadute dall'ultimo passaggio e la coda.
     *
     * @return list<string> descrizione delle attività eseguite
     */
    public function esegui(): array
    {
        $lock = Cache::lock('cron-riserva:in-corso', 600);
        if (! $lock->get()) {
            return [];
        }

        $eseguite = [];

        try {
            @set_time_limit(300);
            ignore_user_abort(true);

            foreach (app(Schedule::class)->events() as $event) {
                if ($this->escluso($event) || ! $this->scaduto($event)) {
                    continue;
                }

                try {
                    $this->eseguiEvento($event);
                    $eseguite[] = $this->nome($event);
                } catch (\Throwable $e) {
                    Log::warning('CronDiRiserva: attività fallita', ['attivita' => $this->nome($event), 'error' => $e->getMessage()]);
                }
            }

            if (in_array(config('queue.default'), ['database', 'redis'], true)) {
                Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 30, '--tries' => 3]);
                $eseguite[] = 'queue:work';
            }
        } finally {
            $lock->release();
        }

        if ($eseguite !== []) {
            Log::info('CronDiRiserva: attività eseguite dal web in assenza di cron', ['attivita' => $eseguite]);
        }

        return $eseguite;
    }

    /**
     * Doveva partire dopo l'ultimo passaggio? Il primo passaggio segna solo
     * il punto di partenza: niente raffica di tutte le attività del giorno.
     * Il passaggio viene segnato prima di eseguire, così un errore non la
     * ripete a ogni richiesta.
     */
    private function scaduto(Event $event): bool
    {
        $chiave = 'cron-riserva:ultimo:'.$event->mutexName();
        $ultimo = Cache::get($chiave);
        Cache::forever($chiave, now()->getTimestamp());

        if (! is_int($ultimo)) {
            return false;
        }

        $previsto = (new CronExpression($event->expression))
            ->getPreviousRunDate(now(), 0, true, $event->timezone ?: config('app.timezone'));

        return $previsto->getTimestamp() > $ultimo && $event->filtersPass(app());
    }

    private function escluso(Event $event): bool
    {
        $comando = (string) $event->command;
        foreach (self::ESCLUSE as $escluso) {
            if (str_contains($comando, $escluso)) {
                return true;
            }
        }

        return false;
    }

    private function eseguiEvento(Event $event): void
    {
        if ($event instanceof CallbackEvent) {
            $event->run(app());

            return;
        }

        // Comandi artisan nello stesso processo: dal web il binario PHP può
        // essere php-fpm, non la CLI. Comandi di sistema: mai dal web.
        $prefisso = ConsoleApplication::formatCommandString('');
        $comando = (string) $event->command;
        if (str_starts_with($comando, $prefisso)) {
            Artisan::call(trim(substr($comando, strlen($prefisso))));
        }
    }

    private function nome(Event $event): string
    {
        return $event instanceof CallbackEvent
            ? ($event->description ?? 'callback')
            : trim(str_replace(ConsoleApplication::formatCommandString(''), '', (string) $event->command));
    }
}
